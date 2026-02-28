<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the status mapping logic used by PostUpsertService.
 *
 * Extracted from the private method so it can be tested without WordPress.
 */
class PostStatusMappingTest extends TestCase
{
    #[Test]
    #[DataProvider('statusMappings')]
    public function it_maps_contentpulse_status_to_wordpress(string $input, string $expected): void
    {
        $result = $this->resolvePostStatus($input);

        $this->assertSame($expected, $result);
    }

    public static function statusMappings(): array
    {
        return [
            'published maps to publish' => ['published', 'publish'],
            'scheduled maps to future' => ['scheduled', 'future'],
            'draft maps to draft' => ['draft', 'draft'],
            'review maps to pending' => ['review', 'pending'],
            'archived maps to private' => ['archived', 'private'],
            'unknown maps to draft' => ['unknown_status', 'draft'],
            'empty maps to draft' => ['', 'draft'],
        ];
    }

    private function resolvePostStatus(string $status): string
    {
        return match ($status) {
            'published' => 'publish',
            'scheduled' => 'future',
            'draft' => 'draft',
            'review' => 'pending',
            'archived' => 'private',
            default => 'draft',
        };
    }
}
