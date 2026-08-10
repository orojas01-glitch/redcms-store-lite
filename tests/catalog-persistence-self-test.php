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
$redStoreLiteCartSubjectContext = [
    'valid' => false,
    'subjectRecordId' => 0,
    'reason' => 'subject_unavailable',
];

function red_addon_public_mutation_page_subject_context($connection): array
{
    global $redStoreLiteCartSubjectContext;
    return $redStoreLiteCartSubjectContext;
}

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

function red_store_lite_persistence_transaction_active(
    mysqli $connection
): bool {
    try {
        mysqli_query(
            $connection,
            'SAVEPOINT redcms_store_lite_test_transaction'
        );
        mysqli_query(
            $connection,
            'RELEASE SAVEPOINT redcms_store_lite_test_transaction'
        );
        return true;
    } catch (Throwable $throwable) {
        return false;
    }
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

function red_store_lite_persistence_browser_product(array $product): array
{
    $browser = $product;
    $browser['summary'] = $product['summary'] ?? '';
    $browser['imageRef'] = $product['imageRef'] ?? '';
    if (($product['type'] ?? null) === 'simple') {
        $browser['priceMinor'] = (string) $product['priceMinor'];
        $browser['stock'] = $product['stock'] === null
            ? ''
            : (string) $product['stock'];
        return $browser;
    }
    $browser['sku'] = '';
    $browser['priceMinor'] = '';
    $browser['stock'] = '';
    foreach ($browser['variants'] as $index => $variant) {
        $browser['variants'][$index]['priceMinor'] =
            (string) $variant['priceMinor'];
        $browser['variants'][$index]['stock'] = $variant['stock'] === null
            ? ''
            : (string) $variant['stock'];
        $browser['variants'][$index]['imageRef'] = $variant['imageRef'] ?? '';
    }
    return $browser;
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
    mysqli_query(
        $application,
        "CREATE TABLE RED_Articles (
            RecordID int unsigned NOT NULL,
            PRIMARY KEY (RecordID)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
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
    require_once $packageRoot . '/src/CartPersistence.php';
    require_once $packageRoot . '/src/CartReadModel.php';
    require_once $packageRoot . '/src/CartComponentBridge.php';
    require_once $coreRoot .
        '/includes/addon_public_mutation_execution_helpers.php';
    require_once $packageRoot . '/src/CartMutationBridge.php';

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

    require_once $packageRoot . '/src/ProductComponentBridge.php';
    mysqli_query(
        $application,
        'INSERT INTO RED_Articles (RecordID) VALUES (101), (102)'
    );
    $createPlacementContext = [
        'component' => RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
        'contentRecordId' => 101,
        'actorRecordId' => 1,
        'planHash' => str_repeat('a', 64),
    ];
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Product_Component_Bridge::create(
            $application,
            $createPlacementContext,
            ['product-id' => 'banana-bunch']
        ) === false,
        'Product placement creator refuses caller use outside an active transaction'
    );
    mysqli_begin_transaction($application);
    $placementCreated = RED_CMS_Store_Lite_Product_Component_Bridge::create(
        $application,
        $createPlacementContext,
        ['product-id' => 'banana-bunch']
    );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $placementCreated === true
            && RED_CMS_Store_Lite_Product_Component_Bridge::load(
                $application,
                [
                    'component' =>
                        RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
                    'contentRecordId' => 101,
                ]
            ) === ['product-id' => 'banana-bunch'],
        'Product placement creator and loader persist only the selected Product ID'
    );
    foreach ([
        'DBHOST' => $hostPort,
        'DBUSER' => $databaseUser,
        'DBPASS' => $databasePassword,
        'DBNAME' => $databaseName,
    ] as $constant => $value) {
        if (!defined($constant)) {
            define($constant, $value);
        }
    }
    $simpleRuntimeView =
        RED_CMS_Store_Lite_Product_Component_Bridge::render([
            'component' =>
                RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
            'placement' => [
                'recordId' => 101,
                'layout' => 'homepage',
                'article' => 'store',
                'position' => 1,
            ],
        ]);
    $simpleExpectedView =
        RED_CMS_Store_Lite_Public_Product_Presenter::present(
            $readBanana['product'],
            'USD'
        );
    $simpleExpectedView['mutationForm'] =
        RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
            $readBanana['product'],
            'USD'
        );
    require_once $coreRoot . '/includes/addon_component_render_helpers.php';
    red_store_lite_persistence_assert(
        $simpleRuntimeView === $simpleExpectedView
            && array_column(
                $simpleRuntimeView['mutationForm']['fields'],
                'key'
            ) === ['product', 'quantity'],
        'simple Product runtime returns display facts plus its data-only mutation presentation'
    );
    red_store_lite_persistence_assert(
        red_addon_public_component_view_model($simpleRuntimeView)
            === $simpleRuntimeView,
        'current RED-CMS core accepts the exact simple Product runtime model'
    );
    $writePlacementContext = [
        'component' => RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
        'contentRecordId' => 101,
        'actorRecordId' => 1,
        'previousStateHash' => str_repeat('b', 64),
    ];
    mysqli_begin_transaction($application);
    $placementWritten = RED_CMS_Store_Lite_Product_Component_Bridge::write(
        $application,
        $writePlacementContext,
        ['product-id' => 'classic-shirt']
    );
    $placementInsideWrite = RED_CMS_Store_Lite_Product_Component_Bridge::load(
        $application,
        [
            'component' =>
                RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
            'contentRecordId' => 101,
        ]
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $placementWritten === true
            && $placementInsideWrite === ['product-id' => 'classic-shirt']
            && RED_CMS_Store_Lite_Product_Component_Bridge::load(
                $application,
                [
                    'component' =>
                        RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
                    'contentRecordId' => 101,
                ]
            ) === ['product-id' => 'banana-bunch'],
        'Product placement writer participates in the caller-owned rollback boundary'
    );
    mysqli_begin_transaction($application);
    $placementWritten = RED_CMS_Store_Lite_Product_Component_Bridge::write(
        $application,
        $writePlacementContext,
        ['product-id' => 'classic-shirt']
    );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $placementWritten === true
            && RED_CMS_Store_Lite_Product_Component_Bridge::load(
                $application,
                [
                    'component' =>
                        RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
                    'contentRecordId' => 101,
                ]
            ) === ['product-id' => 'classic-shirt'],
        'Product placement writer commits an exact existing catalog target'
    );
    $variableRuntimeView =
        RED_CMS_Store_Lite_Product_Component_Bridge::render([
            'component' =>
                RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
            'placement' => [
                'recordId' => 101,
                'layout' => 'homepage',
                'article' => 'store',
                'position' => 1,
            ],
        ]);
    $variableExpectedView =
        RED_CMS_Store_Lite_Public_Product_Presenter::present(
            $readUpdated['product'],
            'USD'
        );
    $variableExpectedView['mutationForm'] =
        RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
            $readUpdated['product'],
            'USD'
        );
    red_store_lite_persistence_assert(
        $variableRuntimeView === $variableExpectedView
            && array_column(
                $variableRuntimeView['mutationForm']['fields'],
                'key'
            ) === ['product', 'quantity', 'variant']
            && count(
                $variableRuntimeView['mutationForm']['fields'][2]['options']
            ) === count($readUpdated['product']['variants']),
        'variable Product runtime returns the exact bounded sellable-variant presentation'
    );
    red_store_lite_persistence_assert(
        red_addon_public_component_view_model($variableRuntimeView)
            === $variableRuntimeView,
        'current RED-CMS core accepts the exact variable Product runtime model'
    );
    mysqli_begin_transaction($application);
    $placementDeleted = RED_CMS_Store_Lite_Product_Component_Bridge::delete(
        $application,
        [
            'component' =>
                RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
            'contentRecordId' => 101,
            'actorRecordId' => 1,
            'planHash' => str_repeat('c', 64),
        ]
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $placementDeleted === true
            && RED_CMS_Store_Lite_Product_Component_Bridge::load(
                $application,
                [
                    'component' =>
                        RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
                    'contentRecordId' => 101,
                ]
            ) === ['product-id' => 'classic-shirt'],
        'Product placement deleter participates in the caller-owned rollback boundary'
    );
    mysqli_begin_transaction($application);
    $placementDeleted = RED_CMS_Store_Lite_Product_Component_Bridge::delete(
        $application,
        [
            'component' =>
                RED_CMS_Store_Lite_Product_Component_Bridge::COMPONENT,
            'contentRecordId' => 101,
            'actorRecordId' => 1,
            'planHash' => str_repeat('d', 64),
        ]
    );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $placementDeleted === true
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Placements'
            ) === 0,
        'Product placement deleter commits without modifying the catalog record'
    );
    mysqli_begin_transaction($application);
    $missingTargetCreated = RED_CMS_Store_Lite_Product_Component_Bridge::create(
        $application,
        array_merge($createPlacementContext, ['contentRecordId' => 102]),
        ['product-id' => 'missing-product']
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $missingTargetCreated === false,
        'Product placement creator refuses an unknown catalog Product ID'
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
    require_once $packageRoot . '/src/CatalogAdministrationSubmission.php';
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

    require_once $coreRoot . '/includes/addon_admin_tool_form_value_helpers.php';
    require_once $coreRoot . '/includes/addon_admin_tool_form_write_helpers.php';
    require_once $coreRoot . '/includes/addon_admin_tool_form_create_helpers.php';
    require_once $packageRoot . '/src/ProductFormBridge.php';
    $appleRead = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'apple-box',
        'USD'
    );
    $currencySettings = new RED_Addon_Admin_Tool_Form_Runtime_Settings(
        ['catalog.currency' => 'USD'],
        hash('sha256', 'store-lite-test-currency')
    );
    $valueRequest = new RED_Addon_Admin_Tool_Form_Value_Request(
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        $appleRead['recordId'],
        $currencySettings
    );
    $loadedValues = RED_CMS_Store_Lite_Product_Form_Bridge::load(
        $application,
        $valueRequest
    )->values();
    $formContract = red_addon_admin_tool_form_contract(
        $manifest,
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM
    );
    $coreValidatedValues = is_array($formContract)
        ? red_addon_admin_tool_form_validate_values(
            $formContract,
            $loadedValues
        )
        : [];
    red_store_lite_persistence_assert(
        $loadedValues === RED_CMS_Store_Lite_Product_Form_Values::fromProduct(
            $appleRead['product']
        )
            && $loadedValues['price-minor'] === 1299
            && ($coreValidatedValues['valid'] ?? false) === true
            && ($coreValidatedValues['values'] ?? null) === $loadedValues,
        'numeric target loads an exact core-valid typed graph with injected currency'
    );
    $targetRequest = new RED_Addon_Admin_Tool_Form_Target_Request(
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        1,
        null,
        $currencySettings
    );
    $targetPage = RED_CMS_Store_Lite_Product_Form_Bridge::targets(
        $application,
        $targetRequest
    )->pageModel();
    red_store_lite_persistence_assert(
        array_column($targetPage['items'], 'label') === [
            'Apple box',
            'Banana bunch',
            'Classic organic T-shirt',
        ]
            && array_column($targetPage['items'], 'targetRecordId') === [
                $appleRead['recordId'],
                $readBanana['recordId'],
                $readUpdated['recordId'],
            ]
            && $targetPage['items'][0]['facts'][2] === [
                'label' => 'Price',
                'value' => 'USD 1,299 minor units',
            ]
            && $targetPage['nextCursor'] === null,
        'product target loader returns bounded numeric records and display facts'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Product_Form_Bridge::tool(
            new RED_Addon_Admin_Tool_Request(
                RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
                1
            )
        )->viewModel() === [
            'title' => 'Products',
            'description' =>
                'Create a Store Lite product or select an existing product to review or edit.',
            'facts' => [],
        ],
        'Products display callback remains static and database free'
    );

    $editedValues = $loadedValues;
    $editedValues['title'] = 'Seasonal apple box';
    $writeRequest = new RED_Addon_Admin_Tool_Form_Write_Request(
        'redcms.store-lite',
        '0.1.22',
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        1,
        $appleRead['recordId'],
        str_repeat('a', 64),
        str_repeat('b', 64),
        $editedValues,
        $currencySettings
    );
    $activityBeforeBridge = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
    );
    mysqli_begin_transaction($application);
    $bridgeWritten = RED_CMS_Store_Lite_Product_Form_Bridge::write(
        $application,
        $writeRequest
    );
    $bridgeReloaded = RED_CMS_Store_Lite_Product_Form_Bridge::load(
        $application,
        $valueRequest
    )->values();
    $activityInsideBridge = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $bridgeWritten === true
            && $bridgeReloaded === $editedValues
            && $activityInsideBridge === $activityBeforeBridge + 1
            && RED_CMS_Store_Lite_Catalog_Persistence::readByRecordId(
                $application,
                $appleRead['recordId'],
                'USD'
            ) === $appleRead
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
            ) === $activityBeforeBridge,
        'core-owned transaction can atomically update and roll back product plus activity'
    );

    $initialValues = RED_CMS_Store_Lite_Product_Form_Bridge::initial(
        $application,
        new RED_Addon_Admin_Tool_Form_Initial_Value_Request(
            RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
            RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
            $currencySettings
        )
    )->values();
    red_store_lite_persistence_assert(
        $initialValues === [
            'id' => '',
            'type' => 'simple',
            'title' => '',
            'summary' => null,
            'currency' => 'USD',
            'state' => 'draft',
            'availability' => 'unavailable',
            'image-reference' => null,
            'sku' => null,
            'price-minor' => null,
            'stock' => null,
            'options' => [],
            'variants' => [],
        ],
        'initial loader derives one unavailable simple draft from runtime currency'
    );
    $createdValues = $initialValues;
    $createdValues['id'] = 'browser-created-shirt';
    $createdValues['title'] = 'Browser-created shirt';
    $createdValues['sku'] = 'BROWSER-SHIRT';
    $createdValues['price-minor'] = 3200;
    $createRequest = new RED_Addon_Admin_Tool_Form_Create_Request(
        'redcms.store-lite',
        '0.1.22',
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        1,
        str_repeat('c', 64),
        str_repeat('d', 64),
        $createdValues,
        $currencySettings
    );
    $activityBeforeCreateBridge = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
    );
    mysqli_begin_transaction($application);
    $createdRecord = RED_CMS_Store_Lite_Product_Form_Bridge::create(
        $application,
        $createRequest
    );
    $createdRecordId = $createdRecord->recordId();
    $createdReloaded = RED_CMS_Store_Lite_Product_Form_Bridge::load(
        $application,
        new RED_Addon_Admin_Tool_Form_Value_Request(
            RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
            RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
            $createdRecordId,
            $currencySettings
        )
    )->values();
    $activityInsideCreateBridge = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $createdRecordId > 0
            && $createdReloaded === $createdValues
            && $activityInsideCreateBridge
                === $activityBeforeCreateBridge + 1
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'browser-created-shirt',
                'USD'
            )['status'] === 'not_found'
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Activity'
            ) === $activityBeforeCreateBridge,
        'core-owned transaction can atomically create and roll back product plus activity'
    );

    $variableCreateInput = $shirt;
    $variableCreateInput['id'] = 'browser-created-variable-shirt';
    $variableCreateInput['title'] = 'Browser-created variable shirt';
    $variableCreateValues =
        RED_CMS_Store_Lite_Product_Form_Values::fromProduct(
            RED_CMS_Store_Lite_Product_Normalizer::normalize(
                $variableCreateInput,
                'USD'
            )['product']
        );
    $variableCreateRequest = new RED_Addon_Admin_Tool_Form_Create_Request(
        'redcms.store-lite',
        '0.1.22',
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        1,
        str_repeat('e', 64),
        str_repeat('f', 64),
        $variableCreateValues,
        $currencySettings
    );
    mysqli_begin_transaction($application);
    $variableCreatedRecord =
        RED_CMS_Store_Lite_Product_Form_Bridge::create(
            $application,
            $variableCreateRequest
        );
    $variableCountsInside = red_store_lite_persistence_product_counts(
        $application,
        'browser-created-variable-shirt'
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $variableCreatedRecord->recordId() > 0
            && $variableCountsInside === '1:2:4:2:4'
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'browser-created-variable-shirt',
                'USD'
            )['status'] === 'not_found',
        'atomic form creator supports and rolls back the complete variable graph'
    );

    $changedIdentity = $editedValues;
    $changedIdentity['id'] = 'substituted-product';
    $changedIdentityRequest = new RED_Addon_Admin_Tool_Form_Write_Request(
        'redcms.store-lite',
        '0.1.22',
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        1,
        $appleRead['recordId'],
        str_repeat('a', 64),
        str_repeat('b', 64),
        $changedIdentity,
        $currencySettings
    );
    mysqli_begin_transaction($application);
    $changedIdentityWritten = RED_CMS_Store_Lite_Product_Form_Bridge::write(
        $application,
        $changedIdentityRequest
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $changedIdentityWritten === false
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'apple-box',
                'USD'
            ) === $appleRead,
        'numeric target cannot be used to substitute the immutable ProductID'
    );

    foreach ([[], ['catalog.currency' => 'usd']] as $invalidSettingsValues) {
        $refused = false;
        try {
            RED_CMS_Store_Lite_Product_Form_Bridge::load(
                $application,
                new RED_Addon_Admin_Tool_Form_Value_Request(
                    RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
                    RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
                    $appleRead['recordId'],
                    new RED_Addon_Admin_Tool_Form_Runtime_Settings(
                        $invalidSettingsValues,
                        hash('sha256', json_encode($invalidSettingsValues))
                    )
                )
            );
        } catch (Throwable $throwable) {
            $refused = true;
        }
        red_store_lite_persistence_assert(
            $refused,
            'missing or malformed installation currency refuses the value loader'
        );
    }

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

    $decodedCreate =
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode([
            'mode' => 'create',
            'expectedStateSha256' => '',
            'planSha256' => $createPlan['planSha256'],
            'product' => red_store_lite_persistence_browser_product(
                $createPlan['product']
            ),
        ], 'USD');
    red_store_lite_persistence_assert(
        $decodedCreate['accepted'] === true
            && $decodedCreate['product'] === $createPlan['product'],
        'browser create submission reconstructs the exact preflight target'
    );
    $createdByAction =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeCreate(
            $application,
            1,
            $decodedCreate['product'],
            'USD',
            $decodedCreate['planSha256']
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

    $decodedReplace =
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode([
            'mode' => 'replace',
            'expectedStateSha256' => $replacePlan['previousStateSha256'],
            'planSha256' => $replacePlan['planSha256'],
            'product' => red_store_lite_persistence_browser_product(
                $replacePlan['product']
            ),
        ], 'USD');
    red_store_lite_persistence_assert(
        $decodedReplace['accepted'] === true
            && $decodedReplace['product'] === $replacePlan['product'],
        'browser replace submission reconstructs the exact preflight target'
    );
    $updatedByAction =
        RED_CMS_Store_Lite_Catalog_Administration_Action::executeReplace(
            $application,
            1,
            $decodedReplace['product'],
            'USD',
            $decodedReplace['expectedStateSha256'],
            $decodedReplace['planSha256']
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

    $cartSubjectRecordId = 7001;
    $emptyCart = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    red_store_lite_persistence_assert(
        $emptyCart['status'] === 'empty'
            && $emptyCart['cartRecordId'] === 0
            && $emptyCart['lineCount'] === 0
            && preg_match(
                '/\A[a-f0-9]{64}\z/',
                $emptyCart['stateSha256']
            ) === 1,
        'anonymous subject starts with one deterministic empty cart state'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['product' => 'banana-bunch', 'quantity' => 2],
            $emptyCart['stateSha256']
        )['status'] === 'invalid'
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Carts'
            ) === 0,
        'cart writes require an already-active caller transaction'
    );

    mysqli_begin_transaction($application);
    $firstCartLine =
        RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['product' => 'banana-bunch', 'quantity' => 2],
            $emptyCart['stateSha256']
        );
    $transactionAfterFirstLine =
        red_store_lite_persistence_transaction_active($application);
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $firstCartLine['status'] === 'created'
            && $firstCartLine['cartRecordId'] > 0
            && $transactionAfterFirstLine
            && preg_match(
                '/\A[a-f0-9]{64}\z/',
                $firstCartLine['lineIdentitySha256']
            ) === 1
            && !hash_equals(
                $firstCartLine['previousStateSha256'],
                $firstCartLine['stateSha256']
            ),
        'caller transaction creates one cart and server-resolved simple line without committing itself'
    );
    $cartAfterFirstLine = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    red_store_lite_persistence_assert(
        $cartAfterFirstLine['status'] === 'found'
            && $cartAfterFirstLine['cartRecordId']
                === $firstCartLine['cartRecordId']
            && $cartAfterFirstLine['lineCount'] === 1
            && hash_equals(
                $firstCartLine['stateSha256'],
                $cartAfterFirstLine['stateSha256']
            )
            && red_store_lite_persistence_value(
                $application,
                "SELECT CONCAT(
                    carts.SubjectRecordID, ':', products.ProductID, ':',
                    IFNULL(variants.VariantID, 'simple'), ':', cart_lines.Quantity,
                    ':', cart_lines.UnitPriceMinor, ':', cart_lines.Currency, ':',
                    cart_lines.LineTotalMinor)
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Carts AS carts
                   ON carts.RecordID=cart_lines.CartRecordID
                 INNER JOIN RED_Addon_StoreLite_Products AS products
                   ON products.RecordID=cart_lines.ProductRecordID
                 LEFT JOIN RED_Addon_StoreLite_Product_Variants AS variants
                   ON variants.ProductRecordID=cart_lines.ProductRecordID
                  AND variants.RecordID=cart_lines.VariantRecordID"
            ) === '7001:banana-bunch:simple:2:599:USD:1198',
        'persisted simple line contains only server-derived product, money, currency, and quantity state'
    );
    red_store_lite_persistence_assert(
        red_store_lite_persistence_value(
            $application,
            "SELECT CONCAT(
                EventName, ':', SubjectRecordID, ':',
                LOWER(HEX(LineIdentitySHA256)), ':',
                LOWER(HEX(PreviousStateSHA256)), ':',
                LOWER(HEX(StateSHA256)))
             FROM RED_Addon_StoreLite_Cart_Activity
             ORDER BY RecordID LIMIT 1"
        ) === 'cart.line.created:7001:'
            . $firstCartLine['lineIdentitySha256'] . ':'
            . $emptyCart['stateSha256'] . ':'
            . $firstCartLine['stateSha256'],
        'first cart activity records only subject, line identity, and before/after state evidence'
    );

    mysqli_begin_transaction($application);
    $staleCartWrite =
        RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['product' => 'banana-bunch', 'quantity' => 1],
            $emptyCart['stateSha256']
        );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $staleCartWrite['status'] === 'stale_state'
            && RED_CMS_Store_Lite_Cart_Persistence::read(
                $application,
                $cartSubjectRecordId,
                'USD'
            ) === $cartAfterFirstLine,
        'stale cart evidence is refused before any line or activity write'
    );

    mysqli_begin_transaction($application);
    $updatedCartLine =
        RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['product' => 'banana-bunch', 'quantity' => 3],
            $cartAfterFirstLine['stateSha256']
        );
    mysqli_commit($application);
    $cartAfterUpdate = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    red_store_lite_persistence_assert(
        $updatedCartLine['status'] === 'updated'
            && $cartAfterUpdate['lineCount'] === 1
            && red_store_lite_persistence_value(
                $application,
                "SELECT CONCAT(Quantity, ':', UnitPriceMinor, ':', LineTotalMinor)
                 FROM RED_Addon_StoreLite_Cart_Lines"
            ) === '5:599:2995'
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity'
            ) === 2,
        'repeat Add increments the exact line under fresh state and recalculates its integer total'
    );

    $currentShirt = RED_CMS_Store_Lite_Catalog_Persistence::read(
        $application,
        'classic-shirt',
        'USD'
    );
    red_store_lite_persistence_assert(
        $currentShirt['status'] === 'found'
            && $currentShirt['product']['state'] === 'published',
        'variable cart fixture uses the current published server product'
    );
    mysqli_begin_transaction($application);
    $variableCartLine =
        RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            [
                'product' => 'classic-shirt',
                'variant' => 'small-black',
                'quantity' => 2,
            ],
            $cartAfterUpdate['stateSha256']
        );
    mysqli_commit($application);
    $cartAfterVariable = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    red_store_lite_persistence_assert(
        $variableCartLine['status'] === 'created'
            && $cartAfterVariable['lineCount'] === 2
            && red_store_lite_persistence_value(
                $application,
                "SELECT CONCAT(
                    products.ProductID, ':', variants.VariantID, ':',
                    cart_lines.Quantity, ':', cart_lines.UnitPriceMinor, ':',
                    cart_lines.LineTotalMinor)
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Products AS products
                   ON products.RecordID=cart_lines.ProductRecordID
                 INNER JOIN RED_Addon_StoreLite_Product_Variants AS variants
                   ON variants.ProductRecordID=cart_lines.ProductRecordID
                  AND variants.RecordID=cart_lines.VariantRecordID
                 WHERE variants.VariantID='small-black'"
            ) === 'classic-shirt:small-black:2:2599:5198',
        'variable line persists the exact selected current variant and server-derived total'
    );
    $publicCart = RED_CMS_Store_Lite_Cart_Read_Model::load(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    $publicCartLines = is_array($publicCart['cart']['lines'] ?? null)
        ? array_column($publicCart['cart']['lines'], null, 'title')
        : [];
    $publicCartTitles = array_keys($publicCartLines);
    sort($publicCartTitles, SORT_STRING);
    $expectedPublicCartTitles = [
        $bananaNormalized['title'],
        $currentShirt['product']['title'],
    ];
    sort($expectedPublicCartTitles, SORT_STRING);
    red_store_lite_persistence_assert(
        $publicCart['loaded'] === true
            && $publicCart['status'] === 'found'
            && $publicCart['cart']['currency'] === 'USD'
            && count($publicCart['cart']['lines']) === 2
            && $publicCartTitles === $expectedPublicCartTitles,
        'read model projects only the current subject cart with bounded titles'
    );
    red_store_lite_persistence_assert(
        $publicCartLines[$currentShirt['product']['title']]['options'] === [
            'Size: Small',
            'Color: Black',
        ]
            && $publicCartLines[$currentShirt['product']['title']]['quantity'] === 2
            && $publicCartLines[$currentShirt['product']['title']]['unitPriceMinor'] === 2599
            && $publicCartLines[$currentShirt['product']['title']]['lineTotalMinor'] === 5198,
        'read model resolves exact variant labels and stored integer money'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Cart_Read_Model::load(
            $application,
            7002,
            'USD'
        ) === [
            'loaded' => true,
            'status' => 'empty',
            'cart' => ['currency' => 'USD', 'lines' => []],
            'reason' => 'loaded',
        ]
            && RED_CMS_Store_Lite_Cart_Read_Model::load(
                $application,
                $cartSubjectRecordId,
                'COP'
            )['loaded'] === false,
        'read model isolates anonymous subjects and refuses currency drift'
    );
    mysqli_query($application, 'INSERT INTO RED_Articles (RecordID) VALUES (103)');
    $cartCreateContext = [
        'component' => RED_CMS_Store_Lite_Cart_Component_Bridge::COMPONENT,
        'contentRecordId' => 103,
        'actorRecordId' => 1,
        'planHash' => str_repeat('c', 64),
    ];
    mysqli_begin_transaction($application);
    $cartPlacementCreated = RED_CMS_Store_Lite_Cart_Component_Bridge::create(
        $application,
        $cartCreateContext,
        ['cart-title' => 'Shopping cart']
    );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $cartPlacementCreated === true
            && RED_CMS_Store_Lite_Cart_Component_Bridge::load(
                $application,
                [
                    'component' => RED_CMS_Store_Lite_Cart_Component_Bridge::COMPONENT,
                    'contentRecordId' => 103,
                ]
            ) === ['cart-title' => 'Shopping cart'],
        'Cart placement persists only its bounded public title'
    );
    $redStoreLiteCartSubjectContext = [
        'valid' => true,
        'subjectRecordId' => $cartSubjectRecordId,
        'reason' => 'subject_resolved',
    ];
    $cartRuntimeView = RED_CMS_Store_Lite_Cart_Component_Bridge::render([
        'component' => RED_CMS_Store_Lite_Cart_Component_Bridge::COMPONENT,
        'placement' => [
            'recordId' => 103,
            'layout' => 'homepage',
            'article' => 'store',
            'position' => 2,
        ],
    ]);
    red_store_lite_persistence_assert(
        $cartRuntimeView['title'] === 'Shopping cart'
            && $cartRuntimeView['facts'] === [
                ['label' => 'Items', 'value' => '7'],
                ['label' => 'Total', 'value' => 'USD 81.93'],
            ]
            && count($cartRuntimeView['collection']['items']) === 2
            && red_addon_public_component_view_model($cartRuntimeView)
                === $cartRuntimeView,
        'Cart runtime binds current subject, package projection, presenter, and core collection model'
    );
    $redStoreLiteCartSubjectContext = [
        'valid' => false,
        'subjectRecordId' => null,
        'reason' => 'subject_missing',
    ];
    $emptyCartRuntimeView = RED_CMS_Store_Lite_Cart_Component_Bridge::render([
        'component' => RED_CMS_Store_Lite_Cart_Component_Bridge::COMPONENT,
        'placement' => [
            'recordId' => 103,
            'layout' => 'homepage',
            'article' => 'store',
            'position' => 2,
        ],
    ]);
    red_store_lite_persistence_assert(
        $emptyCartRuntimeView === [
            'title' => 'Shopping cart',
            'summary' => 'Your cart is empty.',
            'facts' => [
                ['label' => 'Items', 'value' => '0'],
                ['label' => 'Total', 'value' => 'USD 0.00'],
            ],
        ],
        'Cart runtime returns an empty view without creating a browser subject'
    );
    $cartWriteContext = [
        'component' => RED_CMS_Store_Lite_Cart_Component_Bridge::COMPONENT,
        'contentRecordId' => 103,
        'actorRecordId' => 1,
        'previousStateHash' => str_repeat('d', 64),
    ];
    mysqli_begin_transaction($application);
    $cartPlacementWritten = RED_CMS_Store_Lite_Cart_Component_Bridge::write(
        $application,
        $cartWriteContext,
        ['cart-title' => 'Your basket']
    );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $cartPlacementWritten === true
            && RED_CMS_Store_Lite_Cart_Component_Bridge::load(
                $application,
                [
                    'component' => RED_CMS_Store_Lite_Cart_Component_Bridge::COMPONENT,
                    'contentRecordId' => 103,
                ]
            ) === ['cart-title' => 'Shopping cart'],
        'Cart placement writer participates in caller-owned rollback'
    );
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Cart_Persistence::read(
            $application,
            7002,
            'USD'
        )['status'] === 'empty',
        'a different anonymous subject cannot resolve the first subject cart'
    );

    $refusedCartCases = [[
        ['product' => 'classic-shirt', 'quantity' => 1],
        'variant_required',
    ], [
        [
            'product' => 'classic-shirt',
            'variant' => 'small-black',
            'quantity' => 7,
        ],
        'insufficient_stock',
    ], [
        ['product' => 'banana-bunch', 'quantity' => 96],
        'insufficient_stock',
    ], [
        ['product' => 'banana-bunch', 'quantity' => 1, 'price' => 1],
        'invalid_intent',
    ]];
    foreach ($refusedCartCases as [$intent, $reason]) {
        mysqli_begin_transaction($application);
        $refusedCartWrite =
            RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
                $application,
                $cartSubjectRecordId,
                'USD',
                $intent,
                $cartAfterVariable['stateSha256']
            );
        mysqli_rollback($application);
        red_store_lite_persistence_assert(
            $refusedCartWrite['status'] === $reason
                && RED_CMS_Store_Lite_Cart_Persistence::read(
                    $application,
                    $cartSubjectRecordId,
                    'USD'
                ) === $cartAfterVariable,
            $reason . ' cart request rolls back without line or activity drift'
        );
    }

    $pearLineIdentitySha256 = hash(
        'sha256',
        json_encode(
            ['productId' => 'pear-basket', 'variantId' => null],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )
    );
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Cart_Activity
         ADD CONSTRAINT chk_storelite_test_cart_activity_failure
         CHECK (LineIdentitySHA256 <> UNHEX(\'' .
            $pearLineIdentitySha256 . '\'))'
    );
    mysqli_begin_transaction($application);
    $cartActivityFailure =
        RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['product' => 'pear-basket', 'quantity' => 1],
            $cartAfterVariable['stateSha256']
        );
    $cartLinesBeforeRollback = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Lines'
    );
    mysqli_rollback($application);
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Cart_Activity
         DROP CHECK chk_storelite_test_cart_activity_failure'
    );
    red_store_lite_persistence_assert(
        $cartActivityFailure['status'] === 'activity_failed'
            && $cartLinesBeforeRollback === 3
            && RED_CMS_Store_Lite_Cart_Persistence::read(
                $application,
                $cartSubjectRecordId,
                'USD'
            ) === $cartAfterVariable
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Lines'
            ) === 2
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity'
            ) === 3,
        'late cart activity failure leaves rollback ownership with core and restores the exact cart'
    );

    $bananaLineHandle = 'line-' . $firstCartLine['lineIdentitySha256'];
    $shirtLineHandle = 'line-' . $variableCartLine['lineIdentitySha256'];
    red_store_lite_persistence_assert(
        RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $bananaLineHandle, 'quantity' => 4],
            $cartAfterVariable['stateSha256']
        )['status'] === 'invalid',
        'set-quantity persistence requires an already-active caller transaction'
    );

    mysqli_begin_transaction($application);
    $quantitySet =
        RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $bananaLineHandle, 'quantity' => 4],
            $cartAfterVariable['stateSha256']
        );
    $transactionAfterQuantitySet =
        red_store_lite_persistence_transaction_active($application);
    mysqli_commit($application);
    $cartAfterQuantitySet = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    red_store_lite_persistence_assert(
        $quantitySet['status'] === 'updated'
            && $transactionAfterQuantitySet
            && $quantitySet['lineIdentitySha256']
                === $firstCartLine['lineIdentitySha256']
            && $quantitySet['previousStateSha256']
                === $cartAfterVariable['stateSha256']
            && $quantitySet['stateSha256']
                === $cartAfterQuantitySet['stateSha256']
            && $cartAfterQuantitySet['lineCount'] === 2
            && red_store_lite_persistence_value(
                $application,
                "SELECT CONCAT(Quantity, ':', UnitPriceMinor, ':', LineTotalMinor)
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Products AS products
                   ON products.RecordID=cart_lines.ProductRecordID
                 WHERE products.ProductID='banana-bunch'
                   AND cart_lines.CartRecordID=" . $quantitySet['cartRecordId']
            ) === '4:599:2396',
        'set quantity replaces rather than increments and keeps server-derived integer money'
    );
    $activityAfterQuantitySet = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity'
    );
    red_store_lite_persistence_assert(
        red_store_lite_persistence_value(
            $application,
            "SELECT CONCAT(EventName, ':', SubjectRecordID, ':',
                LOWER(HEX(LineIdentitySHA256)))
             FROM RED_Addon_StoreLite_Cart_Activity
             ORDER BY RecordID DESC LIMIT 1"
        ) === 'cart.line.quantity-set:7001:'
            . $firstCartLine['lineIdentitySha256'],
        'set quantity records one distinct value-free subject and line activity fact'
    );

    mysqli_begin_transaction($application);
    $unchangedQuantity =
        RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $bananaLineHandle, 'quantity' => 4],
            $cartAfterQuantitySet['stateSha256']
        );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $unchangedQuantity['status'] === 'unchanged'
            && $unchangedQuantity['previousStateSha256']
                === $cartAfterQuantitySet['stateSha256']
            && $unchangedQuantity['stateSha256']
                === $cartAfterQuantitySet['stateSha256']
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity'
            ) === $activityAfterQuantitySet,
        'same quantity and current commercial state are an activity-free no-op'
    );

    mysqli_begin_transaction($application);
    $staleQuantitySet =
        RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $bananaLineHandle, 'quantity' => 3],
            $cartAfterVariable['stateSha256']
        );
    mysqli_rollback($application);
    red_store_lite_persistence_assert(
        $staleQuantitySet['status'] === 'stale_state'
            && RED_CMS_Store_Lite_Cart_Persistence::read(
                $application,
                $cartSubjectRecordId,
                'USD'
            ) === $cartAfterQuantitySet,
        'set quantity refuses stale captured cart state without storage drift'
    );

    foreach ([
        [['line' => $bananaLineHandle, 'quantity' => 0], 'invalid'],
        [['line' => $shirtLineHandle, 'quantity' => 9], 'insufficient_stock'],
    ] as [$intent, $expectedStatus]) {
        mysqli_begin_transaction($application);
        $refusedQuantity =
            RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
                $application,
                $cartSubjectRecordId,
                'USD',
                $intent,
                $cartAfterQuantitySet['stateSha256']
            );
        mysqli_rollback($application);
        red_store_lite_persistence_assert(
            $refusedQuantity['status'] === $expectedStatus
                && RED_CMS_Store_Lite_Cart_Persistence::read(
                    $application,
                    $cartSubjectRecordId,
                    'USD'
                ) === $cartAfterQuantitySet,
            $expectedStatus . ' set-quantity intent leaves the exact cart unchanged'
        );
    }

    mysqli_begin_transaction($application);
    $variantQuantitySet =
        RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $shirtLineHandle, 'quantity' => 3],
            $cartAfterQuantitySet['stateSha256']
        );
    mysqli_commit($application);
    $cartAfterVariantQuantity = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    red_store_lite_persistence_assert(
        $variantQuantitySet['status'] === 'updated'
            && $variantQuantitySet['stateSha256']
                === $cartAfterVariantQuantity['stateSha256']
            && red_store_lite_persistence_value(
                $application,
                "SELECT CONCAT(cart_lines.Quantity, ':',
                    cart_lines.UnitPriceMinor, ':', cart_lines.LineTotalMinor)
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Product_Variants AS variants
                   ON variants.ProductRecordID=cart_lines.ProductRecordID
                  AND variants.RecordID=cart_lines.VariantRecordID
                 WHERE variants.VariantID='small-black'
                   AND cart_lines.CartRecordID=" . $variantQuantitySet['cartRecordId']
            ) === '3:2599:7797',
        'set quantity resolves and replaces one exact variable-product line'
    );

    mysqli_query(
        $application,
        "UPDATE RED_Addon_StoreLite_Products
         SET PriceMinor=699 WHERE ProductID='banana-bunch'"
    );
    mysqli_begin_transaction($application);
    $repricedQuantity =
        RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $bananaLineHandle, 'quantity' => 4],
            $cartAfterVariantQuantity['stateSha256']
        );
    mysqli_commit($application);
    $cartAfterReprice = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    mysqli_query(
        $application,
        "UPDATE RED_Addon_StoreLite_Products
         SET PriceMinor=599 WHERE ProductID='banana-bunch'"
    );
    red_store_lite_persistence_assert(
        $repricedQuantity['status'] === 'updated'
            && $repricedQuantity['stateSha256']
                === $cartAfterReprice['stateSha256']
            && red_store_lite_persistence_value(
                $application,
                "SELECT CONCAT(Quantity, ':', UnitPriceMinor, ':', LineTotalMinor)
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Products AS products
                   ON products.RecordID=cart_lines.ProductRecordID
                 WHERE products.ProductID='banana-bunch'
                   AND cart_lines.CartRecordID=" . $repricedQuantity['cartRecordId']
            ) === '4:699:2796',
        'same requested quantity refreshes price and product-state evidence from current server product'
    );

    $foreignSubjectRecordId = 7003;
    $foreignEmptyCart = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $foreignSubjectRecordId,
        'USD'
    );
    mysqli_begin_transaction($application);
    $foreignPearLine =
        RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $application,
            $foreignSubjectRecordId,
            'USD',
            ['product' => 'pear-basket', 'quantity' => 1],
            $foreignEmptyCart['stateSha256']
        );
    mysqli_commit($application);
    $foreignCart = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $foreignSubjectRecordId,
        'USD'
    );
    $foreignPearHandle = 'line-' . $foreignPearLine['lineIdentitySha256'];
    foreach (['set', 'remove'] as $foreignOperation) {
        mysqli_begin_transaction($application);
        $foreignRefusal = $foreignOperation === 'set'
            ? RED_CMS_Store_Lite_Cart_Persistence::setLineQuantityWithinTransaction(
                $application,
                $cartSubjectRecordId,
                'USD',
                ['line' => $foreignPearHandle, 'quantity' => 2],
                $cartAfterReprice['stateSha256']
            )
            : RED_CMS_Store_Lite_Cart_Persistence::removeLineWithinTransaction(
                $application,
                $cartSubjectRecordId,
                'USD',
                ['line' => $foreignPearHandle],
                $cartAfterReprice['stateSha256']
            );
        mysqli_rollback($application);
        red_store_lite_persistence_assert(
            $foreignRefusal['status'] === 'line_unavailable'
                && RED_CMS_Store_Lite_Cart_Persistence::read(
                    $application,
                    $foreignSubjectRecordId,
                    'USD'
                ) === $foreignCart
                && RED_CMS_Store_Lite_Cart_Persistence::read(
                    $application,
                    $cartSubjectRecordId,
                    'USD'
                ) === $cartAfterReprice,
            'valid foreign-subject line handle is indistinguishable from an unavailable line for '
                . $foreignOperation
        );
    }

    mysqli_query(
        $application,
        "UPDATE RED_Addon_StoreLite_Products
         SET Availability='unavailable' WHERE ProductID='classic-shirt'"
    );
    mysqli_begin_transaction($application);
    $removedShirt =
        RED_CMS_Store_Lite_Cart_Persistence::removeLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $shirtLineHandle],
            $cartAfterReprice['stateSha256']
        );
    $transactionAfterRemoval =
        red_store_lite_persistence_transaction_active($application);
    mysqli_commit($application);
    mysqli_query(
        $application,
        "UPDATE RED_Addon_StoreLite_Products
         SET Availability='available' WHERE ProductID='classic-shirt'"
    );
    $cartAfterRemoval = RED_CMS_Store_Lite_Cart_Persistence::read(
        $application,
        $cartSubjectRecordId,
        'USD'
    );
    red_store_lite_persistence_assert(
        $removedShirt['status'] === 'removed'
            && $transactionAfterRemoval
            && $removedShirt['lineIdentitySha256']
                === $variableCartLine['lineIdentitySha256']
            && $removedShirt['stateSha256']
                === $cartAfterRemoval['stateSha256']
            && $cartAfterRemoval['lineCount'] === 1
            && (int) red_store_lite_persistence_value(
                $application,
                "SELECT COUNT(*)
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Products AS products
                   ON products.RecordID=cart_lines.ProductRecordID
                 WHERE products.ProductID='classic-shirt'
                   AND cart_lines.CartRecordID=" . $removedShirt['cartRecordId']
            ) === 0,
        'remove line deletes only the subject line even when its product is unavailable'
    );
    red_store_lite_persistence_assert(
        red_store_lite_persistence_value(
            $application,
            "SELECT CONCAT(EventName, ':', SubjectRecordID, ':',
                LOWER(HEX(LineIdentitySHA256)))
             FROM RED_Addon_StoreLite_Cart_Activity
             WHERE SubjectRecordID=7001 ORDER BY RecordID DESC LIMIT 1"
        ) === 'cart.line.removed:7001:'
            . $variableCartLine['lineIdentitySha256'],
        'removal records one distinct value-free subject and line activity fact'
    );

    $activityBeforeRemovalFailure = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity'
    );
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Cart_Activity
         ADD CONSTRAINT chk_storelite_test_cart_remove_activity_failure
         CHECK (EventName <> \'cart.line.removed\'
           OR LineIdentitySHA256 <> UNHEX(\''
            . $firstCartLine['lineIdentitySha256'] . '\'))'
    );
    mysqli_begin_transaction($application);
    $removalActivityFailure =
        RED_CMS_Store_Lite_Cart_Persistence::removeLineWithinTransaction(
            $application,
            $cartSubjectRecordId,
            'USD',
            ['line' => $bananaLineHandle],
            $cartAfterRemoval['stateSha256']
        );
    $linesBeforeRemovalRollback = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Lines
         WHERE CartRecordID=' . $removedShirt['cartRecordId']
    );
    mysqli_rollback($application);
    mysqli_query(
        $application,
        'ALTER TABLE RED_Addon_StoreLite_Cart_Activity
         DROP CHECK chk_storelite_test_cart_remove_activity_failure'
    );
    red_store_lite_persistence_assert(
        $removalActivityFailure['status'] === 'activity_failed'
            && $linesBeforeRemovalRollback === 0
            && RED_CMS_Store_Lite_Cart_Persistence::read(
                $application,
                $cartSubjectRecordId,
                'USD'
            ) === $cartAfterRemoval
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity'
            ) === $activityBeforeRemovalFailure,
        'late removal activity failure rolls the deleted line and complete cart state back'
    );

    $bridgeSubjectRecordId = 7010;
    $bridgeAddCommand = new RED_Addon_Public_Mutation_Command(
        'redcms.store-lite',
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::ADD_ROUTE,
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::ADD_MUTATION,
        $bridgeSubjectRecordId,
        ['product' => 'banana-bunch', 'quantity' => 2]
    );
    mysqli_begin_transaction($application);
    $bridgeAdded = RED_CMS_Store_Lite_Cart_Mutation_Bridge::execute(
        $application,
        new RED_Addon_Public_Mutation_Execution_Request(
            $bridgeAddCommand,
            str_repeat('a', 64),
            str_repeat('b', 64)
        )
    );
    mysqli_commit($application);
    $bridgeLineIdentity = red_store_lite_persistence_value(
        $application,
        'SELECT LOWER(HEX(cart_lines.LineIdentitySHA256))
         FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
         INNER JOIN RED_Addon_StoreLite_Carts AS carts
           ON carts.RecordID=cart_lines.CartRecordID
         WHERE carts.SubjectRecordID=7010 LIMIT 1'
    );
    red_store_lite_persistence_assert(
        $bridgeAdded->outcome() === 'accepted'
            && $bridgeAdded->state()->subjectRecordId()
                === $bridgeSubjectRecordId
            && $bridgeAdded->state()->state()['lineCount'] === 1
            && preg_match('/\A[a-f0-9]{64}\z/', $bridgeLineIdentity) === 1
            && red_store_lite_persistence_value(
                $application,
                'SELECT Quantity
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Carts AS carts
                   ON carts.RecordID=cart_lines.CartRecordID
                 WHERE carts.SubjectRecordID=7010'
            ) === '2',
        'typed bridge preserves the existing accepted Add-to-cart binding'
    );

    $bridgeLineHandle = 'line-' . $bridgeLineIdentity;
    $bridgeSetCommand = new RED_Addon_Public_Mutation_Command(
        'redcms.store-lite',
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::SET_QUANTITY_ROUTE,
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::SET_QUANTITY_MUTATION,
        $bridgeSubjectRecordId,
        ['line' => $bridgeLineHandle, 'quantity' => 3]
    );
    mysqli_begin_transaction($application);
    $bridgeQuantitySet = RED_CMS_Store_Lite_Cart_Mutation_Bridge::execute(
        $application,
        new RED_Addon_Public_Mutation_Execution_Request(
            $bridgeSetCommand,
            str_repeat('c', 64),
            str_repeat('d', 64)
        )
    );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $bridgeQuantitySet->outcome() === 'accepted'
            && $bridgeQuantitySet->state()->state()['lineCount'] === 1
            && red_store_lite_persistence_value(
                $application,
                'SELECT Quantity
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Carts AS carts
                   ON carts.RecordID=cart_lines.CartRecordID
                 WHERE carts.SubjectRecordID=7010'
            ) === '3',
        'typed set-quantity bridge accepts one exact subject-cart line update'
    );
    $bridgeActivityBeforeNoOp = (int) red_store_lite_persistence_value(
        $application,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity
         WHERE SubjectRecordID=7010'
    );
    mysqli_begin_transaction($application);
    $bridgeQuantityUnchanged =
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::execute(
            $application,
            new RED_Addon_Public_Mutation_Execution_Request(
                $bridgeSetCommand,
                str_repeat('e', 64),
                str_repeat('f', 64)
            )
        );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $bridgeQuantityUnchanged->outcome() === 'unchanged'
            && $bridgeQuantityUnchanged->state()->state()
                === $bridgeQuantitySet->state()->state()
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*) FROM RED_Addon_StoreLite_Cart_Activity
                 WHERE SubjectRecordID=7010'
            ) === $bridgeActivityBeforeNoOp,
        'typed set-quantity bridge returns unchanged only for a true storage no-op'
    );

    $bridgeRemoveCommand = new RED_Addon_Public_Mutation_Command(
        'redcms.store-lite',
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::REMOVE_ROUTE,
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::REMOVE_MUTATION,
        $bridgeSubjectRecordId,
        ['line' => $bridgeLineHandle]
    );
    mysqli_begin_transaction($application);
    $bridgeRemoved = RED_CMS_Store_Lite_Cart_Mutation_Bridge::execute(
        $application,
        new RED_Addon_Public_Mutation_Execution_Request(
            $bridgeRemoveCommand,
            str_repeat('1', 64),
            str_repeat('2', 64)
        )
    );
    mysqli_commit($application);
    red_store_lite_persistence_assert(
        $bridgeRemoved->outcome() === 'accepted'
            && $bridgeRemoved->state()->state()['lineCount'] === 0
            && (int) red_store_lite_persistence_value(
                $application,
                'SELECT COUNT(*)
                 FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                 INNER JOIN RED_Addon_StoreLite_Carts AS carts
                   ON carts.RecordID=cart_lines.CartRecordID
                 WHERE carts.SubjectRecordID=7010'
            ) === 0
            && RED_CMS_Store_Lite_Cart_Mutation_Bridge::load(
                $application,
                $bridgeRemoveCommand
            )->state()['lineCount'] === 0,
        'typed remove bridge accepts one exact line removal and reloads empty cart state'
    );

    $substitutedPairRefused = false;
    try {
        RED_CMS_Store_Lite_Cart_Mutation_Bridge::load(
            $application,
            new RED_Addon_Public_Mutation_Command(
                'redcms.store-lite',
                RED_CMS_Store_Lite_Cart_Mutation_Bridge::ADD_ROUTE,
                RED_CMS_Store_Lite_Cart_Mutation_Bridge::REMOVE_MUTATION,
                $bridgeSubjectRecordId,
                ['line' => $bridgeLineHandle]
            )
        );
    } catch (Throwable $throwable) {
        $substitutedPairRefused = true;
    }
    red_store_lite_persistence_assert(
        $substitutedPairRefused,
        'bridge refuses a valid route and mutation identifier in an undeclared pair'
    );

    $deleteBlocked = false;
    try {
        mysqli_query(
            $application,
            "DELETE FROM RED_Addon_StoreLite_Products
             WHERE ProductID='banana-bunch'"
        );
    } catch (Throwable $throwable) {
        $deleteBlocked = true;
    }
    red_store_lite_persistence_assert(
        $deleteBlocked
            && RED_CMS_Store_Lite_Catalog_Persistence::read(
                $application,
                'banana-bunch',
                'USD'
            )['status'] === 'found',
        'cart-line foreign keys prevent deletion of a referenced current product'
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

echo 'Store Lite catalog and cart persistence passed ' . $assertions . " assertions.\n";
