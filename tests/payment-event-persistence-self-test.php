<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$repositoryRoot = dirname(__DIR__);
$configuredCoreRoot = getenv('RED_CMS_CORE_ROOT');
$coreRoot = realpath(
    is_string($configuredCoreRoot) && $configuredCoreRoot !== ''
        ? $configuredCoreRoot
        : dirname($repositoryRoot) . '/redcms v5.1'
);
$packageRoot = realpath($repositoryRoot . '/package');
$databaseName = 'redcms_store_lite_payment_event_' .
    gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
$assertions = 0;
$databaseCreated = false;
$grantCreated = false;
$application = null;
$primary = null;
$admin = null;

function red_store_lite_payment_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_payment_connection(
    string $host,
    int $port,
    string $user,
    string $password,
    string $database = ''
): mysqli {
    $connection = mysqli_init();
    mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    mysqli_real_connect(
        $connection,
        $host,
        $user,
        $password,
        $database,
        $port
    );
    mysqli_set_charset($connection, 'utf8mb4');
    return $connection;
}

function red_store_lite_payment_value(
    mysqli $connection,
    string $sql
): string {
    $query = mysqli_query($connection, $sql);
    $row = mysqli_fetch_row($query);
    mysqli_free_result($query);
    return is_array($row) ? (string) ($row[0] ?? '') : '';
}

function red_store_lite_payment_apply_sql(
    mysqli $connection,
    string $sql
): void {
    mysqli_multi_query($connection, $sql);
    do {
        $result = mysqli_store_result($connection);
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($connection) && mysqli_next_result($connection));
}

function red_store_lite_payment_transaction_active(
    mysqli $connection
): bool {
    try {
        mysqli_query(
            $connection,
            'SAVEPOINT redcms_store_lite_payment_test_guard'
        );
        mysqli_query(
            $connection,
            'RELEASE SAVEPOINT redcms_store_lite_payment_test_guard'
        );
        return true;
    } catch (Throwable $throwable) {
        return false;
    }
}

function red_store_lite_payment_insert_order(
    mysqli $connection,
    string $orderId,
    int $identity,
    string $snapshotSha256,
    string $paymentMethod
): void {
    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO RED_Addon_StoreLite_Orders
         (OrderID, SourceCartRecordID, SubjectRecordID,
          IdempotencyKeySHA256, SourceCartStateSHA256, SnapshotVersion,
          SnapshotSHA256, Currency, CustomerName, CustomerEmail,
          FulfillmentMethod, FulfillmentFeeMinor, PaymentMethod, PaymentKind,
          OrderStatus, PaymentStatus, FulfillmentStatus, QuantityTotal,
          SubtotalMinor, TotalMinor)
         VALUES (?, ?, ?, UNHEX(?), UNHEX(?), 1, UNHEX(?), \'USD\', ?, ?,
                 \'pickup\', 0, ?, \'hosted\', \'pending\', \'pending\',
                 \'unfulfilled\', 1, 1500, 1500)'
    );
    $idempotency = hash('sha256', 'payment-idempotency-' . $identity);
    $cartState = hash('sha256', 'payment-cart-' . $identity);
    $name = 'Payment Fixture ' . $identity;
    $email = 'payment-' . $identity . '@example.test';
    mysqli_stmt_execute($statement, [
        $orderId,
        $identity,
        $identity,
        $idempotency,
        $cartState,
        $snapshotSha256,
        $name,
        $email,
        $paymentMethod,
    ]);
    $orderRecordId = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO RED_Addon_StoreLite_Order_Status_History
         (OrderRecordID, EventName, OrderStatus, PaymentStatus,
          FulfillmentStatus, ActorType, ActorRecordID, SnapshotSHA256)
         VALUES (?, \'order.created\', \'pending\', \'pending\',
                 \'unfulfilled\', \'anonymous\', ?, UNHEX(?))'
    );
    mysqli_stmt_execute(
        $statement,
        [$orderRecordId, $identity, $snapshotSha256]
    );
    mysqli_stmt_close($statement);
}

function red_store_lite_payment_order(
    string $orderId,
    string $snapshotSha256,
    string $paymentMethod,
    string $orderStatus = 'pending',
    string $paymentStatus = 'pending',
    string $fulfillmentStatus = 'unfulfilled'
): array {
    return [
        'orderId' => $orderId,
        'snapshotSha256' => $snapshotSha256,
        'paymentMethod' => $paymentMethod,
        'paymentKind' => 'hosted',
        'currency' => 'USD',
        'totalMinor' => 1500,
        'orderStatus' => $orderStatus,
        'paymentStatus' => $paymentStatus,
        'fulfillmentStatus' => $fulfillmentStatus,
    ];
}

function red_store_lite_payment_event(
    array $order,
    string $outcome,
    string $evidenceSha256,
    int $occurredAt
): array {
    return [
        'verification' => 'verified',
        'replayStatus' => 'unseen',
        'outcome' => $outcome,
        'orderId' => $order['orderId'],
        'orderSnapshotSha256' => $order['snapshotSha256'],
        'paymentMethod' => $order['paymentMethod'],
        'amountMinor' => $order['totalMinor'],
        'currency' => $order['currency'],
        'eventEvidenceSha256' => $evidenceSha256,
        'occurredAt' => $occurredAt,
    ];
}

try {
    red_store_lite_payment_assert(
        is_string($coreRoot) && is_dir($coreRoot),
        'RED-CMS core root resolves'
    );
    red_store_lite_payment_assert(
        is_string($packageRoot) && is_dir($packageRoot),
        'Store Lite package root resolves'
    );
    red_store_lite_payment_assert(
        preg_match(
            '/\Aredcms_store_lite_payment_event_[A-Za-z0-9_]+\z/D',
            $databaseName
        ) === 1 && strlen($databaseName) <= 64,
        'disposable payment-event database name is exact and bounded'
    );

    $configPath = $coreRoot . '/includes/config.local.php';
    if (!is_file($configPath)) {
        throw new RuntimeException(
            'RED-CMS local database configuration is required.'
        );
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('RED-CMS local configuration is invalid.');
    }
    $hostPort = (string) ($config['DBHOST'] ?? '');
    $databaseHost = $hostPort;
    $databasePort = 3306;
    if (str_contains($hostPort, ':')) {
        [$databaseHost, $configuredPort] = explode(':', $hostPort, 2);
        $databasePort = (int) $configuredPort;
    }
    $databaseUser = (string) ($config['DBUSER'] ?? '');
    $databasePassword = (string) ($config['DBPASS'] ?? '');
    $primaryDatabase = (string) ($config['DBNAME'] ?? '');
    if ($databaseHost === '' || $databaseUser === '' || $primaryDatabase === '') {
        throw new RuntimeException('RED-CMS database configuration is incomplete.');
    }

    $primary = red_store_lite_payment_connection(
        $databaseHost,
        $databasePort,
        $databaseUser,
        $databasePassword,
        $primaryDatabase
    );
    $primaryFingerprint = red_store_lite_payment_value(
        $primary,
        "SELECT CONCAT(COUNT(*), ':',
            COALESCE(SUM(TABLE_NAME LIKE 'RED_Addon_StoreLite\\\\_%'), 0))
         FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()"
    );
    $currentUser = red_store_lite_payment_value(
        $primary,
        'SELECT CURRENT_USER()'
    );
    if (preg_match(
        '/\A([A-Za-z0-9_.-]+)@([A-Za-z0-9_.%-]+)\z/D',
        $currentUser,
        $currentUserParts
    ) !== 1) {
        throw new RuntimeException('Application database account is invalid.');
    }

    $adminUser = getenv('RED_ACCEPTANCE_DB_ADMIN_USER');
    $adminPassword = getenv('RED_ACCEPTANCE_DB_ADMIN_PASS');
    $adminUser = is_string($adminUser) && $adminUser !== ''
        ? $adminUser
        : 'root';
    $adminPassword = is_string($adminPassword) ? $adminPassword : '';
    $admin = red_store_lite_payment_connection(
        $databaseHost,
        $databasePort,
        $adminUser,
        $adminPassword
    );
    $applicationAccount = "'" . mysqli_real_escape_string(
        $admin,
        $currentUserParts[1]
    ) . "'@'" . mysqli_real_escape_string(
        $admin,
        $currentUserParts[2]
    ) . "'";

    mysqli_query(
        $admin,
        'CREATE DATABASE `' . $databaseName .
            '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $databaseCreated = true;
    mysqli_query(
        $admin,
        'GRANT ALL PRIVILEGES ON `' . $databaseName . '`.* TO ' .
            $applicationAccount
    );
    $grantCreated = true;
    $application = red_store_lite_payment_connection(
        $databaseHost,
        $databasePort,
        $databaseUser,
        $databasePassword,
        $databaseName
    );
    mysqli_query(
        $application,
        'CREATE TABLE RED_Articles (
            RecordID int unsigned NOT NULL,
            PRIMARY KEY (RecordID)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    mysqli_query(
        $application,
        'CREATE TABLE RED_Addon_Public_Mutation_Subjects (
            RecordID int unsigned NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (RecordID)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $manifest = json_decode(
        (string) file_get_contents($packageRoot . '/addon.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    foreach ($manifest['migrations'] ?? [] as $migration) {
        $sql = file_get_contents(
            $packageRoot . '/' . ($migration['path'] ?? '')
        );
        if (!is_string($sql)) {
            throw new RuntimeException('Store Lite migration is unavailable.');
        }
        red_store_lite_payment_apply_sql($application, $sql);
    }

    require_once $coreRoot . '/includes/addon_service_helpers.php';
    require_once $packageRoot . '/src/PaymentEventPersistence.php';
    require_once $packageRoot . '/src/PaymentEventService.php';

    $orderA = 'ord_' . str_repeat('a', 32);
    $orderB = 'ord_' . str_repeat('b', 32);
    $snapshotA = str_repeat('1', 64);
    $snapshotB = str_repeat('2', 64);
    red_store_lite_payment_insert_order(
        $application,
        $orderA,
        8101,
        $snapshotA,
        'stripe_checkout'
    );
    red_store_lite_payment_insert_order(
        $application,
        $orderB,
        8102,
        $snapshotB,
        'paypal'
    );
    $pendingA = red_store_lite_payment_order(
        $orderA,
        $snapshotA,
        'stripe_checkout'
    );
    $pendingB = red_store_lite_payment_order(
        $orderB,
        $snapshotB,
        'paypal'
    );
    $failedEvent = red_store_lite_payment_event(
        $pendingA,
        'failed',
        str_repeat('3', 64),
        1786841000
    );
    $outsideTransaction = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingA, $failedEvent);
    red_store_lite_payment_assert(
        $outsideTransaction['status'] === 'invalid'
            && red_store_lite_payment_value(
                $application,
                "SELECT CONCAT(OrderStatus, ':', PaymentStatus, ':',
                    FulfillmentStatus) FROM RED_Addon_StoreLite_Orders
                 WHERE OrderID='$orderA'"
            ) === 'pending:pending:unfulfilled',
        'writer refuses use outside an active caller-owned transaction'
    );

    mysqli_begin_transaction($application);
    $unchanged = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingA, $failedEvent);
    $unchangedTransactionActive =
        red_store_lite_payment_transaction_active($application);
    mysqli_commit($application);
    red_store_lite_payment_assert(
        $unchanged['status'] === 'unchanged'
            && $unchanged['stateChanged'] === false
            && $unchangedTransactionActive
            && red_store_lite_payment_value(
                $application,
                "SELECT COUNT(*)
                 FROM RED_Addon_StoreLite_Order_Status_History
                 WHERE OrderRecordID=(SELECT RecordID
                    FROM RED_Addon_StoreLite_Orders WHERE OrderID='$orderA')"
            ) === '1',
        'failed event decision remains non-mutating and caller-committed'
    );

    $rawEvent = $failedEvent;
    $rawEvent['rawPayload'] = '{}';
    mysqli_begin_transaction($application);
    $rawRefused = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingA, $rawEvent);
    mysqli_rollback($application);
    red_store_lite_payment_assert(
        $rawRefused['status'] === 'transition_refused',
        'writer refuses raw or additional provider fields before storage'
    );

    $paidEvidence = str_repeat('4', 64);
    $paidEvent = red_store_lite_payment_event(
        $pendingA,
        'paid',
        $paidEvidence,
        1786842000
    );
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Order_Status_History
         ADD CONSTRAINT chk_storelite_test_payment_history_failure
         CHECK (EventName <> \'payment.paid\')'
    );
    mysqli_begin_transaction($application);
    $lateFailure = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingA, $paidEvent);
    $provisional = red_store_lite_payment_value(
        $application,
        "SELECT CONCAT(orders.OrderStatus, ':', orders.PaymentStatus, ':',
            orders.FulfillmentStatus, ':', COUNT(history.RecordID))
         FROM RED_Addon_StoreLite_Orders AS orders
         INNER JOIN RED_Addon_StoreLite_Order_Status_History AS history
           ON history.OrderRecordID=orders.RecordID
         WHERE orders.OrderID='$orderA'
         GROUP BY orders.RecordID"
    );
    $lateFailureTransactionActive =
        red_store_lite_payment_transaction_active($application);
    mysqli_rollback($application);
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Order_Status_History
         DROP CHECK chk_storelite_test_payment_history_failure'
    );
    red_store_lite_payment_assert(
        $lateFailure['status'] === 'write_failed'
            && $provisional === 'paid:paid:unfulfilled:1'
            && $lateFailureTransactionActive
            && red_store_lite_payment_value(
                $application,
                "SELECT CONCAT(OrderStatus, ':', PaymentStatus, ':',
                    FulfillmentStatus) FROM RED_Addon_StoreLite_Orders
                 WHERE OrderID='$orderA'"
            ) === 'pending:pending:unfulfilled',
        'late history refusal leaves rollback ownership with the caller'
    );

    mysqli_begin_transaction($application);
    $paid = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingA, $paidEvent);
    $paidTransactionActive =
        red_store_lite_payment_transaction_active($application);
    mysqli_commit($application);
    red_store_lite_payment_assert(
        $paid['status'] === 'applied'
            && $paid['stateChanged'] === true
            && $paid['fulfillmentBlocked'] === false
            && $paidTransactionActive
            && red_store_lite_payment_value(
                $application,
                "SELECT CONCAT(orders.OrderStatus, ':', orders.PaymentStatus,
                    ':', orders.FulfillmentStatus, ':', history.EventName, ':',
                    LOWER(HEX(history.EventEvidenceSHA256)), ':',
                    LOWER(HEX(history.TransitionSHA256)), ':',
                    history.EventOccurredAt)
                 FROM RED_Addon_StoreLite_Orders AS orders
                 INNER JOIN RED_Addon_StoreLite_Order_Status_History AS history
                   ON history.OrderRecordID=orders.RecordID
                 WHERE orders.OrderID='$orderA'
                   AND history.EventName='payment.paid'"
            ) === 'paid:paid:unfulfilled:payment.paid:' . $paidEvidence . ':'
                . $paid['planSha256'] . ':1786842000',
        'paid transition atomically updates one order and appends value-free evidence'
    );

    mysqli_begin_transaction($application);
    $paidReplay = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingA, $paidEvent);
    mysqli_commit($application);
    red_store_lite_payment_assert(
        $paidReplay['status'] === 'replayed'
            && $paidReplay['stateChanged'] === false
            && $paidReplay['planSha256'] === $paid['planSha256']
            && red_store_lite_payment_value(
                $application,
                "SELECT COUNT(*)
                 FROM RED_Addon_StoreLite_Order_Status_History
                 WHERE OrderRecordID=(SELECT RecordID
                    FROM RED_Addon_StoreLite_Orders WHERE OrderID='$orderA')"
            ) === '2',
        'exact opaque event replay returns the original transition without a write'
    );

    $conflictingEvent = red_store_lite_payment_event(
        $pendingB,
        'paid',
        $paidEvidence,
        1786842000
    );
    mysqli_begin_transaction($application);
    $replayConflict = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingB, $conflictingEvent);
    mysqli_rollback($application);
    red_store_lite_payment_assert(
        $replayConflict['status'] === 'replay_conflict'
            && red_store_lite_payment_value(
                $application,
                "SELECT CONCAT(OrderStatus, ':', PaymentStatus)
                 FROM RED_Addon_StoreLite_Orders WHERE OrderID='$orderB'"
            ) === 'pending:pending',
        'opaque event evidence cannot be reassigned to a different order'
    );

    $stalePaidEvent = red_store_lite_payment_event(
        $pendingA,
        'paid',
        str_repeat('5', 64),
        1786842100
    );
    mysqli_begin_transaction($application);
    $stale = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $pendingA, $stalePaidEvent);
    mysqli_rollback($application);
    red_store_lite_payment_assert(
        $stale['status'] === 'stale_order',
        'new evidence cannot apply against a stale expected order projection'
    );

    $paidA = red_store_lite_payment_order(
        $orderA,
        $snapshotA,
        'stripe_checkout',
        'paid',
        'paid'
    );
    $reversalEvent = red_store_lite_payment_event(
        $paidA,
        'reversal_reported',
        str_repeat('6', 64),
        1786843000
    );
    mysqli_begin_transaction($application);
    $reversal = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $paidA, $reversalEvent);
    $reversalProvisional = red_store_lite_payment_value(
        $application,
        "SELECT CONCAT(OrderStatus, ':', PaymentStatus, ':',
            FulfillmentStatus) FROM RED_Addon_StoreLite_Orders
         WHERE OrderID='$orderA'"
    );
    mysqli_rollback($application);
    red_store_lite_payment_assert(
        $reversal['status'] === 'applied'
            && $reversal['fulfillmentBlocked'] === true
            && $reversalProvisional === 'paid:reversal_reported:blocked'
            && red_store_lite_payment_value(
                $application,
                "SELECT CONCAT(OrderStatus, ':', PaymentStatus, ':',
                    FulfillmentStatus) FROM RED_Addon_StoreLite_Orders
                 WHERE OrderID='$orderA'"
            ) === 'paid:paid:unfulfilled',
        'reversal blocks fulfillment provisionally and caller rollback restores paid state'
    );

    foreach ([
        'DBHOST' => $databaseHost . ':' . $databasePort,
        'DBUSER' => $databaseUser,
        'DBPASS' => $databasePassword,
        'DBNAME' => $databaseName,
    ] as $constant => $value) {
        if (defined($constant) && constant($constant) !== $value) {
            throw new RuntimeException(
                'Runtime database constant is already bound elsewhere.'
            );
        }
        if (!defined($constant)) {
            define($constant, $value);
        }
    }
    $registrar = require $packageRoot . '/addon.php';
    $registry = new RED_Addon_Runtime_Registry(
        'redcms.store-lite',
        $manifest
    );
    ob_start();
    $registrarResult = $registrar($registry);
    $registrarOutput = ob_get_clean();
    $registry->assertComplete();
    $GLOBALS['RED_ADDON_RUNTIME_CONTEXT'] = new RED_Addon_Runtime_Context(
        ['redcms.store-lite'],
        ['redcms.store-lite' => $registry]
    );
    red_store_lite_payment_assert(
        $registrarResult === null
            && $registrarOutput === ''
            && red_addon_runtime_owner(
                'services',
                RED_CMS_Store_Lite_Payment_Event_Service::SERVICE
            ) === 'redcms.store-lite'
            && $registry->handler(
                'services',
                RED_CMS_Store_Lite_Payment_Event_Service::SERVICE
            ) === [
                RED_CMS_Store_Lite_Payment_Event_Service::class,
                'handle',
            ],
        'enabled runtime ownership resolves the exact Store Lite order service handler'
    );

    $rawService = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $paidA, 'event' => $reversalEvent, 'rawPayload' => '{}']
    );
    red_store_lite_payment_assert(
        $rawService['invoked'] === true
            && $rawService['success'] === false
            && $rawService['package'] === 'redcms.store-lite'
            && $rawService['error'] === 'payment_event_invalid',
        'typed Store Lite service refuses additional raw provider input'
    );

    $refundEvent = red_store_lite_payment_event(
        $paidA,
        'refund_confirmed',
        str_repeat('7', 64),
        1786844000
    );
    $refundService = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $paidA, 'event' => $refundEvent]
    );
    red_store_lite_payment_assert(
        $refundService['invoked'] === true
            && $refundService['success'] === true
            && $refundService['package'] === 'redcms.store-lite'
            && ($refundService['data']['status'] ?? null) === 'applied'
            && ($refundService['data']['stateChanged'] ?? null) === true
            && red_store_lite_payment_value(
                $application,
                "SELECT CONCAT(orders.OrderStatus, ':', orders.PaymentStatus,
                    ':', orders.FulfillmentStatus, ':', COUNT(history.RecordID))
                 FROM RED_Addon_StoreLite_Orders AS orders
                 INNER JOIN RED_Addon_StoreLite_Order_Status_History AS history
                   ON history.OrderRecordID=orders.RecordID
                 WHERE orders.OrderID='$orderA'
                 GROUP BY orders.RecordID"
            ) === 'refunded:refunded:unfulfilled:3',
        'typed service owns and commits one confirmed-refund transaction'
    );
    $refundReplay = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $paidA, 'event' => $refundEvent]
    );
    red_store_lite_payment_assert(
        $refundReplay['success'] === true
            && ($refundReplay['data']['status'] ?? null) === 'replayed'
            && ($refundReplay['data']['stateChanged'] ?? null) === false
            && red_store_lite_payment_value(
                $application,
                "SELECT COUNT(*)
                 FROM RED_Addon_StoreLite_Order_Status_History
                 WHERE OrderRecordID=(SELECT RecordID
                    FROM RED_Addon_StoreLite_Orders WHERE OrderID='$orderA')"
            ) === '3',
        'typed service returns exact replay without a duplicate refund fact'
    );

    $refundedA = red_store_lite_payment_order(
        $orderA,
        $snapshotA,
        'stripe_checkout',
        'refunded',
        'refunded'
    );
    $outOfOrderEvent = red_store_lite_payment_event(
        $refundedA,
        'reversal_reported',
        str_repeat('8', 64),
        1786845000
    );
    mysqli_begin_transaction($application);
    $outOfOrder = RED_CMS_Store_Lite_Payment_Event_Persistence::
        applyWithinTransaction($application, $refundedA, $outOfOrderEvent);
    mysqli_rollback($application);
    red_store_lite_payment_assert(
        $outOfOrder['status'] === 'transition_refused',
        'refund state refuses a later out-of-order reversal'
    );

    unset($GLOBALS['RED_ADDON_RUNTIME_CONTEXT']);
    $disabled = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $refundedA, 'event' => $outOfOrderEvent]
    );
    red_store_lite_payment_assert(
        $disabled['invoked'] === false
            && $disabled['reason'] === 'service_unavailable'
            && red_store_lite_payment_value(
                $application,
                "SELECT CONCAT(OrderStatus, ':', PaymentStatus, ':',
                    FulfillmentStatus) FROM RED_Addon_StoreLite_Orders
                 WHERE OrderID='$orderA'"
            ) === 'refunded:refunded:unfulfilled',
        'missing enabled request-local owner prevents every service execution'
    );

    $persistenceSource = file_get_contents(
        $packageRoot . '/src/PaymentEventPersistence.php'
    );
    $serviceSource = file_get_contents(
        $packageRoot . '/src/PaymentEventService.php'
    );
    red_store_lite_payment_assert(
        is_string($persistenceSource)
            && !str_contains($persistenceSource, 'mysqli_begin_transaction')
            && !str_contains($persistenceSource, 'mysqli_commit')
            && !str_contains($persistenceSource, 'mysqli_rollback')
            && !preg_match(
                '/\$_(?:GET|POST|COOKIE|SERVER|SESSION|FILES)|curl_|fsockopen|file_get_contents|fopen|getenv|DBHOST|DBUSER|DBPASS|DBNAME/i',
                $persistenceSource
            ),
        'writer contains no transaction ownership, request, runtime-config, filesystem, or network path'
    );
    red_store_lite_payment_assert(
        is_string($serviceSource)
            && !preg_match(
                '/\$_(?:GET|POST|COOKIE|SERVER|SESSION|FILES)|curl_|fsockopen|file_get_contents|fopen|resolveSecret|signature|rawPayload|checkoutUrl|providerError/i',
                $serviceSource
            ),
        'service contains no request, provider-payload, secret-resolution, filesystem, or network path'
    );
} finally {
    unset($GLOBALS['RED_ADDON_RUNTIME_CONTEXT']);
    if ($application instanceof mysqli) {
        mysqli_close($application);
        $application = null;
    }
    if ($admin instanceof mysqli
        && $databaseCreated
        && preg_match(
            '/\Aredcms_store_lite_payment_event_[A-Za-z0-9_]+\z/D',
            $databaseName
        ) === 1
    ) {
        $cleanupErrors = [];
        if ($grantCreated && isset($applicationAccount)) {
            try {
                mysqli_query(
                    $admin,
                    'REVOKE ALL PRIVILEGES ON `' . $databaseName .
                        '`.* FROM ' . $applicationAccount
                );
            } catch (Throwable $throwable) {
                $cleanupErrors[] = 'grant';
            }
        }
        try {
            mysqli_query($admin, 'DROP DATABASE `' . $databaseName . '`');
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'database';
        }
        red_store_lite_payment_assert(
            $cleanupErrors === [],
            'disposable payment-event database and grant cleanup succeeds'
        );
        red_store_lite_payment_assert(
            red_store_lite_payment_value(
                $admin,
                "SELECT CONCAT(
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA
                     WHERE SCHEMA_NAME LIKE 'redcms_store_lite_payment_event_%'), ':',
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMA_PRIVILEGES
                     WHERE TABLE_SCHEMA LIKE 'redcms_store_lite_payment_event_%')
                 )"
            ) === '0:0',
            'no payment-event acceptance database or scoped grant remains'
        );
    }
    if ($primary instanceof mysqli) {
        if (isset($primaryFingerprint)) {
            red_store_lite_payment_assert(
                hash_equals(
                    $primaryFingerprint,
                    red_store_lite_payment_value(
                        $primary,
                        "SELECT CONCAT(COUNT(*), ':',
                            COALESCE(SUM(TABLE_NAME LIKE 'RED_Addon_StoreLite\\\\_%'), 0))
                         FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA=DATABASE()"
                    )
                ),
                'configured primary database boundary remains unchanged'
            );
        }
        mysqli_close($primary);
    }
    if ($admin instanceof mysqli) {
        mysqli_close($admin);
    }
}

echo 'Store Lite P3B-3 payment-event persistence passed ' . $assertions .
    " assertions.\n";
