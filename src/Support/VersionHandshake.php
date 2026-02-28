<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Support;

use ContentPulse\Http\ContentPulseClient;

/**
 * Handles version compatibility checks between the plugin and ContentPulse API.
 */
class VersionHandshake
{
    private const MIN_API_VERSION = '1.0.0';

    /**
     * Check if the ContentPulse API is reachable and compatible.
     *
     * @return array{compatible: bool, plugin_version: string, message: string}
     */
    public function check(): array
    {
        $apiUrl = get_option('contentpulse_api_url', '');
        $apiKey = get_option('contentpulse_api_key', '');

        if (empty($apiUrl) || empty($apiKey)) {
            return [
                'compatible' => false,
                'plugin_version' => CONTENTPULSE_WP_VERSION,
                'message' => 'API URL and API Key must be configured.',
            ];
        }

        try {
            $client = new ContentPulseClient($apiUrl, $apiKey);
            $feed = $client->getContentFeed();

            return [
                'compatible' => true,
                'plugin_version' => CONTENTPULSE_WP_VERSION,
                'message' => 'Connection successful.',
            ];
        } catch (\ContentPulse\Core\Exceptions\AuthenticationException $e) {
            return [
                'compatible' => false,
                'plugin_version' => CONTENTPULSE_WP_VERSION,
                'message' => 'Authentication failed — check your API key.',
            ];
        } catch (\Throwable $e) {
            return [
                'compatible' => false,
                'plugin_version' => CONTENTPULSE_WP_VERSION,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get the minimum supported API version.
     */
    public function getMinApiVersion(): string
    {
        return self::MIN_API_VERSION;
    }
}
