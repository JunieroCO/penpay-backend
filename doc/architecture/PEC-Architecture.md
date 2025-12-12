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

3. Ubiquitous Language (UL)

These terms must be used consistently across code, tests, schemas, and services.

Term
Definition
User
Registered identity capable of financial operations
Device
Authenticated client instance linked to a user
Transaction
Financial operation (deposit/withdrawal)
Deposit
M-Pesa → Deriv wallet
Withdrawal
Deriv wallet → M-Pesa
Deriv Transfer
Movement of funds inside Deriv
Mpesa Callback
Confirmation message from Safaricom
Idempotency Key
Unique external request identifier
Saga
Process manager controlling long workflows
Outbox Event
Domain event awaiting guaranteed delivery
Locked Rate
FX rate used for withdrawal


No new terms may be introduced without PEC approval.


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
	•	User Aggregate
	•	Entities:
	•	Device
	•	KycSnapshot (VO)

Payments Context
	•	Transaction Aggregate
	•	WithdrawalTransaction Aggregate

Value Objects
	•	UUID
	•	Money
	•	Currency
	•	TransactionStatus
	•	TransactionType
	•	IdempotencyKey
	•	DerivTransferId
	•	LockedRate

Domain Events
	•	TransactionCreated
	•	TransactionCompleted
	•	TransactionFailed
	•	MpesaCallbackReceived
	•	UserRegistered

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