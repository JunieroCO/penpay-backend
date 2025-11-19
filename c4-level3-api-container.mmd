flowchart TB
    subgraph API_Container ["API Container (PHP-FPM)"]
        direction TB

        %% Controllers
        DepositController[DepositController<br/>POST /deposit]
        WithdrawController[WithdrawController<br/>POST /withdraw]
        MpesaCallbackController[MpesaCallbackController<br/>POST /mpesa/callback]

        %% Middlewares (applied in order)
        middleware1[IdempotencyMiddleware<br/>Idempotency-Key header]
        middleware2[RateLimitMiddleware<br/>Redis + 20/min per user]
        middleware3[JwtAuthMiddleware<br/>Validate access token]
        middleware4[AdminOnlyMiddleware<br/>IP + Role guard]

        %% Application Services (Orchestrators)
        DepositOrchestrator[DepositOrchestrator<br/>Coordinates domain + workers]
        WithdrawOrchestrator[WithdrawOrchestrator]
        CallbackVerifier[MpesaCallbackVerifier<br/>Signature + origin check]

        %% Domain Services (pure PHP, no infra)
        FxService[FX Service<br/>Lock rate +5 KES]
        LimitChecker[DailyLimitService<br/>$2000/day atomic]
        LedgerRecorder[LedgerRecorder<br/>Double-entry posting]

        %% Repositories
        TransactionRepo[TransactionRepository]
        LedgerRepo[LedgerRepository]
        UserRepo[UserRepository]

        %% Outbound (infra)
        RedisPublisher[Redis Stream Publisher]
        Mailer[NotificationService]
    end

    %% Flow — Deposit Happy Path
    DepositController --> middleware1 --> middleware2 --> middleware3
    DepositController --> DepositOrchestrator
    DepositOrchestrator --> FxService
    DepositOrchestrator --> LimitChecker
    DepositOrchestrator --> TransactionRepo::beginPending()
    DepositOrchestrator --> LedgerRecorder::recordInitiated()
    DepositOrchestrator --> RedisPublisher --> "deposit.initiated"
    DepositOrchestrator --> Mailer

    %% Flow — M-Pesa Callback
    MpesaCallbackController --> CallbackVerifier
    CallbackVerifier --> middleware1
    CallbackVerifier --> TransactionRepo::findByMpesaId()
    CallbackVerifier --> RedisPublisher --> "mpesa.callback.received"

    classDef controller fill:#ff9800,color:white
    classDef middleware fill:#f44336,color:white
    classDef service fill:#4caf50,color:white
    classDef domain fill:#2196f3,color:white
    classDef repo fill:#9c27b0,color:white
    classDef infra fill:#607d8b,color:white

    class DepositController,WithdrawController,MpesaCallbackController controller
    class middleware1,middleware2,middleware3,middleware4 middleware
    class DepositOrchestrator,WithdrawOrchestrator,CallbackVerifier service
    class FxService,LimitChecker,LedgerRecorder domain
    class TransactionRepo,LedgerRepo,UserRepo repo
    class RedisPublisher,Mailer infra