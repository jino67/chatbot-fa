<?php

namespace App\Http\Controllers;

use App\Ingestion\Crawler\SafeUrl;
use App\Ingestion\Crawler\UnsafeUrlException;
use App\Ingestion\Crawler\Url;
use App\Jobs\IngestSource;
use App\Models\Bot;
use App\Models\Source;
use App\Services\UsageService;
use App\Social\FacebookGraph;
use App\Social\FacebookUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    public function index(Bot $bot, UsageService $usage, Request $request, FacebookGraph $facebook)
    {
        $workspace = $request->user()->currentWorkspace();

        return view('sources.index', [
            'bot' => $bot,
            'sources' => $bot->sources()->latest()->get(),
            'usage' => $usage->summary($workspace),
            'workspace' => $workspace,
            'facebookConnect' => $facebook->isConfigured(),
        ]);
    }

    public function store(Request $request, Bot $bot, UsageService $usage): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();

        if (! $usage->canAddSource($workspace)) {
            return back()->with('error', 'Votre offre a atteint sa limite de sources. Supprimez-en une ou passez à une offre supérieure.');
        }

        $types = [Source::TYPE_FILE, Source::TYPE_IMAGE, Source::TYPE_URL, Source::TYPE_TEXT, Source::TYPE_QA, Source::TYPE_FACEBOOK];
        $type = $request->validate(['type' => ['required', Rule::in($types)]])['type'];

        $source = match ($type) {
            Source::TYPE_FILE => $this->file($request, $bot),
            Source::TYPE_IMAGE => $this->image($request, $bot),
            Source::TYPE_URL => $this->url($request, $bot),
            Source::TYPE_TEXT => $this->text($request, $bot),
            Source::TYPE_QA => $this->qa($request, $bot),
            Source::TYPE_FACEBOOK => $this->facebook($request, $bot),
        };

        if ($source instanceof RedirectResponse) {
            return $source;
        }

        // Lien Facebook ou Instagram : rien n'est lu automatiquement, on invite le client a fournir le contenu.
        if ($source->needsContent()) {
            return back()->with('status', 'Lien enregistré. Une dernière étape : ajoutez le contenu de votre page ci-dessous.')
                ->with('focus_source', $source->id);
        }

        IngestSource::dispatch($source->id);

        return back()->with('status', 'Source ajoutée : l\'indexation est en cours.');
    }

    /**
     * Le client colle le contenu de sa page Facebook ou Instagram (et peut indiquer le site web lie,
     * que nous lisons automatiquement, dans la limite de son offre).
     */
    public function content(Request $request, Bot $bot, Source $source, UsageService $usage): RedirectResponse
    {
        abort_unless($source->type === Source::TYPE_FACEBOOK, 404);

        $data = $request->validate([
            'content' => ['required', 'string', 'min:20', 'max:100000'],
            'website' => ['nullable', 'string', 'max:300'],
        ]);

        $payload = $source->payload ?? [];
        $payload['content'] = $data['content'];
        $source->forceFill(['payload' => $payload, 'status' => Source::PENDING, 'error' => null])->save();
        IngestSource::dispatch($source->id);

        $message = 'Merci ! Le contenu de votre page est en cours d\'indexation.';

        if (filled($data['website'] ?? null)) {
            $site = $this->websiteSource($request, $bot, $data['website'], $usage);
            $message .= $site === true
                ? ' Votre site web est lu en même temps.'
                : ' Le site web n\'a pas été ajouté : '.$site;
        }

        return back()->with('status', $message);
    }

    public function resync(Bot $bot, Source $source): RedirectResponse
    {
        abort_if($source->needsContent(), 422);

        $source->forceFill(['status' => Source::PENDING, 'error' => null])->save();
        IngestSource::dispatch($source->id);

        return back()->with('status', 'Nouvelle indexation lancée.');
    }

    public function destroy(Bot $bot, Source $source): RedirectResponse
    {
        $source->delete();

        return back()->with('status', 'Source supprimée : l\'assistant ne s\'en servira plus.');
    }

    private function file(Request $request, Bot $bot): Source|RedirectResponse
    {
        $extensions = config('platform.uploads.documents');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('platform.uploads.max_kb'), 'extensions:'.implode(',', $extensions)],
        ]);

        return $this->stored($bot, Source::TYPE_FILE, $data['file']);
    }

    private function image(Request $request, Bot $bot): Source|RedirectResponse
    {
        $extensions = config('platform.uploads.images');
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'max:'.config('platform.uploads.max_kb'), 'extensions:'.implode(',', $extensions)],
        ]);

        return $this->stored($bot, Source::TYPE_IMAGE, $data['image']);
    }

    private function stored(Bot $bot, string $type, $upload): Source
    {
        // Nom de stockage aleatoire : le nom d'origine n'est jamais utilise comme chemin.
        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs(
            'sources/'.$bot->workspace_id.'/'.$bot->id,
            Str::random(40).'.'.$extension,
            config('platform.uploads.disk')
        );

        return Source::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => $type,
            'name' => Str::limit($upload->getClientOriginalName(), 120, ''),
            'payload' => ['path' => $path, 'original_name' => $upload->getClientOriginalName(), 'size' => $upload->getSize()],
        ]);
    }

    private function url(Request $request, Bot $bot): Source|RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'mode' => ['required', Rule::in(['site', 'page'])],
            'resync' => ['nullable', Rule::in(['never', 'daily', 'weekly'])],
        ]);

        // Facebook et Instagram : jamais lus par nos serveurs (conditions de Meta). On garde le lien et on guide le client.
        if ($social = FacebookUrl::parse($data['url'])) {
            return Source::create([
                'workspace_id' => $bot->workspace_id,
                'bot_id' => $bot->id,
                'type' => Source::TYPE_FACEBOOK,
                'name' => ($social['platform'] === 'instagram' ? 'Instagram' : 'Facebook').($social['slug'] ? ' : '.$social['slug'] : ''),
                'status' => Source::NEEDS_CONTENT,
                'payload' => ['url' => $social['url'], 'platform' => $social['platform'], 'kind' => $social['kind'], 'slug' => $social['slug']],
            ]);
        }

        $request->validate(['url' => ['url:http,https']]);
        $url = Url::normalize($data['url']);
        $host = Url::host($url);

        // Autres reseaux sociaux : refus immediat plutot qu'un echec silencieux en file d'attente.
        foreach (config('platform.crawler.blocked_hosts') as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                return back()->withInput()->with('error', "Les réseaux sociaux ne peuvent pas être explorés automatiquement (conditions d'utilisation des plateformes). Copiez le texte de la page et collez-le dans l'onglet « Texte ».");
            }
        }

        try {
            SafeUrl::assertPublic($url);
        } catch (UnsafeUrlException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return Source::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => Source::TYPE_URL,
            'name' => $host,
            'resync' => $data['resync'] ?? 'never',
            'payload' => ['url' => $url, 'mode' => $data['mode']],
        ]);
    }

    /** Ajoute la source « site web » liee a une page Facebook. @return true|string vrai, ou le motif du refus */
    private function websiteSource(Request $request, Bot $bot, string $website, UsageService $usage): bool|string
    {
        if (! $usage->canAddSource($request->user()->currentWorkspace())) {
            return 'la limite de sources de votre offre est atteinte.';
        }

        $url = Url::normalize(str_starts_with($website, 'http') ? $website : 'https://'.$website);

        if (FacebookUrl::parse($url)) {
            return 'c\'est une adresse de réseau social.';
        }
        try {
            SafeUrl::assertPublic($url);
        } catch (UnsafeUrlException $e) {
            return $e->getMessage();
        }

        $source = Source::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => Source::TYPE_URL,
            'name' => Url::host($url),
            'resync' => 'weekly',
            'payload' => ['url' => $url, 'mode' => 'site'],
        ]);
        IngestSource::dispatch($source->id);

        return true;
    }

    private function text(Request $request, Bot $bot): Source
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'min:20', 'max:100000'],
        ]);

        return Source::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => Source::TYPE_TEXT,
            'name' => $data['title'],
            'payload' => ['content' => $data['content']],
        ]);
    }

    private function qa(Request $request, Bot $bot): Source
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:3000'],
        ]);

        return Source::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => Source::TYPE_QA,
            'name' => Str::limit($data['question'], 100, '…'),
            'payload' => ['question' => $data['question'], 'answer' => $data['answer']],
        ]);
    }

    /** Import assiste : le contenu de la page est colle par le client. */
    private function facebook(Request $request, Bot $bot): Source
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'page_url' => ['nullable', 'url:http,https', 'max:300'],
            'content' => ['required', 'string', 'min:20', 'max:100000'],
        ]);

        return Source::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => Source::TYPE_FACEBOOK,
            'name' => $data['title'],
            'payload' => ['content' => $data['content'], 'url' => $data['page_url'] ?? null],
        ]);
    }
}
