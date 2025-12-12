# TransactionFactory Usage Guide

## Overview

The `TransactionFactory` provides precision-safe methods to create deposit and withdrawal transactions. It handles currency conversion, validation, and transaction initialization.

## Key Features

- **Precision-Safe**: Uses cents (integers) or strings to avoid floating-point precision issues
- **Automatic ID Generation**: Generates `TransactionId` and `IdempotencyKey` if not provided
- **Validation**: Enforces minimum/maximum amounts and format validation
- **FX Rate Locking**: Requires a `LockedRate` to ensure consistent currency conversion

## Basic Usage

### Dependency Injection

```php
use PenPay\Domain\Payments\Factory\TransactionFactoryInterface;

class YourService
{
    public function __construct(
        private TransactionFactoryInterface $txFactory,
        private FxServiceInterface $fxService
    ) {}
}
```

## Creating Deposits

### Method 1: From Cents (Recommended - Most Precise)

```php
// Lock the FX rate first
$lockedRate = $this->fxService->lockRate('USD', 'KES');

// Create deposit: $100.00 = 10000 cents
$transaction = $this->txFactory->createDepositFromCents(
    userId: 'user-123',
    amountUsdCents: 10000,  // $100.00
    lockedRate: $lockedRate,
    userDerivLoginId: 'CR123456',
    idempotencyKey: null  // Will be auto-generated if null
);

// With custom idempotency key
$idempotencyKey = IdempotencyKey::fromHeader('custom-key-123');
$transaction = $this->txFactory->createDepositFromCents(
    userId: 'user-123',
    amountUsdCents: 10000,
    lockedRate: $lockedRate,
    userDerivLoginId: 'CR123456',
    idempotencyKey: $idempotencyKey
);
```

### Method 2: From String (Recommended - User-Friendly)

```php
$lockedRate = $this->fxService->lockRate('USD', 'KES');

// Accepts formats: "100.00", "100.5", "100"
$transaction = $this->txFactory->createDepositFromString(
    userId: 'user-123',
    amountUsd: '100.00',  // or "100.5" or "100"
    lockedRate: $lockedRate,
    userDerivLoginId: 'CR123456'
);
```

### Method 3: From Float (Deprecated - Avoid Floating Point)

```php
// ⚠️ DEPRECATED: Use createDepositFromCents() or createDepositFromString()
$transaction = $this->txFactory->createDepositTransaction(
    userId: 'user-123',
    amountUsd: 100.00,  // Float - may have precision issues
    lockedRate: $lockedRate,
    userDerivLoginId: 'CR123456'
);
```

## Creating Withdrawals

### Method 1: From Cents (Recommended - Most Precise)

```php
$lockedRate = $this->fxService->lockRate('USD', 'KES');

// Create withdrawal: $100.00 = 10000 cents
// Requires 6-character alphanumeric verification code
$transaction = $this->txFactory->createWithdrawalFromCents(
    userId: 'user-123',
    amountUsdCents: 10000,  // $100.00
    lockedRate: $lockedRate,
    userDerivLoginId: 'CR123456',
    withdrawalVerificationCode: 'ABC123',  // 6 alphanumeric chars
    idempotencyKey: null
);
```

### Method 2: From String (Recommended - User-Friendly)

```php
$lockedRate = $this->fxService->lockRate('USD', 'KES');

$transaction = $this->txFactory->createWithdrawalFromString(
    userId: 'user-123',
    amountUsd: '100.00',
    lockedRate: $lockedRate,
    userDerivLoginId: 'CR123456',
    withdrawalVerificationCode: 'ABC123'  // Required!
);
```

### Method 3: From Float (Deprecated)

```php
// ⚠️ DEPRECATED: Use createWithdrawalFromCents() or createWithdrawalFromString()
$transaction = $this->txFactory->createWithdrawalTransaction(
    userId: 'user-123',
    amountUsd: 100.00,
    lockedRate: $lockedRate,
    userDerivLoginId: 'CR123456',
    withdrawalVerificationCode: 'ABC123'  // Required!
);
```

## Validation Rules

### Deposit Limits
- **Minimum**: $2.00 (200 cents)
- **Maximum**: $1000.00 (100000 cents)
- **Currency**: Must be USD

### Withdrawal Limits
- **Minimum**: $5.00 (500 cents)
- **Maximum**: $1000.00 (100000 cents)
- **Currency**: Must be USD
- **Verification Code**: Must be exactly 6 alphanumeric characters (case-insensitive)

### Amount Format (String Methods)
- Valid: `"100"`, `"100.0"`, `"100.00"`, `"100.5"`
- Invalid: `"100.123"` (more than 2 decimal places), `"abc"`, `"-100"`

## Complete Example: Deposit Flow

```php
use PenPay\Domain\Payments\Factory\TransactionFactoryInterface;
use PenPay\Domain\Payments\ValueObject\IdempotencyKey;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;

class DepositService
{
    public function __construct(
        private TransactionFactoryInterface $txFactory,
        private TransactionRepositoryInterface $txRepo,
        private FxServiceInterface $fxService
    ) {}

    public function createDeposit(
        string $userId,
        int $amountUsdCents,
        string $userDerivLoginId,
        ?string $idempotencyKeyHeader = null
    ): Transaction {
        // 1. Check idempotency
        if ($idempotencyKeyHeader) {
            $idempotencyKey = IdempotencyKey::fromHeader($idempotencyKeyHeader);
            $existing = $this->txRepo->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                return $existing;  // Idempotent request
            }
        }

        // 2. Lock FX rate
        $lockedRate = $this->fxService->lockRate('USD', 'KES');

        // 3. Create transaction
        $idempotencyKey = $idempotencyKeyHeader 
            ? IdempotencyKey::fromHeader($idempotencyKeyHeader)
            : null;

        $transaction = $this->txFactory->createDepositFromCents(
            userId: $userId,
            amountUsdCents: $amountUsdCents,
            lockedRate: $lockedRate,
            userDerivLoginId: $userDerivLoginId,
            idempotencyKey: $idempotencyKey
        );

        // 4. Persist
        $this->txRepo->save($transaction);

        return $transaction;
    }
}
```

## Complete Example: Withdrawal Flow

```php
class WithdrawalService
{
    public function __construct(
        private TransactionFactoryInterface $txFactory,
        private TransactionRepositoryInterface $txRepo,
        private FxServiceInterface $fxService
    ) {}

    public function createWithdrawal(
        string $userId,
        int $amountUsdCents,
        string $userDerivLoginId,
        string $verificationCode,
        ?string $idempotencyKeyHeader = null
    ): Transaction {
        // 1. Validate verification code format
        if (!preg_match('/^[A-Z0-9]{6}$/i', $verificationCode)) {
            throw new InvalidArgumentException('Verification code must be 6 alphanumeric characters');
        }

        // 2. Check idempotency
        if ($idempotencyKeyHeader) {
            $idempotencyKey = IdempotencyKey::fromHeader($idempotencyKeyHeader);
            $existing = $this->txRepo->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                return $existing;
            }
        }

        // 3. Lock FX rate
        $lockedRate = $this->fxService->lockRate('USD', 'KES');

        // 4. Create transaction
        $idempotencyKey = $idempotencyKeyHeader 
            ? IdempotencyKey::fromHeader($idempotencyKeyHeader)
            : null;

        $transaction = $this->txFactory->createWithdrawalFromCents(
            userId: $userId,
            amountUsdCents: $amountUsdCents,
            lockedRate: $lockedRate,
            userDerivLoginId: $userDerivLoginId,
            withdrawalVerificationCode: strtoupper($verificationCode),
            idempotencyKey: $idempotencyKey
        );

        // 5. Persist
        $this->txRepo->save($transaction);

        return $transaction;
    }
}
```

## Error Handling

```php
try {
    $transaction = $this->txFactory->createDepositFromCents(
        userId: 'user-123',
        amountUsdCents: 100,  // Too small - will throw
        lockedRate: $lockedRate,
        userDerivLoginId: 'CR123456'
    );
} catch (InvalidArgumentException $e) {
    // Handle: "Deposit minimum is $2.00"
    error_log($e->getMessage());
}
```

## Best Practices

1. **Always use cents or strings** - Avoid floats to prevent precision issues
2. **Lock FX rate first** - Get the rate before creating the transaction
3. **Check idempotency** - Always check for existing transactions before creating
4. **Validate user input** - Validate amounts and verification codes before calling factory
5. **Handle exceptions** - Factory methods throw `InvalidArgumentException` for invalid inputs

## Method Comparison

| Method | Precision | Recommended | Use Case |
|--------|-----------|-------------|----------|
| `createDepositFromCents()` | ✅ Perfect | ✅ Yes | API receives cents |
| `createDepositFromString()` | ✅ Perfect | ✅ Yes | API receives string amounts |
| `createDepositTransaction()` | ⚠️ Float issues | ❌ No | Legacy code only |
| `createWithdrawalFromCents()` | ✅ Perfect | ✅ Yes | API receives cents |
| `createWithdrawalFromString()` | ✅ Perfect | ✅ Yes | API receives string amounts |
| `createWithdrawalTransaction()` | ⚠️ Float issues | ❌ No | Legacy code only |

