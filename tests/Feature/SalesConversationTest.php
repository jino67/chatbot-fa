<?php

namespace Tests\Feature;

use App\Chat\ChatService;
use App\Chat\PromptBuilder;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ScriptsTheAssistant;
use Tests\TestCase;

/**
 * Le parcours de vente de l'assistant : une commande ne s'arrête pas sur un « je ne peux pas vous aider », le numéro de
 * téléphone se demande sur le site web mais jamais sur WhatsApp, et le prompt dit la vérité sur ce que le canal permet.
 */
class SalesConversationTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;
    use ScriptsTheAssistant;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        [, , $this->bot] = $this->tenant('Poupécosmetic', 'pro');
        $this->teach($this->bot, 'Duo visage', "# Duo visage\nDuo visage : 5 000 XOF. Très efficace pour traiter l'acné du visage (non éclaircissant). Livraison à Ouagadougou et Bobo sous 48 h. Paiement à la livraison possible.");
    }

    private function conversation(string $channel = 'web', ?string $phone = null): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'channel' => $channel,
            'external_id' => $channel === 'whatsapp' ? '22670999999' : 'visiteur-vente-1', 'contact_phone' => $phone,
        ]);
    }

    /* ---------- Ce que le prompt demande ---------- */

    public function test_the_phone_is_never_asked_on_whatsapp_but_always_on_the_web(): void
    {
        $whatsapp = app(PromptBuilder::class)->system($this->bot, 'whatsapp');
        $web = app(PromptBuilder::class)->system($this->bot, 'web');

        $this->assertStringContainsString('ne le demande JAMAIS', $whatsapp);
        $this->assertStringNotContainsString('demande-lui un numéro de téléphone', $whatsapp);
        $this->assertStringContainsString('demande-lui un numéro de téléphone', $web);
        $this->assertStringNotContainsString('ne le demande JAMAIS', $web);
    }

    public function test_answers_given_during_an_order_are_never_treated_as_unanswerable_questions(): void
    {
        $system = app(PromptBuilder::class)->system($this->bot, 'web');

        $this->assertStringContainsString('ne sont pas des questions sur', $system);
        $this->assertStringContainsString('Ne réponds jamais « je ne peux pas vous aider » à ce moment-là', $system);
        $this->assertStringContainsString("quand le client te donne lui-même des informations (nom, adresse, paiement, numéro)", $system);
    }

    public function test_the_prompt_teaches_consultative_selling_and_forbids_invented_discounts(): void
    {
        $system = app(PromptBuilder::class)->system($this->bot, 'web');

        $this->assertStringContainsString('UNE seule question ciblée', $system);
        $this->assertStringContainsString("N'invente jamais une remise", $system);
        $this->assertStringContainsString('ne mélange jamais les prix', $system);
        $this->assertStringContainsString('ne redemande jamais une information donnée plus haut', $system);
    }

    public function test_the_platform_demo_assistant_does_not_sell_products(): void
    {
        $showcase = Bot::withoutGlobalScopes()->create(['workspace_id' => $this->bot->workspace_id, 'name' => 'Vitrine']);
        app(\App\Services\PlatformSettings::class)->set('marketing.landing_bot_key', $showcase->public_key);

        $this->assertStringNotContainsString('Prendre une commande, une réservation ou un devis', app(PromptBuilder::class)->system($showcase->fresh(), 'web'));
    }

    public function test_the_prompt_tells_the_truth_about_audio(): void
    {
        $with = app(PromptBuilder::class)->system($this->bot->fresh(), 'whatsapp');
        $this->assertStringContainsString('Ne dis jamais que tu ne peux pas envoyer d\'audio', $with);
        $this->assertStringContainsString('Tu comprends les messages vocaux', $with);

        $plan = Plan::bySlug('pro');
        $plan->update(['limits' => ['voice_per_month' => 0] + $plan->limits]);
        $without = app(PromptBuilder::class)->system($this->bot->fresh(), 'whatsapp');

        $this->assertStringContainsString('Tu ne peux pas envoyer de messages vocaux', $without);
        $this->assertStringNotContainsString('Ne dis jamais que tu ne peux pas', $without);
    }

    public function test_the_web_prompt_points_to_the_speaker_button_for_audio(): void
    {
        $this->assertStringContainsString('bouton haut-parleur', app(PromptBuilder::class)->system($this->bot->fresh(), 'web'));
    }

    /* ---------- Le marqueur de commande ---------- */

    public function test_the_lead_marker_reads_name_and_phone_and_keeps_the_old_format(): void
    {
        $prompts = app(PromptBuilder::class);

        $full = $prompts->parse("Merci Jures !\n[[LEAD: commande | Duo visage x1, 5 000 XOF, 1200 logements, espèces | Jures | +226 70 12 34 56]]");
        $this->assertSame(['kind' => 'order', 'summary' => 'Duo visage x1, 5 000 XOF, 1200 logements, espèces', 'name' => 'Jures', 'phone' => '+22670123456'], $full['lead']);
        $this->assertSame('Merci Jures !', $full['text']);

        $this->assertSame(['kind' => 'order', 'summary' => 'Duo visage', 'name' => null, 'phone' => null], $prompts->parse('[[LEAD: commande | Duo visage]]')['lead']);
        $this->assertSame('70123456', $prompts->parse('[[LEAD: commande | Duo visage | | 70 12 34 56]]')['lead']['phone']);
        $this->assertSame('Awa', $prompts->parse('[[LEAD: rendez-vous | Samedi 10 h | Awa]]')['lead']['name']);
        $this->assertSame('+22670123456', $prompts->parse('[[LEAD: devis | Cuisine | +22670123456]]')['lead']['phone'], 'un troisième champ qui ressemble à un numéro est le téléphone');
        $this->assertNull($prompts->parse('[[LEAD: commande | Duo visage | Jures | demain]]')['lead']['phone'], 'un faux numéro est ignoré');
        $this->assertNull($prompts->parse('[[LEAD: inconnu | Duo visage]]')['lead']);
    }

    public function test_a_web_order_keeps_the_name_and_phone_the_visitor_gave(): void
    {
        $this->scriptedAssistant("Parfait Jures, votre commande est enregistrée. L'équipe vous contacte sur WhatsApp.\n[[LEAD: commande | Duo visage x1, 5 000 XOF, livraison 1200 logements, paiement à la livraison | Jures | 70 12 34 56]]");
        $conversation = $this->conversation('web');

        app(ChatService::class)->handleUserMessage($conversation, 'Jures, 1200 logements, je paie à la livraison, mon numéro est le 70 12 34 56');

        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('order', $lead->kind);
        $this->assertSame('Jures', $lead->contact_name);
        $this->assertSame('70123456', $lead->contact_phone, 'le propriétaire peut rappeler le client');
        $this->assertSame('Jures', $conversation->fresh()->contact_name);
        $this->assertSame('70123456', $conversation->fresh()->contact_phone);
        $this->assertStringNotContainsString('[[', Message::withoutGlobalScopes()->where('role', 'assistant')->latest('id')->value('content'));
    }

    public function test_on_whatsapp_the_known_number_is_never_replaced_by_one_written_by_the_model(): void
    {
        $this->scriptedAssistant("Merci Jures !\n[[LEAD: commande | Duo visage x1 | Jures | 01 02 03 04]]");
        $conversation = $this->conversation('whatsapp', '+22670999999');

        app(ChatService::class)->handleUserMessage($conversation, 'Oui je confirme, Jures, quartier 1200 logements');

        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('+22670999999', $lead->contact_phone);
        $this->assertSame('+22670999999', $conversation->fresh()->contact_phone);
        $this->assertSame('Jures', $lead->contact_name);
    }

    public function test_a_corrected_order_updates_the_open_lead_and_its_contact(): void
    {
        $this->scriptedAssistant([
            "Commande enregistrée.\n[[LEAD: commande | Duo visage x1 | Jures]]",
            "C'est noté, deux pièces.\n[[LEAD: commande | Duo visage x2, 10 000 XOF | Jures | 70 12 34 56]]",
        ]);
        $conversation = $this->conversation('web');
        $chat = app(ChatService::class);

        $chat->handleUserMessage($conversation, 'Je prends le duo visage, je suis Jures, 1200 logements');
        $chat->handleUserMessage($conversation->fresh(), 'En fait deux, et mon numéro est le 70 12 34 56');

        $this->assertSame(1, Lead::withoutGlobalScopes()->count(), 'une seule demande ouverte');
        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertStringContainsString('x2', $lead->summary);
        $this->assertSame('70123456', $lead->contact_phone);
    }

    public function test_the_order_details_reach_the_model_as_an_answer_with_the_conversation_history(): void
    {
        $llm = $this->scriptedAssistant('Merci Jures !');
        $conversation = $this->conversation('web');
        $chat = app(ChatService::class);

        $chat->handleUserMessage($conversation, 'Je veux le duo visage');
        $chat->handleUserMessage($conversation->fresh(), 'Jures.. 1200 logements je vais régler en espèces à la livraison.');

        $history = array_column($llm->last->messages, 'content');
        $this->assertStringContainsString('Je veux le duo visage', implode("\n", array_filter($history, 'is_string')), "l'assistant garde le fil de la commande");
        $this->assertStringContainsString('Jures.. 1200 logements', $llm->lastUserTurn());
    }
}
