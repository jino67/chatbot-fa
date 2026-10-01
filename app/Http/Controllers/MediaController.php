<?php

namespace App\Http\Controllers;

use App\Speech\VoiceMedia;
use Symfony\Component\HttpFoundation\Response;

/** Sert un message vocal de reponse a Twilio (adresse signee, expiree apres deux heures). */
class MediaController extends Controller
{
    public function voice(string $name): Response
    {
        abort_unless(VoiceMedia::exists($name), 404);

        return response(VoiceMedia::contents($name), 200, [
            'Content-Type' => str_ends_with($name, '.mp3') ? 'audio/mpeg' : (str_ends_with($name, '.wav') ? 'audio/wav' : 'audio/ogg'),
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
