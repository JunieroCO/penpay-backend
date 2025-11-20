sequenceDiagram
    participant User
    participant API
    participant DepositOrchestrator
    participant Transaction Agg
    participant LedgerAccount
    participant Redis
    participant MpesaCallbackWorker
    participant DerivWorker
    participant Deriv

    User->>API: POST /deposit {kes:5000}
    API->>DepositOrchestrator: initiateDeposit()
    DepositOrchestrator->>Transaction Agg: initiateDeposit()
    DepositOrchestrator->>LedgerAccount: recordDepositInitiated()
    DepositOrchestrator->>Redis: publish deposit.initiated
    API-->>User: 202 Accepted + transaction_id

    Note over MpesaCallbackWorker: M-Pesa pushes callback
    MpesaCallbackWorker->>Transaction Agg: recordMpesaCallback()
    MpesaCallbackWorker->>Redis: publish deriv.transfer.requested

    DerivWorker->>Deriv: transfer_to_deriv()
    Deriv-->>DerivWorker: success + txn_id
    DerivWorker->>Transaction Agg: completeWithDerivTransfer()
    DerivWorker->>LedgerAccount: recordDepositCompleted()
    LedgerAccount->>Redis: BalanceChanged event

    Note over User: Real-time balance updates via WS