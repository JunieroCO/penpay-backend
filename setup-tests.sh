#!/bin/bash

set -e

echo "Creating PEC-aligned test scaffold..."

# Base tests folder
mkdir -p tests/Application/Deposit
mkdir -p tests/Application/Withdrawal
mkdir -p tests/Application/Callback
mkdir -p tests/Domain/Wallet
mkdir -p tests/Domain/Payments
mkdir -p tests/Infrastructure/DerivWsGateway
mkdir -p tests/Infrastructure/Mpesa
mkdir -p tests/Infrastructure/Fx
mkdir -p tests/Infrastructure/Notification
mkdir -p tests/Workers

# Helper function to create placeholder test
create_placeholder_test() {
    local path=$1
    local class_name=$2

    cat > "$path" <<EOL
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class $class_name extends TestCase
{
    public function testPlaceholder(): void
    {
        \$this->assertTrue(true, 'Placeholder test — always passes');
    }
}
EOL
}

# Application
create_placeholder_test "tests/Application/Deposit/DepositOrchestratorTest.php" "DepositOrchestratorTest"
create_placeholder_test "tests/Application/Withdrawal/WithdrawOrchestratorTest.php" "WithdrawOrchestratorTest"
create_placeholder_test "tests/Application/Callback/MpesaCallbackVerifierTest.php" "MpesaCallbackVerifierTest"

# Domain
create_placeholder_test "tests/Domain/Wallet/LedgerAccountTest.php" "LedgerAccountTest"
create_placeholder_test "tests/Domain/Payments/TransactionTest.php" "TransactionTest"

# Infrastructure
create_placeholder_test "tests/Infrastructure/DerivWsGateway/WsClientTest.php" "WsClientTest"
create_placeholder_test "tests/Infrastructure/Mpesa/MpesaClientTest.php" "MpesaClientTest"
create_placeholder_test "tests/Infrastructure/Fx/FxProviderClientTest.php" "FxProviderClientTest"
create_placeholder_test "tests/Infrastructure/Notification/MailerServiceTest.php" "MailerServiceTest"

# Workers
create_placeholder_test "tests/Workers/DepositWorkerTest.php" "DepositWorkerTest"
create_placeholder_test "tests/Workers/WithdrawWorkerTest.php" "WithdrawWorkerTest"
create_placeholder_test "tests/Workers/MpesaCallbackWorkerTest.php" "MpesaCallbackWorkerTest"
create_placeholder_test "tests/Workers/DerivTransferWorkerTest.php" "DerivTransferWorkerTest"

echo "All placeholder tests created successfully."