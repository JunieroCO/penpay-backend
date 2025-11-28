<?php
declare(strict_types=1);

return [
    'app_id' => $_ENV['DERIV_APP_ID'] ?? getenv('DERIV_APP_ID') ?: '1089',
    'ws_url' => $_ENV['DERIV_WS_URL'] ?? null,
];