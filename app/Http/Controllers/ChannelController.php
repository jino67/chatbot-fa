<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\Channel;
use App\Ingestion\Crawler\SafeHttp;
use App\Ingestion\Crawler\SafeUrl;
use App\Ingestion\Crawler\UnsafeUrlException;
use App\Models\ChannelRequest;
use App\Support\WidgetInstallCheck;
use App\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Canaux de l'assistant : site web (autonome) et WhatsApp (active a la demande par l'equipe technique). */
class ChannelController extends Controller
{
    public function show(Request $request, Bot $bot)
    {
        $workspace = $request->user()->currentWorkspace();

        return view('channels.show', [
            'bot' => $bot,
            'whatsapp' => Channel::where('bot_id', $bot->id)
                ->whereIn('type', [Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])
                ->latest()->first(),
            'request' => ChannelRequest::where('bot_id', $bot->id)->latest()->first(),
            'snippet' => $this->snippet($bot),
            'widgetSeen' => cache()->get('widget-seen:'.$bot->id),
            'whatsappAllowed' => $workspace->hasFeature('whatsapp'),
            'templatesAllowed' => $workspace->hasFeature('templates'),
        ]);
    }

    public function requestWhatsApp(Request $request, Bot $bot, PlatformSettings $settings): RedirectResponse
    {
        if (! $request->user()->currentWorkspace()->hasFeature('whatsapp')) {
            return redirect()->route('billing.show')->with('error', "WhatsApp est inclus à partir de l'offre Essentiel. Choisissez une offre pour l'activer.");
        }

        $open = ChannelRequest::where('bot_id', $bot->id)->whereIn('status', [ChannelRequest::REQUESTED, ChannelRequest::IN_PROGRESS])->exists();
        if ($open) {
            return back()->with('error', 'Une demande est déjà en cours de traitement pour cet assistant.');
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-\.]{7,25}$/'],
            'country' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $channelRequest = ChannelRequest::create($data + [
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'requester_id' => $request->user()->id,
        ]);

        app(\App\Notify\Events::class)->whatsappRequested($channelRequest, $bot);

        return back()->with('status', "Demande envoyée. Notre équipe technique vous contactera pour finaliser l'activation.");
    }

    /**
     * « Je ne vois pas la bulle sur mon site » : ouvre la page indiquée comme le ferait un visiteur et dit pourquoi le script
     * n'y est pas, ou n'agit pas (voir WidgetInstallCheck). L'adresse passe par la protection habituelle contre les adresses internes.
     */
    public function checkInstall(Request $request, Bot $bot): RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:300']]);
        $url = trim($data['url']);
        $url = preg_match('#^https?://#i', $url) ? $url : 'https://'.$url;

        try {
            SafeUrl::assertPublic($url);
            $response = SafeHttp::get($url, 3, 1_500_000);
            $result = $response['status'] === 200
                ? WidgetInstallCheck::analyze($response['body'], $response['url'], $bot)
                : WidgetInstallCheck::unreachable("Le site a répondu HTTP {$response['status']} : vérifiez l'adresse de la page.");
        } catch (UnsafeUrlException $e) {
            $result = WidgetInstallCheck::unreachable($e->getMessage());
        } catch (\Throwable) {
            $result = WidgetInstallCheck::unreachable("Le site ne répond pas pour le moment. Vérifiez l'adresse, puis réessayez.");
        }

        return back()->with('install_check', $result + ['url' => $url])->withInput();
    }

    private function snippet(Bot $bot): string
    {
        $src = url('/widget/widget.js');

        return '<script src="'.$src.'" data-bot="'.$bot->public_key.'" async></script>';
    }
}
