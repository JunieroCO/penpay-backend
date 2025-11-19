flowchart TB
    %% ====================== ACTORS ======================
    User[Mobile User<br/>React Native App]
    Admin[Admin Dashboard<br/>SuperAdmin  Support]

    %% ====================== PENPAY CONTAINERS ======================
    subgraph MobileAppContainer ["Mobile App (Expo)"]
        AuthFlow[Auth Components<br/>OAuth, PIN, Biometric, SecureStore]
        WalletUI[Wallet Screens<br/>Balance, History]
        DepositFlow[Deposit Flow]
        WithdrawFlow[Withdrawal Flow]
        ApiClient[API Client<br/>TanStack Query + Axios]
    end

    subgraph ApiContainer ["API Container<br/>(NGINX + PHP-FPM)"]
        direction TB
        Controllers[REST Controllers<br/>v1/*]
        Middlewares[Middlewares<br/>JWT, RateLimit, Idempotency, IP Allowlist]
        AuthService[Auth Service<br/>JWT, Refresh, Deriv OAuth]
        TransactionOrchestrator[Transaction Orchestrator<br/>Deposit/Withdraw initiation]
        FxService[FX Service<br/>Lock rate +5/−5 KES]
        NotificationService[Notification Service<br/>Email + future Push]
        AdminController[Admin Controllers<br/>Reconciliation, Freeze, Reversals]
    end

    subgraph DerivGatewayContainer ["deriv-ws-gateway (Dedicated Worker)"]
        DerivClient[Deriv WebSocket Client<br/>Single persistent connection<br/>Auto-reconnect + backoff]
        BalanceSubscriber[Balance Subscriber<br/>Real-time per user]
        TransferHandler[Transfer Handler<br/>payment_agent_transfer]
        EventPublisher[Event Publisher<br/>Redis Streams → balance.updated]
    end

    subgraph WorkerContainer ["Queue Workers (Multiple Instances)"]
        DepositWorker[Deposit Worker<br/>STK Push → Deriv deposit]
        WithdrawWorker[Withdrawal Worker<br/>Deriv withdraw → B2C]
        MpesaCallbackWorker[M-Pesa Callback Worker<br/>Idempotent + verify signature]
        DerivCallbackWorker[Deriv Webhook Worker<br/>(future)]
        RetryEngine[Retry + DLQ Handler]
    end

    subgraph DataStores ["Data Stores"]
        UserDB[(MySQL<br/>UserDB – mutable)]
        LedgerDB[(LedgerDB<br/>Double-entry, append-only)]
        AuditDB[(AuditDB<br/>Write-once, tamper-evident)]
        Redis[(Redis<br/>Streams + Cache + Rate limits)]
    end

    %% ====================== EXTERNAL ======================
    subgraph External
        Deriv[Deriv Platform]
        Safaricom[Safaricom Daraja]
        FX[FX Provider]
        SMTP[SMTP]
    end

    %% ====================== CONNECTIONS ======================
    User --> MobileAppContainer
    Admin --> ApiContainer

    MobileAppContainer -->|"HTTPS /api/v1"| ApiContainer
    ApiContainer -->|"Redis Streams"| WorkerContainer
    ApiContainer -->|"gRPC / Redis PubSub"| DerivGatewayContainer

    DerivGatewayContainer -->|"Persistent WSS"| Deriv
    DerivGatewayContainer -->|"balance.updated"| Redis

    WorkerContainer -->|"REST + Callbacks"| Safaricom
    WorkerContainer -->|"WSS / REST"| Deriv
    ApiContainer --> FX
    ApiContainer --> SMTP

    SMTP

    ApiContainer & WorkerContainer --> UserDB
    ApiContainer & WorkerContainer --> LedgerDB
    ApiContainer & WorkerContainer & DerivGatewayContainer --> AuditDB
    ApiContainer & WorkerContainer & DerivGatewayContainer --> Redis

    %% ====================== STYLING ======================
    classDef container fill:#e6f7ff,stroke:#1890ff,stroke-width:3px
    classDef critical fill:#ff4d4f,color:white
    classDef immutable fill:#531dab,color:white

    class ApiContainer,DerivGatewayContainer,WorkerContainer,MobileAppContainer container
    class DerivGatewayContainer,LedgerDB,AuditDB critical
    class LedgerDB,AuditDB immutable