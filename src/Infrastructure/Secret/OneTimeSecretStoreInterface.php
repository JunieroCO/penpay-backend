<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Secret;

interface OneTimeSecretStoreInterface
{
    public function store(string $key, string $value, int $ttlSeconds): void;
    public function getAndDelete(string $key): ?string;
}