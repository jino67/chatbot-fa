<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Support\QrCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Partage du lien de discussion d'un assistant : QR code (SVG) et affiche à imprimer. Le QR pointe vers la page publique
 * de discussion ou, si l'offre et le numéro le permettent, directement vers WhatsApp.
 */
class ShareController extends Controller
{
    public function qr(Request $request, Bot $bot): Response
    {
        [$target] = $this->target($request, $bot);
        $svg = QrCode::svg($target);

        $headers = ['Content-Type' => 'image/svg+xml; charset=UTF-8', 'Cache-Control' => 'private, max-age=300'];
        if ($request->boolean('telecharger')) {
            $headers['Content-Disposition'] = 'attachment; filename="qr-'.Str::slug($bot->company() ?: 'assistant').'.svg"';
        }

        return response($svg, 200, $headers);
    }

    /** L'affiche A4 : le nom de l'entreprise, un grand QR code et la consigne. Elle s'imprime ou s'enregistre en PDF depuis le navigateur. */
    public function poster(Request $request, Bot $bot)
    {
        [$target, $whatsapp] = $this->target($request, $bot);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $bot->theme('color')) ? $bot->theme('color') : '#2340D9';

        return view('share.poster', [
            'bot' => $bot,
            'company' => $bot->company(),
            'svg' => QrCode::svg($target, '#0b1240', '#ffffff', 2),
            'whatsapp' => $whatsapp,
            'link' => $whatsapp ? 'wa.me/'.$bot->whatsappNumber() : preg_replace('#^https?://#', '', $bot->chatUrl()),
            'color' => $color,
            'branding' => ! ($bot->workspace?->hasFeature('remove_branding') ?? false),
        ]);
    }

    /** @return array{0:string,1:bool} l'adresse encodée, et si c'est celle de WhatsApp */
    private function target(Request $request, Bot $bot): array
    {
        $number = $request->query('cible') === 'whatsapp' ? $bot->whatsappNumber() : null;

        return $number
            ? ['https://wa.me/'.$number.'?text='.rawurlencode('Bonjour, je vous écris depuis votre affiche.'), true]
            : [$bot->chatUrl(), false];
    }
}
