<?php

namespace App\Http\Controllers;

use App\Channels\WhatsApp\GatewayException;
use App\Channels\WhatsApp\GatewayFactory;
use App\Channels\WhatsApp\InboundHandler;
use App\Channels\WhatsApp\TemplateManager;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Boite de reception : suivi des conversations et reprise en main par un humain. */
class ConversationController extends Controller
{
    public function index(Request $request, Bot $bot)
    {
        $status = $request->query('status');

        $conversations = $bot->conversations()
            ->real()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->addSelect(['last_text' => Message::select('content')
                ->whereColumn('conversation_id', 'conversations.id')
                ->latest('id')->limit(1)])
            ->orderByRaw("case status when 'needs_human' then 0 when 'human' then 1 else 2 end")
            ->latest('last_message_at')
            ->paginate(25)
            ->withQueryString();

        return view('conversations.index', [
            'bot' => $bot,
            'conversations' => $conversations,
            'status' => $status,
            'counts' => $bot->conversations()->real()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(Request $request, Bot $bot, Conversation $conversation)
    {
        // Modeles utilisables pour ecrire au client hors de la fenetre de 24 h.
        $templates = collect();
        if ($conversation->channel === 'whatsapp' && $request->user()->currentWorkspace()->hasFeature('templates')) {
            $channel = $this->whatsappChannel($bot);
            $templates = $channel ? WhatsAppTemplate::where('channel_id', $channel->id)->where('status', WhatsAppTemplate::APPROVED)->orderBy('name')->get() : collect();
        }

        return view('conversations.show', [
            'bot' => $bot,
            'conversation' => $conversation,
            'messages' => $conversation->messages()->orderBy('id')->get(),
            'templates' => $templates,
        ]);
    }

    /** Envoie un modele approuve : seule facon d'ecrire a un client apres 24 h de silence. */
    public function template(Request $request, Bot $bot, Conversation $conversation, TemplateManager $manager): RedirectResponse
    {
        abort_unless($conversation->channel === 'whatsapp', 422);
        abort_unless($request->user()->currentWorkspace()->hasFeature('templates'), 403, 'Les modèles WhatsApp sont inclus dans les offres Pro et Business.');

        $channel = $this->whatsappChannel($bot);
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:200'],
        ]);

        $template = WhatsAppTemplate::where('channel_id', $channel?->id)->findOrFail($data['template_id']);

        try {
            $manager->sendTo($conversation, $template, array_values($data['variables'] ?? []), $request->user()->name);
        } catch (GatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Modèle envoyé.');
    }

    private function whatsappChannel(Bot $bot): ?Channel
    {
        return Channel::where('bot_id', $bot->id)
            ->whereIn('type', [Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])
            ->where('status', Channel::ACTIVE)
            ->first();
    }

    public function reply(Request $request, Bot $bot, Conversation $conversation, GatewayFactory $gateways, InboundHandler $handler): RedirectResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:2000']]);

        if (! $conversation->isWithinServiceWindow()) {
            return back()->with('error', "La fenêtre de 24 h de WhatsApp est fermée : le client n'a pas écrit depuis plus d'un jour. Envoyez un modèle de message approuvé (liste ci-dessous) pour reprendre la conversation.");
        }

        $message = $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::AGENT,
            'content' => $data['content'],
            'meta' => ['agent' => $request->user()->name],
        ]);
        $conversation->forceFill(['status' => Conversation::HUMAN, 'last_message_at' => now()])->save();

        if ($conversation->channel === 'whatsapp') {
            $channel = Channel::where('bot_id', $bot->id)
                ->whereIn('type', [Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])
                ->where('status', Channel::ACTIVE)
                ->first();

            if (! $channel) {
                return back()->with('error', "Aucun canal WhatsApp actif n'est configuré pour cet assistant.");
            }

            $handler->deliver($gateways->for($channel), $conversation, $message);

            if (isset($message->fresh()->meta['delivery_error'])) {
                return back()->with('error', "Le message n'a pas pu être livré sur WhatsApp : ".$message->fresh()->meta['delivery_error']);
            }
        }

        return back()->with('status', 'Message envoyé.');
    }

    public function status(Request $request, Bot $bot, Conversation $conversation): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['take', 'release', 'close'])]])['action'];

        $conversation->update(['status' => match ($action) {
            'take' => Conversation::HUMAN,
            'release' => Conversation::BOT,
            'close' => Conversation::CLOSED,
        }]);

        return back()->with('status', match ($action) {
            'take' => 'Vous avez la main : le bot ne répond plus dans cette conversation.',
            'release' => "L'assistant reprend la conversation.",
            'close' => 'Conversation clôturée.',
        });
    }
}
