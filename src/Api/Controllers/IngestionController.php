<?php

declare(strict_types=1);

namespace ContentPulseIO\WordPress\Api\Controllers;

use ContentPulseIO\WordPress\Support\SyncHistoryService;
use WP_REST_Response;

class IngestionController
{
    /**
     * Return the current ingestion/sync status.
     */
    public function status(): WP_REST_Response
    {
        $lastSync = get_option('cpulse_last_sync', null);
        $syncCount = (int) get_option('cpulse_sync_count', 0);
        $history = (new SyncHistoryService)->latest(5);

        return new WP_REST_Response([
            'status' => 'ready',
            'last_sync_at' => $lastSync,
            'total_synced' => $syncCount,
            'recent_syncs' => $history,
            'plugin_version' => CPULSE_VERSION,
        ], 200);
    }
}
