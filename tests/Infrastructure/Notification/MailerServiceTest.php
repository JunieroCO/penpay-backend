<?php

declare(strict_types=1);

namespace PenPay\Tests\Infrastructure\Notification;

use PHPUnit\Framework\TestCase;

final class MailerServiceTest extends TestCase
{
    public function testPlaceholder(): void
    {
        $this->assertTrue(true, 'Placeholder test — always passes');
    }
}
