<?php

declare(strict_types=1);

namespace ContentPulseIO\WordPress\Support;

use ContentPulse\Core\Exceptions\AuthenticationException;
use ContentPulse\Http\ContentPulseClient;
use ContentPulse\WordPress\Support\ContentPulseEndpointResolver;
use Throwable;

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
        $apiUrl = $this->resolveContentPulseApiBaseUrl();
        $apiKey = get_option('cpulse_api_key', '');

        if (empty($apiUrl) || empty($apiKey)) {
            return [
                'compatible' => false,
                'plugin_version' => CPULSE_VERSION,
                'message' => 'Settings API key must be configured.',
            ];
        }

        try {
            $client = new ContentPulseClient(apiKey: $apiKey, baseUrl: $apiUrl);
            $feed = $client->getContentFeed();

            return [
                'compatible' => true,
                'plugin_version' => CPULSE_VERSION,
                'message' => 'Connection successful.',
            ];
        } catch (AuthenticationException $e) {
            return [
                'compatible' => false,
                'plugin_version' => CPULSE_VERSION,
                'message' => 'Authentication failed - check your API key.',
            ];
        } catch (Throwable $e) {
            return [
                'compatible' => false,
                'plugin_version' => CPULSE_VERSION,
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

    private function resolveContentPulseApiBaseUrl(): string
    {
        $resolved = ContentPulseEndpointResolver::resolveApiBaseUrlFromEnvironment();
        $filtered = (string) apply_filters('cpulse_api_base_url', $resolved);

        return ContentPulseEndpointResolver::resolveApiBaseUrl($filtered);
    }
}
