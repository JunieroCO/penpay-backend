flowchart TD
    %% ====================== EXTERNAL ACTORS ======================
    subgraph External_Actors [External Actors & Systems]
        direction TB
        User[Mobile User<br/>Kenyan Trader<br/>Deriv + M-Pesa]
        AdminDashboard[Admin Dashboard<br/>SuperAdmin  Support<br/>IP Allowlist + 2FA<br/>Separate VPC / Subnet]
        Safaricom[Safaricom Daraja<br/>STK Push + B2C + Callbacks]
        Deriv[Deriv Platform<br/>WebSocket + REST API]
        FXProvider[FX Rate Provider<br/>exchangerate.host / Fixer.io]
        SMTP[SMTP Provider<br/>Resend / AWS SES]
    end

    %% ====================== PENPAY PLATFORM BOUNDARY ======================
    subgraph PenPay [PenPay Platform — Non-Custodial<br/>Zero Balance Held]
        direction TB
        
        MobileApp[React Native Mobile App<br/>iOS + Android]
        
        subgraph Backend_Services [Backend Services Stateless]
            API[API Containers<br/>PHP-FPM + NGINX]
            Workers[Worker Containers<br/>Redis Streams Consumers]
            DerivGateway[deriv-ws-gateway<br/>Single Persistent WSS]
        end
        
        subgraph Data_Stores [Data Stores Separated by Concern]
            UserDB[User & Session DB<br/>MySQL — Mutable]
            LedgerDB[Immutable Ledger DB<br/>Double-Entry Bookkeeping<br/>Append-Only]
            AuditLog[Immutable Audit Log<br/>3-Year Retention<br/>Write-Once]
            Cache[Redis<br/>Cache + Streams + Rate Limits]
        end
    end

    %% ====================== INTERACTIONS ======================
    User -->|Uses App| MobileApp
    MobileApp -->|HTTPS /api/v1 JWT| API

    AdminDashboard -->|Privileged HTTPS IP + Role| API

    API -->|gRPC / Redis Streams| DerivGateway
    API & Workers -->|Redis Streams| Cache
    API & Workers --> UserDB & LedgerDB & AuditLog & Cache

    Workers -->|WSS + REST| Deriv
    DerivGateway -->|Persistent WSS Only| Deriv

    Workers -->|HTTPS + Callbacks| Safaricom
    API -->|HTTPS| FXProvider
    API -->|SMTP| SMTP

    %% ====================== STYLING ======================
    style PenPay fill:#e6f7ff,stroke:#1890ff,stroke-width:4px,stroke-dasharray: 0
    classDef external fill:#fff2e6,stroke:#ff9800
    classDef critical fill:#ff4d4f,color:white
    class User,MobileApp,AdminDashboard,DerivGateway,LedgerDB,AuditLog critical
    class UserDB,Cache external