graph TB
    subgraph Shared_Kernel
        SK_TID[TransactionId<br/>UUIDv7]
        SK_Money[Money<br/>int cents]
        SK_Currency[Currency<br/>enum]
        SK_LockedRate[LockedRate]
    end

    subgraph User_Identity_Context
        USER[User<br/>Aggregate Root]
        USER --> EMAIL[Email<br/>VO]
        USER --> PHONE[PhoneNumber<br/>VO]
        USER --> DERIV_ID[DerivLoginId<br/>VO]
        USER --> PASS[PasswordHash<br/>VO]
        USER --> DEVICES[Device<br/>Entity x2 max]
        USER --> KYC[KycSnapshot<br/>VO immutable]
    end

    subgraph Wallet_Context
        LEDGER[LedgerAccount<br/>Aggregate Root]
        LEDGER -->|1..* entries| ENTRY[LedgerEntry<br/>Entity]
        ENTRY --> SIDE[LedgerSide<br/>enum DEBIT CREDIT]
        ENTRY --> SK_TID
        ENTRY --> SK_Money
        ENTRY --> SK_LockedRate

        LEDGER --> BAL_USD[getBalanceUsd: Money]
        LEDGER --> BAL_KES[getBalanceKes: Money]

        DS_LIMIT[DailyLimitChecker<br/>Domain Service]
        DS_RECORDER[LedgerRecorder<br/>Domain Service]
        LEDGER --> DS_LIMIT
        LEDGER --> DS_RECORDER

        event_wallet_deposit[DepositInitiated]
        event_wallet_balance[BalanceChanged]
        LEDGER --> event_wallet_deposit
        LEDGER --> event_wallet_balance
    end

    subgraph Payments_Transaction_Context
        TX[Transaction<br/>Aggregate Root]
        TX --> SK_TID
        TX --> STATUS[TransactionStatus<br/>enum]
        TX --> IDEM[IdempotencyKey<br/>VO]
        TX --> TYPE[TransactionType<br/>enum DEPOSIT WITHDRAWAL]

        TX -->|0..1| MPESA[MpesaRequest<br/>Entity]
        TX -->|0..1| DERIV[DerivTransfer<br/>Entity]

        DS_TX[TransactionService<br/>Domain Service]
        TX --> DS_TX

        event_tx_created[TransactionCreated]
        event_tx_mpesa[MpesaCallbackReceived]
        event_tx_completed[TransactionCompleted]
        event_tx_failed[TransactionFailed]
        TX --> event_tx_created
        TX --> event_tx_mpesa
        TX --> event_tx_completed
        TX --> event_tx_failed
    end

    subgraph Audit_Compliance_Context
        AUDIT[AuditLog<br/>Aggregate Root]
        AUDIT -->|1..*| AUDIT_ENTRY[AuditEntry<br/>Entity write-once]
        AUDIT_ENTRY --> ACTOR[Actor<br/>VO]
        AUDIT_ENTRY --> EVENT_TYPE[EventType<br/>enum]
        AUDIT_ENTRY --> PAYLOAD[JsonPayload]

        DS_AUDIT[AuditLogger<br/>Domain Service]
        AUDIT --> DS_AUDIT
    end

    %% Cross-context relationships via Shared Kernel
    TX --> SK_TID
    LEDGER --> SK_TID
    ENTRY --> SK_TID

    %% Event flow
    TX -->|triggers| event_wallet_deposit
    TX -->|triggers| event_wallet_balance
    TX -->|triggers| AUDIT
    LEDGER -->|triggers| AUDIT

    classDef shared fill:#e1f5fe,stroke:#01579b,stroke-width:3px
    classDef aggregate fill:#fff3e0,stroke:#e65100,stroke-width:2px
    classDef entity fill:#f3e5f5,stroke:#4a148c
    classDef vo fill:#e8f5e8,stroke:#1b5e20
    classDef service fill:#e0f2f1,stroke:#00695c
    classDef event fill:#ffebee,stroke:#b71c1c

    class SK_TID,SK_Money,SK_Currency,SK_LockedRate shared
    class USER,LEDGER,TX,AUDIT aggregate
    class ENTRY,MPESA,DERIV,DEVICES,AUDIT_ENTRY entity
    class EMAIL,PHONE,DERIV_ID,PASS,KYC,STATUS,IDEM,TYPE,SIDE,ACTOR,EVENT_TYPE,PAYLOAD vo
    class DS_LIMIT,DS_RECORDER,DS_TX,DS_AUDIT service
    class event_wallet_deposit,event_wallet_balance,event_tx_created,event_tx_mpesa,event_tx_completed,event_tx_failed event