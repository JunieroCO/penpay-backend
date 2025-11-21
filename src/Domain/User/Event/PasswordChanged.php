<?php
declare(strict_types=1);

namespace PenPay\Domain\User\Event;

use PenPay\Domain\Shared\Kernel\DomainEvent;
use PenPay\Domain\Shared\Kernel\UserId;

final readonly class PasswordChanged extends DomainEvent
{
    public function __construct(
        public UserId $userId,
    ) {
        parent::__construct();
    }
}