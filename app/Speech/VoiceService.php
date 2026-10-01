<?php

namespace App\Speech;

use App\Models\Bot;
use App\Models\Workspace;
use App\Services\UsageMeter;
use App\Services\UsageService;
use App\Support\Languages;
use Illuminate\Support\Facades\Log;

/**
 * Regles de la voix, communes a WhatsApp et au widget : offre (option « voix » et volume mensuel), reglages de
 * l'assistant (ecouter, repondre en audio), limites de duree, comptage de la consommation. Aucun echec de la voix
 * ne doit faire perdre une reponse ecrite : `speak` ne leve jamais d'exception.
 */
class VoiceService
{
    private ?SpeechClient $client = null;

    public function __construct(
        private readonly SpeechFactory $factory,
        private readonly UsageService $usage,
        private readonly UsageMeter $meter,
    ) {}

    public function client(): SpeechClient
    {
        return $this->client ??= $this->factory->make();
    }

    /** Pour les tests : moteur impose. */
    public function useClient(SpeechClient $client): void
    {
        $this->client = $client;
    }

    /** L'offre du client comprend-elle la voix (option activee et volume mensuel non nul) ? */
    public function planAllows(Workspace $workspace): bool
    {
        return $workspace->hasFeature('voice') && $this->usage->voiceAllowance($workspace) > 0;
    }

    /** Ce que le widget et les canaux peuvent proposer a cet assistant, sans compter le quota (verifie a chaque usage). */
    public function capabilities(Bot $bot): array
    {
        $workspace = $bot->workspace ?? Workspace::withoutGlobalScopes()->find($bot->workspace_id);
        $plan = $workspace && $this->planAllows($workspace);

        return [
            'listen' => $plan && $bot->voice_in && $this->canListen($bot),
            'speak' => $plan && $bot->voice_out !== 'never' && $this->client()->canSpeak() && $this->speaksLanguage($bot),
        ];
    }

    /** Langue pour orienter la transcription : la seule langue de l'assistant, ou sa langue principale si elle est locale. */
    public function listeningLanguage(Bot $bot): ?string
    {
        $languages = $bot->spokenLanguages();
        $primary = $languages[0];

        if (count($languages) === 1 || (Languages::all()[$primary]['stt'] ?? null) === 'local') {
            return $primary;
        }

        return null;
    }

    /** Au moins une des langues de l'assistant peut etre ecoutee. */
    public function canListen(Bot $bot): bool
    {
        $client = $this->client();

        foreach ($bot->spokenLanguages() as $code) {
            $supported = $client instanceof SpeechRouter ? $client->supportsListening($code) : $client->canTranscribe($code);
            if ($supported) {
                return true;
            }
        }

        return false;
    }

    /** La voix de synthese ne prononce bien que le francais, l'anglais et l'arabe. */
    public function speaksLanguage(Bot $bot): bool
    {
        $language = $bot->spokenLanguages()[0];

        return (bool) (Languages::all()[$language]['tts'] ?? false);
    }

    /**
     * Ecoute un message vocal : verifie l'offre et le quota, transcrit, compte la consommation.
     *
     * @throws VoiceRefused
     */
    public function listen(Bot $bot, string $audio, string $mime): Transcription
    {
        $workspace = $bot->workspace ?? Workspace::withoutGlobalScopes()->findOrFail($bot->workspace_id);

        if (! $this->planAllows($workspace) || ! $bot->voice_in) {
            throw new VoiceRefused('disabled');
        }

        if (! $this->usage->canUseVoice($workspace)) {
            throw new VoiceRefused('quota');
        }

        if (strlen($audio) > (int) config('platform.speech.max_bytes') || AudioInfo::seconds($audio) > (int) config('platform.speech.max_seconds')) {
            throw new VoiceRefused('too_long');
        }

        if (! $this->canListen($bot)) {
            throw new VoiceRefused('engine');
        }

        try {
            $transcript = $this->client()->transcribe($audio, $mime, $this->listeningLanguage($bot));
        } catch (SpeechException $e) {
            Log::warning('Transcription en échec', ['bot' => $bot->id, 'kind' => $e->kind, 'message' => $e->getMessage()]);

            throw new VoiceRefused('unclear', $e->getMessage());
        }

        $this->meter->voice($workspace->id, $bot->id, 'in', $transcript->seconds, explode(':', $transcript->engine)[0]);

        return $transcript;
    }

    /** L'assistant doit-il repondre en audio, compte tenu de son reglage et du message recu ? */
    public function wantsAudioReply(Bot $bot, bool $inboundWasVoice): bool
    {
        return match ($bot->voice_out) {
            'always' => true,
            'mirror' => $inboundWasVoice,
            default => false,
        };
    }

    /**
     * Dit une reponse a voix haute. Retourne null (sans erreur) si l'offre, le volume, la langue ou le moteur ne le
     * permettent pas : le texte de la reponse part de toute facon.
     */
    public function speak(Bot $bot, string $text): ?SpeechAudio
    {
        try {
            $workspace = $bot->workspace ?? Workspace::withoutGlobalScopes()->find($bot->workspace_id);
            if (! $workspace || ! $this->planAllows($workspace) || ! $this->usage->canUseVoice($workspace)
                || ! $this->client()->canSpeak() || ! $this->speaksLanguage($bot)) {
                return null;
            }

            $spoken = $this->excerpt(SpeechText::forSpeech($text), $bot->spokenLanguages()[0]);
            if ($spoken === '') {
                return null;
            }

            $audio = $this->client()->speak($spoken, $bot->voice_style ?: 'feminine', $bot->spokenLanguages()[0]);
            $this->meter->voice($workspace->id, $bot->id, 'out', $audio->seconds, explode(':', $audio->engine)[0]);

            return $audio;
        } catch (\Throwable $e) {
            Log::warning('Réponse audio en échec', ['bot' => $bot->id, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Une reponse trop longue est dite jusqu'a une fin de phrase, puis renvoie vers le texte. */
    private function excerpt(string $text, string $language): string
    {
        $max = (int) config('platform.speech.tts_max_chars');
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '! ') ?: 0, mb_strrpos($cut, '? ') ?: 0);
        $cut = $end > $max * 0.4 ? mb_substr($cut, 0, $end + 1) : rtrim($cut, " ,;:").'…';

        return $cut.' '.VoiceMessages::get('more_in_text', $language);
    }
}
