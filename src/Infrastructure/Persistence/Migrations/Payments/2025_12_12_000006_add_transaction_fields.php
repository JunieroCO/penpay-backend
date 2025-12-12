<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Persistence\Migration\Payments;

use PenPay\Infrastructure\Persistence\Migration;

final class AddTransactionFields extends Migration
{
    public function up(): void
    {
        $this->exec("
            ALTER TABLE transactions
            ADD COLUMN user_deriv_login_id VARCHAR(50) NULL COMMENT 'User Deriv account login ID',
            ADD COLUMN withdrawal_verification_code VARCHAR(6) NULL COMMENT '6-character verification code for withdrawals',
            ADD INDEX idx_user_deriv_login_id (user_deriv_login_id)
        ");
    }

    public function down(): void
    {
        $this->exec("
            ALTER TABLE transactions
            DROP INDEX idx_user_deriv_login_id,
            DROP COLUMN withdrawal_verification_code,
            DROP COLUMN user_deriv_login_id
        ");
    }
}
