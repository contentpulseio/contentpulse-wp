<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Api;

use ContentPulse\WordPress\Api\Controllers\IngestionController;
use ContentPulse\WordPress\Api\Controllers\PostsController;
use WP_REST_Request;
use WP_REST_Response;

class Routes
{
    private const NAMESPACE = 'contentpulse/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/plugin-info', [
            'methods' => 'GET',
            'callback' => [$this, 'pluginInfo'],
            'permission_callback' => [$this, 'checkApiKeyPermission'],
        ]);

        $posts = new PostsController;

        register_rest_route(self::NAMESPACE, '/posts', [
            'methods' => 'POST',
            'callback' => [$posts, 'upsert'],
            'permission_callback' => [$this, 'checkApiKeyPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$posts, 'show'],
            'permission_callback' => [$this, 'checkApiKeyPermission'],
            'args' => [
                'id' => [
                    'validate_callback' => fn ($v) => is_numeric($v) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$posts, 'destroy'],
            'permission_callback' => [$this, 'checkApiKeyPermission'],
            'args' => [
                'id' => [
                    'validate_callback' => fn ($v) => is_numeric($v) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        $ingestion = new IngestionController;

        register_rest_route(self::NAMESPACE, '/ingestion/status', [
            'methods' => 'GET',
            'callback' => [$ingestion, 'status'],
            'permission_callback' => [$this, 'checkApiKeyPermission'],
        ]);
    }

    /**
     * Validate the request API key matches the stored plugin key.
     */
    public function checkApiKeyPermission(WP_REST_Request $request): bool
    {
        $storedKey = get_option('contentpulse_api_key', '');
        if (empty($storedKey)) {
            return false;
        }

        $providedKey = $request->get_header('X-ContentPulse-Key');
        if (! $providedKey) {
            $providedKey = $request->get_param('api_key');
        }

        return hash_equals($storedKey, (string) $providedKey);
    }

    /**
     * Return plugin version and compatibility information.
     */
    public function pluginInfo(): WP_REST_Response
    {
        global $wp_version;

        return new WP_REST_Response([
            'plugin_version' => CONTENTPULSE_WP_VERSION,
            'wordpress_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'supports_blocks' => version_compare($wp_version, '5.0', '>='),
            'rest_api_version' => 'v1',
        ], 200);
    }
}
