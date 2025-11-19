flowchart TB
    %% ====================== ACTORS ======================
    User[Mobile User<br/>Kenyan Trader<br/>React Native App]
    Admin[Admin Dashboard<br/>SuperAdmin  Support<br/>IP Allowlist + 2FA]

    %% ====================== PENPAY CONTAINERS ======================
    subgraph PenPay ["PenPay Platform – Non-Custodial (AWS Production)"]
        direction TB

        MobileApp["Mobile App Container<br/>(React Native – Expo)<br/>iOS + Android<br/>SecureStore + Biometric"]

        subgraph Backend ["Backend Services – Stateless"]
            API["API Gateway<br/>(NGINX + PHP-FPM)<br/>Docker containers<br/>/api/v1"]
            DerivWS["deriv-ws-gateway<br/>(Dedicated Worker)<br/>Single persistent WSS<br/>Auto-reconnect + backoff"]
            Workers["Queue Workers<br/>(Redis Streams consumers)<br/>Supervisord, multiple instances"]
        end

        subgraph Data ["Data Stores – Separation of Concerns"]
            UserDB[(User & Session DB<br/>MySQL 8 – Mutable<br/>ACID transactions)]
            LedgerDB[(Immutable Ledger DB<br/>Double-Entry Bookkeeping<br/>Append-Only, 7-year retention)]
            AuditDB[(Immutable Audit Log<br/>Write-Once, Tamper-evident<br/>3-year minimum retention)]
            Redis[(Redis Cluster<br/>Cache + Streams + Rate-limit<br/>Session store + Pub/Sub)]
        end
    end

    %% ====================== EXTERNAL SYSTEMS ======================
    subgraph External ["External Systems"]
        Deriv[Deriv Platform<br/>WebSocket + REST API]
        Safaricom[Safaricom Daraja<br/>STK Push + B2C + Callbacks]
        FX[FX Provider<br/>exchangerate.host / Fixer.io]
        SMTP[SMTP Server<br/>Resend / AWS SES]
    end

    %% ====================== INTERACTIONS ======================
    User -->|"HTTPS + JWT"| MobileApp
    MobileApp -->|"HTTPS /api/v1"| API
    Admin -->|"Privileged HTTPS<br/>(IP + Role + 2FA)"| API

    API -->|"gRPC or Redis Streams"| DerivWS
    API -->|"Redis Streams (exactly-once)"| Workers
    API & Workers -->|"Read/Write"| UserDB
    API & Workers -->|"Append-Only"| LedgerDB
    API & Workers & DerivWS -->|"Append-Only"| AuditDB
    API & Workers & DerivWS -->|"Cache + Streams"| Redis

    DerivWS -->|"Persistent WebSocket Only"| Deriv
    Workers -->|"REST + Callbacks"| Safaricom
    Workers -->|"WSS / REST fallback"| Deriv
    API -->|"HTTPS"| FX
    API -->|"SMTP"| SMTP

    %% ====================== STYLING ======================
    classDef critical fill:#ff4d4f,color:white
    classDef immutable fill:#531dab,color:white
    classDef external fill:#fff2e6,stroke:#ff9800
    class DerivWS,LedgerDB,AuditDB critical
    class UserDB,Redis external
    class MobileApp,API,Workers fill:#e6f7ff,stroke:#1890ff,stroke-width:2px

    style PenPay fill:#f9f9f9,stroke:#1890ff,stroke-width:5px