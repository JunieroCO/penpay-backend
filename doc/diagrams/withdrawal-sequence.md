sequenceDiagram
    participant User as User (App)
    participant FE as Frontend
    participant API as API Gateway
    participant WS as Deriv WS Gateway
    participant Orchestrator as WithdrawalOrchestrator
    participant Domain as Transaction Aggregate
    participant Repo as TransactionRepository
    participant Ledger as LedgerRecorder
    participant Queue as Redis Streams
    participant W1 as DerivDebitWorker
    participant Deriv as Deriv Platform
    participant W2 as MpesaDisbursementWorker
    participant Mpesa as Safaricom B2C

    Note over User,Deriv: CORRECT WITHDRAWAL FLOW — USD FIRST + VERIFICATION CODE

    %% PHASE 1: Request verification code
    User->>FE: I want to withdraw $50
    FE->>API: POST /withdrawals/request
    Note right of API: { usd_amount: 50, idempotency_key }
    API->>WS: request_withdrawal(amount=50, currency="USD")
    WS->>Deriv: Trigger verification email
    Deriv-->>User: Email → "Your code: 483712"
    API-->>FE: { status: "CODE_SENT" }
    FE-->>User: "Enter the 6-digit code from email"

    %% PHASE 2: Confirm with code → real money movement starts
    User->>FE: Enters 483712
    FE->>API: POST /withdrawals/confirm
    Note right of API: { usd_amount: 50, verification_code: "483712", idempotency_key }

    API->>Orchestrator: confirmWithdrawal(userId, usdCents, verificationCode, idempotencyKey)

    Orchestrator->>Repo: findByIdempotencyKey(idempotencyKey)
    alt Idempotent – already processed
        Repo-->>Orchestrator: existing Transaction
        Orchestrator-->>API: return existing tx
    else First time
        Orchestrator->>Domain: Transaction::initiateWithdrawal(usdAmount, verificationCode)
        Domain->>Domain: status = PENDING<br/>type = WITHDRAWAL<br/>store verification_code

        Orchestrator->>Ledger: recordWithdrawalInitiated(usd=50.00)
        Orchestrator->>Repo: save(transaction)
        Orchestrator->>Queue: publish withdrawals.initiated<br/>{ transaction_id, usd_cents, verification_code }
        Orchestrator-->>API: { transaction_id, status: "PENDING" }
    end
    API-->>FE: Withdrawal in progress...
    FE-->>User: "Withdrawing $50..."

    %% PHASE 3: Deriv debit (USD removed)
    Queue->>W1: withdrawals.initiated
    W1->>Repo: getById(transaction_id)
    alt Already finalized
        W1->>W1: skip (idempotent)
    else Pending
        W1->>Deriv: paymentagent_withdraw<br/>amount=50.0, verification_code="483712"
        alt Success
            Deriv-->>W1: { success: 1, transaction_id: "DW98765" }
            W1->>Domain: recordDerivWithdrawalSuccess("DW98765")
            W1->>Repo: save(tx)
            W1->>Queue: publish withdrawals.deriv_debited
            Note over W1: Deriv balance -$50
        else Failure
            Deriv-->>W1: error (invalid code, insufficient, etc)
            W1->>Domain: fail("deriv_withdraw_failed")
            W1->>Repo: save(tx)
            W1->>Queue: publish withdrawals.failed
        end
    end

    %% PHASE 4: M-Pesa B2C payout
    Queue->>W2: withdrawals.deriv_debited
    W2->>Repo: getById(transaction_id)
    alt Already disbursed
        W2->>W2: skip
    else Pay user
        W2->>Mpesa: B2C → 6,500 KES
        alt Success
            Mpesa-->>W2: { ResultCode: 0, Receipt: "RBK987..." }
            W2->>Domain: recordMpesaDisbursement("RBK987...")
            Domain->>Domain: status = COMPLETED
            W2->>Repo: save(tx)
            W2->>Queue: publish withdrawals.completed
        else Failure
            Mpesa-->>W2: timeout / error
            W2->>Domain: fail("mpesa_disbursement_failed")
            W2->>Queue: publish withdrawals.failed
            Note right of W2: Manual reconciliation needed
        end
    end

    Note over User,Mpesa: FINAL STATE:<br/>• Deriv balance: -$50<br/>• User received: 6,500 KES<br/>• Transaction: COMPLETED
