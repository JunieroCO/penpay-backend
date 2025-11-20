flowchart TB
    subgraph Worker_Container ["Queue Workers (Multiple Instances)"]
        direction TB

        Consumer[Redis Stream Consumer<br/>group: penpay-workers]
        
        DepositWorker[DepositWorker<br/>Handles deposit.initiated]
        MpesaCallbackWorker[MpesaCallbackWorker<br/>Handles mpesa.callback.received]
        DerivTransferWorker[DerivTransferWorker<br/>Handles deriv.transfer.requested]

        DerivGatewayClient[DerivGatewayClient<br/>gRPC to deriv-ws-gateway]
        MpesaClient[MpesaClient<br/>Daraja B2C + STK Push]

        TransactionService[TransactionService<br/>Domain state machine]
        LedgerService[LedgerRecorder<br/>Double-entry]

        Repo[Repositories<br/>Transaction, Ledger, Audit]

        Redis[Redis Streams<br/>XADD / XREADGROUP]
    end

    Consumer --> DepositWorker & MpesaCallbackWorker & DerivTransferWorker
    DepositWorker --> TransactionService --> LedgerService
    DepositWorker --> DerivGatewayClient
    MpesaCallbackWorker --> MpesaClient
    DerivTransferWorker --> DerivGatewayClient

    TransactionService --> Repo
    LedgerService --> Repo

    style Worker_Container fill:#fff3e0,stroke:#ff9800,stroke-width:4px