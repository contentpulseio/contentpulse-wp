<?php

declare(strict_types=1);

namespace ContentPulseIO\WordPress\Tests\Unit;

use ContentPulse\WordPress\Support\ContentPulseEndpointResolver;
use ContentPulseIO\WordPress\Api\Controllers\IngestionController;
use ContentPulseIO\WordPress\Api\Controllers\PostsController;
use ContentPulseIO\WordPress\Api\Routes;
use ContentPulseIO\WordPress\Plugin;
use ContentPulseIO\WordPress\Support\MediaSideloadService;
use ContentPulseIO\WordPress\Support\PostUpsertService;
use ContentPulseIO\WordPress\Support\SyncHistoryService;
use ContentPulseIO\WordPress\Support\VersionHandshake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the namespace rename (ContentPulse\WordPress -> ContentPulseIO\WordPress):
 * every plugin class must still autoload, and the plugin must keep resolving the
 * bundled SDK class ContentPulse\WordPress\Support\ContentPulseEndpointResolver,
 * which a blanket rename previously broke.
 */
class PluginWiringTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string}>
     */
    public static function pluginClassProvider(): array
    {
        return [
            'Plugin' => [Plugin::class],
            'Api\Routes' => [Routes::class],
            'Api\Controllers\PostsController' => [PostsController::class],
            'Api\Controllers\IngestionController' => [IngestionController::class],
            'Support\PostUpsertService' => [PostUpsertService::class],
            'Support\SyncHistoryService' => [SyncHistoryService::class],
            'Support\MediaSideloadService' => [MediaSideloadService::class],
            'Support\VersionHandshake' => [VersionHandshake::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[Test]
    #[DataProvider('pluginClassProvider')]
    public function plugin_classes_autoload_under_the_new_namespace(string $class): void
    {
        $this->assertTrue(class_exists($class), "Plugin class did not autoload: {$class}");
    }

    #[Test]
    public function bundled_sdk_endpoint_resolver_is_reachable_and_builds_urls(): void
    {
        $this->assertTrue(class_exists(ContentPulseEndpointResolver::class));

        $this->assertSame(
            'https://contentpulse.io',
            ContentPulseEndpointResolver::resolveApiBaseUrl('https://contentpulse.io'),
        );
        $this->assertSame(
            'https://contentpulse.io/api/v1/content/123/publish-wordpress',
            ContentPulseEndpointResolver::buildPublishWordPressEndpoint('https://contentpulse.io', '123'),
        );
        $this->assertSame(
            'https://contentpulse.io/content/123',
            ContentPulseEndpointResolver::buildContentUrl('https://contentpulse.io', '123'),
        );
    }

    #[Test]
    public function version_handshake_resolves_the_sdk_resolver_at_runtime(): void
    {
        // check() calls the SDK resolver before its early return; with no API key
        // it never makes a network call. This fails fast if the resolver wiring
        // (use statement / namespace) is broken.
        $GLOBALS['__cpulse_test_options']['cpulse_api_key'] = '';

        $result = (new VersionHandshake)->check();

        $this->assertFalse($result['compatible']);
        $this->assertSame('1.0.2', $result['plugin_version']);
        $this->assertStringContainsString('API key', $result['message']);
    }
}
