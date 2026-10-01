<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Parametres modifiables depuis le tableau de bord (marque, contact, embeddings, Facebook...).
 * Les secrets sont chiffres avec APP_KEY. Chaque cle a une valeur par defaut dans config/platform.php.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform.settings.v1';

    /** @var array<string,mixed>|null */
    private ?array $loaded = null;

    /** Memo tres court : evite de relire le cache a chaque appel, sans figer un worker de file d'attente. */
    private float $loadedAt = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== ''
            ? $all[$key]
            : ($default ?? config('platform.settings.'.$key));
    }

    public function set(string $key, mixed $value, bool $secret = false): void
    {
        $stored = $value === null || $value === '' ? null : ($secret ? Crypt::encryptString(json_encode($value)) : json_encode($value));

        PlatformSetting::updateOrCreate(['key' => $key], ['value' => $stored, 'is_secret' => $secret]);

        $this->forget();
    }

    /** @param array<string,mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function has(string $key): bool
    {
        $value = $this->all()[$key] ?? null;

        return $value !== null && $value !== '';
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->loaded !== null && (microtime(true) - $this->loadedAt) < 3) {
            return $this->loaded;
        }

        if (! Schema::hasTable('platform_settings')) {
            return $this->loaded = [];
        }

        $this->loadedAt = microtime(true);

        return $this->loaded = Cache::rememberForever(self::CACHE_KEY, function () {
            $out = [];
            foreach (PlatformSetting::all() as $row) {
                if ($row->value === null) {
                    continue;
                }
                $out[$row->key] = json_decode($row->is_secret ? Crypt::decryptString($row->value) : $row->value, true);
            }

            return $out;
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    /** Marque affichee partout : nom, accroche, contact d'assistance. */
    public function brand(): array
    {
        return [
            'name' => (string) $this->get('brand.name', config('brand.name')),
            'tagline' => (string) $this->get('brand.tagline', config('brand.tagline')),
            'url' => (string) $this->get('brand.url', config('app.url')),
            'whatsapp' => preg_replace('/\D/', '', (string) $this->get('brand.whatsapp', config('brand.whatsapp', ''))),
            'email' => (string) $this->get('brand.email', config('brand.email', '')),
        ];
    }
}
