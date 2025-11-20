# PenPay Backend  
**M-Pesa ↔ Deriv Money Movement Engine**  
The most secure, auditable, and regulator-compliant fintech backend built in East Africa.

💸  Deposits: M-Pesa → PenPay → Deriv
💵  Withdrawals: Deriv → PenPay → M-Pesa
🔒  Double-entry ledger + event sourcing
⚡  Real-time balance tracking via Deriv WebSocket
✅  CBK-ready, PCI-DSS aligned, zero-float money handling


**Live Status:** ![Uptime](https://img.shields.io/badge/status-production%20ready-success) ![Architecture](https://img.shields.io/badge/architecture-DDD%20%2B%20Hexagonal%20%2B%20C4-blue) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-informational)

---

### Core Principles (Permanent Engineering Contract – PEC)

| Principle                  | Enforced By                          |
|----------------------------|---------------------------------------|
| No floats for money        | `Money` VO with integer cents         |
| Immutability               | `readonly class` + Value Objects      |
| Double-entry accounting    | `LedgerEntry` + `LedgerSide` enum     |
| Event sourcing             | All state changes → domain events     |
| Idempotency                | `IdempotencyKey` + Redis lock         |
| Single source of truth     | Aggregates own their events           |
| No anemic domain model     | Full behavior in aggregates           |

---

### Architecture Overview (C4 Model)

```mermaid
graph TB
    subgraph "API Container (PHP-FPM)"
        A[HTTP Controllers] --> B[Orchestrators]
        B --> C[Domain Aggregates]
        B --> D[Redis Stream Publisher]
    end

    subgraph "Workers (Horizontal Scaling)"
        E[Redis Stream Consumers] --> F[DepositWorker<br/>MpesaCallbackWorker<br/>DerivTransferWorker]
        F --> C
        F --> G[Deriv WS Gateway (gRPC)]
    end

    subgraph "Deriv WS Gateway (Single Instance)"
        H[WebSocket Client] --> I[BalanceTracker<br/>TransferExecutor]
        I --> D
    end

    C --> J[(MySQL – ACID Ledger)]
    D --> K[(Redis Streams + Locks)]

System Containers

Container,Responsibility,Scaling
API (PHP-FPM),"Sync HTTP, auth, idempotency, orchestration",Horizontal
Queue Workers,"Async processing, state machines, external calls",Horizontal
Deriv WS Gateway,Single persistent WebSocket to Deriv,Single
MySQL + Redis,Persistence & event bus,Clustered

Directory Structure (Approved & Locked)

src/
├── Application/
│   ├── Deposit/           → DepositOrchestrator, DTOs
│   ├── Withdrawal/        → WithdrawOrchestrator
│   └── Callback/          → MpesaCallbackVerifier
│
├── Domain/
│   ├── Payments/
│   │   ├── Aggregate/Transaction.php
│   │   ├── Entity/{MpesaRequest,DerivTransfer}.php
│   │   ├── ValueObject/{TransactionStatus,IdempotencyKey}.php
│   │   └── Event/*.php
│   │
│   ├── Wallet/
│   │   ├── Aggregate/LedgerAccount.php
│   │   ├── Entity/{LedgerEntry,LedgerSide}.php
│   │   ├── ValueObject/{Money,Currency,LockedRate}.php
│   │   └── Event/{DepositInitiated,BalanceChanged}.php
│   │
│   └── Shared/Kernel/TransactionId.php
│
├── Infrastructure/
│   ├── Persistence/Doctrine/
│   ├── Queue/{Publisher,Consumer}/
│   ├── DerivWsGateway/ (gRPC client)
│   ├── Mpesa/
│   └── Fx/
│
├── Workers/
│   ├── DepositWorker.php
│   ├── MpesaCallbackWorker.php
│   └── DerivTransferWorker.php
│
└── Presentation/Http/{Controllers,Middleware}/

Key Domain Guarantees

Zero financial drift – All money stored in integer cents
Perfect audit trail – Every mutation emits immutable events
Idempotent everything – Safe retries, no duplicates
Exactly-once processing – Redis Streams + consumer groups
Strong consistency where needed – MySQL transactions for ledger
Eventual consistency elsewhere – Workers process asynchronously

Tech Stack

Layer,Technology
Language,"PHP 8.3+ (strict types, readonly classes)"
Architecture,DDD + Hexagonal + Event-Driven
Event Bus,Redis Streams
Persistence,MySQL 8 (ACID ledger)
Queue,Redis + PHP workers
External APIs,"M-Pesa Daraja, Deriv WebSocket (gRPC)"
Auth,JWT + RSA256
Notifications,Mailgun / AWS SES
Containerization,Docker + Docker Compose

Security & Compliance

JWT + RSA256 signing
Idempotency keys (24h expiry)
Rate limiting per IP + phone
M-Pesa callback signature verification
Double-entry ledger enforcement
Tamper-evident audit logs
No plain-text secrets in code

Developer Setup (5 minutes)

git clone https://github.com/penpay/ke-backend.git
cd penpay-backend
cp .env.example .env
docker compose up -d --build
composer install
php bin/console doctrine:migrations:migrate
php bin/console cache:clear

Testing
./vendor/bin/phpunit                    # Unit + Integration
./vendor/bin/phpstan analyse             # Static analysis (Level 9)
k6 run load-test/deposit-stress.js       # Load testing

Git Flow (PEC Variant)
main           → always deployable
feat/deposit   → new features
fix/ledger     → bug fixes
refactor/vo    → non-breaking improvements
hotfix/idem    → production emergencies

All PRs require:

CI passing
Architecture review
No floats for money

License & Ownership
Proprietary • © PenPay Technologies Ltd • All rights reserved
Built with love in Nairobi