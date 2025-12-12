<?php
declare(strict_types=1);

return [
    'app_id' => $_ENV['DERIV_APP_ID'] ?? getenv('DERIV_APP_ID') ?: '1089',
    'ws_url' => $_ENV['DERIV_WS_URL'] ?? null,
    'agent_token' => $_ENV['DERIV_PAYMENT_AGENT_TOKEN'] ?? null,
    'currency' => $_ENV['DERIV_CURRENCY'] ?? 'USD',
    'default_login_id' => $_ENV['PAYMENTAGENT_LOGINID'] ?? null,
];