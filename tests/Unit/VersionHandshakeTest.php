<?php

declare(strict_types=1);

namespace ContentPulseIO\WordPress\Tests\Unit;

use ContentPulseIO\WordPress\Support\VersionHandshake;
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
