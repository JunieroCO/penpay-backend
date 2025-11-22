<?php
declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
    // Load auth routes
    require __DIR__ . '/routes/auth.php';

    // Load future protected routes here...
    // require __DIR__ . '/routes/protected.php';
};