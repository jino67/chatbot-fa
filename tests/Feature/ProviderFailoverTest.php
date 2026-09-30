<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Ai\Llm\AnthropicLlm;
use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmRequest;
use App\Ai\Llm\LlmResponse;
use App\Ai\Llm\LlmRouter;
use App\Ai\Llm\OpenAiCompatibleLlm;
use App\Ai\Llm\ProviderRegistry;
use App\Ai\LlmException;
use App\Models\AiProvider;
use App\Models\PlatformSetting;
use App\Services\PlatformSettings;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Chaine de bascule : Claude Haiku, puis OpenAI, puis Llama. Les fournisseurs sont scriptes :
 * aucun appel reseau reel, chaque test decrit une panne (credit epuise, cle refusee, surcharge).
 */
class ProviderFailoverTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    /** @var array<string,\Closure> reponse scriptee par preset ; absent = succes */
    private array $script = [];

    /** @var list<string> fournisseurs effectivement appeles, dans l'ordre */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config(['platform.ai.offline' => false]);
        AiProvider::all()->each(fn (AiProvider $p) => $p->update(['api_key' => 'cle-de-test']));

        // Registre dont les clients sont scriptes plutot que reseau.
        $this->app->singleton(ProviderRegistry::class, function ($app) {
            return new class($app->make(PlatformSettings::class), $this) extends ProviderRegistry
            {
                public function __construct(PlatformSettings $settings, private readonly ProviderFailoverTest $test)
                {
                    parent::__construct($settings);
                }

                public function client(AiProvider $provider): LlmClient
                {
                    return $this->test->fakeClient($provider->preset);
                }
            };
        });
        $this->app->forgetInstance(LlmRouter::class);
    }

    /** Appele par le registre scripte ci-dessus. */
    public function fakeClient(string $preset): LlmClient
    {
        return new class($preset, $this->script, $this->calls) implements LlmClient
        {
            public function __construct(private readonly string $preset, private readonly array $script, private array &$calls) {}

            public function name(): string
            {
                return $this->preset;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls[] = $this->preset;
                if (isset($this->script[$this->preset])) {
                    ($this->script[$this->preset])();
                }

                return new LlmResponse(text: "reponse de {$this->preset}", inputTokens: 10, outputTokens: 5, model: 'test', provider: $this->preset);
            }

            public function transcribe(string $binary, string $mimeType, string $instruction): string
            {
                $this->calls[] = $this->preset;

                return "transcription {$this->preset}";
            }
        };
    }

    private function failWith(string $preset, string $kind, bool $retryable = false): void
    {
        $this->script[$preset] = fn () => throw new LlmException("panne {$preset}", retryable: $retryable, kind: $kind);
    }

    private function ask(): LlmResponse
    {
        return app(LlmRouter::class)->complete(new LlmRequest('Système.', [['role' => 'user', 'content' => 'Bonjour']], 100));
    }

    public function test_claude_answers_first_when_everything_works(): void
    {
        $response = $this->ask();

        $this->assertSame('reponse de anthropic', $response->text);
        $this->assertSame(['anthropic'], $this->calls);
        $this->assertSame('ok', AiProvider::where('preset', 'anthropic')->value('status'));
    }

    public function test_an_exhausted_claude_balance_switches_to_openai(): void
    {
        $this->failWith('anthropic', 'billing');

        $response = $this->ask();

        $this->assertSame('reponse de openai', $response->text);
        $this->assertSame(['anthropic', 'openai'], $this->calls);

        $claude = AiProvider::where('preset', 'anthropic')->first();
        $this->assertSame('down', $claude->status);
        $this->assertSame('billing', $claude->last_error_kind);
        $this->assertTrue($claude->isCoolingDown(), 'mis en pause pour ne pas etre reessaye a chaque message');
    }

    public function test_a_paused_provider_is_skipped_on_the_next_requests(): void
    {
        $this->failWith('anthropic', 'billing');
        $this->ask();
        $this->calls = [];

        $this->ask();

        $this->assertSame(['openai'], $this->calls, 'Claude est en pause : on ne perd pas un appel dessus');
    }

    public function test_when_openai_is_also_out_of_credit_llama_takes_over(): void
    {
        $this->failWith('anthropic', 'billing');
        $this->failWith('openai', 'billing');

        $response = $this->ask();

        $this->assertSame('reponse de openrouter', $response->text);
        $this->assertSame(['anthropic', 'openai', 'openrouter'], $this->calls);
        $this->assertSame('Llama 3.3 70B (OpenRouter)', $response->provider, 'le fournisseur qui a repondu est identifie');
    }

    public function test_temporary_failures_also_fail_over(): void
    {
        foreach (['rate_limit', 'overloaded', 'network', 'auth', 'unsupported'] as $kind) {
            AiProvider::query()->update(['disabled_until' => null, 'status' => 'unknown', 'last_error_kind' => null]);
            $this->calls = [];
            $this->failWith('anthropic', $kind, true);

            $this->assertSame('reponse de openai', $this->ask()->text, "bascule attendue pour : {$kind}");
        }
    }

    public function test_a_request_refused_because_of_our_own_input_does_not_burn_through_every_provider(): void
    {
        $this->failWith('anthropic', 'bad_request');

        $this->expectException(LlmException::class);
        try {
            $this->ask();
        } finally {
            $this->assertSame(['anthropic'], $this->calls);
            $this->assertNotTrue(AiProvider::where('preset', 'anthropic')->first()->isCoolingDown());
        }
    }

    public function test_when_every_provider_fails_the_last_error_is_raised(): void
    {
        foreach (['anthropic', 'openai', 'openrouter'] as $preset) {
            $this->failWith($preset, 'overloaded', true);
        }

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('panne openrouter');
        $this->ask();
    }

    public function test_a_billing_failure_alerts_the_team_once(): void
    {
        config(['platform.admin_email' => 'equipe@kouma.test']);
        $this->failWith('anthropic', 'billing');

        $this->ask();
        // Claude est remis en service puis retombe en panne : une seconde alerte dans l'heure serait du bruit.
        AiProvider::query()->update(['disabled_until' => null]);
        $this->ask();

        $mailer = app('mailer')->getSymfonyTransport();
        $alerts = collect($mailer->messages())->filter(fn ($m) => str_contains($m->getOriginalMessage()->getSubject(), 'Alerte IA'));
        $this->assertCount(1, $alerts, 'une seule alerte, pas une par message client');
    }

    public function test_a_manually_pinned_provider_answers_first_with_automatic_fallback(): void
    {
        $llama = AiProvider::where('preset', 'openrouter')->first();
        $settings = app(PlatformSettings::class);
        $settings->set('ai.mode', 'forced');
        $settings->set('ai.forced_provider_id', $llama->id);

        $this->assertSame('reponse de openrouter', $this->ask()->text);
        $this->assertSame(['openrouter'], $this->calls);

        $this->calls = [];
        $this->failWith('openrouter', 'overloaded', true);
        $this->assertSame('reponse de anthropic', $this->ask()->text, 'le fournisseur epingle tombe : la chaine reprend');
        $this->assertSame(['openrouter', 'anthropic'], $this->calls);
    }

    public function test_strict_pinning_never_falls_back(): void
    {
        $llama = AiProvider::where('preset', 'openrouter')->first();
        $settings = app(PlatformSettings::class);
        $settings->set('ai.mode', 'forced');
        $settings->set('ai.forced_provider_id', $llama->id);
        $settings->set('ai.strict_forced', true);
        $this->failWith('openrouter', 'overloaded', true);

        try {
            $this->ask();
            $this->fail('exception attendue');
        } catch (LlmException $e) {
            $this->assertSame(['openrouter'], $this->calls);
        }
    }

    public function test_only_vision_capable_providers_read_images(): void
    {
        $text = app(LlmRouter::class)->transcribe('binaire', 'image/png', 'Lis ce texte');

        $this->assertSame('transcription anthropic', $text);

        AiProvider::where('preset', 'anthropic')->update(['enabled' => false]);
        $this->assertSame('transcription openai', app(LlmRouter::class)->transcribe('binaire', 'image/png', 'Lis'), 'Llama ne lit pas les images : on saute directement a OpenAI');
    }

    public function test_a_disabled_or_keyless_provider_is_never_called(): void
    {
        AiProvider::where('preset', 'anthropic')->update(['api_key' => null]);
        AiProvider::where('preset', 'openai')->update(['enabled' => false]);

        $this->assertSame('reponse de openrouter', $this->ask()->text);
        $this->assertSame(['openrouter'], $this->calls);
    }

    public function test_without_any_key_the_platform_answers_offline(): void
    {
        AiProvider::query()->update(['api_key' => null]);

        $registry = app(ProviderRegistry::class);
        $this->assertTrue($registry->isOffline());
        $this->assertSame('offline', app(LlmRouter::class)->complete(new LlmRequest('Système.', [['role' => 'user', 'content' => 'Bonjour']], 50))->provider);
        $this->assertSame([], $this->calls);
    }

    public function test_a_provider_that_recovers_leaves_the_pause_once_reset(): void
    {
        $this->failWith('anthropic', 'billing');
        $this->ask();
        $provider = AiProvider::where('preset', 'anthropic')->first();
        $this->assertTrue($provider->isCoolingDown());

        $this->actingAs($this->admin())->post(route('admin.ai.reset', $provider))->assertSessionHas('status');

        $this->script = [];
        $this->assertFalse($provider->fresh()->isCoolingDown());
        $this->assertSame('reponse de anthropic', $this->ask()->text);
    }

    /* ---------- Classification des erreurs reelles des fournisseurs ---------- */

    public function test_openai_insufficient_quota_is_a_billing_failure_not_a_rate_limit(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'insufficient_quota', 'message' => 'You exceeded your current quota, please check your plan and billing details.']], 429)]);

        try {
            (new OpenAiCompatibleLlm(['preset' => 'openai', 'api_key' => 'x', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']))
                ->complete(new LlmRequest('S', [['role' => 'user', 'content' => 'B']], 50));
            $this->fail('exception attendue');
        } catch (LlmException $e) {
            $this->assertSame('billing', $e->kind);
            $this->assertTrue($e->shouldFailover());
        }
    }

    public function test_openai_compatible_error_statuses_are_classified(): void
    {
        $cases = [[401, [], 'auth'], [402, [], 'billing'], [429, ['error' => ['message' => 'Rate limit reached']], 'rate_limit'], [503, [], 'overloaded'], [404, [], 'unsupported'], [400, ['error' => ['message' => 'bad json']], 'bad_request']];

        // Une seule simulation, une reponse par appel : la premiere regle declaree l'emporterait sinon.
        $sequence = Http::sequence();
        foreach ($cases as [$status, $body]) {
            $sequence->push($body, $status);
        }
        Http::fake(['*' => $sequence]);

        foreach ($cases as [$status, $body, $expected]) {
            try {
                (new OpenAiCompatibleLlm(['preset' => 'openrouter', 'api_key' => 'x', 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'm']))
                    ->complete(new LlmRequest('S', [['role' => 'user', 'content' => 'B']], 50));
                $this->fail("exception attendue pour {$status}");
            } catch (LlmException $e) {
                $this->assertSame($expected, $e->kind, "HTTP {$status}");
            }
        }
    }

    public function test_openai_compatible_success_is_parsed_and_uses_the_right_token_parameter(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Bonjour !'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]])]);

        $response = (new OpenAiCompatibleLlm(['preset' => 'openai', 'api_key' => 'x', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']))
            ->complete(new LlmRequest('S', [['role' => 'user', 'content' => 'B']], 77));

        $this->assertSame('Bonjour !', $response->text);
        $this->assertSame(12, $response->inputTokens);
        Http::assertSent(fn ($request) => $request['max_completion_tokens'] === 77 && ! isset($request['max_tokens']));
    }

    public function test_a_connection_failure_is_a_network_kind(): void
    {
        // Connexion refusee pour de vrai (port ferme en local) : Http::failedConnection() fait planter PHP 8.2 sous Windows.
        try {
            (new OpenAiCompatibleLlm(['preset' => 'ollama', 'base_url' => 'http://127.0.0.1:9/v1', 'model' => 'm', 'timeout' => 3]))
                ->complete(new LlmRequest('S', [['role' => 'user', 'content' => 'B']], 50));
            $this->fail('exception attendue');
        } catch (LlmException $e) {
            $this->assertSame('network', $e->kind);
            $this->assertTrue($e->shouldFailover());
        }
    }

    public function test_anthropic_credit_exhaustion_is_detected_from_its_400_message(): void
    {
        $classify = function (int $status, string $message): string {
            $exception = APIStatusException::from(
                new PsrRequest('POST', 'https://api.anthropic.com/v1/messages'),
                new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $message]])),
            );
            $method = new \ReflectionMethod(AnthropicLlm::class, 'classifyStatus');

            return $method->invoke(new AnthropicLlm(['model' => 'claude-haiku-4-5']), $exception);
        };

        $this->assertSame('billing', $classify(400, 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.'));
        $this->assertSame('billing', $classify(402, 'Payment required'));
        $this->assertSame('unsupported', $classify(404, 'model: claude-old'));
        $this->assertSame('bad_request', $classify(400, 'messages: text content blocks must be non-empty'));
    }

    public function test_haiku_does_not_receive_the_effort_parameter_but_larger_models_do(): void
    {
        $method = new \ReflectionMethod(AnthropicLlm::class, 'supportsEffort');
        $llm = new AnthropicLlm(['model' => 'claude-haiku-4-5']);

        $this->assertFalse($method->invoke($llm, 'claude-haiku-4-5'));
        $this->assertFalse($method->invoke($llm, 'claude-sonnet-4-5'));
        $this->assertTrue($method->invoke($llm, 'claude-opus-5-5'));
        $this->assertTrue($method->invoke($llm, 'claude-sonnet-5-5'));
    }

    /* ---------- Tableau de bord ---------- */

    public function test_only_super_admins_reach_the_ai_dashboard_and_keys_are_never_displayed(): void
    {
        $this->actingAs($this->staff())->get(route('admin.ai.index'))->assertForbidden();

        AiProvider::where('preset', 'anthropic')->first()->update(['api_key' => 'sk-ant-secret-1234567890']);

        $this->actingAs($this->admin())->get(route('admin.ai.index'))
            ->assertOk()
            ->assertSee('Anthropic (Claude Haiku)')
            ->assertSee('7890')
            ->assertDontSee('sk-ant-secret-1234567890');
    }

    public function test_a_super_admin_can_pin_a_provider_from_the_dashboard_and_go_back_to_automatic(): void
    {
        $admin = $this->admin();
        $llama = AiProvider::where('preset', 'openrouter')->first();

        $this->actingAs($admin)->put(route('admin.ai.mode'), ['mode' => 'forced', 'forced_provider_id' => $llama->id])->assertSessionHas('status');
        $this->assertSame($llama->id, app(ProviderRegistry::class)->active()->id);

        $this->actingAs($admin)->put(route('admin.ai.mode'), ['mode' => 'forced'])->assertSessionHas('error');

        $this->actingAs($admin)->put(route('admin.ai.mode'), ['mode' => 'auto'])->assertSessionHas('status');
        $this->assertSame('anthropic', app(ProviderRegistry::class)->active()->preset);
    }

    public function test_provider_keys_are_stored_encrypted_and_a_blank_key_keeps_the_existing_one(): void
    {
        $admin = $this->admin();
        $provider = AiProvider::where('preset', 'openai')->first();
        $form = ['name' => 'OpenAI', 'model' => 'gpt-4o-mini', 'priority' => 2, 'enabled' => 1, 'api_key' => 'sk-nouvelle-cle-9999'];

        $this->actingAs($admin)->put(route('admin.ai.update', $provider), $form)->assertSessionHas('status');

        $raw = \DB::table('ai_providers')->where('id', $provider->id)->value('api_key');
        $this->assertStringNotContainsString('sk-nouvelle-cle-9999', $raw);
        $this->assertSame('sk-nouvelle-cle-9999', $provider->fresh()->api_key);

        $this->actingAs($admin)->put(route('admin.ai.update', $provider), ['api_key' => ''] + $form)->assertSessionHas('status');
        $this->assertSame('sk-nouvelle-cle-9999', $provider->fresh()->api_key);

        $this->actingAs($admin)->put(route('admin.ai.update', $provider), ['clear_key' => 1, 'api_key' => ''] + $form);
        $this->assertNull($provider->fresh()->api_key);
    }

    public function test_the_provider_test_button_reports_the_outcome(): void
    {
        $admin = $this->admin();
        $provider = AiProvider::where('preset', 'anthropic')->first();

        $this->actingAs($admin)->post(route('admin.ai.test', $provider))->assertSessionHas('status', fn ($m) => str_contains($m, 'Réponse reçue'));

        $this->failWith('anthropic', 'billing');
        $this->actingAs($admin)->post(route('admin.ai.test', $provider))->assertSessionHas('error', fn ($m) => str_contains($m, 'Crédit épuisé'));
    }

    public function test_a_super_admin_can_add_and_remove_a_provider(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.ai.store'), ['preset' => 'deepinfra', 'api_key' => 'di-key'])->assertSessionHas('status');
        $added = AiProvider::where('preset', 'deepinfra')->firstOrFail();
        $this->assertSame(4, $added->priority, 'ajoute en fin de chaine');
        $this->assertSame('https://api.deepinfra.com/v1/openai', $added->base_url);

        $this->actingAs($admin)->delete(route('admin.ai.destroy', $added))->assertSessionHas('status');
        $this->assertNull(AiProvider::where('preset', 'deepinfra')->first());
    }

    public function test_settings_rows_do_not_leak_secret_values_in_plain_text(): void
    {
        app(PlatformSettings::class)->set('embeddings.api_key', 'voyage-secret-abc', secret: true);

        $this->assertStringNotContainsString('voyage-secret-abc', (string) PlatformSetting::where('key', 'embeddings.api_key')->value('value'));
        $this->assertSame('voyage-secret-abc', app(PlatformSettings::class)->get('embeddings.api_key'));
    }
}
