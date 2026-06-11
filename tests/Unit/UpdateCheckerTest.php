<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Tests\Unit;

use ContentPulse\WordPress\Support\UpdateChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UpdateCheckerTest extends TestCase
{
    #[Test]
    public function it_resolves_version_from_release_tag(): void
    {
        $checker = new UpdateChecker;

        $this->assertSame('1.2.0', $checker->resolveVersion(['tag_name' => 'v1.2.0']));
        $this->assertSame('1.2.0', $checker->resolveVersion(['tag_name' => '1.2.0']));
        $this->assertSame('', $checker->resolveVersion([]));
        $this->assertSame('', $checker->resolveVersion(['tag_name' => 123]));
    }

    #[Test]
    public function it_prefers_built_release_asset_over_zipball(): void
    {
        $checker = new UpdateChecker;

        $url = $checker->resolvePackageUrl([
            'zipball_url' => 'https://api.github.com/repos/contentpulseio/contentpulse-wp/zipball/v1.2.0',
            'assets' => [
                ['name' => 'other.zip', 'browser_download_url' => 'https://example.com/other.zip'],
                ['name' => 'contentpulse-wp.zip', 'browser_download_url' => 'https://example.com/contentpulse-wp.zip'],
            ],
        ]);

        $this->assertSame('https://example.com/contentpulse-wp.zip', $url);
    }

    #[Test]
    public function it_falls_back_to_zipball_when_no_asset_matches(): void
    {
        $checker = new UpdateChecker;

        $url = $checker->resolvePackageUrl([
            'zipball_url' => 'https://api.github.com/repos/contentpulseio/contentpulse-wp/zipball/v1.2.0',
            'assets' => [],
        ]);

        $this->assertSame('https://api.github.com/repos/contentpulseio/contentpulse-wp/zipball/v1.2.0', $url);
    }

    #[Test]
    public function it_returns_empty_package_url_when_release_is_malformed(): void
    {
        $checker = new UpdateChecker;

        $this->assertSame('', $checker->resolvePackageUrl([]));
        $this->assertSame('', $checker->resolvePackageUrl(['zipball_url' => 42, 'assets' => 'nope']));
    }

    #[Test]
    public function it_builds_update_payload_from_release(): void
    {
        $checker = new UpdateChecker;

        $payload = $checker->buildUpdatePayload([
            'tag_name' => 'v1.2.0',
            'assets' => [
                ['name' => 'contentpulse-wp.zip', 'browser_download_url' => 'https://example.com/contentpulse-wp.zip'],
            ],
        ], 'contentpulse-wp/contentpulse-wp.php');

        $this->assertSame([
            'id' => 'https://github.com/contentpulseio/contentpulse-wp',
            'slug' => 'contentpulse-wp',
            'plugin' => 'contentpulse-wp/contentpulse-wp.php',
            'version' => '1.2.0',
            'url' => 'https://github.com/contentpulseio/contentpulse-wp',
            'package' => 'https://example.com/contentpulse-wp.zip',
        ], $payload);
    }

    #[Test]
    public function it_returns_null_payload_when_version_or_package_missing(): void
    {
        $checker = new UpdateChecker;

        $this->assertNull($checker->buildUpdatePayload([], 'contentpulse-wp/contentpulse-wp.php'));
        $this->assertNull($checker->buildUpdatePayload(['tag_name' => 'v1.2.0'], 'contentpulse-wp/contentpulse-wp.php'));
        $this->assertNull($checker->buildUpdatePayload(['zipball_url' => 'https://example.com/z.zip'], 'contentpulse-wp/contentpulse-wp.php'));
    }
}
