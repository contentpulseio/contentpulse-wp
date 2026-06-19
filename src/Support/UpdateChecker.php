<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Support;

/**
 * Provides plugin updates from GitHub releases via the native
 * WordPress `Update URI` mechanism (WP 5.8+).
 *
 * WordPress calls the `update_plugins_{hostname}` filter during update
 * checks; this class answers with the latest GitHub release so core can
 * offer updates and the auto-updates toggle for this plugin.
 */
class UpdateChecker
{
    public const GITHUB_REPO = 'contentpulseio/contentpulse-wp';

    public const PLUGIN_SLUG = 'contentpulse-wp';

    private const RELEASE_ASSET_NAME = 'contentpulse-wp.zip';

    private const TRANSIENT_KEY = 'cpulse_latest_release';

    private const CACHE_TTL_SECONDS = 21600; // 6 hours

    /**
     * Hook into the WordPress update system.
     */
    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'provideUpdateInfo'], 10, 3);
    }

    /**
     * Filter callback for `update_plugins_github.com`.
     *
     * Returns release info for this plugin so core can decide whether an
     * update is available (core performs the version comparison itself).
     *
     * @param  array<string, mixed>|false  $update
     * @param  array<string, mixed>  $pluginData
     * @return array<string, mixed>|false
     */
    public function provideUpdateInfo(mixed $update, array $pluginData, string $pluginFile): mixed
    {
        if ($pluginFile !== plugin_basename(CPULSE_FILE)) {
            return $update;
        }

        $release = $this->fetchLatestRelease();
        if ($release === null) {
            return $update;
        }

        $payload = $this->buildUpdatePayload($release, $pluginFile);

        return $payload ?? $update;
    }

    /**
     * Build the update payload WordPress expects from a GitHub release.
     *
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>|null
     */
    public function buildUpdatePayload(array $release, string $pluginFile): ?array
    {
        $version = $this->resolveVersion($release);
        $package = $this->resolvePackageUrl($release);

        if ($version === '' || $package === '') {
            return null;
        }

        return [
            'id' => 'https://github.com/'.self::GITHUB_REPO,
            'slug' => self::PLUGIN_SLUG,
            'plugin' => $pluginFile,
            'version' => $version,
            'url' => 'https://github.com/'.self::GITHUB_REPO,
            'package' => $package,
        ];
    }

    /**
     * Extract the plugin version from the release tag (strips a leading "v").
     *
     * @param  array<string, mixed>  $release
     */
    public function resolveVersion(array $release): string
    {
        $tag = isset($release['tag_name']) && is_string($release['tag_name']) ? $release['tag_name'] : '';

        return ltrim(trim($tag), 'v');
    }

    /**
     * Prefer the built release asset zip; fall back to the source zipball.
     *
     * @param  array<string, mixed>  $release
     */
    public function resolvePackageUrl(array $release): string
    {
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = isset($asset['name']) && is_string($asset['name']) ? $asset['name'] : '';
            $url = isset($asset['browser_download_url']) && is_string($asset['browser_download_url'])
                ? $asset['browser_download_url']
                : '';

            if ($name === self::RELEASE_ASSET_NAME && $url !== '') {
                return $url;
            }
        }

        return isset($release['zipball_url']) && is_string($release['zipball_url']) ? $release['zipball_url'] : '';
    }

    /**
     * Fetch the latest release from the GitHub API (cached via transient).
     *
     * @return array<string, mixed>|null
     */
    private function fetchLatestRelease(): ?array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/'.self::GITHUB_REPO.'/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'contentpulse-wp/'.CPULSE_VERSION,
                ],
            ],
        );

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($decoded)) {
            return null;
        }

        set_transient(self::TRANSIENT_KEY, $decoded, self::CACHE_TTL_SECONDS);

        return $decoded;
    }
}
