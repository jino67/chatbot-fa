<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\PushSubscription;
use App\Push\PushEndpoint;
use App\Push\PushResult;
use App\Push\Vapid;
use App\Push\WebPushCrypto as Crypto;
use App\Push\WebPushGateway;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Le chiffrement des notifications (RFC 8291), la signature VAPID (RFC 8292) et l'envoi : vérifiés sans aucun réseau. */
class PushCryptoTest extends TestCase
{
    use RefreshDatabase;

    /** L'exemple publié dans l'annexe A de la RFC 8291 : si notre chiffrement donne exactement ce résultat, il est conforme. */
    public function test_encryption_matches_the_rfc_8291_example(): void
    {
        $ephemeral = Crypto::privateKeyFromRaw(
            Crypto::b64urlDecode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            Crypto::b64urlDecode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
        );

        $body = Crypto::encrypt(
            'When I grow up, I want to be a watermelon',
            Crypto::b64urlDecode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            Crypto::b64urlDecode('BTBZMqHH6r4Tts7J_aSIgg'),
            $ephemeral,
            Crypto::b64urlDecode('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            Crypto::b64urlEncode($body),
        );
    }

    /** Ce que fait le navigateur à la réception : on déchiffre avec la clé privée de l'abonnement et on retrouve le message. */
    public function test_the_receiving_side_can_decrypt_what_we_send(): void
    {
        [$private, $public, $auth] = $this->subscriber();
        $message = json_encode(['title' => 'Nouvelle commande', 'body' => 'Awa : 2 boubous brodés, livraison à Bobo', 'badge' => 3], JSON_UNESCAPED_UNICODE);

        $body = Crypto::encrypt($message, $public, $auth);

        $this->assertSame($message, $this->decrypt($body, $private, $public, $auth));
    }

    public function test_each_message_uses_a_fresh_salt_and_key(): void
    {
        [, $public, $auth] = $this->subscriber();

        $a = Crypto::encrypt('bonjour', $public, $auth);
        $b = Crypto::encrypt('bonjour', $public, $auth);

        $this->assertNotSame($a, $b);
        $this->assertNotSame(substr($a, 0, 16), substr($b, 0, 16), 'sel différent');
        $this->assertNotSame(substr($a, 21, 65), substr($b, 21, 65), 'clé éphémère différente');
    }

    public function test_a_message_too_long_or_a_bad_secret_is_refused(): void
    {
        [, $public, $auth] = $this->subscriber();

        $this->expectException(\RuntimeException::class);
        Crypto::encrypt(str_repeat('x', Crypto::MAX_PAYLOAD + 1), $public, $auth);
    }

    public function test_a_bad_auth_secret_is_refused(): void
    {
        [, $public] = $this->subscriber();

        $this->expectException(\RuntimeException::class);
        Crypto::encrypt('bonjour', $public, 'trop-court');
    }

    public function test_the_vapid_token_is_a_valid_es256_jwt_for_the_push_service(): void
    {
        $pair = Crypto::generateKeyPair();
        $jwt = Crypto::vapidJwt($pair['pem'], 'https://fcm.googleapis.com', 'mailto:equipe@kouma.example', 1_900_000_000);

        [$head, $claims, $signature] = explode('.', $jwt);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(Crypto::b64urlDecode($head), true));
        $this->assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_900_000_000, 'sub' => 'mailto:equipe@kouma.example'], json_decode(Crypto::b64urlDecode($claims), true));
        $this->assertSame(64, strlen(Crypto::b64urlDecode($signature)), 'signature brute R || S');

        // La signature se vérifie avec la clé publique : on la remet au format DER que comprend OpenSSL.
        $raw = Crypto::b64urlDecode($signature);
        $der = $this->rawToDer($raw);
        $public = Crypto::publicKeyFromPoint($pair['public']);
        $this->assertSame(1, openssl_verify($head.'.'.$claims, $der, $public, OPENSSL_ALGO_SHA256));
    }

    public function test_the_platform_creates_its_vapid_keys_once_and_keeps_the_private_one_encrypted(): void
    {
        $vapid = app(Vapid::class);
        $this->assertFalse($vapid->exists());

        $public = $vapid->publicKey();
        $this->assertSame(65, strlen(Crypto::b64urlDecode($public)));
        $this->assertSame($public, app(Vapid::class)->publicKey(), 'la clé ne change pas d\'une lecture à l\'autre');

        $row = PlatformSetting::where('key', 'push.vapid_private')->firstOrFail();
        $this->assertTrue((bool) $row->is_secret);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $row->value, 'la clé privée n\'est jamais écrite en clair');
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(PlatformSetting::where('key', 'push.vapid_public')->value('value')));
    }

    public function test_the_authorization_header_names_the_push_service_as_audience(): void
    {
        $header = app(Vapid::class)->authorization('https://updates.push.services.mozilla.com/wpush/v2/xyz');

        $this->assertStringStartsWith('vapid t=', $header);
        $this->assertStringContainsString(', k='.app(Vapid::class)->publicKey(), $header);

        $jwt = explode(',', substr($header, 8))[0];
        $claims = json_decode(Crypto::b64urlDecode(explode('.', $jwt)[1]), true);
        $this->assertSame('https://updates.push.services.mozilla.com', $claims['aud']);
        $this->assertLessThanOrEqual(time() + 24 * 3600, $claims['exp'], 'un jeton VAPID ne dure pas plus de 24 h');
    }

    /* ---------- Adresses autorisées ---------- */

    public function test_only_real_push_services_over_https_are_allowed(): void
    {
        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'https://web.push.apple.com/QWxs', 'https://wns2-par02p.notify.windows.com/w/?token=x', 'https://android.googleapis.com/gcm/send/abc',
        ] as $ok) {
            $this->assertTrue(PushEndpoint::isAllowed($ok), $ok);
        }

        foreach ([
            'http://fcm.googleapis.com/fcm/send/abc', 'https://127.0.0.1/x', 'https://localhost/x', 'https://169.254.169.254/latest/meta-data',
            'https://fcm.googleapis.com.evil.example/x', 'https://evilfcm.googleapis.com.example/x', 'https://user:pass@fcm.googleapis.com/x',
            'https://fcm.googleapis.com:8443/x', 'ftp://fcm.googleapis.com/x', 'javascript:alert(1)', '', 'https://example.com/x',
            'https://'.str_repeat('a', 1100).'.googleapis.com/x',
        ] as $bad) {
            $this->assertFalse(PushEndpoint::isAllowed($bad), $bad);
        }
    }

    public function test_the_device_type_comes_from_the_browser_agent(): void
    {
        $this->assertSame('ios', PushEndpoint::platform('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'));
        $this->assertSame('android', PushEndpoint::platform('Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120 Mobile'));
        $this->assertSame('desktop', PushEndpoint::platform('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120'));
        $this->assertSame('other', PushEndpoint::platform(''));
    }

    /* ---------- Envoi ---------- */

    private function subscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): PushSubscription
    {
        [, $public, $auth] = $this->subscriber();

        return new PushSubscription([
            'user_id' => 1, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'p256dh' => Crypto::b64urlEncode($public), 'auth' => Crypto::b64urlEncode($auth),
        ]);
    }

    public function test_the_gateway_posts_an_encrypted_signed_message_with_the_right_headers(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $subscription = $this->subscription();

        $result = app(WebPushGateway::class)->send($subscription, ['title' => 'Test', 'body' => 'Bonjour', 'badge' => 2], 'high');

        $this->assertTrue($result->ok());
        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send/abc'
                && str_starts_with($request->header('Authorization')[0], 'vapid t=')
                && $request->header('Content-Encoding')[0] === 'aes128gcm'
                && $request->header('Urgency')[0] === 'high'
                && $request->header('TTL')[0] === '86400'
                && $request->hasHeader('Content-Type', 'application/octet-stream')
                && strlen($request->body()) > 100
                && ! str_contains($request->body(), 'Bonjour');
        });
    }

    public function test_the_gateway_translates_the_services_answers(): void
    {
        $gateway = app(WebPushGateway::class);

        $cases = [[201, PushResult::OK], [404, PushResult::GONE], [410, PushResult::GONE], [429, PushResult::RETRY], [503, PushResult::RETRY], [400, PushResult::ERROR], [403, PushResult::ERROR]];

        // Http::fake : le premier motif qui correspond gagne, donc une suite de réponses plutôt qu'un nouveau faux à chaque tour.
        $responses = Http::sequence();
        foreach ($cases as [$code]) {
            $responses->push('', $code);
        }
        Http::fake(['*' => $responses]);

        foreach ($cases as [$code, $expected]) {
            $this->assertSame($expected, $gateway->send($this->subscription(), ['title' => 'x'])->status, "code {$code}");
        }
    }

    public function test_the_gateway_never_contacts_an_address_that_is_not_a_push_service(): void
    {
        Http::fake();

        $result = app(WebPushGateway::class)->send($this->subscription('https://169.254.169.254/latest/meta-data'), ['title' => 'x']);

        $this->assertSame(PushResult::GONE, $result->status);
        Http::assertNothingSent();
    }

    /* ---------- Outils de test ---------- */

    /** @return array{0:\OpenSSLAsymmetricKey, 1:string, 2:string} clé privée, point public, secret d'authentification d'un abonné fictif */
    private function subscriber(): array
    {
        $pair = Crypto::generateKeyPair();

        return [openssl_pkey_get_private($pair['pem']), $pair['public'], random_bytes(16)];
    }

    /** Le déchiffrement fait par le navigateur (RFC 8291, 4) : sert à prouver que ce que nous envoyons est lisible. */
    private function decrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $uaPublic, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $asPublic = substr($body, 21, $idLength);
        $cipher = substr($body, 21 + $idLength);

        $secret = openssl_pkey_derive(Crypto::publicKeyFromPoint($asPublic), $uaPrivate, 32);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$uaPublic.$asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain, 'le message ne se déchiffre pas');
        $this->assertSame("\x02", substr($plain, -1), 'délimiteur du dernier bloc');

        return substr($plain, 0, -1);
    }

    private function rawToDer(string $raw): string
    {
        $encode = function (string $integer): string {
            $integer = ltrim($integer, "\0");
            if ($integer === '' || ord($integer[0]) > 127) {
                $integer = "\0".$integer;
            }

            return "\x02".chr(strlen($integer)).$integer;
        };

        $body = $encode(substr($raw, 0, 32)).$encode(substr($raw, 32));

        return "\x30".chr(strlen($body)).$body;
    }
}
