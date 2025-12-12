<?php
declare(strict_types=1);

namespace PenPay\Infrastructure\Repository\Payments\Transaction;

use PenPay\Domain\Payments\Aggregate\Transaction;
use PenPay\Domain\Wallet\ValueObject\Money;
use DateTimeImmutable;

final class TransactionSerializer
{
    public function toTransactionRow(Transaction $tx): array
    {
        $now = new DateTimeImmutable();

        // timestamps
        $createdAt = $now->format('Y-m-d H:i:s.u');

        $completedAt = null;
        $failedAt = null;

        if ($tx->status()->isCompleted()) {
            $completedAt = $now->format('Y-m-d H:i:s.u');
        }
        if ($tx->status()->isFailed()) {
            $failedAt = $now->format('Y-m-d H:i:s.u');
        }

        return [
            'id' => (string)$tx->id(),
            'user_id' => $tx->userId(),
            'type' => $tx->type()->value,
            'state' => $tx->status()->value,
            'amount_usd_cents' => $tx->amountUsd()->cents,
            'amount_kes_cents' => $tx->amountKes()->cents,
            'fx_rate' => $tx->lockedRate()->rate, 
            'fx_rate_source' => 'locked-rate',
            'fx_rate_timestamp' => $tx->lockedRate()->lockedAt->format('Y-m-d H:i:s.u'), 
            'idempotency_key_hash' => $tx->idempotencyKey(), 
            'user_deriv_login_id' => $tx->userDerivLoginId(),
            'withdrawal_verification_code' => $tx->withdrawalVerificationCode(),
            'failure_reason' => $tx->failureReason(),
            'retry_count' => $tx->retryCount(),
            'created_at' => $createdAt,
            'completed_at' => $completedAt,
            'failed_at' => $failedAt,
        ];
    }

    public function toMpesaRequestRow(Transaction $tx): ?array
    {
        $req = $tx->mpesaRequest();
        if ($req === null) {
            return null;
        }

        return [
            'transaction_id' => (string)$tx->id(),
            'mpesa_receipt_number' => $req->mpesaReceiptNumber ?? '',
            'phone_number' => $req->phoneNumber->toE164(),
            'amount_kes_cents' => $req->amountKes->cents,
            'transaction_date' => $req->callbackReceivedAt?->format('Y-m-d H:i:s.u') ?? (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'raw_payload' => json_encode($req->rawPayload ?? []),
            'received_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ];
    }

    public function toDerivTransferRow(Transaction $tx): ?array
    {
        $d = $tx->derivTransfer();
        if ($d === null) {
            return null;
        }

        return [
            'transaction_id' => (string)$tx->id(),
            'deriv_transfer_id' => $d->derivTransferId,
            'from_login_id' => $d->fromLoginId,
            'to_login_id' => $d->toLoginId,
            'amount_usd_cents' => $d->amountUsd->cents,
            'executed_at' => $d->executedAt->format('Y-m-d H:i:s.u'),
            'raw_payload' => json_encode($d->rawResponse ?? []),
        ];
    }

    public function toMpesaDisbursementRow(Transaction $tx): ?array
    {
        $m = $tx->mpesaDisbursement();
        if ($m === null) {
            return null;
        }

        // Determine status based on resultCode
        $status = 'PENDING';
        if ($m->resultCode !== null) {
            $status = $m->resultCode === '0' ? 'COMPLETED' : 'FAILED';
        }

        return [
            'transaction_id' => (string)$tx->id(),
            'conversation_id' => $m->conversationId,
            'originator_conversation_id' => $m->originatorConversationId,
            'phone_number' => $m->phoneNumber->toE164(),
            'amount_kes_cents' => $m->amountKes->cents,
            'status' => $status, // Derived from resultCode
            'result_code' => $m->resultCode,
            'result_description' => $m->resultDescription,
            'mpesa_receipt_number' => $m->mpesaReceiptNumber, // Add this field
            'raw_payload' => json_encode($m->rawPayload ?? []),
            'initiated_at' => $m->initiatedAt->format('Y-m-d H:i:s.u'),
            'completed_at' => $m->callbackReceivedAt?->format('Y-m-d H:i:s.u'), // Use callbackReceivedAt
        ];
    }
}