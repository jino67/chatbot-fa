<?php

namespace Tests\Unit;

use App\Ingestion\Crawler\RobotsTxt;
use App\Ingestion\Crawler\SafeUrl;
use App\Ingestion\Crawler\UnsafeUrlException;
use App\Ingestion\Crawler\Url;
use PHPUnit\Framework\TestCase;

class CrawlerSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Aucune vraie requete DNS : chaque nom resolu vers une adresse publique, sauf mention contraire.
        SafeUrl::useResolver(fn (string $host) => match ($host) {
            'interne.exemple.com' => ['10.0.0.5'],
            'metadata.exemple.com' => ['169.254.169.254'],
            'inconnu.exemple.com' => [],
            default => ['93.184.216.34'],
        });
    }

    protected function tearDown(): void
    {
        SafeUrl::useResolver(null);
        parent::tearDown();
    }

    /** @dataProvider forbidden */
    public function test_ssrf_targets_are_refused(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);
        SafeUrl::assertPublic($url);
    }

    public static function forbidden(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/admin'],
            'localhost' => ['http://localhost/'],
            'reseau prive 10.x' => ['http://10.1.2.3/'],
            'reseau prive 192.168' => ['http://192.168.1.10/'],
            'metadonnees cloud' => ['http://169.254.169.254/latest/meta-data'],
            'ipv6 loopback' => ['http://[::1]/'],
            'nom pointant vers une IP privee' => ['https://interne.exemple.com/'],
            'nom pointant vers les metadonnees' => ['https://metadata.exemple.com/'],
            'domaine inexistant' => ['https://inconnu.exemple.com/'],
            'schema file' => ['file:///etc/passwd'],
            'schema ftp' => ['ftp://exemple.com/'],
            'identifiants dans l\'url' => ['https://user:pass@exemple.com/'],
            'port non standard' => ['http://exemple.com:6379/'],
            'suffixe .internal' => ['http://db.internal/'],
        ];
    }

    public function test_public_urls_are_accepted_and_resolved(): void
    {
        $target = SafeUrl::assertPublic('https://www.exemple.com/page');

        $this->assertSame('www.exemple.com', $target['host']);
        $this->assertSame(443, $target['port']);
        $this->assertSame(['93.184.216.34'], $target['ips']);
    }

    public function test_url_normalization_strips_fragments_tracking_and_default_ports(): void
    {
        $this->assertSame(
            'https://exemple.com/a?x=1',
            Url::normalize('HTTPS://Exemple.com:443/a/?utm_source=fb&x=1#haut')
        );
    }

    public function test_relative_url_resolution(): void
    {
        $this->assertSame('https://exemple.com/contact', Url::resolve('https://exemple.com/a/b', '/contact'));
        $this->assertSame('https://exemple.com/a/tarifs', Url::resolve('https://exemple.com/a/b', 'tarifs'));
        $this->assertSame('https://exemple.com/tarifs', Url::resolve('https://exemple.com/a/b', '../tarifs'));
        $this->assertSame('https://autre.com/x', Url::resolve('https://exemple.com', 'https://autre.com/x'));
    }

    public function test_same_site_ignores_www(): void
    {
        $this->assertTrue(Url::sameSite('https://www.exemple.com', 'https://exemple.com/a'));
        $this->assertFalse(Url::sameSite('https://exemple.com', 'https://exemple.org'));
    }

    public function test_robots_txt_rules(): void
    {
        $robots = RobotsTxt::parse(<<<'TXT'
User-agent: *
Disallow: /prive/
Disallow: /*.pdf$
Allow: /prive/public/
Sitemap: https://exemple.com/sitemap.xml
TXT, 'koumabot');

        $this->assertTrue($robots->allows('https://exemple.com/produits'));
        $this->assertFalse($robots->allows('https://exemple.com/prive/secret'));
        $this->assertTrue($robots->allows('https://exemple.com/prive/public/page'), 'le motif Allow le plus long gagne');
        $this->assertFalse($robots->allows('https://exemple.com/docs/tarifs.pdf'));
        $this->assertSame(['https://exemple.com/sitemap.xml'], $robots->sitemaps());
    }

    public function test_robots_txt_specific_agent_group_wins_over_wildcard(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: KoumaBot\nDisallow: /admin\n", 'koumabot');

        $this->assertTrue($robots->allows('https://exemple.com/produits'));
        $this->assertFalse($robots->allows('https://exemple.com/admin'));
    }

    public function test_robots_txt_full_block_for_everyone(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n", 'koumabot');

        $this->assertFalse($robots->allows('https://exemple.com/'));
    }
}
