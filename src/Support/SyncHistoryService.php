<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Support;

final class SyncHistoryService
{
    private const OPTION_KEY = 'contentpulse_recent_syncs';

    private const MAX_ITEMS = 10;

    /**
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): void
    {
        $history = $this->latest(self::MAX_ITEMS);
        array_unshift($history, $entry);

        if (count($history) > self::MAX_ITEMS) {
            $history = array_slice($history, 0, self::MAX_ITEMS);
        }

        update_option(self::OPTION_KEY, $history);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = self::MAX_ITEMS): array
    {
        $history = get_option(self::OPTION_KEY, []);
        if (! is_array($history)) {
            return [];
        }

        return array_slice($history, 0, max(1, $limit));
    }
}
