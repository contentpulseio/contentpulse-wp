<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Support;

/**
 * Downloads external images and attaches them to the WordPress media library.
 */
class MediaSideloadService
{
    /**
     * Sideload an external image into the media library.
     *
     * @return int|null The attachment ID, or null on failure.
     */
    public function sideload(string $url, string $description = ''): ?int
    {
        if (empty($url)) {
            return null;
        }

        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }

        $existingId = $this->findExistingByUrl($url);
        if ($existingId) {
            return $existingId;
        }

        $attachmentId = media_sideload_image($url, 0, $description, 'id');

        if (is_wp_error($attachmentId)) {
            // Local/staging hosts can fail wp_http_validate_url checks; fall back to manual import.
            $attachmentId = $this->manualImport($url, $description);
            if ($attachmentId === null) {
                return null;
            }
        }

        update_post_meta($attachmentId, '_contentpulse_source_url', $url);

        return (int) $attachmentId;
    }

    /**
     * Check if an image from this URL was already sideloaded.
     */
    private function findExistingByUrl(string $url): ?int
    {
        global $wpdb;

        $attachmentId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_contentpulse_source_url' AND meta_value = %s LIMIT 1",
                $url,
            ),
        );

        return $attachmentId ? (int) $attachmentId : null;
    }

    private function manualImport(string $url, string $description): ?int
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 3,
            'reject_unsafe_urls' => false,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300 || $body === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path);
        if ($filename === '' || $filename === '/' || str_contains($filename, '?')) {
            $filename = 'contentpulse-image-'.gmdate('YmdHis').'.jpg';
        }
        $filename = sanitize_file_name($filename);

        $tmpFile = wp_tempnam($filename);
        if (! is_string($tmpFile) || $tmpFile === '') {
            return null;
        }

        $written = file_put_contents($tmpFile, $body);
        if ($written === false) {
            @unlink($tmpFile);

            return null;
        }

        $fileArray = [
            'name' => $filename,
            'tmp_name' => $tmpFile,
        ];

        $attachmentId = media_handle_sideload($fileArray, 0, $description);
        if (is_wp_error($attachmentId)) {
            @unlink($tmpFile);

            return null;
        }

        return (int) $attachmentId;
    }
}
