🚀 PenPay Backend (PHP + DDD + C4 Architecture)

Enterprise-grade money-movement platform for Deriv ↔ M-Pesa transfers

⸻

📌 Overview

PenPay Backend is a high-security, high-availability financial orchestration platform enabling:
	•	💸 Deposits (M-Pesa → PenPay → Deriv)
	•	💵 Withdrawals (Deriv → PenPay → M-Pesa)
	•	🔔 Real-time balance tracking
	•	🎯 Secure Deriv WebSocket operations (single gateway container)
	•	🔄 Callback verification pipeline (M-Pesa → PenPay Workers → Ledger)

This backend follows:
	•	C4 Architecture Model (Level-1 → Level-3)
	•	DDD (Domain-Driven Design)
	•	Event-driven architecture (Redis Streams)
	•	PEC (Permanent Engineering Contract)
	•	Hexagonal architecture boundaries
	•	Enterprise patterns (Idempotency, CQRS-ish, Outbox-style consistency)

⸻

🏛 Architecture Summary

PenPay is composed of 4 major containers:

⸻

1️⃣ API Container (PHP-FPM)

Handles synchronous user requests:
	•	/deposit
	•	/withdraw
	•	/mpesa/callback

Responsibilities:
	•	HTTP controllers
	•	Authentication middleware (JWT)
	•	Idempotency
	•	Rate limiting
	•	Orchestrator services
	•	Publishing to Redis Streams
	•	Initial ledger posting (initiated state)

⸻

2️⃣ Queue Worker Container(s)

Horizontally scalable workers consuming Redis Streams:
	•	DepositWorker
	•	WithdrawWorker
	•	MpesaCallbackWorker
	•	DerivTransferWorker

Responsibilities:
	•	State machine transitions
	•	Double-entry ledger enforcement
	•	Calling Deriv WebSocket Gateway
	•	Calling M-Pesa Daraja
	•	Publishing events
	•	Writing immutable audit logs

⸻

3️⃣ Deriv WS Gateway (Single Instance)

A dedicated container holding the only persistent WebSocket connection to Deriv.

Responsibilities:
	•	Connection lifecycle
	•	Token management
	•	Balance subscription
	•	Transfer execution
	•	Publishing balance & transfer events

⸻

4️⃣ MySQL + Redis Infrastructure
	•	MySQL: ACID storage for ledger, transactions, audit
	•	Redis: Stream-based event bus for async pipelines
	•	Redis: Locking, rate-limit buckets, idempotency

⸻

🧱 C4 Level-3 Diagrams

API Container

flowchart TB
    subgraph API_Container ["API Container (PHP-FPM)"]
        direction TB

        DepositController[DepositController<br/>POST /deposit]
        WithdrawController[WithdrawController<br/>POST /withdraw]
        MpesaCallbackController[MpesaCallbackController<br/>POST /mpesa/callback]

        middleware1[IdempotencyMiddleware]
        middleware2[RateLimitMiddleware]
        middleware3[JwtAuthMiddleware]
        middleware4[AdminOnlyMiddleware]

        DepositOrchestrator[DepositOrchestrator]
        WithdrawOrchestrator[WithdrawOrchestrator]
        CallbackVerifier[MpesaCallbackVerifier]

        FxService[FX Service]
        LimitChecker[DailyLimitService]
        LedgerRecorder[LedgerRecorder]

        TransactionRepo[TransactionRepository]
        LedgerRepo[LedgerRepository]
        UserRepo[UserRepository]

        RedisPublisher[Redis Stream Publisher]
        Mailer[NotificationService]
    end

    DepositController --> middleware1 --> middleware2 --> middleware3
    DepositController --> DepositOrchestrator
    DepositOrchestrator --> FxService
    DepositOrchestrator --> LimitChecker
    DepositOrchestrator --> TransactionRepo
    DepositOrchestrator --> LedgerRecorder
    DepositOrchestrator --> RedisPublisher


Queue Workers
flowchart TB
    subgraph Workers ["Queue Workers (Autoscaling)"]
        Consumer[Redis Stream Consumer]

        DepositWorker[DepositWorker]
        MpesaCallbackWorker[MpesaCallbackWorker]
        DerivTransferWorker[DerivTransferWorker]

        DerivGatewayClient[gRPC: Deriv Gateway]
        MpesaClient[M-Pesa Client]

        TransactionService[TransactionService]
        LedgerService[LedgerService]

        Repo[Repositories]
    end

    Consumer --> DepositWorker
    Consumer --> MpesaCallbackWorker
    Consumer --> DerivTransferWorker

    DepositWorker --> TransactionService --> LedgerService --> Repo


Deriv WS Gateway

flowchart TB
    subgraph DerivGateway ["deriv-ws-gateway (Single Instance)"]
        WsClient[WebSocket Client]
        AuthManager[Token Manager]
        BalanceTracker[BalanceTracker]
        TransferExecutor[TransferExecutor]
        RedisPub[Redis Publisher]
        Health[Health Endpoint]
    end

    WsClient --> AuthManager
    WsClient --> BalanceTracker --> RedisPub
    WsClient --> TransferExecutor --> RedisPub


Directory Structure (Final + Approved)

src/
├── Application/
│   ├── Deposit/
│   │   ├── DepositOrchestrator.php
│   │   └── DTO/
│   │       └── DepositRequestDTO.php
│   ├── Withdrawal/
│   │   ├── WithdrawOrchestrator.php
│   │   └── DTO/
│   │       └── WithdrawRequestDTO.php
│   └── Callback/
│       └── MpesaCallbackVerifier.php
│
├── Domain/
│   ├── Wallet/
│   │   ├── Aggregate/
│   │   │   └── LedgerAccount.php
│   │   ├── Entity/
│   │   │   └── LedgerEntry.php
│   │   ├── ValueObject/
│   │   │   ├── Money.php
│   │   │   ├── LockedRate.php
│   │   │   └── TransactionId.php
│   │   ├── Service/
│   │   │   ├── LedgerRecorder.php
│   │   │   └── DailyLimitChecker.php
│   │   ├── Repository/
│   │   │   └── LedgerRepositoryInterface.php
│   │   └── Event/
│   │       ├── DepositInitiated.php
│   │       └── BalanceChanged.php
│   │
│   ├── Payments/
│   │   ├── Aggregate/
│   │   │   └── Transaction.php
│   │   ├── Entity/
│   │   │   ├── MpesaRequest.php
│   │   │   └── DerivTransfer.php
│   │   ├── ValueObject/
│   │   │   ├── TransactionStatus.php
│   │   │   └── IdempotencyKey.php
│   │   ├── Repository/
│   │   │   └── TransactionRepositoryInterface.php
│   │   └── Event/
│   │       ├── TransactionCreated.php
│   │       ├── MpesaCallbackReceived.php
│   │       └── TransactionCompleted.php
│   │
│   └── Shared/
│       └── Kernel/
│           └── TransactionId.php
│
├── Infrastructure/
│   ├── Persistence/
│   │   └── Doctrine/
│   │       ├── Entity/
│   │       └── Repository/
│   ├── Queue/
│   │   ├── Publisher/
│   │   └── Consumer/
│   ├── DerivWsGateway/
│   ├── Mpesa/
│   ├── Fx/
│   ├── Notification/
│   └── Audit/
│
├── Workers/
│   ├── DepositWorker.php
│   ├── WithdrawWorker.php
│   ├── MpesaCallbackWorker.php
│   └── DerivTransferWorker.php
│
├── Presentation/
│   └── Http/
│       ├── Controllers/
│       └── Middleware/
│
├── bootstrap.php
└── composer.json

⚙️ Tech Stack
	•	PHP 8.2+
	•	Redis Streams
	•	MySQL 8
	•	Docker
	•	gRPC
	•	Composer + PSR-4
	•	PHPMailer
	•	JWT Auth

⸻

🧪 Testing
	•	PHPUnit (unit + integration)
	•	Contract tests for Workers and Deriv Gateway
	•	Load testing: k6 or Locust
	•	DB migrations: Doctrine Migrations

⸻

🔐 Security
	•	Strong idempotency
	•	JWT validation
	•	IP-restricted admin routes
	•	Redis rate limiting
	•	Double-entry ledger enforcement
	•	Signed M-Pesa callbacks
	•	Tamper-evident audit trails

⸻

🧬 Git Branching Model

We use GitHub Flow (PEC variant):
	•	main → always deployable
	•	feat/<name> → new features
	•	fix/<name> → bug fixes
	•	refactor/<name> → non-breaking improvements
	•	hotfix/<name> → urgent production issues

PRs require:
	•	CI pass
	•	Code review
	•	Architecture compliance

🧰 Developer Setup

composer install
cp .env.example .env
docker compose up -d
php artisan migrate   # if using Doctrine, run doctrine:migrations:migrate