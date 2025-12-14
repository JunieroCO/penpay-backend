# PenPay Backend  
**M-Pesa ↔ Deriv Money Movement Engine**  
The most secure, auditable, and regulator-compliant fintech backend built in East Africa.

💸  **Deposits**: M-Pesa → PenPay → Deriv  
💵  **Withdrawals**: Deriv → PenPay → M-Pesa  
🔒  **Double-entry ledger** + event sourcing  
⚡  **Real-time balance tracking** via Deriv WebSocket  
✅  **CBK-ready**, PCI-DSS aligned, zero-float money handling

**Project Status:** ![Phase](https://img.shields.io/badge/phase-0%20foundations-yellow) ![Architecture](https://img.shields.io/badge/architecture-DDD%20%2B%20Hexagonal%20%2B%20C4-blue) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-informational) ![Compliance](https://img.shields.io/badge/PEC-compliant-success)

> **📋 Current Phase**: Phase 0 — Foundations (~85% complete)  
> **📚 Documentation**: See [`doc/`](./doc/) for architecture, diagrams, and project plan  
> **🎯 Governance**: All development follows PEC (Permanent Engineering Contract) standards

---

## 📚 Documentation & Architecture

**All architecture decisions are documented and versioned:**

- **[PEC Architecture Specification](./doc/architecture/PEC-Architecture.md)** — Canonical architecture reference
  - Ubiquitous Language & Naming Standards (Section 3)
  - Bounded Contexts, Domain Model, Layered Architecture
  - Security, Compliance, and Non-Functional Requirements
  
- **[Master Project Plan](./doc/PROJECT_PLAN.md)** — Phase-by-phase development roadmap
  - Current status: Phase 0 — Foundations
  - Governance rules and development standards
  
- **[C4 Diagrams](./doc/diagrams/)** — System architecture visualizations
  - System Context (Level 0)
  - Container Architecture (Level 1)
  - Component Diagrams (Level 2)
  - Code-level details (Level 3)
  - Sequence diagrams (deposit/withdrawal flows)
  
- **[Transaction Factory Usage](./doc/TRANSACTION_FACTORY_USAGE.md)** — Precision-safe transaction creation guide

---

### Core Principles (Permanent Engineering Contract – PEC)

**All code must adhere to these non-negotiable principles:**

| Principle                  | Enforced By                          | Reference |
|----------------------------|---------------------------------------|-----------|
| **No floats for money**    | `Money` VO with integer cents         | [PEC §3.1.4](./doc/architecture/PEC-Architecture.md#314-money--value) |
| **Immutability**           | `readonly class` + Value Objects      | Domain layer contracts |
| **Double-entry accounting**| `LedgerEntry` + `LedgerSide` enum     | [PEC §2.3](./doc/architecture/PEC-Architecture.md#23-wallet-context) |
| **Event sourcing**         | All state changes → domain events     | Outbox Pattern |
| **Idempotency**            | `IdempotencyKey` + Redis lock         | [PEC §10](./doc/architecture/PEC-Architecture.md#10-idempotency-layer) |
| **Single source of truth** | Aggregates own their events           | DDD aggregates |
| **No anemic domain model** | Full behavior in aggregates           | Rich domain model |
| **Ubiquitous Language**    | Canonical terminology across codebase | [PEC §3](./doc/architecture/PEC-Architecture.md#3-ubiquitous-language-ul--naming-standard) |

---

## 🧩 Project Governance Rules

**Every contributor (LLM or human) must follow these rules:**

1. **No code before architecture** — Architecture diagrams must be approved first
2. **No feature without diagrams** — Features require sequence/flow diagrams
3. **No endpoint without specification** — API endpoints need OpenAPI specs
4. **No domain entity without value-object rules** — Follow UL naming standards
5. **No shortcuts to "move fast"** — Quality over speed
6. **Pushback when user requests harmful design** — Advocate for best practices
7. **If unclear → ask questions** — Clarify before implementing
8. **PEC compliance is mandatory** — All code must follow PEC standards
9. **All decisions are logged in ADR format** — Architecture Decision Records
10. **Security is non-negotiable** — Security-first mindset always

See [Project Plan](./doc/PROJECT_PLAN.md#-project-governance-rules-mandatory) for details.

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

**System Containers** (see [C4 Level 1](./doc/diagrams/c4-level1-containers.md))

| Container | Responsibility | Scaling |
|-----------|---------------|---------|
| API (PHP-FPM) | Sync HTTP, auth, idempotency, orchestration | Horizontal |
| Queue Workers | Async processing, state machines, external calls | Horizontal |
| Deriv WS Gateway | Single persistent WebSocket to Deriv | Single |
| MySQL + Redis | Persistence & event bus | Clustered |

---

### Directory Structure (PEC-Compliant)

**All directory names follow [Ubiquitous Language & Naming Standards](./doc/architecture/PEC-Architecture.md#32-naming-rules-what-we-call-things-from-now-on):**

```
src/
├── Application/                    # Application Layer (CQRS + Saga)
│   ├── Deposit/                   → DepositOrchestrator
│   ├── Withdrawal/                → WithdrawalOrchestrator
│   ├── Auth/                      → AuthService
│   └── Callback/                  → MpesaCallbackVerifier
│
├── Domain/                        # Domain Layer (Aggregates, VOs, Events)
│   ├── User/                      # Identity Context
│   │   ├── Aggregate/User.php
│   │   ├── Entity/Device.php
│   │   ├── ValueObject/{Email,PhoneNumber,DerivLoginId,KycSnapshot,PasswordHash}.php
│   │   ├── Repository/UserRepositoryInterface.php
│   │   └── Event/{UserRegistered,PasswordChanged,DeviceAdded}.php
│   │
│   ├── Payments/                  # Payments Context
│   │   ├── Aggregate/Transaction.php  # Unified (DEPOSIT + WITHDRAWAL)
│   │   ├── Entity/{MpesaRequest,MpesaDisbursement,DerivTransfer,DerivWithdrawal}.php
│   │   ├── ValueObject/{TransactionStatus,TransactionType,IdempotencyKey}.php
│   │   ├── Factory/TransactionFactory.php
│   │   ├── Repository/TransactionRepositoryInterface.php
│   │   └── Event/{TransactionCreated,TransactionCompleted,MpesaCallbackReceived}.php
│   │
│   ├── Wallet/                    # Wallet Context
│   │   ├── Aggregate/LedgerAccount.php
│   │   ├── Entity/LedgerEntry.php
│   │   ├── ValueObject/{Money,Currency,LockedRate,LedgerSide}.php
│   │   └── Event/{DepositInitiated,BalanceChanged}.php
│   │
│   └── Shared/                    # Shared Kernel
│       └── Kernel/{TransactionId,UserId}.php
│
├── Infrastructure/                # Infrastructure Layer (Adapters)
│   ├── Persistence/               → MySQL repositories
│   ├── Repository/{User,Payments,Wallet}/ → Concrete implementations
│   ├── Queue/                     → Redis Streams
│   ├── DerivWsGateway/            → WebSocket client (gRPC)
│   ├── Mpesa/                     → Daraja API adapter
│   ├── Fx/                        → FX rate service
│   └── Security/                  → JWT, encryption
│
├── Workers/                       # Background Worker Layer
│   ├── Deposit/                   → DepositWorker, DerivTransferWorker
│   └── Withdrawal/                → DerivDebitWorker, MpesaDisbursementWorker
│
└── Presentation/                  # Presentation Layer
    └── Http/                      → Controllers, Middleware
```

**Naming Conventions** (see [PEC §3.2](./doc/architecture/PEC-Architecture.md#32-naming-rules-what-we-call-things-from-now-on)):
- Repositories: Singular nouns (`UserRepository`, `TransactionRepository`)
- Orchestrators: Verbs (`DepositOrchestrator`, `WithdrawalOrchestrator`)
- Workers: Singular (`DepositWorker`, `MpesaCallbackWorker`)
- Database tables: Plural snake_case (`users`, `transactions`, `mpesa_requests`)

---

### Key Domain Guarantees

| Guarantee | Implementation | Reference |
|-----------|---------------|-----------|
| **Zero financial drift** | All money stored in integer cents (BIGINT) | [PEC §3.4.2](./doc/architecture/PEC-Architecture.md#342-storage-rules-non-negotiable) |
| **Perfect audit trail** | Every mutation emits immutable events | Outbox Pattern |
| **Idempotent everything** | Safe retries, no duplicates | [PEC §10](./doc/architecture/PEC-Architecture.md#10-idempotency-layer) |
| **Exactly-once processing** | Redis Streams + consumer groups | Event-driven architecture |
| **Strong consistency** | MySQL transactions for ledger | Double-entry bookkeeping |
| **Eventual consistency** | Workers process asynchronously | Saga pattern |

---

### Tech Stack

| Layer | Technology | Notes |
|-------|------------|-------|
| **Language** | PHP 8.3+ | Strict types, readonly classes |
| **Architecture** | DDD + Hexagonal + Event-Driven | [PEC Architecture](./doc/architecture/PEC-Architecture.md) |
| **Event Bus** | Redis Streams | Exactly-once delivery |
| **Persistence** | MySQL 8 | ACID-compliant ledger |
| **Queue** | Redis + PHP workers | Horizontal scaling |
| **External APIs** | M-Pesa Daraja, Deriv WebSocket | gRPC for Deriv gateway |
| **Auth** | JWT + RSA256 | Short TTL, HttpOnly tokens |
| **Notifications** | Mailgun / AWS SES | Email notifications |
| **Containerization** | Docker + Docker Compose | Development & deployment |

---

### Security & Compliance

**Security Requirements** (see [PEC §13](./doc/architecture/PEC-Architecture.md#13-security-requirements)):

- ✅ **JWT + RSA256 signing** — Short TTL, HttpOnly tokens
- ✅ **Idempotency keys** — 24h expiry, hashed storage
- ✅ **Rate limiting** — Per IP + phone number
- ✅ **M-Pesa callback signature verification** — Request validation
- ✅ **Double-entry ledger enforcement** — Immutable audit trail
- ✅ **Tamper-evident audit logs** — Write-once, 3-year retention
- ✅ **No plain-text secrets** — Environment variables only
- ✅ **Argon2ID password hashing** — Secure password storage
- ✅ **Encrypted PII** — Where required by regulation

**Compliance Requirements** (see [PEC §14](./doc/architecture/PEC-Architecture.md#14-compliance-requirements)):

- ✅ Anti-Money Laundering (AML) guidelines
- ✅ KYC enforcement
- ✅ Data sovereignty rules
- ✅ Immutable ledger (append-only)
- ✅ Financial events audit-logged

---

## 🚀 Developer Setup

### Prerequisites

- PHP 8.3+
- Composer
- Docker & Docker Compose
- MySQL 8
- Redis

### Quick Start (5 minutes)

```bash
git clone <repository-url>
cd penpay-backend
cp .env.example .env
docker compose up -d --build
composer install
php migrate.php                    # Run migrations
php bin/console cache:clear        # Clear cache
```

### Environment Configuration

Ensure `.env` includes:
- Database credentials (MySQL)
- Redis connection
- M-Pesa Daraja API credentials
- Deriv WebSocket credentials
- JWT signing keys
- SMTP credentials

---

## 🧪 Testing

```bash
# Unit + Integration tests
./vendor/bin/phpunit

# Static analysis (Level 9)
./vendor/bin/phpstan analyse

# Code style
./vendor/bin/php-cs-fixer fix --dry-run

# Load testing
k6 run load-test/deposit-stress.js
```

**Test Requirements**:
- All tests must pass (`APP_ENV=testing`)
- Code coverage reporting
- Integration tests for critical flows
- See [PEC §12](./doc/architecture/PEC-Architecture.md#12-testing-architecture)

---

## 🔀 Git Flow (PEC Variant)

**Branches**:
- `main` → always deployable (protected)
- `feat/<feature>` → new features (e.g., `feat/deposit`)
- `fix/<issue>` → bug fixes (e.g., `fix/ledger`)
- `refactor/<area>` → non-breaking improvements (e.g., `refactor/vo`)
- `hotfix/<issue>` → production emergencies

**All PRs require**:
1. ✅ CI passing (tests, static analysis, linting)
2. ✅ Architecture review (diagrams updated if needed)
3. ✅ No floats for money (must use cents)
4. ✅ PEC compliance check
5. ✅ Documentation updated
6. ✅ Follows [Ubiquitous Language](./doc/architecture/PEC-Architecture.md#3-ubiquitous-language-ul--naming-standard)

---

## 📖 Key Concepts

### Ubiquitous Language (UL)

**All code, APIs, and documentation must use canonical terms** (see [PEC §3](./doc/architecture/PEC-Architecture.md#3-ubiquitous-language-ul--naming-standard)):

- **User** (not `Customer`, `Account`, `Member`)
- **Deriv Login ID** (not `DerivUserId`, `DerivAccountId`)
- **Transaction** (unified aggregate for DEPOSIT + WITHDRAWAL)
- **MpesaRequest** (deposit callback)
- **MpesaDisbursement** (withdrawal payout)
- **IdempotencyKey** (not `IdempotentKey`, `RequestId`)

### Money Handling

**Never use floats** — Always use:
- Integer cents (`BIGINT` in DB)
- `Money` value object with cents
- See [Transaction Factory Usage](./doc/TRANSACTION_FACTORY_USAGE.md)

### Bounded Contexts

1. **Identity Context** — User, Device, KYC
2. **Payments Context** — Transactions, M-Pesa, Deriv
3. **Wallet Context** — Ledger, Balance
4. **Deriv Integration Context** — WebSocket gateway
5. **Mpesa Integration Context** — Daraja API
6. **Shared Kernel** — Common VOs, events

See [PEC §2](./doc/architecture/PEC-Architecture.md#2-bounded-contexts) for details.

---

## 📋 Project Status

**Current Phase**: [Phase 0 — Foundations](./doc/PROJECT_PLAN.md#-phase-0--foundations-we-are-here) (~85% complete)

### Recent Updates

- ✅ PEC Architecture Specification finalized
- ✅ Ubiquitous Language & Naming Standards documented
- ✅ C4 diagrams (Level 0-3) completed
- ✅ Domain Model and event flows documented
- ⏳ API High-Level Specification (in progress)
- ⏳ DevOps & Deployment Plan (in progress)

See [Project Plan](./doc/PROJECT_PLAN.md) for full roadmap.

---

## 🤝 Contributing

**Before contributing, read:**
1. [Project Plan](./doc/PROJECT_PLAN.md) — Development phases and governance
2. [PEC Architecture](./doc/architecture/PEC-Architecture.md) — Architecture standards
3. [Ubiquitous Language](./doc/architecture/PEC-Architecture.md#3-ubiquitous-language-ul--naming-standard) — Naming conventions

**Questions?** Open an issue or contact the Principal Engineering team.

---

## 📄 License & Ownership

**Proprietary** • © PenPay Technologies Ltd • All rights reserved  
Built with ❤️ in Nairobi, Kenya

---

## 🔗 Quick Links

- [PEC Architecture Specification](./doc/architecture/PEC-Architecture.md)
- [Master Project Plan](./doc/PROJECT_PLAN.md)
- [C4 Diagrams](./doc/diagrams/)
- [Transaction Factory Usage](./doc/TRANSACTION_FACTORY_USAGE.md)