<?php

declare(strict_types=1);

namespace PenPay\Tests\Application\Callback;

use PHPUnit\Framework\TestCase;

final class MpesaCallbackVerifierTest extends TestCase
{
    public function testPlaceholder(): void
    {
        $this->assertTrue(true, 'Placeholder test — always passes');
    }
}
