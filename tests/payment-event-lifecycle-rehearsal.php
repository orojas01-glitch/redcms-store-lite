<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = realpath((string) getenv('RED_STORE_LITE_PROJECT_ROOT'));
$databaseName = (string) getenv('RED_DB_NAME');
if (!is_string($projectRoot)
    || !is_dir($projectRoot)
    || preg_match(
        '/\Aredcms_sl_payment_lifecycle_[A-Za-z0-9_]+\z/D',
        $databaseName
    ) !== 1
) {
    fwrite(STDERR, "Store Lite payment lifecycle rehearsal refused unsafe input.\n");
    exit(64);
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/class/class_connection.php';
require_once $projectRoot . '/includes/addon_install_helpers.php';
require_once $projectRoot . '/includes/addon_enable_helpers.php';
require_once $projectRoot . '/includes/addon_disable_helpers.php';
require_once $projectRoot . '/includes/addon_service_helpers.php';

$db = new connection(DBHOST, DBUSER, DBPASS, DBNAME);
$connection = $db->connection;
$packageId = 'redcms.store-lite';
$actorId = 1;
$assertions = 0;

function red_store_lite_p3b4_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_p3b4_scalar(
    mysqli $connection,
    string $sql
): string {
    $query = mysqli_query($connection, $sql);
    $row = $query ? mysqli_fetch_row($query) : null;
    if ($query) {
        mysqli_free_result($query);
    }
    return is_array($row) ? (string) ($row[0] ?? '') : '';
}

function red_store_lite_p3b4_rows(
    mysqli $connection,
    string $sql
): array {
    $query = mysqli_query($connection, $sql);
    $rows = [];
    while ($query && ($row = mysqli_fetch_row($query))) {
        $rows[] = array_map(
            static fn (mixed $value): string =>
                $value === null ? '' : (string) $value,
            $row
        );
    }
    if ($query) {
        mysqli_free_result($query);
    }
    return $rows;
}

function red_store_lite_p3b4_prepare_owner(
    mysqli $connection,
    int $actorId
): void {
    mysqli_query(
        $connection,
        "INSERT IGNORE INTO RED_Admin_Roles
         (AdminRecordID, RoleName, AssignedByAdminRecordID)
         VALUES ($actorId, 'owner', $actorId)"
    );
    foreach (['addons.install', 'addons.enable', 'addons.disable'] as $capability) {
        $escaped = mysqli_real_escape_string($connection, $capability);
        mysqli_query(
            $connection,
            "INSERT IGNORE INTO RED_Admin_Capabilities
             (AdminRecordID, Capability, GrantedByAdminRecordID)
             VALUES ($actorId, '$escaped', $actorId)"
        );
    }
}

function red_store_lite_p3b4_store_settings(
    mysqli $connection,
    string $packageId,
    int $actorId
): void {
    $settings = [
        ['catalog.currency', 'text', 'USD'],
        ['checkout.delivery-enabled', 'boolean', false],
        ['checkout.delivery-fee-minor', 'integer', 0],
        ['checkout.pay-on-receipt-enabled', 'boolean', true],
        ['checkout.pickup-enabled', 'boolean', true],
    ];
    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO RED_Addon_Settings
            (PackageID, SettingKey, ValueType, ValueJSON,
             SecretReference, UpdatedByAdminRecordID)
         VALUES (?, ?, ?, ?, NULL, ?)'
    );
    foreach ($settings as [$key, $type, $value]) {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        mysqli_stmt_execute(
            $statement,
            [$packageId, $key, $type, $encoded, $actorId]
        );
    }
    mysqli_stmt_close($statement);
}

function red_store_lite_p3b4_insert_article(
    mysqli $connection,
    int $contentRecordId,
    string $componentId
): void {
    $query = mysqli_query(
        $connection,
        'SELECT * FROM RED_Articles ORDER BY RecordID LIMIT 1'
    );
    $row = $query ? mysqli_fetch_assoc($query) : null;
    if ($query) {
        mysqli_free_result($query);
    }
    if (!is_array($row) || $row === []) {
        throw new RuntimeException('Disposable Article seed is unavailable.');
    }
    $row['RecordID'] = (string) $contentRecordId;
    $row['Component'] = $componentId;
    $row['Alias'] = 'p3b4-subscription-preview';
    $row['Title'] = 'P3B4 subscription preview';
    $row['Active'] = 'Y';
    $row['StartDate'] = '1970-01-01 00:00:01';
    $row['EventDate'] = '1970-01-01 00:00:01';
    $row['ExpDate'] = '2099-12-31 23:59:59';
    $columns = array_keys($row);
    $values = [];
    foreach (array_values($row) as $value) {
        $values[] = $value === null
            ? 'NULL'
            : "'" . mysqli_real_escape_string(
                $connection,
                (string) $value
            ) . "'";
    }
    $sql = 'INSERT INTO RED_Articles (`'
        . implode('`,`', $columns)
        . '`) VALUES (' . implode(',', $values) . ')';
    if (!mysqli_query($connection, $sql)) {
        throw new RuntimeException(
            'Disposable Subscription component parent could not be created.'
        );
    }
}

function red_store_lite_p3b4_insert_order(
    mysqli $connection,
    string $orderId,
    string $snapshotSha256
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
         VALUES (?, 9301, 9301, UNHEX(?), UNHEX(?), 1, UNHEX(?), \'USD\',
                 \'P3B4 Fixture\', \'p3b4@example.test\', \'pickup\', 0,
                 \'stripe_checkout\', \'hosted\', \'pending\', \'pending\',
                 \'unfulfilled\', 1, 2500, 2500)'
    );
    mysqli_stmt_execute($statement, [
        $orderId,
        hash('sha256', 'p3b4-idempotency'),
        hash('sha256', 'p3b4-cart-state'),
        $snapshotSha256,
    ]);
    $orderRecordId = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO RED_Addon_StoreLite_Order_Status_History
         (OrderRecordID, EventName, OrderStatus, PaymentStatus,
          FulfillmentStatus, ActorType, ActorRecordID, SnapshotSHA256)
         VALUES (?, \'order.created\', \'pending\', \'pending\',
                 \'unfulfilled\', \'anonymous\', 9301, UNHEX(?))'
    );
    mysqli_stmt_execute($statement, [$orderRecordId, $snapshotSha256]);
    mysqli_stmt_close($statement);
}

function red_store_lite_p3b4_order(
    string $orderId,
    string $snapshotSha256,
    string $orderStatus,
    string $paymentStatus,
    string $fulfillmentStatus = 'unfulfilled'
): array {
    return [
        'orderId' => $orderId,
        'snapshotSha256' => $snapshotSha256,
        'paymentMethod' => 'stripe_checkout',
        'paymentKind' => 'hosted',
        'currency' => 'USD',
        'totalMinor' => 2500,
        'orderStatus' => $orderStatus,
        'paymentStatus' => $paymentStatus,
        'fulfillmentStatus' => $fulfillmentStatus,
    ];
}

function red_store_lite_p3b4_event(
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

function red_store_lite_p3b4_data_fingerprint(
    mysqli $connection
): string {
    $material = [
        'orders' => red_store_lite_p3b4_rows(
            $connection,
            'SELECT OrderID, SourceCartRecordID, SubjectRecordID,
                    LOWER(HEX(IdempotencyKeySHA256)),
                    LOWER(HEX(SourceCartStateSHA256)),
                    LOWER(HEX(SnapshotSHA256)), Currency, PaymentMethod,
                    PaymentKind, OrderStatus, PaymentStatus,
                    FulfillmentStatus, TotalMinor
             FROM RED_Addon_StoreLite_Orders ORDER BY RecordID'
        ),
        'history' => red_store_lite_p3b4_rows(
            $connection,
            'SELECT EventName, OrderStatus, PaymentStatus,
                    FulfillmentStatus, ActorType, ActorRecordID,
                    LOWER(HEX(SnapshotSHA256)),
                    COALESCE(LOWER(HEX(EventEvidenceSHA256)), \'\'),
                    COALESCE(LOWER(HEX(TransitionSHA256)), \'\'),
                    COALESCE(EventOccurredAt, 0)
             FROM RED_Addon_StoreLite_Order_Status_History ORDER BY RecordID'
        ),
        'subscriptions' => red_store_lite_p3b4_rows(
            $connection,
            'SELECT IntentReference, LOWER(HEX(OfferStateSHA256)), Provider,
                    LOWER(HEX(CheckoutSessionRefSHA256)),
                    COALESCE(LOWER(HEX(ProviderSubscriptionRefSHA256)), \'\'),
                    SubscriptionStatus, EntitlementStatus,
                    COALESCE(CurrentPeriodEndEpoch, 0),
                    LOWER(HEX(LastEventEvidenceSHA256))
             FROM RED_Addon_StoreLite_Subscriptions ORDER BY RecordID'
        ),
        'subscriptionHistory' => red_store_lite_p3b4_rows(
            $connection,
            'SELECT EventName, SubscriptionStatus, EntitlementStatus,
                    COALESCE(LOWER(HEX(ProviderSubscriptionRefSHA256)), \'\'),
                    LOWER(HEX(EventEvidenceSHA256)),
                    LOWER(HEX(TransitionSHA256)),
                    COALESCE(CurrentPeriodEndEpoch, 0), OccurredAtEpoch
             FROM RED_Addon_StoreLite_Subscription_Status_History
             ORDER BY RecordID'
        ),
    ];
    return hash(
        'sha256',
        json_encode(
            $material,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )
    );
}

try {
    red_store_lite_p3b4_assert(
        red_store_lite_p3b4_scalar($connection, 'SELECT DATABASE()')
            === $databaseName,
        'connection is bound to the approved disposable project database'
    );
    red_store_lite_p3b4_prepare_owner($connection, $actorId);

    $catalog = red_addon_discover($projectRoot, [
        'cmsVersion' => '5.1.0',
        'phpVersion' => PHP_VERSION,
    ]);
    $package = $catalog['packages'][$packageId] ?? null;
    $snapshot = is_array($package)
        ? red_addon_registry_snapshot($package)
        : null;
    red_store_lite_p3b4_assert(
        !empty($catalog['valid'])
            && is_array($package)
            && !empty($package['valid'])
            && is_array($snapshot)
            && ($snapshot['version'] ?? '') === '0.1.49'
            && count($snapshot['migrations'] ?? []) === 15,
        'staged Store Lite 0.1.49 package and fifteen migrations are trusted'
    );

    $installPlan = red_addon_install_plan(
        $connection,
        $package,
        $actorId,
        false,
        $catalog
    );
    $installed = red_addon_install_package(
        $connection,
        $packageId,
        $projectRoot,
        $actorId,
        $installPlan['planSha256'] ?? ''
    );
    red_store_lite_p3b4_assert(
        !empty($installPlan['valid'])
            && count($installPlan['pendingMigrations'] ?? []) === 15
            && ($installed['status'] ?? '') === 'installed_disabled'
            && count($installed['appliedMigrations'] ?? []) === 15
            && red_store_lite_p3b4_scalar(
                $connection,
                "SELECT CONCAT_WS(':', PackageVersion, LifecycleState,
                    (SELECT COUNT(*) FROM RED_Addon_Migrations
                     WHERE PackageID='$packageId'),
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA=DATABASE()
                       AND TABLE_NAME LIKE 'RED_Addon_StoreLite\\\\_%'))
                 FROM RED_Addon_Installations WHERE PackageID='$packageId'"
            ) === '0.1.49:installed_disabled:15:21',
        'real installation begins disabled with the exact schema and ledger'
    );
    red_store_lite_p3b4_store_settings($connection, $packageId, $actorId);
    red_store_lite_p3b4_assert(
        red_store_lite_p3b4_scalar(
            $connection,
            "SELECT CONCAT_WS(':', COUNT(*),
                SUM(SecretReference IS NOT NULL))
             FROM RED_Addon_Settings WHERE PackageID='$packageId'"
        ) === '5:0',
        'all five required non-secret installation settings are configured'
    );

    $orderId = 'ord_' . str_repeat('9', 32);
    $snapshotSha256 = str_repeat('a', 64);
    red_store_lite_p3b4_insert_order(
        $connection,
        $orderId,
        $snapshotSha256
    );
    $pending = red_store_lite_p3b4_order(
        $orderId,
        $snapshotSha256,
        'pending',
        'pending'
    );
    $paid = red_store_lite_p3b4_order(
        $orderId,
        $snapshotSha256,
        'paid',
        'paid'
    );
    $refunded = red_store_lite_p3b4_order(
        $orderId,
        $snapshotSha256,
        'refunded',
        'refunded'
    );
    $paidEvent = red_store_lite_p3b4_event(
        $pending,
        'paid',
        str_repeat('b', 64),
        1786901000
    );
    $refundEvent = red_store_lite_p3b4_event(
        $paid,
        'refund_confirmed',
        str_repeat('c', 64),
        1786902000
    );
    $outOfOrderEvent = red_store_lite_p3b4_event(
        $refunded,
        'reversal_reported',
        str_repeat('d', 64),
        1786903000
    );

    $enablePlan = red_addon_enable_transition_plan(
        $connection,
        $package,
        $actorId,
        $catalog
    );
    $enabled = red_addon_enable_package(
        $connection,
        $packageId,
        $projectRoot,
        $actorId,
        $enablePlan['planSha256'] ?? ''
    );
    $runtime = red_addon_runtime_bootstrap($connection, $projectRoot);
    red_addon_runtime_set_request_context($runtime['context']);
    red_store_lite_p3b4_assert(
        !empty($enablePlan['transitionReady']),
        'real Store Lite package receives an operational enablement plan'
    );
    red_store_lite_p3b4_assert(
        ($enabled['status'] ?? '') === 'enabled',
        'real Store Lite package enables atomically'
    );
    red_store_lite_p3b4_assert(
        red_addon_valid_sha256(
            $enabled['registrarEvidenceSha256'] ?? ''
        ),
        'enablement records bounded registrar evidence'
    );
    red_store_lite_p3b4_assert(
        red_addon_runtime_owner('services', 'commerce.orders') === $packageId,
        'enabled runtime establishes the request-local payment service owner'
    );
    red_store_lite_p3b4_assert(
        red_addon_runtime_owner('services', 'commerce.subscriptions')
            === $packageId,
        'enabled runtime establishes the subscription lifecycle service owner'
    );
    red_store_lite_p3b4_assert(
        red_addon_runtime_owner(
            'components',
            'redcms.store-lite/subscription'
        ) === $packageId
            && red_addon_runtime_owner(
                'routes',
                'redcms.store-lite/subscription-intent'
            ) === $packageId
            && red_addon_runtime_owner(
                'publicMutationHandlers',
                'redcms.store-lite/create-subscription-intent'
            ) === $packageId,
        'enabled runtime owns the subscription component and intent boundary'
    );
    $registrarEvidence = (string) $enabled['registrarEvidenceSha256'];

    $subscriptionProduct = [
        'id' => 'p3b4-membership',
        'type' => 'simple',
        'title' => 'P3B4 membership',
        'summary' => 'Disposable subscription fixture',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'imageRef' => null,
        'sku' => 'P3B4-MEMBERSHIP',
        'priceMinor' => 2900,
        'stock' => null,
        'options' => [],
        'variants' => [],
    ];
    $subscriptionOffer = [
        'id' => 'p3b4-membership-monthly',
        'productId' => 'p3b4-membership',
        'variantId' => null,
        'title' => 'P3B4 membership',
        'summary' => 'Disposable subscription fixture',
        'currency' => 'USD',
        'priceMinor' => 2900,
        'billingPeriod' => 'monthly',
        'state' => 'published',
        'availability' => 'available',
        'buttonLabel' => 'Subscribe monthly',
    ];
    mysqli_begin_transaction($connection);
    $subscriptionProductCreated =
        RED_CMS_Store_Lite_Catalog_Persistence::createWithinTransaction(
            $connection,
            $subscriptionProduct,
            'USD'
        );
    $subscriptionOfferCreated =
        RED_CMS_Store_Lite_Subscription_Offer_Persistence::
            createWithinTransaction(
                $connection,
                $subscriptionOffer,
                'USD'
            );
    mysqli_commit($connection);
    red_store_lite_p3b4_assert(
        ($subscriptionProductCreated['status'] ?? '') === 'created'
            && ($subscriptionOfferCreated['status'] ?? '') === 'created',
        'disposable published product and subscription offer are created'
    );

    red_store_lite_p3b4_insert_article(
        $connection,
        9401,
        'redcms.store-lite/subscription'
    );
    mysqli_begin_transaction($connection);
    $subscriptionPlacementCreated =
        RED_CMS_Store_Lite_Subscription_Component_Bridge::create(
            $connection,
            [
                'component' => 'redcms.store-lite/subscription',
                'contentRecordId' => 9401,
                'actorRecordId' => $actorId,
                'planHash' => str_repeat('e', 64),
            ],
            ['offer-id' => 'p3b4-membership-monthly']
        );
    mysqli_commit($connection);
    red_store_lite_p3b4_assert(
        $subscriptionPlacementCreated
            && RED_CMS_Store_Lite_Subscription_Component_Bridge::load(
                $connection,
                [
                    'component' => 'redcms.store-lite/subscription',
                    'contentRecordId' => 9401,
                ]
            ) === ['offer-id' => 'p3b4-membership-monthly'],
        'subscription component placement persists its exact offer binding'
    );

    require_once $projectRoot
        . '/includes/addon_public_mutation_page_helpers.php';
    require_once $projectRoot . '/includes/addon_component_render_helpers.php';
    red_addon_public_mutation_page_begin(true, '');
    ob_start();
    $subscriptionRendered = red_addon_public_component_render(
        [
            'component' => 'redcms.store-lite/subscription',
            'active' => true,
            'inputs' => [
                'recordId' => 9401,
                'layout' => 'Main',
                'article' => 'subscription-preview',
                'position' => 1,
            ],
        ],
        $connection
    );
    $subscriptionHtml = (string) ob_get_clean();
    $subscriptionPageContext = red_addon_public_mutation_page_context_current();
    red_store_lite_p3b4_assert(
        $subscriptionRendered === true
            && str_contains(
                $subscriptionHtml,
                'data-red-addon-component="redcms.store-lite/subscription"'
            )
            && str_contains($subscriptionHtml, 'Subscribe monthly')
            && str_contains(
                $subscriptionHtml,
                '/addons/redcms/store-lite/subscription-intent'
            )
            && ($subscriptionPageContext['formCount'] ?? 0) === 1
            && ($subscriptionPageContext['subjectRecordId'] ?? 0) > 0,
        'core renders one CSRF-backed visible subscription button locally'
    );

    $subscriptionSubjectRecordId =
        (int) $subscriptionPageContext['subjectRecordId'];
    $subscriptionRuntimeSettings =
        new RED_Addon_Public_Mutation_Runtime_Settings(
            ['catalog.currency' => 'USD'],
            hash('sha256', 'p3b4-subscription-runtime-settings'),
            true
        );
    $subscriptionCommand = new RED_Addon_Public_Mutation_Command(
        $packageId,
        'redcms.store-lite/subscription-intent',
        'redcms.store-lite/create-subscription-intent',
        $subscriptionSubjectRecordId,
        ['offer' => 'p3b4-membership-monthly'],
        $subscriptionRuntimeSettings
    );
    mysqli_begin_transaction($connection);
    $subscriptionIntentBefore =
        RED_CMS_Store_Lite_Subscription_Intent_Bridge::load(
            $connection,
            $subscriptionCommand
        );
    $subscriptionIntentCreated =
        RED_CMS_Store_Lite_Subscription_Intent_Bridge::execute(
            $connection,
            new RED_Addon_Public_Mutation_Execution_Request(
                $subscriptionCommand,
                hash('sha256', 'p3b4-subscription-previous-state'),
                hash('sha256', 'p3b4-subscription-plan')
            )
        );
    mysqli_commit($connection);
    mysqli_begin_transaction($connection);
    $subscriptionIntentReplayed =
        RED_CMS_Store_Lite_Subscription_Intent_Bridge::execute(
            $connection,
            new RED_Addon_Public_Mutation_Execution_Request(
                $subscriptionCommand,
                hash('sha256', 'p3b4-subscription-created-state'),
                hash('sha256', 'p3b4-subscription-plan')
            )
        );
    mysqli_commit($connection);
    red_store_lite_p3b4_assert(
        ($subscriptionIntentBefore->state()['status'] ?? '') === 'absent'
            && $subscriptionIntentCreated->outcome() === 'accepted'
            && ($subscriptionIntentCreated->state()->state()['status'] ?? '')
                === 'requested'
            && $subscriptionIntentReplayed->outcome() === 'unchanged'
            && red_store_lite_p3b4_scalar(
                $connection,
                'SELECT COUNT(*) '
                    . 'FROM RED_Addon_StoreLite_Subscription_Intents'
            ) === '1',
        'provider-neutral intent persists once and exact replay is unchanged'
    );

    $intentState = RED_CMS_Store_Lite_Subscription_Intent_Persistence::read(
        $connection,
        $subscriptionSubjectRecordId,
        'p3b4-membership-monthly',
        'USD'
    );
    $checkoutProjection = red_addon_service_invoke(
        'commerce.subscriptions',
        'subscription.checkout.load',
        [
            'subjectRecordId' => $subscriptionSubjectRecordId,
            'offerId' => 'p3b4-membership-monthly',
        ]
    );
    red_store_lite_p3b4_assert(
        ($checkoutProjection['success'] ?? false) === true
            && ($checkoutProjection['data']['intent']['intentStateSha256']
                ?? '') === $intentState['intentStateSha256']
            && ($checkoutProjection['data']['offer']['id'] ?? '')
                === 'p3b4-membership-monthly'
            && ($checkoutProjection['data']['offer']['priceMinor'] ?? 0)
                === 2900,
        'read-only lifecycle service returns authoritative intent and offer facts'
    );
    $intentReference = 'sint_' . substr(hash(
        'sha256',
        $subscriptionSubjectRecordId . ':p3b4-membership-monthly:'
            . $intentState['intentStateSha256']
    ), 0, 32);
    $preparedSubscription = red_addon_service_invoke(
        'commerce.subscriptions',
        'subscription.checkout.prepare',
        [
            'intent' => [
                'subjectRecordId' => $subscriptionSubjectRecordId,
                'offerId' => 'p3b4-membership-monthly',
                'intentReference' => $intentReference,
                'intentStateSha256' => $intentState['intentStateSha256'],
                'offerStateSha256' => $intentState['offerStateSha256'],
            ],
            'checkout' => [
                'checkoutSessionRefSha256' => hash(
                    'sha256',
                    'cs_test_p3b4_subscription'
                ),
                'responseEvidenceSha256' => hash(
                    'sha256',
                    'p3b4-subscription-checkout-response'
                ),
                'expiresAtEpoch' => 1787632200,
                'occurredAt' => 1787630400,
            ],
        ]
    );
    red_store_lite_p3b4_assert(
        ($preparedSubscription['success'] ?? false) === true
            && ($preparedSubscription['data']['status'] ?? '') === 'prepared'
            && ($preparedSubscription['data']['subscriptionStatus'] ?? '')
                === 'pending'
            && ($preparedSubscription['data']['entitlementStatus'] ?? '')
                === 'inactive',
        'synthetic Checkout preparation records pending inactive lifecycle state'
    );
    $pendingSubscription =
        RED_CMS_Store_Lite_Subscription_Lifecycle_Persistence::read(
            $connection,
            $intentReference
        );
    $providerSubscriptionSha256 = hash(
        'sha256',
        'sub_test_p3b4_subscription'
    );
    $activationEvent = [
        'verification' => 'verified',
        'replayStatus' => 'unseen',
        'intentReference' => $intentReference,
        'offerStateSha256' => $intentState['offerStateSha256'],
        'outcome' => 'activated',
        'providerSubscriptionRefSha256' => $providerSubscriptionSha256,
        'currentPeriodEndEpoch' => 1790308800,
        'eventEvidenceSha256' => hash(
            'sha256',
            'p3b4-subscription-activated'
        ),
        'occurredAt' => 1787630500,
    ];
    $activatedSubscription = red_addon_service_invoke(
        'commerce.subscriptions',
        'subscription.event.apply',
        [
            'current' => $pendingSubscription['current'],
            'event' => $activationEvent,
        ]
    );
    $replayedSubscription = red_addon_service_invoke(
        'commerce.subscriptions',
        'subscription.event.apply',
        [
            'current' => $pendingSubscription['current'],
            'event' => $activationEvent,
        ]
    );
    red_store_lite_p3b4_assert(
        ($activatedSubscription['success'] ?? false) === true
            && ($activatedSubscription['data']['status'] ?? '') === 'applied'
            && ($activatedSubscription['data']['subscriptionStatus'] ?? '')
                === 'active'
            && ($activatedSubscription['data']['entitlementStatus'] ?? '')
                === 'active'
            && ($replayedSubscription['success'] ?? false) === true
            && ($replayedSubscription['data']['status'] ?? '') === 'replayed'
            && red_store_lite_p3b4_scalar(
                $connection,
                'SELECT COUNT(*) FROM '
                    . 'RED_Addon_StoreLite_Subscription_Status_History'
            ) === '2',
        'verified activation grants entitlement once and exact replay adds no row'
    );

    mysqli_query(
        $connection,
        'ALTER TABLE RED_Addon_StoreLite_Order_Status_History
         ADD CONSTRAINT chk_sl_p3b4_forced_history
         CHECK (EventName <> \'payment.paid\')'
    );
    $forcedFailure = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $pending, 'event' => $paidEvent]
    );
    mysqli_query(
        $connection,
        'ALTER TABLE RED_Addon_StoreLite_Order_Status_History
         DROP CHECK chk_sl_p3b4_forced_history'
    );
    red_store_lite_p3b4_assert(
        ($forcedFailure['invoked'] ?? false) === true
            && ($forcedFailure['success'] ?? true) === false
            && ($forcedFailure['error'] ?? '')
                === 'payment_event_storage_unavailable'
            && red_store_lite_p3b4_scalar(
                $connection,
                "SELECT CONCAT_WS(':', OrderStatus, PaymentStatus,
                    FulfillmentStatus,
                    (SELECT COUNT(*)
                     FROM RED_Addon_StoreLite_Order_Status_History
                     WHERE OrderRecordID=orders.RecordID))
                 FROM RED_Addon_StoreLite_Orders AS orders
                 WHERE OrderID='$orderId'"
            ) === 'pending:pending:unfulfilled:1',
        'forced late history failure rolls back the enabled service transaction'
    );

    $appliedPaid = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $pending, 'event' => $paidEvent]
    );
    $replayedPaid = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $pending, 'event' => $paidEvent]
    );
    red_store_lite_p3b4_assert(
        ($appliedPaid['success'] ?? false) === true
            && ($appliedPaid['data']['status'] ?? '') === 'applied'
            && ($replayedPaid['success'] ?? false) === true
            && ($replayedPaid['data']['status'] ?? '') === 'replayed'
            && hash_equals(
                (string) ($appliedPaid['data']['planSha256'] ?? ''),
                (string) ($replayedPaid['data']['planSha256'] ?? '')
            )
            && red_store_lite_p3b4_scalar(
                $connection,
                "SELECT CONCAT_WS(':', OrderStatus, PaymentStatus,
                    FulfillmentStatus,
                    (SELECT COUNT(*)
                     FROM RED_Addon_StoreLite_Order_Status_History
                     WHERE OrderRecordID=orders.RecordID))
                 FROM RED_Addon_StoreLite_Orders AS orders
                 WHERE OrderID='$orderId'"
            ) === 'paid:paid:unfulfilled:2',
        'paid applies once and exact duplicate evidence replays without a row'
    );

    $appliedRefund = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $paid, 'event' => $refundEvent]
    );
    $outOfOrder = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $refunded, 'event' => $outOfOrderEvent]
    );
    red_store_lite_p3b4_assert(
        ($appliedRefund['success'] ?? false) === true
            && ($appliedRefund['data']['status'] ?? '') === 'applied'
            && ($outOfOrder['success'] ?? true) === false
            && ($outOfOrder['error'] ?? '') === 'payment_event_refused'
            && red_store_lite_p3b4_scalar(
                $connection,
                "SELECT CONCAT_WS(':', OrderStatus, PaymentStatus,
                    FulfillmentStatus,
                    (SELECT COUNT(*)
                     FROM RED_Addon_StoreLite_Order_Status_History
                     WHERE OrderRecordID=orders.RecordID))
                 FROM RED_Addon_StoreLite_Orders AS orders
                 WHERE OrderID='$orderId'"
            ) === 'refunded:refunded:unfulfilled:3',
        'confirmed refund commits and later out-of-order reversal is refused'
    );
    $retainedFingerprint = red_store_lite_p3b4_data_fingerprint($connection);

    $disablePlan = red_addon_disable_transition_plan(
        $connection,
        $package,
        $actorId,
        $catalog
    );
    $disabled = red_addon_disable_package(
        $connection,
        $packageId,
        $projectRoot,
        $actorId,
        $disablePlan['planSha256'] ?? ''
    );
    unset($GLOBALS['RED_ADDON_RUNTIME_CONTEXT']);
    $disabledRuntime = red_addon_runtime_bootstrap($connection, $projectRoot);
    red_addon_runtime_set_request_context($disabledRuntime['context']);
    $stopped = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $paid, 'event' => $refundEvent]
    );
    $subscriptionStopped = red_addon_service_invoke(
        'commerce.subscriptions',
        'subscription.event.apply',
        ['current' => [], 'event' => []]
    );
    red_store_lite_p3b4_assert(
        !empty($disablePlan['transitionReady'])
            && ($disabled['status'] ?? '') === 'installed_disabled'
            && ($stopped['invoked'] ?? true) === false
            && ($stopped['reason'] ?? '') === 'service_unavailable'
            && red_addon_runtime_owner('services', 'commerce.orders') === null
            && ($subscriptionStopped['invoked'] ?? true) === false
            && red_addon_runtime_owner(
                'services',
                'commerce.subscriptions'
            ) === null
            && hash_equals(
                $retainedFingerprint,
                red_store_lite_p3b4_data_fingerprint($connection)
            ),
        'real disablement stops service execution and retains exact order rows'
    );

    $reEnablePlan = red_addon_enable_transition_plan(
        $connection,
        $package,
        $actorId,
        $catalog
    );
    $reEnabled = red_addon_enable_package(
        $connection,
        $packageId,
        $projectRoot,
        $actorId,
        $reEnablePlan['planSha256'] ?? ''
    );
    unset($GLOBALS['RED_ADDON_RUNTIME_CONTEXT']);
    $reEnabledRuntime = red_addon_runtime_bootstrap(
        $connection,
        $projectRoot
    );
    red_addon_runtime_set_request_context($reEnabledRuntime['context']);
    $restoredReplay = red_addon_service_invoke(
        'commerce.orders',
        'payment.event.apply',
        ['order' => $paid, 'event' => $refundEvent]
    );
    red_store_lite_p3b4_assert(
        ($reEnabled['status'] ?? '') === 'enabled'
            && hash_equals(
                $registrarEvidence,
                (string) ($reEnabled['registrarEvidenceSha256'] ?? '')
            )
            && red_addon_runtime_owner('services', 'commerce.orders')
                === $packageId
            && red_addon_runtime_owner(
                'services',
                'commerce.subscriptions'
            ) === $packageId
            && ($restoredReplay['success'] ?? false) === true
            && ($restoredReplay['data']['status'] ?? '') === 'replayed'
            && hash_equals(
                $retainedFingerprint,
                red_store_lite_p3b4_data_fingerprint($connection)
            ),
        're-enable restores identical ownership and retained-event replay'
    );
    red_store_lite_p3b4_assert(
        red_store_lite_p3b4_scalar(
            $connection,
            "SELECT CONCAT_WS(':', LifecycleState,
                SUM(EventName='addon.enable.completed'),
                SUM(EventName='addon.disable.completed'))
             FROM RED_Addon_Installations installation
             INNER JOIN RED_Addon_Activity_Log activity
               ON activity.PackageID=installation.PackageID
             WHERE installation.PackageID='$packageId'"
        ) === 'enabled:2:1',
        'lifecycle finishes enabled with two enable and one disable facts'
    );

    echo json_encode(
        [
            'ok' => true,
            'packageVersion' => $snapshot['version'],
            'database' => $databaseName,
            'retainedDataSHA256' => $retainedFingerprint,
            'registrarEvidenceSHA256' => $registrarEvidence,
            'assertions' => $assertions,
        ],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    unset($GLOBALS['RED_ADDON_RUNTIME_CONTEXT']);
    $db->close();
    exit(1);
}

unset($GLOBALS['RED_ADDON_RUNTIME_CONTEXT']);
$db->close();
exit(0);
