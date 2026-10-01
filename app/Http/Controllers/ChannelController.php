<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\ChannelRequest;
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

    private function snippet(Bot $bot): string
    {
        $src = url('/widget/widget.js');

        return '<script src="'.$src.'" data-bot="'.$bot->public_key.'" async></script>';
    }
}
