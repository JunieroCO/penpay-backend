flowchart TB
    subgraph DerivGateway ["deriv-ws-gateway (Single Instance)"]
        WsClient[Deriv WebSocket Client<br/>Persistent connection<br/>Ping/pong + reconnect]
        
        AuthManager[Token Manager<br/>Encrypted tokens from DB]
        BalanceTracker[BalanceTracker<br/>Subscribe per user]
        TransferExecutor[TransferExecutor<br/>payment_agent_transfer]

        RedisPub[Redis Publisher<br/>balance.updated, transfer.confirmed]

        Health[Health Check<br/>/health → 200 + connection status]
    end

    WsClient --> AuthManager
    WsClient --> BalanceTracker --> RedisPub
    WsClient --> TransferExecutor --> RedisPub

    style DerivGateway fill:#ff4d4f,color:white