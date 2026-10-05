<?php

namespace App\Services\Users;

use App\Mail\Notice;
use App\Models\AppNotification;
use App\Models\CustomerContact;
use App\Models\User;
use App\Notify\Notifier;
use App\Services\PlatformSettings;
use App\Support\ContactTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Les gestes de contact de l'équipe avec une personne inscrite (un envoi seul, ou chaque envoi d'un envoi groupé) : le message
 * lui-même, la trace dans `customer_contacts`, et ce que le contact change dans le suivi (dernier contact, relance, statut).
 */
class ContactSender
{
    public function __construct(private readonly PlatformSettings $settings, private readonly Notifier $notifier) {}

    /** L'e-mail aux couleurs de la marque, avec « Bonjour Prénom, » ; les réponses vont à l'adresse de contact public. */
    public function email(User $customer, User $staff, string $subject, string $body, ?string $template): void
    {
        $brand = $this->settings->brand();
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: [])));
        $action = ContactTemplates::exists((string) $template) ? ContactTemplates::raw((string) $template)['action'] : null;

        $notice = new Notice(
            subjectLine: $subject,
            heading: $subject,
            paragraphs: $paragraphs,
            actionLabel: ContactTemplates::actionLabel($action),
            actionUrl: $action ? route($action) : null,
            greetingName: $customer->name,
            reason: 'Message de l\'équipe '.$brand['name'].' : répondez simplement à cet e-mail pour nous écrire.',
            settings: false,
        );
        $notice->replyTo($brand['email'] ?: $staff->email, $brand['name']);

        Mail::to($customer->email)->send($notice);
    }

    /** La notification dans la cloche de la personne, et sur ses appareils s'ils sont activés. */
    public function push(User $customer, string $title, string $body): ?AppNotification
    {
        return $this->notifier->toUser($customer, 'system', Str::limit($title !== '' ? $title : 'Un message de l\'équipe', 65, ''), Str::limit($body, 178, ''), route('dashboard', [], false), ['workspace_id' => $customer->workspace_id, 'sync' => true, 'urgent' => true]);
    }

    /** Consigne le contact. @return CustomerContact */
    public function record(User $customer, User $staff, string $channel, ?string $subject, ?string $body, ?string $outcome = null, ?string $template = null): CustomerContact
    {
        return CustomerContact::create([
            'user_id' => $customer->id, 'staff_id' => $staff->id, 'channel' => $channel,
            'subject' => filled($subject) ? $subject : null, 'body' => filled($body) ? $body : null,
            'outcome' => $outcome, 'template' => ContactTemplates::exists((string) $template) ? $template : null,
        ]);
    }

    /** Après un contact : date du dernier contact, relance prévue ou faite, statut de la relation. @param array<string,mixed> $data follow_up, status, outcome */
    public function afterContact(User $customer, string $channel, array $data = []): void
    {
        $changes = [];

        if ($channel !== 'note') {
            $changes['crm_last_contacted_at'] = now();
        }

        if (filled($data['follow_up'] ?? null)) {
            $changes['crm_next_follow_up_at'] = Carbon::parse($data['follow_up'])->setTime(9, 0);
        } elseif ($channel !== 'note' && $customer->crm_next_follow_up_at && $customer->crm_next_follow_up_at->isPast()) {
            $changes['crm_next_follow_up_at'] = null; // la relance prévue vient d'être faite
        }

        $status = $data['status'] ?? match ($data['outcome'] ?? null) {
            'interested' => 'interesse',
            'converted' => 'client',
            'not_interested' => 'perdu',
            'replied' => in_array($customer->crm_status, [null, 'contacte'], true) ? 'en_discussion' : null,
            default => $channel !== 'note' && $customer->crm_status === null ? 'contacte' : null,
        };
        if ($status) {
            $changes['crm_status'] = $status === 'nouveau' ? null : $status;
        }

        if ($changes) {
            $customer->forceFill($changes)->save();
        }
    }

    /** Ce qui personnalise un modèle pour cette personne : le nom de son premier assistant et la fin de son essai. @return array{assistant:?string, ends_at:?Carbon} */
    public function context(User $customer): array
    {
        return [
            'assistant' => DB::table('bots')->where('workspace_id', $customer->workspace_id)->orderBy('id')->value('name'),
            'ends_at' => $customer->workspace?->plan_ends_at,
        ];
    }

    /** Les e-mails de l'équipe partis aujourd'hui (jour de la plateforme), envois groupés compris. */
    public function emailsSentToday(): int
    {
        $start = now(\App\Services\Analytics\Tracker::timezone())->startOfDay()->setTimezone(config('app.timezone'));

        return CustomerContact::where('channel', 'email')->where('created_at', '>=', $start)->count();
    }

    public function dailyEmailCap(): int
    {
        return max(0, (int) config('people.daily_email_cap'));
    }
}
