<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Deriv;

class DerivConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function appId(): string
    {
        return $this->config['app_id'] ?? '1089';
    }

    public function wsUrl(): ?string
    {
        return $this->config['ws_url'] ?? null;
    }

    public function agentToken(): ?string
    {
        return $this->config['agent_token'] ?? null;
    }

    public function currency(): string
    {
        return $this->config['currency'] ?? 'USD';
    }

    public function defaultLoginId(): ?string
    {
        return $this->config['default_login_id'] ?? null;
    }
}