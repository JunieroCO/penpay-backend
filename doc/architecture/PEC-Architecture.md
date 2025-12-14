📄 PEC-Architecture.md

PenPay Enterprise Architecture Specification

Version: 1.0
Status: Adopted
Last Updated: 2025-12-07
Owner: Principal Engineering (PEC)

⸻

1. Overview

PenPay is a financial application enabling:
	•	deposits from M-Pesa to Deriv
	•	withdrawals from Deriv to M-Pesa
	•	user identity management
	•	audit & regulatory compliance

PenPay uses a Composite Modern Architecture combining:
	•	Domain-Driven Design (DDD)
	•	CQRS
	•	Event-Driven Architecture (EDA)
	•	Saga Pattern / Process Managers
	•	Hexagonal Architecture (Ports & Adapters)
	•	Outbox Pattern (Guaranteed Event Delivery)
	•	Idempotency Pattern
	•	Lightweight Event-Sourcing Principles
	•	Read-Optimized Views

This document is the canonical architecture reference for all development.

⸻

2. Bounded Contexts

PenPay contains six (6) bounded contexts:

2.1 Identity Context

Manages:
	•	user registration
	•	login & authentication
	•	device tracking
	•	phone & email verification
	•	KYC snapshot
	•	Deriv login binding

2.2 Payments Context

Defines:
	•	deposit transaction model
	•	withdrawal transaction model
	•	transaction life cycle
	•	MPesa + Deriv flow orchestration
	•	FX rate locking
	•	failure recovery

2.3 Wallet Context

Includes:
	•	ledger accounts
	•	ledger entries (double-entry)
	•	balance projections

2.4 Deriv Integration Context

Handles:
	•	Deriv WebSocket API
	•	transfers (deposit/withdrawal)
	•	retry logic
	•	idempotency
	•	call correlation

2.5 Mpesa Integration Context

Handles:
	•	STK push
	•	B2C disbursements
	•	callback validation
	•	pairing with transactions

2.6 Shared Kernel

Contains:
	•	domain events
	•	value objects
	•	identifiers
	•	money & currency models
	•	common interfaces
	•	exceptions

⸻

3. Ubiquitous Language (UL) & Naming Standard

**RULE**: From this point forward, PenPay must use the Canonical Ubiquitous Language defined in this document; all new code, tables, APIs, and tests must use these names (or an approved alias backed by an explicit ADR). Any rename must be treated as a breaking change and requires a migration + test update + ADR entry.

This section prevents "language drift" — the hidden cost that slowly makes a codebase feel like a maze. The vocabulary below is treated as an API contract across domain + infrastructure + persistence + endpoints.

⸻

3.1 Core Domain Terms (Single Meaning, One Name)

**3.1.1 User / Identity**

| Term | Definition | Value Object / Entity |
|------|------------|----------------------|
| **User** | The person using PenPay (root aggregate) | `Domain/User/Aggregate/User.php` |
| **UserId** | UUIDv7 identity value object | `Domain/Shared/Kernel/UserId` |
| **Contact** | Email + Phone (E.164) — these are the canonical contacts | `Email`, `PhoneNumber` (VO) |
| **Device** | Authenticated client instance linked to a user (max 2 per user) | `Domain/User/Entity/Device.php` |

**Rule**: Do not invent new "login" fields. Email and Phone (E.164) are the canonical contacts.

**3.1.2 Deriv (Trading Account)**

| Term | Definition | Value Object |
|------|------------|--------------|
| **Deriv Login ID** | The Deriv account identifier | `Domain/User/ValueObject/DerivLoginId.php` |
| **Deriv Account** | The single supported USD account for the user (one per user in v1) | Stored in `deriv_accounts` table |

**Rule**: "One user ⇄ one USD Deriv account" is the invariant for this release.

**3.1.3 KYC (Regulatory Profile Snapshot)**

| Term | Definition | Value Object |
|------|------------|--------------|
| **KycSnapshot** | The authoritative snapshot of the user's Deriv KYC settings (from `get_settings` API) | `Domain/User/ValueObject/KycSnapshot.php` |

**Rule**: KYC state is always stored as one snapshot (not scattered across many unrelated tables/fields).

**3.1.4 Money / Value**

| Term | Definition | Value Object |
|------|------------|--------------|
| **Money** | (amount + currency) value object — no floats; cents only in DB | `Domain/Wallet/ValueObject/Money.php` |
| **Currency** | ISO 4217 code (USD or KES in this release) | `Domain/Wallet/ValueObject/Currency.php` |
| **LockedRate** | FX rate used for transaction (locked at transaction initiation) | `Domain/Wallet/ValueObject/LockedRate.php` |

**Rule**: Never use floats for money. Always use cents (integers) or string representations.

**3.1.5 Payments (One Conceptual "Transaction" Family)**

We keep **one Transaction concept** with two concrete business flows:

| Term | Definition | Aggregate / Flow |
|------|------------|------------------|
| **Transaction** | Financial operation aggregate root | `Domain/Payments/Aggregate/Transaction.php` |
| **Deposit Transaction** | Customer sends KES via M-Pesa → we credit wallet (after M-Pesa callback) → we fund Deriv (Deriv transfer) | Type: `DEPOSIT` |
| **Withdrawal Transaction** | We debit Deriv (Deriv debit) → we disburse KES via M-Pesa (B2C) | Type: `WITHDRAWAL` |

**Rule**: The repository (`TransactionRepository`) stores both kinds (deposit + withdrawal) using:
- `type` field (`DEPOSIT` / `WITHDRAWAL`)
- Flow-specific "attachments" stored in dedicated tables (`mpesa_requests`, `mpesa_disbursements`, `deriv_transfers`, `deriv_withdrawals`)

**3.1.6 External Artifacts (Store as "Evidence" Records)**

| Term | Definition | Entity / Table |
|------|------------|----------------|
| **MpesaRequest** | The inbound M-Pesa callback (deposit side) | `Domain/Payments/Entity/MpesaRequest.php` → `mpesa_requests` table |
| **MpesaDisbursement** | The outbound M-Pesa payment (withdrawal side) | `Domain/Payments/Entity/MpesaDisbursement.php` → `mpesa_disbursements` table |
| **DerivTransfer** | Deriv transfer record for deposit (funding) | `Domain/Payments/Entity/DerivTransfer.php` → `deriv_transfers` table |
| **DerivWithdrawal** | Deriv debit record for withdrawal (settlement from Deriv) | `Domain/Payments/Entity/DerivWithdrawal.php` → `deriv_withdrawals` table |

**3.1.7 Idempotency (Request Safety)**

| Term | Definition | Value Object |
|------|------------|--------------|
| **IdempotencyKey** | Client-provided key (stored hashed) used to guarantee "one business action once" | `Domain/Payments/ValueObject/IdempotencyKey.php` |

**Rule**: Required for deposits, withdrawals, M-Pesa callbacks, Deriv callbacks, and worker retry steps.

**3.1.8 Process Management**

| Term | Definition |
|------|------------|
| **Saga** | Process manager controlling long workflows (deposit/withdrawal orchestrators) |
| **Orchestrator** | Application service coordinating domain + infrastructure (e.g., `DepositOrchestrator`, `WithdrawalOrchestrator`) |
| **Outbox Event** | Domain event awaiting guaranteed delivery (stored in `domain_events` table) |

⸻

3.2 Naming Rules (What We Call Things From Now On)

**3.2.1 Directory & File Naming (Must Be Consistent)**

**Domain Layer:**
```
Domain/<Context>/Aggregate/<Aggregate>.php
  Example: Domain/Payments/Aggregate/Transaction.php
  Example: Domain/User/Aggregate/User.php

Domain/<Context>/ValueObject/<Name>.php
  Example: Domain/User/ValueObject/DerivLoginId.php
  Example: Domain/Wallet/ValueObject/Money.php

Domain/<Context>/Entity/<Name>.php
  Example: Domain/User/Entity/Device.php
  Example: Domain/Payments/Entity/MpesaRequest.php

Domain/<Context>/Repository/<Name>RepositoryInterface.php
  Example: Domain/User/Repository/UserRepositoryInterface.php
  Example: Domain/Payments/Repository/TransactionRepositoryInterface.php

Domain/<Context>/Factory/<Name>Factory.php
  (Only when reconstitution/creation logic is non-trivial)
  Example: Domain/User/Factory/UserFactory.php
  Example: Domain/Payments/Factory/TransactionFactory.php

Domain/<Context>/Event/<Name>.php
  Example: Domain/User/Event/UserRegistered.php
  Example: Domain/Payments/Event/TransactionCreated.php

Domain/<Context>/Exception/<Name>.php
  Example: Domain/User/Exception/UserNotFoundException.php
```

**Infrastructure Layer:**
```
Infrastructure/Repository/<Context>/<Name>Repository.php
  Example: Infrastructure/Repository/User/UserRepository.php
  Example: Infrastructure/Repository/Payments/Transaction/TransactionWriteRepository.php

Infrastructure/<Integration>/<Concrete>.php
  Example: Infrastructure/DerivWsGateway/WsClient.php
  Example: Infrastructure/Mpesa/Gateway/MpesaGateway.php
  Example: Infrastructure/Deriv/HttpClient.php
```

**Application Layer:**
```
Application/<Context>/<Name>Orchestrator.php
  Example: Application/Deposit/DepositOrchestrator.php
  Example: Application/Withdrawal/WithdrawalOrchestrator.php

Application/<Context>/<Name>Service.php
  Example: Application/Auth/AuthService.php

Application/<Context>/Command/<Name>Command.php
Application/<Context>/Query/<Name>Query.php
```

**Workers:**
```
Workers/<Context>/<Name>Worker.php
  Example: Workers/Deposit/DepositWorker.php
  Example: Workers/Withdrawal/WithdrawalWorker.php
```

**3.2.2 "Verb vs Noun" Rule (Prevents Rename Chaos)**

| Pattern | Naming | Examples |
|---------|--------|----------|
| **Repositories** | Nouns (singular) | `UserRepository`, `TransactionRepository` |
| **Services/Orchestrators** | Verbs or "Orchestrator" | `DepositOrchestrator`, `WithdrawalOrchestrator`, `AuthService` |
| **Gateways** | External boundary | `DerivDepositGateway`, `DerivWithdrawalGateway`, `MpesaGateway` |
| **Workers** | Background processors | `DepositWorker`, `DerivTransferWorker`, `MpesaDisbursementWorker` |
| **Factories** | Factory suffix | `TransactionFactory`, `UserFactory` |

**3.2.3 Canonical Pluralization (Stops the "What Is This File?" Problem)**

- **Repository** (singular): `TransactionRepository`, not `TransactionsRepository`
- **Orchestrator** (singular): `DepositOrchestrator`, not `DepositsOrchestrator`
- **Gateway** (singular): `MpesaGateway`, not `MpesaGateways`
- **Worker** (singular): `DepositWorker`, not `DepositsWorker`
- **Database tables**: snake_case and plural (e.g., `users`, `transactions`, `deriv_accounts`, `mpesa_requests`)

⸻

3.3 Data Model Alignment (Current Code → UL Mapping)

**3.3.1 User Aggregate (What Must Remain Stable)**

`Domain\User\Aggregate\User` = the single source of truth for identity + credentials + device bindings.

**Fixed Vocabulary:**
- `UserId` (UUIDv7)
- `Email` (VO)
- `PhoneNumber` (VO - E.164 format)
- `DerivLoginId` (VO)
- `KycSnapshot` (VO - immutable)
- `PasswordHash` (VO)
- `Device` (Entity - max 2 per user)

**3.3.2 Payments Transactions (Unified Aggregate)**

You have one `Transaction` aggregate that handles both deposit and withdrawal flows using:
- `type` field (`TransactionType::DEPOSIT` / `TransactionType::WITHDRAWAL`)
- Flow-specific "attachments" via entities:
  - `MpesaRequest` (for deposits)
  - `MpesaDisbursement` (for withdrawals)
  - `DerivTransfer` (for deposit funding)
  - `DerivWithdrawal` (for withdrawal debiting)

**This removes the "two transaction worlds" confusion while keeping domain logic clean.**

⸻

3.4 Database Naming (To Stop Renames Mid-Stream)

**3.4.1 Table Set (Normalized, Minimal Duplication)**

| Table | Purpose | Notes |
|-------|---------|-------|
| `users` | Identity core | Primary user table |
| `user_profile` | KYC/profile attributes that are NOT secrets | Extended profile data |
| `user_compliance` | Regulatory flags + tax-related fields | Compliance status |
| `user_address` | Postal address fields | Address information |
| `user_phone_verification` | Phone + verified flag | Phone verification state |
| `deriv_accounts` | One row per user for USD; enforce unique per user + currency | Deriv account binding |
| `transactions` | Unified deposit/withdrawal ledger entry | Single transaction table with `type` field |
| `mpesa_requests` | Deposit callback record | Evidence of M-Pesa payment received |
| `mpesa_disbursements` | Withdrawal payout record | Evidence of M-Pesa payment sent |
| `deriv_transfers` | Deposit funding record | Evidence of Deriv credit |
| `deriv_withdrawals` | Withdrawal debit record | Evidence of Deriv debit |
| `idempotency_keys` | Hashed key registry | Idempotency tracking |
| `domain_events` | Outbox for guaranteed delivery | Event sourcing outbox |
| `audit_logs` | Append-only audit | Compliance audit trail |

**3.4.2 Storage Rules (Non-Negotiable)**

- **Money**: `BIGINT` cents (no `DECIMAL` for amounts)
- **Secrets**: Never stored raw (token → hash or secret store)
- **Timestamps**: UTC (`TIMESTAMP` with explicit semantics)
- **UUIDs**: UUIDv7 for all aggregate IDs
- **Foreign Keys**: Reference `users.id` as `user_id` (snake_case)

⸻

3.5 API / JSON Naming (So Frontend + Backend Never Drift)

**Use one style and stick to it:**

- **JSON keys**: `snake_case` (matches current backend style and avoids "loginId vs loginid" inconsistencies)
- **API params**: Map directly to domain names
  - `deriv_login_id` (not `derivLoginId`, `derivUserId`, `derivAccountId`)
  - `idempotency_key` (not `idempotencyKey`, `idempotentKey`)
  - `amount_cents` (not `amountCents`, `amountInCents`)
  - `currency` (always ISO 4217: `USD`, `KES`)
  - `user_id` (UUIDv7 string)

**Example API Request:**
```json
{
  "amount_cents": 10000,
  "currency": "USD",
  "deriv_login_id": "CR123456",
  "idempotency_key": "req-abc-123-def-456"
}
```

⸻

3.6 What This Changes (Practical "Stop Doing" List)

From this point:
1. **No new "equivalent" names**: 
   - ❌ `deriv_user_id` vs `deriv_login_id` → pick one (`deriv_login_id`)
   - ❌ `login_email` vs `email` → use `email` (canonical contact)
   - Normalize everything to the UL term
2. **No new "user info" tables** that overlap existing ones:
   - New fields must fit into `user_profile` / `user_compliance` / `user_address` buckets
   - Do not create `user_preferences`, `user_settings`, etc. without PEC approval
3. **No new "transaction" types** beyond the two core flows (`DEPOSIT` / `WITHDRAWAL`):
   - Any new behavior must be modeled as an event or attachment record
   - Do not create a new aggregate unless it represents a new bounded context
4. **No float-based money calculations**: Always use cents (integers) or strings
5. **No repository method names that violate UL**: Use `findByIdempotencyKey()`, not `findByKey()`, `getByIdempotency()`, etc.

⸻

3.7 Change Control for UL

Any new terms or naming changes must be:
1. Proposed via PEC RFC
2. Reviewed by Principal Engineer
3. Approved before code change
4. Documented in this section
5. Migrated across all affected code, tests, schemas, and APIs

**No new terms may be introduced without PEC approval.**


⸻

4. Layered Architecture

PenPay uses a strict 4-layer architecture:

┌──────────────────────────────────────────────┐
│ 1. Application Layer                         │
│ Orchestrators, Sagas, AuthService, Workers   │
└──────────────────────────────────────────────┘
                   ▲
                   │ Commands
                   ▼
┌──────────────────────────────────────────────┐
│ 2. Domain Layer                              │
│ Aggregates, Value Objects, Events, Repos     │
└──────────────────────────────────────────────┘
                   ▲
                   │ Ports
                   ▼
┌──────────────────────────────────────────────┐
│ 3. Infrastructure Layer                      │
│ MySQL, WS Clients, Mpesa, Redis, SMTP        │
└──────────────────────────────────────────────┘
                   ▲
                   │ Outbox consumption
                   ▼
┌──────────────────────────────────────────────┐
│ 4. Background Worker Layer                   │
│ OutboxPublisher, Payment Workers             │
└──────────────────────────────────────────────┘


Layer Rules
	•	Application → Domain (allowed)
	•	Domain → Application (forbidden)
	•	Infrastructure → Domain (allowed)
	•	Domain → Infrastructure (forbidden)
	•	Workers consume Infrastructure only through ports

⸻

5. Domain Model

Identity Context
	•	User Aggregate (root aggregate)
	•	Entities:
	•	Device (max 2 per user)
	•	Value Objects:
	•	UserId (UUIDv7)
	•	Email
	•	PhoneNumber (E.164)
	•	DerivLoginId
	•	KycSnapshot (immutable)
	•	PasswordHash

Payments Context
	•	Transaction Aggregate (unified - handles both deposit and withdrawal)
	•	Entities (flow-specific attachments):
	•	MpesaRequest (deposit callbacks)
	•	MpesaDisbursement (withdrawal payouts)
	•	DerivTransfer (deposit funding)
	•	DerivWithdrawal (withdrawal debiting)
	•	Value Objects:
	•	TransactionId (UUIDv7)
	•	TransactionType (DEPOSIT / WITHDRAWAL)
	•	TransactionStatus
	•	IdempotencyKey

Wallet Context
	•	LedgerAccount Aggregate (double-entry bookkeeping)
	•	LedgerEntry Entity

Shared Kernel
	•	Money (amount + currency, cents-based)
	•	Currency (USD, KES)
	•	LockedRate (FX rate locked at transaction time)

Domain Events
	•	TransactionCreated
	•	TransactionCompleted
	•	TransactionFailed
	•	MpesaCallbackReceived
	•	MpesaDisbursementCompleted
	•	UserRegistered
	•	DepositInitiated
	•	BalanceChanged

⸻

6. Application Layer (CQRS + Saga)

6.1 Commands

Responsible for:
	•	validating input
	•	invoking aggregates
	•	saving state
	•	generating domain events
	•	ensuring idempotency

6.2 Queries

Rules:
	•	no aggregates
	•	no domain events
	•	pure data fetching
	•	safe SQL allowed
	•	no side effects

6.3 Sagas (Process Managers)

Deposit Saga
	1.	Create deposit transaction
	2.	STK push → M-Pesa
	3.	Callback received
	4.	Deriv deposit transfer
	5.	Transaction completed

Withdrawal Saga
	1.	Create withdrawal request
	2.	Debit Deriv wallet
	3.	Lock FX rate
	4.	B2C disbursement via M-Pesa
	5.	Transaction completed

⸻

7. Repositories (Ports)

All stored under:

src/Domain/Repository/

Interfaces:
	•	UserRepositoryInterface
	•	TransactionRepositoryInterface
	•	WalletRepositoryInterface
	•	OutboxRepositoryInterface

Infrastructure implements them in:

src/Infrastructure/Persistence/MySQL/


8. Infrastructure Layer

Contains:
	•	MySQL repositories
	•	Deriv WebSocket adapter
	•	Mpesa HTTP adapter
	•	Redis cache
	•	SMTP email sender
	•	Audit log writer
	•	Migration system
	•	Test factories

Rules:
	•	No business logic
	•	No cross-context logic
	•	No domain decisions
	•	Only technical concerns

⸻

9. Events & Outbox Pattern

PenPay uses guaranteed delivery for all domain events.

Flow
	1.	Aggregate raises event
	2.	Repository stores state + event in DB transaction
	3.	Worker publishes events
	4.	Outbox marks event as published

Benefits
	•	no double payouts
	•	crash-safe workflows
	•	replayable
	•	auditing
	•	deterministic saga state

⸻

10. Idempotency Layer

Idempotency keys are required for:
	•	deposits
	•	withdrawals
	•	M-Pesa callbacks
	•	Deriv callbacks
	•	worker retry steps

Stored in table:

idempotency_keys

If the same key appears twice → operation is skipped.

⸻

11. Database Schema

PenPay uses a normalized schema.

Tables include:
	•	users
	•	user_profile
	•	user_address
	•	user_compliance
	•	user_phone_verification
	•	transactions
	•	mpesa_requests
	•	deriv_transfers
	•	ledger_accounts
	•	ledger_entries
	•	domain_events
	•	idempotency_keys
	•	audit_logs

Migrations live under:

src/Infrastructure/Persistence/Migrations/


⸻

12. Testing Architecture

PenPay follows full integration testing:
	•	MySQL test DB
	•	Test bootstrapper
	•	Fake Deriv Client
	•	Fake Mpesa Client
	•	Repository tests
	•	Orchestrator tests
	•	Full E2E tests

All tests MUST run using:

APP_ENV=testing


Test database schema = production schema.

⸻

13. Security Requirements

Mandatory:
	•	Argon2ID password hashing
	•	JWT with short TTL
	•	HttpOnly tokens only
	•	Encrypt PII where required
	•	Audit logs for financial actions
	•	Strict device limit
	•	Secure token storage
	•	No raw credentials in logs

⸻

14. Compliance Requirements

PenPay must follow:
	•	Anti-Money Laundering (AML) guidelines
	•	KYC enforcement
	•	Data sovereignty rules
	•	Ledger must be immutable
	•	All financial events must be audit-logged

⸻

15. Change Control

Any architectural changes must be:
	1.	Proposed via PEC RFC
	2.	Reviewed by Principal Engineer
	3.	Approved before code change
	4.	Documented here

⸻

16. Non-Functional Requirements
	•	Reliability: ≥ 99.5%
	•	Consistency: Strong for critical operations
	•	Scalability: Horizontal worker scale
	•	Observability: Structured logs & audit logs
	•	Resilience: Retry on network failure
	•	Testability: High isolation + integration tests

⸻

17. Appendix: Directory Structure


src/
  Application/
    Command/
    Query/
    Saga/
    Service/
  Domain/
    Aggregate/
    Entity/
    ValueObject/
    Event/
    Repository/
  Infrastructure/
    Persistence/
    Http/
    WebSocket/
    Mail/
    Redis/
    Worker/
  Shared/
    UUID/
    Money/
tests/
docs/