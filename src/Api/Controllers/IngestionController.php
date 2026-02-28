<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Api\Controllers;

use ContentPulse\WordPress\Support\SyncHistoryService;
use WP_REST_Response;

class IngestionController
{
    /**
     * Return the current ingestion/sync status.
     */
    public function status(): WP_REST_Response
    {
        $lastSync = get_option('contentpulse_last_sync', null);
        $syncCount = (int) get_option('contentpulse_sync_count', 0);
        $history = (new SyncHistoryService)->latest(5);

        return new WP_REST_Response([
            'status' => 'ready',
            'last_sync_at' => $lastSync,
            'total_synced' => $syncCount,
            'recent_syncs' => $history,
            'plugin_version' => CONTENTPULSE_WP_VERSION,
        ], 200);
    }
}
