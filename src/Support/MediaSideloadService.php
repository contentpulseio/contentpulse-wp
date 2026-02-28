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
            return null;
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
}
