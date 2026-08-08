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
$databaseName = 'redcms_store_lite_persistence_' .
    gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
$assertions = 0;
$databaseCreated = false;
$grantCreated = false;
$application = null;
$primary = null;
$admin = null;

function red_store_lite_persistence_assert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_persistence_connection(
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

function red_store_lite_persistence_value(
    mysqli $connection,
    string $sql
): string {
    $query = mysqli_query($connection, $sql);
    $row = mysqli_fetch_row($query);
    mysqli_free_result($query);
    return is_array($row) ? (string) ($row[0] ?? '') : '';
}

function red_store_lite_persistence_apply_sql(
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

function red_store_lite_persistence_product_counts(
    mysqli $connection,
    string $productId
): string {
    $escaped = mysqli_real_escape_string($connection, $productId);
    return red_store_lite_persistence_value(
        $connection,
        "SELECT CONCAT(
            COUNT(*), ':',
            COALESCE(SUM((SELECT COUNT(*)
                FROM RED_Addon_StoreLite_Product_Options AS product_options
                WHERE product_options.ProductRecordID=products.RecordID)), 0), ':',
            COALESCE(SUM((SELECT COUNT(*)
                FROM RED_Addon_StoreLite_Product_Option_Values AS option_values
                WHERE option_values.ProductRecordID=products.RecordID)), 0), ':',
            COALESCE(SUM((SELECT COUNT(*)
                FROM RED_Addon_StoreLite_Product_Variants AS variants
                WHERE variants.ProductRecordID=products.RecordID)), 0), ':',
            COALESCE(SUM((SELECT COUNT(*)
                FROM RED_Addon_StoreLite_Product_Variant_Selections AS selections
                WHERE selections.ProductRecordID=products.RecordID)), 0)
         )
         FROM RED_Addon_StoreLite_Products AS products
         WHERE products.ProductID='$escaped'"
    );
}

try {
    red_store_lite_persistence_assert(
        is_string($coreRoot) && is_dir($coreRoot),
        'RED-CMS core root resolves'
    );
    red_store_lite_persistence_assert(
        is_string($packageRoot) && is_dir($packageRoot),
        'Store Lite package root resolves'
    );
    red_store_lite_persistence_assert(
        preg_match('/\Aredcms_store_lite_persistence_[A-Za-z0-9_]+\z/', $databaseName) === 1
            && strlen($databaseName) <= 64,
        'disposable persistence database name is exact and bounded'
    );

    $configPath = $coreRoot . '/includes/config.local.php';
    if (!is_file($configPath)) {
        throw new RuntimeException(
            'RED-CMS local database configuration is required for this disposable test.'
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

    $primary = red_store_lite_persistence_connection(
        $databaseHost,
        $databasePort,
        $databaseUser,
        $databasePassword,
        $primaryDatabase
    );
    $primaryFingerprint = red_store_lite_persistence_value(
        $primary,
        "SELECT CONCAT(COUNT(*), ':',
            COALESCE(SUM(TABLE_NAME LIKE 'RED_Addon_StoreLite\\\\_%'), 0))
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA=DATABASE()"
    );
    $currentUser = red_store_lite_persistence_value(
        $primary,
        'SELECT CURRENT_USER()'
    );
    if (preg_match(
        '/\A([A-Za-z0-9_.-]+)@([A-Za-z0-9_.%-]+)\z/',
        $currentUser,
        $currentUserParts
    ) !== 1) {
        throw new RuntimeException('Application database account is invalid.');
    }

    $adminUser = getenv('RED_ACCEPTANCE_DB_ADMIN_USER');
    $adminPassword = getenv('RED_ACCEPTANCE_DB_ADMIN_PASS');
    $adminUser = is_string($adminUser) && $adminUser !== '' ? $adminUser : 'root';
    $adminPassword = is_string($adminPassword) ? $adminPassword : '';
    $admin = red_store_lite_persistence_connection(
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

    $application = red_store_lite_persistence_connection(
        $databaseHost,
        $databasePort,
        $databaseUser,
        $databasePassword,
        $databaseName
    );
    $manifest = json_decode(
        (string) file_get_contents($packageRoot . '/addon.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    foreach ($manifest['migrations'] ?? [] as $migration) {
        $migrationSql = file_get_contents(
            $packageRoot . '/' . ($migration['path'] ?? '')
        );
        if (!is_string($migrationSql)) {
            throw new RuntimeException('Catalog migration could not be read.');
        }
        red_store_lite_persistence_apply_sql($application, $migrationSql);
    }

    require_once $packageRoot . '/src/CatalogPersistence.php';

    $banana = [
        'id' => 'banana-bunch',
        'type' => 'simple',
        'title' => 'Banana bunch',
        'summary' => 'Six ripe bananas.',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'imageRef' => 'media:banana-bunch.jpg',
        'sku' => 'BANANA-BUNCH',
        'priceMinor' => 599,
        'stock' => 40,
    ];
    $bananaNormalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
        $banana,
        'USD'
    )['product'];
    $createdBanana = RED_CMS_Store_Lite_Catalog_Persistence::create(
        $application,
        $banana,
        'USD'
    );
    red_store_lite_persistence_assert(
        $createdBanana['status'] === 'created'
            && preg_match('/\A[a-f0-9]{64}\z/', $createdBanana['stateSha256']) === 1,
        'simple product creation commits with a bounded state hash'
    );
    $readBanana = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'banana-bunch',
        'USD'
    );
    red_store_lite_persistence_assert(
        $readBanana['status'] === 'found'
            && $readBanana['product'] === $bananaNormalized
            && hash_equals(
                $createdBanana['stateSha256'],
                $readBanana['stateSha256']
            ),
        'simple product reload exactly reconstructs the normalized record'
    );
    red_store_lite_persistence_assert(
        red_store_lite_persistence_product_counts(
            $application,
            'banana-bunch'
        ) === '1:0:0:0:0',
        'simple product writes no variable-product child rows'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Catalog_Persistence::create(
            $application,
            $banana,
            'USD'
        )['status'] === 'already_exists',
        'duplicate product creation is refused without replacement'
    );

    $shirt = [
        'id' => 'classic-shirt',
        'type' => 'variable',
        'title' => 'Classic T-shirt',
        'summary' => 'A soft everyday shirt.',
        'currency' => 'USD',
        'state' => 'draft',
        'availability' => 'available',
        'imageRef' => 'media:classic-shirt.jpg',
        'options' => [[
            'key' => 'size',
            'label' => 'Size',
            'values' => [
                ['id' => 'small', 'label' => 'Small'],
                ['id' => 'large', 'label' => 'Large'],
            ],
        ], [
            'key' => 'color',
            'label' => 'Color',
            'values' => [
                ['id' => 'black', 'label' => 'Black'],
                ['id' => 'white', 'label' => 'White'],
            ],
        ]],
        'variants' => [[
            'id' => 'small-black',
            'sku' => 'SHIRT-S-BLACK',
            'options' => ['size' => 'small', 'color' => 'black'],
            'priceMinor' => 2499,
            'availability' => 'available',
            'stock' => 8,
            'imageRef' => 'media:shirt-black.jpg',
        ], [
            'id' => 'large-white',
            'sku' => 'SHIRT-L-WHITE',
            'options' => ['size' => 'large', 'color' => 'white'],
            'priceMinor' => 2699,
            'availability' => 'available',
            'stock' => 5,
            'imageRef' => null,
        ]],
    ];
    $shirtNormalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
        $shirt,
        'USD'
    )['product'];
    $createdShirt = RED_CMS_Store_Lite_Catalog_Persistence::create(
        $application,
        $shirt,
        'USD'
    );
    $readShirt = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $createdShirt['status'] === 'created'
            && $readShirt['status'] === 'found'
            && $readShirt['product'] === $shirtNormalized,
        'variable product create and reload preserve exact option and variant order'
    );
    red_store_lite_persistence_assert(
        red_store_lite_persistence_product_counts(
            $application,
            'classic-shirt'
        ) === '1:2:4:2:4',
        'variable product writes the exact bounded catalog graph'
    );

    $updatedShirt = $shirt;
    $updatedShirt['title'] = 'Classic organic T-shirt';
    $updatedShirt['state'] = 'published';
    $updatedShirt['variants'][0]['priceMinor'] = 2599;
    $updatedNormalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
        $updatedShirt,
        'USD'
    )['product'];
    $stale = RED_CMS_Store_Lite_Catalog_Persistence::replace(
        $application,
        $updatedShirt,
        'USD',
        str_repeat('0', 64)
    );
    red_store_lite_persistence_assert(
        $stale['status'] === 'stale_state'
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'classic-shirt',
                'USD'
            )['product'] === $shirtNormalized,
        'stale replacement is refused before any catalog mutation'
    );
    $updated = RED_CMS_Store_Lite_Catalog_Persistence::replace(
        $application,
        $updatedShirt,
        'USD',
        $readShirt['stateSha256']
    );
    $readUpdated = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $updated['status'] === 'updated'
            && hash_equals(
                $readShirt['stateSha256'],
                $updated['previousStateSha256']
            )
            && $readUpdated['product'] === $updatedNormalized
            && hash_equals($updated['stateSha256'], $readUpdated['stateSha256']),
        'matching replacement atomically reloads the exact target graph'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Catalog_Persistence::replace(
            $application,
            $updatedShirt,
            'USD',
            $readUpdated['stateSha256']
        )['status'] === 'unchanged',
        'exact current replacement returns unchanged without rewriting children'
    );

    mysqli_begin_transaction($application);
    mysqli_query(
        $application,
        "UPDATE RED_Addon_StoreLite_Product_Variants
         SET OptionTupleSHA256=UNHEX(REPEAT('00', 32))
         WHERE ProductRecordID=(
             SELECT RecordID FROM RED_Addon_StoreLite_Products
             WHERE ProductID='classic-shirt'
         ) AND Position=1"
    );
    $corruptRead = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'classic-shirt',
        'USD'
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $corruptRead['status'] === 'storage_unavailable'
            && hash_equals(
                $readUpdated['stateSha256'],
                RED_CMS_Store_Lite_Catalog_Persistence::read(
                    $application,
                    'classic-shirt',
                    'USD'
                )['stateSha256']
            ),
        'reader fails closed on a mismatched stored option-tuple hash'
    );

    mysqli_query(
        $application,
        "ALTER TABLE RED_Addon_StoreLite_Product_Variants
         ADD CONSTRAINT chk_storelite_test_forced_rollback
         CHECK (SKU <> 'FAIL-ROLLBACK')"
    );
    $failedTarget = $updatedShirt;
    $failedTarget['title'] = 'This title must roll back';
    $failedTarget['variants'][0]['sku'] = 'FAIL-ROLLBACK';
    $failedWrite = RED_CMS_Store_Lite_Catalog_Persistence::replace(
        $application,
        $failedTarget,
        'USD',
        $readUpdated['stateSha256']
    );
    $afterFailure = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $failedWrite['status'] === 'write_failed'
            && $afterFailure['product'] === $updatedNormalized
            && hash_equals(
                $readUpdated['stateSha256'],
                $afterFailure['stateSha256']
            )
            && red_store_lite_persistence_product_counts(
                $application,
                'classic-shirt'
            ) === '1:2:4:2:4',
        'mid-graph database failure rolls back parent and every child mutation'
    );
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Product_Variants
         DROP CHECK chk_storelite_test_forced_rollback'
    );

    mysqli_begin_transaction($application);
    $callerOwned = RED_CMS_Store_Lite_Catalog_Persistence::create(
        $application,
        array_merge($banana, ['id' => 'caller-transaction']),
        'USD'
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $callerOwned['status'] === 'transaction_active'
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'caller-transaction',
                'USD'
            )['status'] === 'not_found',
        'caller-owned transaction is refused and remains caller controlled'
    );

    $invalid = $banana;
    $invalid['priceMinor'] = 5.99;
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Catalog_Persistence::create(
            $application,
            $invalid,
            'USD'
        ) === [
            'status' => 'invalid',
            'productId' => '',
            'previousStateSha256' => '',
            'targetStateSha256' => '',
            'stateSha256' => '',
        ],
        'invalid product fails closed with no SQL or value-bearing diagnostic'
    );

    mysqli_query(
        $application,
        "CREATE TABLE RED_Admin (
            RecordID int unsigned NOT NULL,
            Username varchar(64) NOT NULL,
            PRIMARY KEY (RecordID)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    mysqli_query(
        $application,
        "CREATE TABLE RED_Admin_Capabilities (
            AdminRecordID int unsigned NOT NULL,
            Capability varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (AdminRecordID, Capability),
            CONSTRAINT fk_red_admin_capabilities_admin
              FOREIGN KEY (AdminRecordID) REFERENCES RED_Admin (RecordID)
              ON DELETE CASCADE ON UPDATE RESTRICT
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    mysqli_query(
        $application,
        "INSERT INTO RED_Admin (RecordID, Username)
         VALUES (1, 'product-manager'), (2, 'owner-without-product-grant')"
    );
    mysqli_query(
        $application,
        "INSERT INTO RED_Admin_Capabilities (AdminRecordID, Capability)
         VALUES (1, 'store.products.manage')"
    );
    require_once $coreRoot .
        '/includes/addon_component_editor_authorization_helpers.php';
    require_once $packageRoot . '/src/CatalogAdministration.php';
    require_once $packageRoot . '/src/CatalogAdministrationAction.php';
    red_store_lite_persistence_assert(
        red_addon_component_editor_permission_storage_available($application),
        'disposable authorization fixture matches the RED-CMS capability contract'
    );

    $apple = array_merge($banana, [
        'id' => 'apple-box',
        'title' => 'Apple box',
        'sku' => 'APPLE-BOX',
        'priceMinor' => 1299,
        'stock' => 12,
        'imageRef' => 'media:apple-box.jpg',
    ]);
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Catalog_Persistence::create(
            $application,
            $apple,
            'USD'
        )['status'] === 'created',
        'administration fixture adds one third normalized catalog product'
    );

    $ownerWithoutGrant =
        RED_CMS_Store_Lite_Catalog_Administration::listProducts(
            $application,
            2,
            'USD'
        );
    red_store_lite_persistence_assert(
        $ownerWithoutGrant['authorized'] === false
            && $ownerWithoutGrant['loaded'] === false
            && $ownerWithoutGrant['items'] === []
            && $ownerWithoutGrant['reason'] === 'permission_denied',
        'Owner identity without the exact product grant receives no catalog data'
    );

    $firstPage = RED_CMS_Store_Lite_Catalog_Administration::listProducts(
        $application,
        1,
        'USD',
        2
    );
    red_store_lite_persistence_assert(
        $firstPage['authorized'] === true
            && $firstPage['loaded'] === true
            && array_column($firstPage['items'], 'id') === [
                'apple-box',
                'banana-bunch',
            ]
            && $firstPage['nextCursor'] === 'banana-bunch'
            && preg_match(
                '/\A[a-f0-9]{64}\z/',
                $firstPage['catalogStateSha256']
            ) === 1,
        'authorized product list is bounded, sorted, cursor-based, and hashed'
    );
    red_store_lite_persistence_assert(
        $firstPage['items'][0]['variantCount'] === 0
            && $firstPage['items'][0]['minimumPriceMinor'] === 1299
            && $firstPage['items'][0]['maximumPriceMinor'] === 1299
            && preg_match(
                '/\A[a-f0-9]{64}\z/',
                $firstPage['items'][0]['stateSha256']
            ) === 1,
        'simple-product summary uses server-loaded price and state evidence'
    );
    $secondPage = RED_CMS_Store_Lite_Catalog_Administration::listProducts(
        $application,
        1,
        'USD',
        2,
        $firstPage['nextCursor']
    );
    red_store_lite_persistence_assert(
        $secondPage['loaded'] === true
            && array_column($secondPage['items'], 'id') === ['classic-shirt']
            && $secondPage['nextCursor'] === null
            && $secondPage['items'][0]['variantCount'] === 2
            && $secondPage['items'][0]['minimumPriceMinor'] === 2599
            && $secondPage['items'][0]['maximumPriceMinor'] === 2699,
        'cursor continuation returns the remaining variable-product summary'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Catalog_Administration::listProducts(
            $application,
            1,
            'USD',
            2
        ) === $firstPage,
        'unchanged authorized catalog page reconstructs deterministically'
    );
    $invalidPage = RED_CMS_Store_Lite_Catalog_Administration::listProducts(
        $application,
        1,
        'USD',
        101
    );
    red_store_lite_persistence_assert(
        $invalidPage['authorized'] === true
            && $invalidPage['loaded'] === false
            && $invalidPage['items'] === []
            && $invalidPage['reason'] === 'invalid_request',
        'unbounded catalog page requests fail closed without partial items'
    );

    $deniedEdit = RED_CMS_Store_Lite_Catalog_Administration::editModel(
        $application,
        2,
        'classic-shirt',
        'USD'
    );
    $authorizedEdit = RED_CMS_Store_Lite_Catalog_Administration::editModel(
        $application,
        1,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $deniedEdit['product'] === null
            && $deniedEdit['stateSha256'] === ''
            && $deniedEdit['reason'] === 'permission_denied'
            && $authorizedEdit['loaded'] === true
            && $authorizedEdit['product'] === $updatedNormalized
            && hash_equals(
                $readUpdated['stateSha256'],
                $authorizedEdit['stateSha256']
            ),
        'full edit model is returned only after the fresh exact product grant'
    );

    $pear = array_merge($banana, [
        'id' => 'pear-basket',
        'title' => 'Pear basket',
        'sku' => 'PEAR-BASKET',
        'priceMinor' => 899,
        'stock' => 6,
        'imageRef' => null,
    ]);
    $createPlan =
        RED_CMS_Store_Lite_Catalog_Administration::createPreflight(
            $application,
            1,
            $pear,
            'USD'
        );
    red_store_lite_persistence_assert(
        $createPlan['authorized'] === true
            && $createPlan['ready'] === true
            && $createPlan['mode'] === 'create'
            && $createPlan['productId'] === 'pear-basket'
            && $createPlan['previousStateSha256'] === ''
            && preg_match('/\A[a-f0-9]{64}\z/', $createPlan['planSha256']) === 1
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'pear-basket',
                'USD'
            )['status'] === 'not_found',
        'create preflight binds normalized target and actor without writing it'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Catalog_Administration::createPreflight(
            $application,
            1,
            $banana,
            'USD'
        )['reason'] === 'already_exists',
        'create preflight refuses an existing product identity'
    );

    $plannedReplacement = $updatedShirt;
    $plannedReplacement['title'] = 'Planned title only';
    $stalePlan =
        RED_CMS_Store_Lite_Catalog_Administration::replacePreflight(
            $application,
            1,
            $plannedReplacement,
            'USD',
            str_repeat('0', 64)
        );
    red_store_lite_persistence_assert(
        $stalePlan['ready'] === false
            && $stalePlan['product'] === null
            && $stalePlan['reason'] === 'stale_state',
        'replace preflight refuses stale product evidence without a target plan'
    );
    $replacePlan =
        RED_CMS_Store_Lite_Catalog_Administration::replacePreflight(
            $application,
            1,
            $plannedReplacement,
            'USD',
            $readUpdated['stateSha256']
        );
    red_store_lite_persistence_assert(
        $replacePlan['ready'] === true
            && $replacePlan['unchanged'] === false
            && $replacePlan['reason'] === 'ready'
            && hash_equals(
                $readUpdated['stateSha256'],
                $replacePlan['previousStateSha256']
            )
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'classic-shirt',
                'USD'
            )['product'] === $updatedNormalized,
        'matching replace preflight creates deterministic evidence without mutation'
    );
    $unchangedPlan =
        RED_CMS_Store_Lite_Catalog_Administration::replacePreflight(
            $application,
            1,
            $updatedShirt,
            'USD',
            $readUpdated['stateSha256']
        );
    red_store_lite_persistence_assert(
        $unchangedPlan['ready'] === true
            && $unchangedPlan['unchanged'] === true
            && $unchangedPlan['reason'] === 'unchanged',
        'exact current replacement preflight identifies a no-op'
    );

    $createdByAction =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeCreate(
            $application,
            1,
            $pear,
            'USD',
            $createPlan['planSha256']
        );
    $pearAfterAction = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'pear-basket',
        'USD'
    );
    red_store_lite_persistence_assert(
        $createdByAction['authorized'] === true
            && $createdByAction['executed'] === true
            && $createdByAction['reason'] === 'created'
            && $createdByAction['activityRecordId'] > 0
            && $pearAfterAction['status'] === 'found'
            && hash_equals(
                $createdByAction['stateSha256'],
                $pearAfterAction['stateSha256']
            ),
        'create action reauthorizes the exact plan and commits its product'
    );
    red_store_lite_persistence_assert(
        red_store_lite_persistence_value(
            $application,
            "SELECT CONCAT(
                EventName, ':', ProductID, ':', ActorAdminRecordID, ':',
                IFNULL(LOWER(HEX(PreviousStateSHA256)), 'null'), ':',
                LOWER(HEX(StateSHA256)))
             FROM RED_Addon_StoreLite_Product_Activity
             WHERE RecordID=" . (int) $createdByAction['activityRecordId']
        ) === 'product.created:pear-basket:1:null:' .
            $pearAfterAction['stateSha256'],
        'create activity contains only exact actor, identity, event, and state evidence'
    );
    $activityAfterCreate = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
    );
    $replayedCreate =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeCreate(
            $application,
            1,
            $pear,
            'USD',
            $createPlan['planSha256']
        );
    red_store_lite_persistence_assert(
        $replayedCreate['executed'] === false
            && $replayedCreate['reason'] === 'already_exists'
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
            ) === $activityAfterCreate,
        'consumed create plan cannot replay or append duplicate activity'
    );

    $updatedByAction =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeReplace(
            $application,
            1,
            $plannedReplacement,
            'USD',
            $readUpdated['stateSha256'],
            $replacePlan['planSha256']
        );
    $shirtAfterAction = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $updatedByAction['executed'] === true
            && $updatedByAction['reason'] === 'updated'
            && $shirtAfterAction['product']['title'] === 'Planned title only'
            && hash_equals(
                $updatedByAction['stateSha256'],
                $shirtAfterAction['stateSha256']
            ),
        'replace action commits only the exact current-state and target plan'
    );
    red_store_lite_persistence_assert(
        red_store_lite_persistence_value(
            $application,
            "SELECT CONCAT(
                EventName, ':', ProductID, ':', ActorAdminRecordID, ':',
                LOWER(HEX(PreviousStateSHA256)), ':',
                LOWER(HEX(StateSHA256)))
             FROM RED_Addon_StoreLite_Product_Activity
             WHERE RecordID=" . (int) $updatedByAction['activityRecordId']
        ) === 'product.updated:classic-shirt:1:' .
            $readUpdated['stateSha256'] . ':' .
            $shirtAfterAction['stateSha256'],
        'replace activity binds both prior and committed product states'
    );

    $currentNoOpPlan =
        RED_CMS_Store_Lite_Catalog_Administration::replacePreflight(
            $application,
            1,
            $plannedReplacement,
            'USD',
            $shirtAfterAction['stateSha256']
        );
    $activityBeforeNoOp = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
    );
    $noOpAction =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeReplace(
            $application,
            1,
            $plannedReplacement,
            'USD',
            $shirtAfterAction['stateSha256'],
            $currentNoOpPlan['planSha256']
        );
    red_store_lite_persistence_assert(
        $noOpAction['executed'] === false
            && $noOpAction['unchanged'] === true
            && $noOpAction['reason'] === 'unchanged'
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
            ) === $activityBeforeNoOp,
        'unchanged action rolls back without recording a product event'
    );

    $substitutedTarget = $plannedReplacement;
    $substitutedTarget['title'] = 'Substituted after preflight';
    $substituted =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeReplace(
            $application,
            1,
            $substitutedTarget,
            'USD',
            $shirtAfterAction['stateSha256'],
            $currentNoOpPlan['planSha256']
        );
    red_store_lite_persistence_assert(
        $substituted['executed'] === false
            && $substituted['reason'] === 'plan_changed'
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'classic-shirt',
                'USD'
            )['product'] === $shirtAfterAction['product']
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
            ) === $activityBeforeNoOp,
        'target substitution after preflight is refused without state or activity'
    );

    mysqli_query(
        $application,
        "DELETE FROM RED_Admin_Capabilities
         WHERE AdminRecordID=1 AND Capability='store.products.manage'"
    );
    $revokedAction =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeReplace(
            $application,
            1,
            $plannedReplacement,
            'USD',
            $shirtAfterAction['stateSha256'],
            $currentNoOpPlan['planSha256']
        );
    red_store_lite_persistence_assert(
        $revokedAction['authorized'] === false
            && $revokedAction['executed'] === false
            && $revokedAction['reason'] === 'permission_denied'
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
            ) === $activityBeforeNoOp,
        'grant revoked after planning refuses execution and activity'
    );
    mysqli_query(
        $application,
        "INSERT INTO RED_Admin_Capabilities (AdminRecordID, Capability)
         VALUES (1, 'store.products.manage')"
    );

    $rollbackTarget = $plannedReplacement;
    $rollbackTarget['title'] = 'Activity failure must roll back';
    mysqli_query(
        $application,
        "INSERT INTO RED_Admin_Capabilities (AdminRecordID, Capability)
         VALUES (2, 'store.products.manage')"
    );
    $rollbackPlan =
        RED_CMS_Store_Lite_Catalog_Administration::replacePreflight(
            $application,
            2,
            $rollbackTarget,
            'USD',
            $shirtAfterAction['stateSha256']
        );
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Product_Activity
         ADD CONSTRAINT chk_storelite_test_activity_failure
         CHECK (ActorAdminRecordID <> 2)'
    );
    $activityFailure =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeReplace(
            $application,
            2,
            $rollbackTarget,
            'USD',
            $shirtAfterAction['stateSha256'],
            $rollbackPlan['planSha256']
        );
    $afterActivityFailure = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $activityFailure['executed'] === false
            && $activityFailure['reason'] === 'activity_failed'
            && $afterActivityFailure['product'] === $shirtAfterAction['product']
            && hash_equals(
                $shirtAfterAction['stateSha256'],
                $afterActivityFailure['stateSha256']
            )
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
            ) === $activityBeforeNoOp,
        'activity insert failure rolls the complete product mutation back'
    );
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Product_Activity
         DROP CHECK chk_storelite_test_activity_failure'
    );
    mysqli_query(
        $application,
        "DELETE FROM RED_Admin_Capabilities
         WHERE AdminRecordID=2 AND Capability='store.products.manage'"
    );

    mysqli_begin_transaction($application);
    $actionInCallerTransaction =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeCreate(
            $application,
            1,
            array_merge($pear, ['id' => 'action-caller-transaction']),
            'USD',
            str_repeat('a', 64)
        );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $actionInCallerTransaction['executed'] === false
            && $actionInCallerTransaction['reason'] === 'transaction_active'
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'action-caller-transaction',
                'USD'
            )['status'] === 'not_found',
        'action runner refuses a caller-owned transaction before preflight'
    );

    mysqli_query(
        $application,
        "DELETE FROM RED_Admin_Capabilities
         WHERE AdminRecordID=1 AND Capability='store.products.manage'"
    );
    $revoked = RED_CMS_Store_Lite_Catalog_Administration::editModel(
        $application,
        1,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $revoked['authorized'] === false
            && $revoked['loaded'] === false
            && $revoked['product'] === null
            && $revoked['reason'] === 'permission_denied',
        'grant revocation immediately removes Store Lite product access'
    );
} finally {
    if ($application instanceof mysqli) {
        mysqli_close($application);
        $application = null;
    }
    if ($admin instanceof mysqli
        && $databaseCreated
        && preg_match(
            '/\Aredcms_store_lite_persistence_[A-Za-z0-9_]+\z/',
            $databaseName
        ) === 1
    ) {
        $cleanupErrors = [];
        if ($grantCreated && isset($applicationAccount)) {
            try {
                mysqli_query(
                    $admin,
                    'REVOKE ALL PRIVILEGES ON `' . $databaseName . '`.* FROM ' .
                        $applicationAccount
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
        red_store_lite_persistence_assert(
            $cleanupErrors === [],
            'disposable persistence database and scoped grant cleanup succeeds'
        );
        red_store_lite_persistence_assert(
            red_store_lite_persistence_value(
                $admin,
                "SELECT CONCAT(
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA
                     WHERE SCHEMA_NAME LIKE 'redcms_store_lite_persistence_%'), ':',
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMA_PRIVILEGES
                     WHERE TABLE_SCHEMA LIKE 'redcms_store_lite_persistence_%')
                 )"
            ) === '0:0',
            'no Store Lite persistence database or scoped grant remains'
        );
    }
    if ($primary instanceof mysqli) {
        if (isset($primaryFingerprint)) {
            red_store_lite_persistence_assert(
                hash_equals(
                    $primaryFingerprint,
                    red_store_lite_persistence_value(
                        $primary,
                        "SELECT CONCAT(COUNT(*), ':',
                            COALESCE(SUM(TABLE_NAME LIKE 'RED_Addon_StoreLite\\\\_%'), 0))
                         FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA=DATABASE()"
                    )
                ),
                'configured primary database table boundary remains unchanged'
            );
        }
        mysqli_close($primary);
    }
    if ($admin instanceof mysqli) {
        mysqli_close($admin);
    }
}

echo 'Store Lite catalog persistence passed ' . $assertions . " assertions.\n";
