<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repositoryRoot = dirname(__DIR__);
$configuredCoreRoot = getenv('RED_CMS_CORE_ROOT');
$coreRoot = is_string($configuredCoreRoot) && $configuredCoreRoot !== ''
    ? $configuredCoreRoot
    : dirname($repositoryRoot) . '/redcms v5.1';
$coreRoot = realpath($coreRoot);
$packageRoot = realpath($repositoryRoot . '/package');
$mysqlBinary = getenv('RED_MYSQL_BIN');
if (!is_string($mysqlBinary) || $mysqlBinary === '') {
    $mysqlBinary = '/Users/oscarrojas/Documents/red-cms-dev/' .
        'mysql-8.4.10-macos15-arm64/bin/mysql';
}
$assertions = 0;
$defaultsFile = '';
$adminDefaultsFile = '';
$databaseCreated = false;
$grantCreated = false;
$acceptanceDatabase = 'redcms_store_lite_acceptance_' .
    gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));

function red_store_lite_catalog_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_catalog_option(string $value): string
{
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        throw new RuntimeException('Database option contains a control byte.');
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function red_store_lite_catalog_mysql(
    string $mysqlBinary,
    string $defaultsFile,
    array $arguments,
    string $stdin = ''
): array {
    $command = array_merge([
        $mysqlBinary,
        '--defaults-extra-file=' . $defaultsFile,
        '--protocol=TCP',
        '--batch',
        '--skip-column-names',
    ], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the MySQL client.');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return [
        'status' => $status,
        'stdout' => is_string($stdout) ? trim($stdout) : '',
        'stderr' => is_string($stderr) ? trim($stderr) : '',
    ];
}

function red_store_lite_catalog_query(
    string $mysqlBinary,
    string $defaultsFile,
    string $sql,
    string $database = ''
): string {
    $arguments = $database === '' ? [] : [$database];
    $arguments[] = '--execute=' . $sql;
    $result = red_store_lite_catalog_mysql(
        $mysqlBinary,
        $defaultsFile,
        $arguments
    );
    if ($result['status'] !== 0) {
        throw new RuntimeException(
            'MySQL command failed: ' . ($result['stderr'] ?: 'unknown error')
        );
    }
    return $result['stdout'];
}

function red_store_lite_catalog_expect_refusal(
    string $mysqlBinary,
    string $defaultsFile,
    string $database,
    string $sql,
    string $message
): void {
    $result = red_store_lite_catalog_mysql(
        $mysqlBinary,
        $defaultsFile,
        [$database, '--execute=' . $sql]
    );
    red_store_lite_catalog_assert($result['status'] !== 0, $message);
}

try {
    red_store_lite_catalog_assert(
        is_string($coreRoot) && is_dir($coreRoot),
        'RED-CMS core root resolves'
    );
    red_store_lite_catalog_assert(
        is_string($packageRoot) && is_dir($packageRoot),
        'Store Lite package root resolves'
    );
    red_store_lite_catalog_assert(
        is_file($mysqlBinary) && is_executable($mysqlBinary),
        'MySQL client resolves'
    );
    red_store_lite_catalog_assert(
        preg_match('/\Aredcms_store_lite_acceptance_[A-Za-z0-9_]+\z/', $acceptanceDatabase) === 1
            && strlen($acceptanceDatabase) <= 64,
        'disposable database name is exact and bounded'
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
    $databaseUser = (string) ($config['DBUSER'] ?? '');
    $databasePassword = (string) ($config['DBPASS'] ?? '');
    $primaryDatabase = (string) ($config['DBNAME'] ?? '');
    $databaseHost = $hostPort;
    $databasePort = 3306;
    if (str_contains($hostPort, ':')) {
        [$databaseHost, $port] = explode(':', $hostPort, 2);
        $databasePort = (int) $port;
    }
    if ($databaseHost === '' || $databaseUser === '' || $primaryDatabase === '') {
        throw new RuntimeException('RED-CMS database configuration is incomplete.');
    }

    $defaultsFile = tempnam(
        sys_get_temp_dir(),
        'redcms-store-lite-mysql-'
    );
    if (!is_string($defaultsFile)) {
        throw new RuntimeException('Could not create temporary MySQL defaults.');
    }
    chmod($defaultsFile, 0600);
    $defaults = "[client]\n" .
        'host=' . red_store_lite_catalog_option($databaseHost) . "\n" .
        'port=' . $databasePort . "\n" .
        'user=' . red_store_lite_catalog_option($databaseUser) . "\n" .
        'password=' . red_store_lite_catalog_option($databasePassword) . "\n" .
        "default-character-set=utf8mb4\n";
    if (file_put_contents($defaultsFile, $defaults) === false) {
        throw new RuntimeException('Could not write temporary MySQL defaults.');
    }

    $adminDefaultsFile = tempnam(
        sys_get_temp_dir(),
        'redcms-store-lite-mysql-admin-'
    );
    if (!is_string($adminDefaultsFile)) {
        throw new RuntimeException('Could not create temporary MySQL admin defaults.');
    }
    chmod($adminDefaultsFile, 0600);
    $adminUser = getenv('RED_ACCEPTANCE_DB_ADMIN_USER');
    $adminPassword = getenv('RED_ACCEPTANCE_DB_ADMIN_PASS');
    $adminUser = is_string($adminUser) && $adminUser !== '' ? $adminUser : 'root';
    $adminPassword = is_string($adminPassword) ? $adminPassword : '';
    $adminDefaults = "[client]\n" .
        'host=' . red_store_lite_catalog_option($databaseHost) . "\n" .
        'port=' . $databasePort . "\n" .
        'user=' . red_store_lite_catalog_option($adminUser) . "\n" .
        'password=' . red_store_lite_catalog_option($adminPassword) . "\n" .
        "default-character-set=utf8mb4\n";
    if (file_put_contents($adminDefaultsFile, $adminDefaults) === false) {
        throw new RuntimeException('Could not write temporary MySQL admin defaults.');
    }

    $currentUser = red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        'SELECT CURRENT_USER()'
    );
    if (preg_match(
        '/\A([A-Za-z0-9_.-]+)@([A-Za-z0-9_.%-]+)\z/',
        $currentUser,
        $currentUserParts
    ) !== 1) {
        throw new RuntimeException('Application database account is invalid.');
    }
    $applicationUser = $currentUserParts[1];
    $applicationHost = $currentUserParts[2];
    $quotedApplicationUser = "'" . str_replace("'", "''", $applicationUser) . "'";
    $quotedApplicationHost = "'" . str_replace("'", "''", $applicationHost) . "'";

    $escapedPrimary = str_replace("'", "''", $primaryDatabase);
    $primaryFingerprintSql = "SELECT CONCAT(
        COUNT(*), ':',
        COALESCE(SUM(TABLE_NAME LIKE 'RED_Addon_StoreLite\\\\_%'), 0)
     )
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA='$escapedPrimary'";
    $primaryBefore = red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        $primaryFingerprintSql
    );

    red_store_lite_catalog_query(
        $mysqlBinary,
        $adminDefaultsFile,
        'CREATE DATABASE `' . $acceptanceDatabase .
            '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $databaseCreated = true;
    red_store_lite_catalog_query(
        $mysqlBinary,
        $adminDefaultsFile,
        'GRANT ALL PRIVILEGES ON `' . $acceptanceDatabase . '`.* TO ' .
            $quotedApplicationUser . '@' . $quotedApplicationHost
    );
    $grantCreated = true;

    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "CREATE TABLE RED_Articles (
            RecordID int unsigned NOT NULL,
            PRIMARY KEY (RecordID)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        $acceptanceDatabase
    );

    $manifest = json_decode(
        (string) file_get_contents($packageRoot . '/addon.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $migrations = $manifest['migrations'] ?? null;
    if (!is_array($migrations) || count($migrations) !== 10) {
        throw new RuntimeException('Catalog migration manifest is invalid.');
    }
    require_once $coreRoot . '/includes/addon_install_helpers.php';
    foreach ($migrations as $migration) {
        $migrationPath = $packageRoot . '/' . ($migration['path'] ?? '');
        $migrationSql = file_get_contents($migrationPath);
        if (!is_string($migrationSql)) {
            throw new RuntimeException('Catalog migration could not be read.');
        }
        red_store_lite_catalog_assert(
            red_addon_install_sql_guard($migrationSql) === '',
            'catalog migration passes the RED-CMS package SQL guard'
        );
        $migrationResult = red_store_lite_catalog_mysql(
            $mysqlBinary,
            $defaultsFile,
            [$acceptanceDatabase],
            $migrationSql
        );
        if ($migrationResult['status'] !== 0) {
            throw new RuntimeException(
                'Catalog migration failed: ' .
                ($migrationResult['stderr'] ?: 'unknown error')
            );
        }
    }

    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT CONCAT(COUNT(*), ':', SUM(ENGINE='InnoDB'))
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME LIKE 'RED_Addon_StoreLite\\\\_%'",
            $acceptanceDatabase
        ) === '15:15',
        'migrations create exactly fifteen package-owned InnoDB catalog, cart, and order tables'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Carts'",
            $acceptanceDatabase
        ) === 'RecordID:SubjectRecordID:Currency:CreatedAt:UpdatedAt',
        'cart ownership stores only the core-issued numeric subject relation and currency'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Carts'
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            $acceptanceDatabase
        ) === '0',
        'cart ownership deliberately has no foreign key to expiring core subject storage'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Cart_Lines'",
            $acceptanceDatabase
        ) === 'RecordID:CartRecordID:ProductRecordID:VariantRecordID:LineIdentitySHA256:Quantity:UnitPriceMinor:Currency:LineTotalMinor:ProductStateSHA256:CreatedAt:UpdatedAt',
        'cart lines contain only exact catalog references and server-derived commercial state'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(
                CONCAT(REFERENCED_TABLE_NAME, ':', DELETE_RULE, ':', UPDATE_RULE)
                ORDER BY CONSTRAINT_NAME SEPARATOR '|'
             )
             FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Cart_Lines'",
            $acceptanceDatabase
        ) === 'RED_Addon_StoreLite_Carts:CASCADE:RESTRICT|RED_Addon_StoreLite_Products:RESTRICT:RESTRICT|RED_Addon_StoreLite_Product_Variants:RESTRICT:RESTRICT',
        'cart lines cascade only with their cart and restrict referenced catalog deletion'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Cart_Activity'",
            $acceptanceDatabase
        ) === 'RecordID:EventName:CartRecordID:SubjectRecordID:LineIdentitySHA256:PreviousStateSHA256:StateSHA256:OccurredAt',
        'cart activity stores value-free identity and before/after state evidence'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Orders'",
            $acceptanceDatabase
        ) === 'RecordID:OrderID:SourceCartRecordID:SubjectRecordID:IdempotencyKeySHA256:SourceCartStateSHA256:SnapshotVersion:SnapshotSHA256:Currency:CustomerName:CustomerEmail:CustomerPhone:FulfillmentMethod:FulfillmentFeeMinor:DeliveryLine1:DeliveryLine2:DeliveryCity:DeliveryRegion:DeliveryPostalCode:DeliveryCountryCode:DeliveryInstructions:PaymentMethod:PaymentKind:OrderStatus:PaymentStatus:FulfillmentStatus:QuantityTotal:SubtotalMinor:TotalMinor:CreatedAt:StatusUpdatedAt',
        'order header stores exact source, customer, fulfillment, payment, state, and integer-total snapshots'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Orders'
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            $acceptanceDatabase
        ) === '0',
        'order header deliberately snapshots core subject and consumed cart identities without foreign keys'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(
                CONCAT(INDEX_NAME, ':', COLUMN_NAME)
                ORDER BY INDEX_NAME, SEQ_IN_INDEX SEPARATOR '|')
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Orders'
               AND INDEX_NAME IN (
                 'idx_storelite_order_fulfillment_status',
                 'idx_storelite_order_payment_status'
               )",
            $acceptanceDatabase
        ) === 'idx_storelite_order_fulfillment_status:FulfillmentStatus|idx_storelite_order_fulfillment_status:RecordID|idx_storelite_order_payment_status:PaymentStatus|idx_storelite_order_payment_status:RecordID',
        'append-only 0.1.29 migrations add exact fulfillment and payment order-list indexes'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(
                CONCAT(TABLE_NAME, ':', REFERENCED_TABLE_NAME, ':',
                       DELETE_RULE, ':', UPDATE_RULE)
                ORDER BY TABLE_NAME SEPARATOR '|')
             FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE()
               AND TABLE_NAME IN (
                 'RED_Addon_StoreLite_Order_Lines',
                 'RED_Addon_StoreLite_Order_Line_Options',
                 'RED_Addon_StoreLite_Order_Status_History'
               )",
            $acceptanceDatabase
        ) === 'RED_Addon_StoreLite_Order_Line_Options:RED_Addon_StoreLite_Order_Lines:RESTRICT:RESTRICT|RED_Addon_StoreLite_Order_Lines:RED_Addon_StoreLite_Orders:RESTRICT:RESTRICT|RED_Addon_StoreLite_Order_Status_History:RED_Addon_StoreLite_Orders:RESTRICT:RESTRICT',
        'order lines, option labels, and history retain restrictive immutable ownership'
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Cart_Activity
            (EventName, CartRecordID, SubjectRecordID, LineIdentitySHA256,
             PreviousStateSHA256, StateSHA256)
         VALUES
            ('cart.line.quantity-set', 1, 1, UNHEX(REPEAT('1', 64)),
             UNHEX(REPEAT('2', 64)), UNHEX(REPEAT('3', 64))),
            ('cart.line.removed', 1, 1, UNHEX(REPEAT('4', 64)),
             UNHEX(REPEAT('5', 64)), UNHEX(REPEAT('6', 64)))",
        $acceptanceDatabase
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(EventName ORDER BY RecordID SEPARATOR ':')
             FROM RED_Addon_StoreLite_Cart_Activity",
            $acceptanceDatabase
        ) === 'cart.line.quantity-set:cart.line.removed',
        'follow-up migration admits distinct quantity-set and removal activity facts'
    );
    red_store_lite_catalog_expect_refusal(
        $mysqlBinary,
        $defaultsFile,
        $acceptanceDatabase,
        "INSERT INTO RED_Addon_StoreLite_Cart_Activity
            (EventName, CartRecordID, SubjectRecordID, LineIdentitySHA256,
             PreviousStateSHA256, StateSHA256)
         VALUES ('cart.line.unknown', 1, 1, UNHEX(REPEAT('7', 64)),
             UNHEX(REPEAT('8', 64)), UNHEX(REPEAT('9', 64)))",
        'cart activity event allowlist remains closed after expansion'
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        'DELETE FROM RED_Addon_StoreLite_Cart_Activity',
        $acceptanceDatabase
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Product_Placements'",
            $acceptanceDatabase
        ) === 'ContentRecordID:ProductRecordID',
        'Product placement storage contains only the core parent and product references'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(
                CONCAT(REFERENCED_TABLE_NAME, ':', DELETE_RULE, ':', UPDATE_RULE)
                ORDER BY REFERENCED_TABLE_NAME SEPARATOR '|'
             )
             FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Product_Placements'",
            $acceptanceDatabase
        ) === 'RED_Addon_StoreLite_Products:RESTRICT:RESTRICT|RED_Articles:RESTRICT:RESTRICT',
        'Product placements preserve both core-content and catalog ownership boundaries'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Cart_Placements'",
            $acceptanceDatabase
        ) === 'ContentRecordID:Title',
        'Cart placement storage contains only the core parent and bounded public title'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT CONCAT(REFERENCED_TABLE_NAME, ':', DELETE_RULE, ':', UPDATE_RULE)
             FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Cart_Placements'",
            $acceptanceDatabase
        ) === 'RED_Articles:RESTRICT:RESTRICT',
        'Cart placements preserve the core-content ownership boundary'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='RED_Addon_StoreLite_Product_Activity'",
            $acceptanceDatabase
        ) === 'RecordID:EventName:ProductID:ActorAdminRecordID:PreviousStateSHA256:StateSHA256:OccurredAt',
        'product activity storage is bounded to value-free event and state evidence'
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT GROUP_CONCAT(CHARACTER_MAXIMUM_LENGTH ORDER BY TABLE_NAME SEPARATOR ':')
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME IN (
                   'RED_Addon_StoreLite_Products',
                   'RED_Addon_StoreLite_Product_Variants'
               )
               AND COLUMN_NAME='ImageReference'",
            $acceptanceDatabase
        ) === '126:126',
        'follow-up migration aligns both media-reference columns to the 120-character contract'
    );

    $longMediaReference = 'media:' . str_repeat('a', 120);
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Products
            (ProductID, ProductType, Title, Summary, Currency, State,
             Availability, ImageReference, SKU, PriceMinor, Stock)
         VALUES
            ('banana', 'simple', 'Bananas', 'Sold by the bunch.', 'USD',
             'published', 'available', '$longMediaReference', 'BANANA-BUNCH', 599, 40)",
        $acceptanceDatabase
    );
    $bananaRecordId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Products
         WHERE ProductID='banana'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_assert(
        $bananaRecordId > 0
            && red_store_lite_catalog_query(
                $mysqlBinary,
                $defaultsFile,
                "SELECT CONCAT(ProductType, ':', PriceMinor, ':', Currency, ':', Stock)
                 FROM RED_Addon_StoreLite_Products
                 WHERE RecordID=$bananaRecordId",
                $acceptanceDatabase
            ) === 'simple:599:USD:40',
        'simple product stores one server-authoritative SKU, integer price, currency, and stock'
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        'INSERT INTO RED_Articles (RecordID) VALUES (101), (102)',
        $acceptanceDatabase
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Cart_Placements
            (ContentRecordID, Title)
         VALUES (102, 'Shopping cart')",
        $acceptanceDatabase
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT CONCAT(ContentRecordID, ':', Title)
             FROM RED_Addon_StoreLite_Cart_Placements",
            $acceptanceDatabase
        ) === '102:Shopping cart',
        'one core Cart component record stores one bounded public title'
    );
    red_store_lite_catalog_expect_refusal(
        $mysqlBinary,
        $defaultsFile,
        $acceptanceDatabase,
        "INSERT INTO RED_Addon_StoreLite_Cart_Placements
            (ContentRecordID, Title)
         VALUES (999, 'Missing parent')",
        'Cart placements refuse missing core content parents'
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Product_Placements
            (ContentRecordID, ProductRecordID)
         VALUES (101, $bananaRecordId)",
        $acceptanceDatabase
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT CONCAT(ContentRecordID, ':', ProductRecordID)
             FROM RED_Addon_StoreLite_Product_Placements",
            $acceptanceDatabase
        ) === '101:' . $bananaRecordId,
        'one core Product component record binds to one Store Lite product'
    );
    red_store_lite_catalog_expect_refusal(
        $mysqlBinary,
        $defaultsFile,
        $acceptanceDatabase,
        "INSERT INTO RED_Addon_StoreLite_Product_Placements
            (ContentRecordID, ProductRecordID)
         VALUES (999, $bananaRecordId)",
        'Product placements refuse missing core content parents'
    );
    red_store_lite_catalog_expect_refusal(
        $mysqlBinary,
        $defaultsFile,
        $acceptanceDatabase,
        "INSERT INTO RED_Addon_StoreLite_Product_Placements
            (ContentRecordID, ProductRecordID)
         VALUES (102, 999999)",
        'Product placements refuse missing products'
    );
    red_store_lite_catalog_expect_refusal(
        $mysqlBinary,
        $defaultsFile,
        $acceptanceDatabase,
        "DELETE FROM RED_Addon_StoreLite_Products WHERE RecordID=$bananaRecordId",
        'placed products cannot be removed through a dangling relationship'
    );

    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Products
            (ProductID, ProductType, Title, Currency, State, Availability)
         VALUES ('classic-shirt', 'variable', 'Classic T-shirt', 'USD',
                 'published', 'available')",
        $acceptanceDatabase
    );
    $shirtRecordId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Products
         WHERE ProductID='classic-shirt'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Product_Options
            (ProductRecordID, OptionKey, Label, Position)
         VALUES
            ($shirtRecordId, 'size', 'Size', 1),
            ($shirtRecordId, 'color', 'Color', 2)",
        $acceptanceDatabase
    );
    $sizeOptionId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Product_Options
         WHERE ProductRecordID=$shirtRecordId AND OptionKey='size'",
        $acceptanceDatabase
    );
    $colorOptionId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Product_Options
         WHERE ProductRecordID=$shirtRecordId AND OptionKey='color'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Product_Option_Values
            (ProductRecordID, OptionRecordID, ValueID, Label, Position)
         VALUES
            ($shirtRecordId, $sizeOptionId, 'small', 'Small', 1),
            ($shirtRecordId, $sizeOptionId, 'large', 'Large', 2),
            ($shirtRecordId, $colorOptionId, 'black', 'Black', 1),
            ($shirtRecordId, $colorOptionId, 'white', 'White', 2)",
        $acceptanceDatabase
    );
    $smallValueId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Product_Option_Values
         WHERE OptionRecordID=$sizeOptionId AND ValueID='small'",
        $acceptanceDatabase
    );
    $blackValueId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Product_Option_Values
         WHERE OptionRecordID=$colorOptionId AND ValueID='black'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Product_Variants
            (ProductRecordID, VariantID, SKU, OptionTupleSHA256,
             Position, PriceMinor, Availability, Stock)
         VALUES
            ($shirtRecordId, 'small-black', 'SHIRT-S-BLK',
             UNHEX(SHA2('color=black&size=small', 256)), 1, 2499, 'available', 8)",
        $acceptanceDatabase
    );
    $variantRecordId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Product_Variants
         WHERE ProductRecordID=$shirtRecordId AND VariantID='small-black'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Product_Variant_Selections
            (ProductRecordID, VariantRecordID, OptionRecordID, OptionValueRecordID)
         VALUES
            ($shirtRecordId, $variantRecordId, $sizeOptionId, $smallValueId),
            ($shirtRecordId, $variantRecordId, $colorOptionId, $blackValueId)",
        $acceptanceDatabase
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Options
                 WHERE ProductRecordID=$shirtRecordId), ':',
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Option_Values
                 WHERE ProductRecordID=$shirtRecordId), ':',
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Variants
                 WHERE ProductRecordID=$shirtRecordId), ':',
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Variant_Selections
                 WHERE ProductRecordID=$shirtRecordId)
             )",
            $acceptanceDatabase
        ) === '2:4:1:2',
        'variable product stores explicit groups, values, variant, and complete selections'
    );

    $refusals = [
        ["INSERT INTO RED_Addon_StoreLite_Products
           (ProductID, ProductType, Title, Currency, SKU, PriceMinor)
          VALUES ('Banana', 'simple', 'Invalid', 'USD', 'INVALID-1', 100)",
         'non-canonical product identifiers are refused'],
        ["INSERT INTO RED_Addon_StoreLite_Products
           (ProductID, ProductType, Title, Currency, SKU, PriceMinor)
          VALUES ('bad-currency', 'simple', 'Invalid', 'usd', 'INVALID-2', 100)",
         'non-canonical currency is refused'],
        ["INSERT INTO RED_Addon_StoreLite_Products
           (ProductID, ProductType, Title, Currency, SKU, PriceMinor)
          VALUES ('mixed-parent', 'variable', 'Invalid', 'USD', 'INVALID-3', 100)",
         'variable parent sellable-field mixing is refused'],
        ["INSERT INTO RED_Addon_StoreLite_Products
           (ProductID, ProductType, Title, Currency, SKU, PriceMinor)
          VALUES ('price-overflow', 'simple', 'Invalid', 'USD', 'INVALID-4', 1000000000)",
         'prices above the fixed minor-unit bound are refused'],
        ["INSERT INTO RED_Addon_StoreLite_Product_Options
           (ProductRecordID, OptionKey, Label, Position)
          VALUES ($shirtRecordId, 'material', 'Material', 4)",
         'option positions above the three-group bound are refused'],
        ["INSERT INTO RED_Addon_StoreLite_Product_Option_Values
           (ProductRecordID, OptionRecordID, ValueID, Label, Position)
          VALUES ($shirtRecordId, $sizeOptionId, 'medium', 'Medium', 17)",
         'option-value positions above the sixteen-value bound are refused'],
        ["INSERT INTO RED_Addon_StoreLite_Product_Variants
           (ProductRecordID, VariantID, SKU, OptionTupleSHA256,
            Position, PriceMinor, Availability)
          VALUES ($shirtRecordId, 'duplicate-tuple', 'SHIRT-DUP',
                  UNHEX(SHA2('color=black&size=small', 256)), 2, 2499, 'available')",
         'duplicate explicit option tuples are refused within one product'],
        ["INSERT INTO RED_Addon_StoreLite_Product_Variants
           (ProductRecordID, VariantID, SKU, OptionTupleSHA256,
            Position, PriceMinor, Availability)
          VALUES ($shirtRecordId, 'position-overflow', 'SHIRT-OVERFLOW',
                  UNHEX(SHA2('color=white&size=small', 256)), 129, 2499, 'available')",
         'variant positions above the 128-variant bound are refused'],
    ];
    foreach ($refusals as [$sql, $message]) {
        red_store_lite_catalog_expect_refusal(
            $mysqlBinary,
            $defaultsFile,
            $acceptanceDatabase,
            $sql,
            $message
        );
    }

    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Products
            (ProductID, ProductType, Title, Currency, State, Availability)
         VALUES ('classic-hat', 'variable', 'Classic Hat', 'USD', 'draft', 'available')",
        $acceptanceDatabase
    );
    $hatRecordId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Products
         WHERE ProductID='classic-hat'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Product_Options
            (ProductRecordID, OptionKey, Label, Position)
         VALUES ($hatRecordId, 'size', 'Size', 1)",
        $acceptanceDatabase
    );
    $hatOptionId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Product_Options
         WHERE ProductRecordID=$hatRecordId AND OptionKey='size'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "INSERT INTO RED_Addon_StoreLite_Product_Option_Values
            (ProductRecordID, OptionRecordID, ValueID, Label, Position)
         VALUES ($hatRecordId, $hatOptionId, 'one-size', 'One size', 1)",
        $acceptanceDatabase
    );
    $hatValueId = (int) red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "SELECT RecordID FROM RED_Addon_StoreLite_Product_Option_Values
         WHERE OptionRecordID=$hatOptionId AND ValueID='one-size'",
        $acceptanceDatabase
    );
    red_store_lite_catalog_expect_refusal(
        $mysqlBinary,
        $defaultsFile,
        $acceptanceDatabase,
        "INSERT INTO RED_Addon_StoreLite_Product_Variant_Selections
            (ProductRecordID, VariantRecordID, OptionRecordID, OptionValueRecordID)
         VALUES ($shirtRecordId, $variantRecordId, $hatOptionId, $hatValueId)",
        'variant selections cannot cross product ownership'
    );

    red_store_lite_catalog_query(
        $mysqlBinary,
        $defaultsFile,
        "DELETE FROM RED_Addon_StoreLite_Products WHERE RecordID=$shirtRecordId",
        $acceptanceDatabase
    );
    red_store_lite_catalog_assert(
        red_store_lite_catalog_query(
            $mysqlBinary,
            $defaultsFile,
            "SELECT CONCAT(
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Options
                 WHERE ProductRecordID=$shirtRecordId), ':',
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Option_Values
                 WHERE ProductRecordID=$shirtRecordId), ':',
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Variants
                 WHERE ProductRecordID=$shirtRecordId), ':',
                (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Variant_Selections
                 WHERE ProductRecordID=$shirtRecordId)
             )",
            $acceptanceDatabase
        ) === '0:0:0:0',
        'product deletion cascades only through its package-owned catalog graph'
    );
} finally {
    if ($databaseCreated
        && $adminDefaultsFile !== ''
        && preg_match(
            '/\Aredcms_store_lite_acceptance_[A-Za-z0-9_]+\z/',
            $acceptanceDatabase
        ) === 1
    ) {
        $cleanupErrors = [];
        if ($grantCreated
            && isset($quotedApplicationUser, $quotedApplicationHost)
        ) {
            try {
                red_store_lite_catalog_query(
                    $mysqlBinary,
                    $adminDefaultsFile,
                    'REVOKE ALL PRIVILEGES ON `' . $acceptanceDatabase .
                        '`.* FROM ' . $quotedApplicationUser . '@' .
                        $quotedApplicationHost
                );
            } catch (Throwable $throwable) {
                $cleanupErrors[] = 'grant:' . $throwable->getMessage();
            }
        }
        try {
            red_store_lite_catalog_query(
                $mysqlBinary,
                $adminDefaultsFile,
                'DROP DATABASE `' . $acceptanceDatabase . '`'
            );
        } catch (Throwable $throwable) {
            $cleanupErrors[] = 'database:' . $throwable->getMessage();
        }
        red_store_lite_catalog_assert(
            $cleanupErrors === [],
            'disposable database and scoped grant cleanup succeeds'
        );
        red_store_lite_catalog_assert(
            red_store_lite_catalog_query(
                $mysqlBinary,
                $adminDefaultsFile,
                "SELECT CONCAT(
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA
                     WHERE SCHEMA_NAME LIKE 'redcms_store_lite_acceptance_%'), ':',
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMA_PRIVILEGES
                     WHERE TABLE_SCHEMA LIKE 'redcms_store_lite_acceptance_%')
                 )"
            ) === '0:0',
            'no Store Lite acceptance database or scoped grant remains'
        );
    }

    try {
        if (isset($primaryFingerprintSql, $primaryBefore)
            && $defaultsFile !== ''
            && is_file($defaultsFile)
        ) {
            $primaryAfter = red_store_lite_catalog_query(
                $mysqlBinary,
                $defaultsFile,
                $primaryFingerprintSql
            );
            red_store_lite_catalog_assert(
                hash_equals($primaryBefore, $primaryAfter),
                'configured primary database table boundary remains unchanged'
            );
        }
    } finally {
        if ($defaultsFile !== '' && is_file($defaultsFile)) {
            unlink($defaultsFile);
        }
        if ($adminDefaultsFile !== '' && is_file($adminDefaultsFile)) {
            unlink($adminDefaultsFile);
        }
    }
}

echo 'Store Lite catalog migration passed ' . $assertions . " assertions.\n";
