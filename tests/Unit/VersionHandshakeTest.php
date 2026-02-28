<?php

declare(strict_types=1);

namespace ContentPulse\WordPress\Tests\Unit;

use ContentPulse\WordPress\Support\VersionHandshake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class VersionHandshakeTest extends TestCase
{
    #[Test]
    public function it_returns_minimum_api_version(): void
    {
        $handshake = new VersionHandshake;

        $this->assertSame('1.0.0', $handshake->getMinApiVersion());
    }
}
