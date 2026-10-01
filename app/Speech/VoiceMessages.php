<?php

namespace App\Speech;

/** Phrases dites au client quand la voix ne peut pas servir, dans la langue principale de l'assistant (français par défaut). */
final class VoiceMessages
{
    private const TEXTS = [
        // L'offre ou l'assistant n'ecoute pas les vocaux, ou le quota du mois est atteint
        'disabled' => [
            'fr' => "Je ne peux pas écouter les messages vocaux pour le moment. Pouvez-vous m'écrire votre question ?",
            'en' => "I can't listen to voice messages right now. Could you write your question instead?",
            'ar' => 'لا أستطيع الاستماع إلى الرسائل الصوتية حاليًا. هل يمكنك كتابة سؤالك؟',
        ],
        // Rien d'audible, langue non reconnue, panne
        'unclear' => [
            'fr' => "Je n'ai pas bien compris votre message vocal. Pouvez-vous le répéter, ou m'écrire votre question ?",
            'en' => "I couldn't quite make out your voice message. Could you repeat it, or write your question?",
            'ar' => 'لم أفهم رسالتك الصوتية جيدًا. هل يمكنك إعادتها أو كتابة سؤالك؟',
        ],
        'too_long' => [
            'fr' => 'Votre message vocal est trop long. Pouvez-vous le raccourcir (2 minutes au plus), ou m\'écrire votre question ?',
            'en' => 'Your voice message is too long. Could you shorten it (2 minutes at most), or write your question?',
            'ar' => 'رسالتك الصوتية طويلة جدًا. هل يمكنك اختصارها (دقيقتان على الأكثر) أو كتابة سؤالك؟',
        ],
        // Ajoute a la fin d'une reponse lue quand elle est trop longue pour etre dite en entier
        'more_in_text' => [
            'fr' => 'Je vous envoie le détail par écrit.',
            'en' => "I'm sending you the details in writing.",
            'ar' => 'أرسل لك التفاصيل كتابةً.',
        ],
    ];

    public static function get(string $key, string $language = 'fr'): string
    {
        return self::TEXTS[$key][$language] ?? self::TEXTS[$key]['fr'];
    }

    /** Message a envoyer pour un refus de la voix (raison renvoyee par VoiceService). */
    public static function forRefusal(string $reason, string $language = 'fr'): string
    {
        return self::get(match ($reason) {
            'too_long' => 'too_long',
            'unclear', 'empty' => 'unclear',
            default => 'disabled',
        }, $language);
    }
}
