<?php
declare(strict_types=1);

use PenPay\Core\Container\Container;
use PenPay\Infrastructure\DerivWsGateway\WsClient;
use PenPay\Infrastructure\Deriv\Deposit\DerivDepositGateway;
use PenPay\Infrastructure\Deriv\Withdrawal\DerivWithdrawalGateway;
use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaGateway;
use PenPay\Infrastructure\Mpesa\Deposit\MpesaClientInterface;
use PenPay\Infrastructure\Mpesa\Deposit\DarajaMpesaClient;
use PenPay\Infrastructure\Mpesa\Withdrawal\MpesaGatewayInterface;
use PenPay\Infrastructure\Repository\DepositRepository;
use PenPay\Infrastructure\Repository\WithdrawalRepository;
use PenPay\Infrastructure\Repository\LedgerRepository;
use PenPay\Application\Deposit\DepositOrchestrator;
use PenPay\Application\Withdrawal\WithdrawalOrchestrator;
use PenPay\Workers\Deposit\DepositWorker;
use PenPay\Workers\Withdrawal\MpesaDisbursementWorker;
use PenPay\Presentation\Http\Controllers\DepositController;
use PenPay\Presentation\Http\Controllers\WithdrawalController;
use PenPay\Domain\Wallet\Services\FxServiceInterface;
use PenPay\Domain\Wallet\Services\DailyLimitCheckerInterface;
use PenPay\Domain\Wallet\Services\LedgerRecorderInterface;
use PenPay\Domain\Payments\Repository\TransactionRepositoryInterface;
use PenPay\Domain\Payments\Repository\WithdrawalTransactionRepositoryInterface;
use PenPay\Domain\Payments\Factory\TransactionFactoryInterface;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisherInterface;
use PenPay\Infrastructure\Audit\AuditLoggerInterface;
use PenPay\Infrastructure\Fx\FxService;
use PenPay\Infrastructure\Wallet\DailyLimitChecker;
use PenPay\Infrastructure\Wallet\LedgerRecorder;
use PenPay\Infrastructure\Payments\TransactionFactory;
use PenPay\Infrastructure\Queue\Publisher\RedisStreamPublisher;
use PenPay\Infrastructure\Audit\AuditLogger;
use PenPay\Infrastructure\Secret\OneTimeSecretStoreInterface;
use PenPay\Infrastructure\Secret\OneTimeSecretStore;
use React\EventLoop\Loop;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

return function (Container $c): void {

    // ================================
    // Shared Infrastructure Factories
    // ================================

    $c->set('pdo', fn() => new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_NAME']),
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    ));

    $c->set('event_loop', fn() => Loop::get());

    $c->set('logger', fn() => new \Monolog\Logger('penpay', [
        new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info)
    ]));

    $c->set(CacheInterface::class, fn() => new Psr16Cache(
        new FilesystemAdapter('penpay', 3600, sys_get_temp_dir() . '/penpay-cache')
    ));

    // ================================
    // HTTP Clients
    // ================================

    $c->set(\Psr\Http\Client\ClientInterface::class, fn() => new \GuzzleHttp\Client([
        'timeout' => 30,
        'connect_timeout' => 10,
    ]));

    $c->set(\Psr\Http\Message\RequestFactoryInterface::class, fn() => new \GuzzleHttp\Psr7\HttpFactory());
    $c->set(\Psr\Http\Message\StreamFactoryInterface::class, fn() => new \GuzzleHttp\Psr7\HttpFactory());

    // ================================
    // Core Domain Services
    // ================================

    $c->set(FxServiceInterface::class, fn() => new FxService(
        cache: $c->get(CacheInterface::class),
        logger: $c->get('logger')
    ));

    $c->set(DailyLimitCheckerInterface::class, fn() => new DailyLimitChecker(
        pdo: $c->get('pdo'),
        logger: $c->get('logger')
    ));

    $c->set(LedgerRecorderInterface::class, fn() => new LedgerRecorder(
        pdo: $c->get('pdo'),
        logger: $c->get('logger')
    ));

    $c->set(TransactionFactoryInterface::class, fn() => new TransactionFactory());

    $c->set(RedisStreamPublisherInterface::class, fn() => new RedisStreamPublisher(
        redis: new \Redis(), // You might want to configure Redis connection properly
        logger: $c->get('logger')
    ));

    $c->set(AuditLoggerInterface::class, fn() => new AuditLogger(
        logger: $c->get('logger')
    ));

    $c->set(OneTimeSecretStoreInterface::class, fn() => new OneTimeSecretStore(
        cache: $c->get(CacheInterface::class)
    ));

    // ================================
    // WebSocket Client - Fixed parameters
    // ================================

    $c->set(WsClient::class, fn() => new WsClient(
        loop: $c->get('event_loop'),
        logger: $c->get('logger'),
        timeoutSeconds: 30,
        derivConfig: [
            'app_id' => $_ENV['DERIV_APP_ID'] ?? '1089',
            'ws_url' => $_ENV['DERIV_WS_URL'] ?? 'wss://ws.binaryws.com/websockets/v3'
        ]
    ));

    // ================================
    // Gateways - Fixed parameters
    // ================================

    $c->set(DerivDepositGateway::class, fn() => new DerivDepositGateway(
        wsClient: $c->get(WsClient::class)
    ));

    $c->set(DerivWithdrawalGateway::class, fn() => new DerivWithdrawalGateway(
        wsClient: $c->get(WsClient::class)
    ));

    // ================================
    // M-Pesa Clients - CORRECTED: Using exact constructor parameters
    // ================================

    $c->set(MpesaClientInterface::class, fn() => new DarajaMpesaClient(
        consumerKey: $_ENV['MPESA_CONSUMER_KEY'],
        consumerSecret: $_ENV['MPESA_CONSUMER_SECRET'],
        shortcode: $_ENV['MPESA_SHORTCODE'],
        passkey: $_ENV['MPESA_PASSKEY'],
        sandbox: ($_ENV['MPESA_ENV'] ?? 'sandbox') === 'sandbox'
    ));

    $c->set(MpesaGateway::class, fn() => new MpesaGateway(
        http: $c->get(\Psr\Http\Client\ClientInterface::class),
        requestFactory: $c->get(\Psr\Http\Message\RequestFactoryInterface::class),
        streamFactory: $c->get(\Psr\Http\Message\StreamFactoryInterface::class),
        cache: $c->get(CacheInterface::class),
        logger: $c->get('logger'),
        consumerKey: $_ENV['MPESA_CONSUMER_KEY'],
        consumerSecret: $_ENV['MPESA_CONSUMER_SECRET'],
        shortCode: $_ENV['MPESA_SHORTCODE'],
        initiatorName: $_ENV['MPESA_INITIATOR_NAME'],
        securityCredential: $_ENV['MPESA_SECURITY_CREDENTIAL'],
        resultUrl: $_ENV['MPESA_B2C_RESULT_URL'],
        timeoutUrl: $_ENV['MPESA_B2C_TIMEOUT_URL'],
        sandbox: ($_ENV['MPESA_ENV'] ?? 'sandbox') === 'sandbox',
        maxRetries: 3,
        cacheTtl: 3300
    ));

    // Bind the interface to the concrete implementation
    $c->set(MpesaGatewayInterface::class, fn() => $c->get(MpesaGateway::class));

    // ================================
    // Repositories
    // ================================

    $c->set(TransactionRepositoryInterface::class, fn() => new DepositRepository($c->get('pdo')));
    $c->set(WithdrawalTransactionRepositoryInterface::class, fn() => new WithdrawalRepository($c->get('pdo')));
    $c->set(DepositRepository::class, fn() => new DepositRepository($c->get('pdo')));
    $c->set(WithdrawalRepository::class, fn() => new WithdrawalRepository($c->get('pdo')));
    $c->set(LedgerRepository::class, fn() => new LedgerRepository($c->get('pdo')));

    // ================================
    // Orchestrators - Fixed parameters
    // ================================

    $c->set(DepositOrchestrator::class, fn() => new DepositOrchestrator(
        txRepo: $c->get(TransactionRepositoryInterface::class),
        txFactory: $c->get(TransactionFactoryInterface::class),
        fxService: $c->get(FxServiceInterface::class),
        dailyLimit: $c->get(DailyLimitCheckerInterface::class),
        ledger: $c->get(LedgerRecorderInterface::class),
        publisher: $c->get(RedisStreamPublisherInterface::class),
        auditLogger: $c->get(AuditLoggerInterface::class)
    ));

    $c->set(WithdrawalOrchestrator::class, fn() => new WithdrawalOrchestrator(
        txRepo: $c->get(TransactionRepositoryInterface::class),
        dailyLimitChecker: $c->get(DailyLimitCheckerInterface::class),
        fxService: $c->get(FxServiceInterface::class),
        ledgerRecorder: $c->get(LedgerRecorderInterface::class),
        publisher: $c->get(RedisStreamPublisherInterface::class),
        secretStore: $c->get(OneTimeSecretStoreInterface::class)
    ));

    // ================================
    // Workers - FIXED: Using correct parameter names that match your constructors
    // ================================

    $c->set(DepositWorker::class, fn() => new DepositWorker(
        txRepo: $c->get(TransactionRepositoryInterface::class),
        mpesaClient: $c->get(MpesaClientInterface::class),
        publisher: $c->get(RedisStreamPublisherInterface::class),
        logger: $c->get('logger'),
        maxRetries: 3
    ));

    $c->set(MpesaDisbursementWorker::class, fn() => new MpesaDisbursementWorker(
        txRepo: $c->get(WithdrawalTransactionRepositoryInterface::class),
        mpesaGateway: $c->get(MpesaGatewayInterface::class),
        publisher: $c->get(RedisStreamPublisherInterface::class),
        logger: $c->get('logger'),
        maxRetries: 3
    ));

    // ================================
    // Controllers
    // ================================

    $c->set(DepositController::class, fn() => new DepositController(
        orchestrator: $c->get(DepositOrchestrator::class)
    ));

    $c->set(WithdrawalController::class, fn() => new WithdrawalController(
        orchestrator: $c->get(WithdrawalOrchestrator::class)
    ));

    // ================================
    // Optional: Environment validation
    // ================================

    $requiredEnvVars = [
        'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'MPESA_CONSUMER_KEY', 'MPESA_CONSUMER_SECRET', 'MPESA_SHORTCODE',
        'MPESA_INITIATOR_NAME', 'MPESA_SECURITY_CREDENTIAL', 'MPESA_PASSKEY',
        'MPESA_B2C_RESULT_URL', 'MPESA_B2C_TIMEOUT_URL'
    ];

    foreach ($requiredEnvVars as $envVar) {
        if (empty($_ENV[$envVar])) {
            $c->get('logger')->warning("Missing required environment variable: {$envVar}");
        }
    }
};