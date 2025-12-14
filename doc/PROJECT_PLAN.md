📋 PenPay — Master Project Plan (PEC-Aligned)

Version: 1.0
Owner: Project Manager (Nashon Okeyo)
Last Updated: Today
Lifecycle Model: Agile + DDD + Architecture-First

---

## Non-Negotiables

* Architecture first
* Domain clarity first
* Security-first (PCI-like for M-Pesa, KYC compliance)
* PEC adherence at all layers
* Zero hard-coded secrets
* No accidental complexity
* Documentation before implementation
* Testability as a first-class citizen

---

## 🔥 PHASE 0 — FOUNDATIONS (We are here)

**Goal**: Ensure full clarity before any coding or refactoring.

### Deliverables:

1. ✅ Full System Context Diagram (C4 level 0)
2. ✅ Container Architecture (C4 level 1)
3. ✅ Domain Model (DDD bounded contexts)
4. ✅ Event Flow Diagrams
5. ✅ Non-Functional Requirements
6. ✅ Security Requirements
7. ✅ API High-Level Specification
8. ✅ Project-wide coding conventions (Backend + RN)
9. ✅ Versioned API Strategy (e.g., /api/v1)
10. ✅ DevOps & Deployment Plan
11. ✅ Ubiquitous Language (UL) & Naming Standard

### What we will do before touching any code:

- [x] Confirm domain boundaries
- [x] Confirm core services
- [x] Confirm external integrations
- [x] Confirm money-flow logic
- [x] Confirm risk scenarios
- [x] Validate with stakeholders

---

## 🔥 PHASE 1 — DOMAIN ARCHITECTURE

**(We start once Phase 0 diagrams are approved)**

### Deliverables:

#### 1. Domain Bounded Contexts

* **User Context** (Registration, KYC, Auth)
* **Wallet Context** (Balance, Ledger, FX conversions)
* **Payments Context**
  * Deriv deposit/withdraw
  * MPesa (STKPush, C2B, B2C)
* **Compliance Context** (Audit Logs, KYC status, Limits)
* **Admin Context**
  * Dashboard
  * Manual overrides
  * Reconciliation Tools

#### 2. Domain Entities + Aggregates + Value Objects

(Following strict DDD)

#### 3. Domain Events

* UserRegistered
* PasswordCreated
* KYCSubmitted
* DepositInitiated
* DepositCompleted
* WithdrawalRequested
* WithdrawalPaid
* BalanceUpdated
* MpesaCallbackReceived
* DerivTransferConfirmed

#### 4. Complete Money Flow Blueprint

* Escrowed transactions
* Ledger entries
* Double-entry bookkeeping (non-negotiable)
* Reconciliation rules

---

## 🔥 PHASE 2 — INFRASTRUCTURE ARCHITECTURE

After domain clarity, we define the technical infrastructure.

### Deliverables:

#### 1. Backend Architecture

* PHP / Composer / MVC+DDD
* Controllers → Services → Repositories → Entities
* MySQL schema (normalised + ACID)
* Migrations folder
* Layered security middleware
* JWT auth with key rotation
* WebSocket client for Deriv
* MQ for transaction processing (optional Phase 5)

#### 2. Frontend Architecture (RN)

* Atomic design
* Screens → Feature Hooks → UI components → Services
* Central API client
* Token security
* Error boundary system
* Strict form validation

#### 3. DevOps & Environments

* Docker for backend
* Render.com deployment
* Staging environment
* CI/CD
* Secrets management (Vault / Render private envs)
* Logging (JSON logs)
* Metrics & monitoring

---

## 🔥 PHASE 3 — BACKEND IMPLEMENTATION (Safe Order)

This is the exact implementation sequence.

### Step 1: Bootstrap backend skeleton

### Step 2: Implement Auth + User mgmt

### Step 3: Implement Wallet + Ledger

### Step 4: Implement Deriv integration (WebSocket, retries, mapping)

### Step 5: Implement M-Pesa (Daraja) integration

### Step 6: Transaction lifecycle orchestration

### Step 7: Admin tools

### Step 8: Automated tests

### Step 9: Performance optimisations

### Step 10: Security hardening

---

## 🔥 PHASE 4 — FRONTEND IMPLEMENTATION (Safe Order)

### Step 1: Project skeleton

### Step 2: Auth flow

### Step 3: Balance + Ledger UI

### Step 4: Deposit workflows

### Step 5: Withdrawal workflows

### Step 6: Admin / Support tools

### Step 7: Offline handling

### Step 8: App review compliance

### Step 9: E2E testing

### Step 10: Launch

---

## 🔥 PHASE 5 — POST-LAUNCH IMPROVEMENTS

* Reconciliation automation
* Risk engine
* Auto FX hedging
* Performance tuning
* Kill-switch mechanisms
* Redundancy & failover
* Automated fraud rules
* Automated chargeback-handling flow

---

## 🔥 PHASE 6 — GROWTH ROADMAP

* iOS + Android parity
* Multi-country support
* Additional payment providers
* AI-driven insights
* Predictive onboarding
* Customer-tier models

---

## 🧩 Project Governance Rules (Mandatory)

These are the rules every contributor (LLM or human) must obey.

1. **No code before architecture.**
2. **No feature without diagrams.**
3. **No endpoint without specification.**
4. **No domain entity without value-object rules.**
5. **No shortcuts to "move fast".**
6. **Pushback when user requests harmful design.**
7. **If unclear → ask questions.**
8. **PEC compliance is mandatory.**
9. **All decisions are logged in ADR format.**
10. **Security is non-negotiable.**

---

## Current Status

**Phase**: 0 — FOUNDATIONS
**Status**: In Progress
**Completion**: ~85%

### Phase 0 Checklist:

- [x] Full System Context Diagram (C4 level 0) — `doc/diagrams/c4-level0-system-context.md`
- [x] Container Architecture (C4 level 1) — `doc/diagrams/c4-level1-containers.md`
- [x] Domain Model (DDD bounded contexts) — `doc/diagrams/DDD-Model.md`
- [x] Component Diagrams (C4 level 2) — `doc/diagrams/c4-level2-components.md`
- [x] API Container Details (C4 level 3) — `doc/diagrams/c4-level3-api-container.md`
- [x] Deriv Gateway Details (C4 level 3) — `doc/diagrams/c4-level3-deriv-gateway.md`
- [x] Worker Container Details (C4 level 3) — `doc/diagrams/c4-level3-worker-container.md`
- [x] Event Flow Diagrams — `doc/diagrams/deposit-sequence.md`, `doc/diagrams/withdrawal-sequence.md`
- [x] Non-Functional Requirements — `doc/architecture/PEC-Architecture.md` (Section 16)
- [x] Security Requirements — `doc/architecture/PEC-Architecture.md` (Section 13)
- [x] PEC Architecture Specification — `doc/architecture/PEC-Architecture.md`
- [x] Ubiquitous Language & Naming Standard — `doc/architecture/PEC-Architecture.md` (Section 3)
- [x] Transaction Factory Usage Guide — `doc/TRANSACTION_FACTORY_USAGE.md`
- [ ] API High-Level Specification (in progress)
- [ ] Project-wide coding conventions document
- [ ] Versioned API Strategy document
- [ ] DevOps & Deployment Plan

---

## Next Steps

1. Complete remaining Phase 0 deliverables
2. Get stakeholder approval on all Phase 0 artifacts
3. Begin Phase 1 — Domain Architecture refinement
4. Ensure all code follows PEC Architecture and UL standards

---

**Note**: This plan is living documentation. Update this file as phases progress and decisions are made.

