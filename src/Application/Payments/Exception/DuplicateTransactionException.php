<?php
declare(strict_types=1);

namespace PenPay\Application\Payments\Exception;

use DomainException;

final class DuplicateTransactionException extends DomainException {}