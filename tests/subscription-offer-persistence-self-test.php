<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$core = realpath(dirname($root) . '/redcms v5.1');
$database = 'redcms_sl_subscription_' . gmdate('Ymd_His') . '_'
    . bin2hex(random_bytes(3));
$assertions = 0;
$created = false;
$granted = false;
$primary = null;
$admin = null;
$app = null;
$primaryFingerprint = '';
$accountUser = '';
$accountHost = '';

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};
$connect = static function (
    string $host,
    int $port,
    string $user,
    string $password,
    string $name = ''
): mysqli {
    $connection = mysqli_init();
    mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    mysqli_real_connect($connection, $host, $user, $password, $name, $port);
    mysqli_set_charset($connection, 'utf8mb4');
    return $connection;
};
$value = static function (mysqli $connection, string $sql): string {
    $query = mysqli_query($connection, $sql);
    $row = mysqli_fetch_row($query);
    mysqli_free_result($query);
    return (string) ($row[0] ?? '');
};
$apply = static function (mysqli $connection, string $sql): void {
    mysqli_multi_query($connection, $sql);
    do {
        $result = mysqli_store_result($connection);
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($connection)
        && mysqli_next_result($connection));
};

$cleanup = static function () use (
    &$created,
    &$granted,
    &$primary,
    &$admin,
    &$app,
    &$primaryFingerprint,
    &$accountUser,
    &$accountHost,
    $database,
    $value
): void {
    if ($app instanceof mysqli) {
        mysqli_close($app);
        $app = null;
    }
    if ($admin instanceof mysqli) {
        if ($granted) {
            mysqli_query(
                $admin,
                "REVOKE ALL PRIVILEGES ON `$database`.* FROM "
                    . "'$accountUser'@'$accountHost'"
            );
            $granted = false;
        }
        if ($created) {
            mysqli_query($admin, "DROP DATABASE IF EXISTS `$database`");
            $created = false;
        }
        mysqli_close($admin);
        $admin = null;
    }
    if ($primary instanceof mysqli) {
        $after = $value(
            $primary,
            "SELECT CONCAT(COUNT(*), ':',
                COALESCE(SUM(TABLE_NAME LIKE 'RED_Addon_StoreLite\\_%'), 0))
             FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()"
        );
        if ($primaryFingerprint !== '' && $after !== $primaryFingerprint) {
            throw new RuntimeException('Configured primary changed.');
        }
        mysqli_close($primary);
        $primary = null;
    }
};

try {
    $assert(is_string($core) && is_dir($core), 'core root resolves');
    $config = require $core . '/includes/config.local.php';
    $hostPort = (string) ($config['DBHOST'] ?? '');
    $host = $hostPort;
    $port = 3306;
    if (str_contains($hostPort, ':')) {
        [$host, $portValue] = explode(':', $hostPort, 2);
        $port = (int) $portValue;
    }
    $user = (string) ($config['DBUSER'] ?? '');
    $password = (string) ($config['DBPASS'] ?? '');
    $primaryName = (string) ($config['DBNAME'] ?? '');
    $primary = $connect($host, $port, $user, $password, $primaryName);
    $primaryFingerprint = $value(
        $primary,
        "SELECT CONCAT(COUNT(*), ':',
            COALESCE(SUM(TABLE_NAME LIKE 'RED_Addon_StoreLite\\_%'), 0))
         FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()"
    );
    $currentUser = $value($primary, 'SELECT CURRENT_USER()');
    if (preg_match('/\A([A-Za-z0-9_.-]+)@([A-Za-z0-9_.%-]+)\z/', $currentUser, $parts) !== 1) {
        throw new RuntimeException('Application account refused.');
    }
    $accountUser = $parts[1];
    $accountHost = $parts[2];
    $adminUser = getenv('RED_ACCEPTANCE_DB_ADMIN_USER') ?: 'root';
    $adminPassword = getenv('RED_ACCEPTANCE_DB_ADMIN_PASS') ?: '';
    $admin = $connect($host, $port, $adminUser, $adminPassword);
    $assert(
        $value($admin, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$database'") === '0',
        'disposable database does not preexist'
    );
    mysqli_query($admin, "CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    mysqli_query($admin, "GRANT ALL PRIVILEGES ON `$database`.* TO '$accountUser'@'$accountHost'");
    $granted = true;
    $app = $connect($host, $port, $user, $password, $database);
    $apply($app, (string) file_get_contents(
        $root . '/package/migrations/2026-08-07-create-catalog.sql'
    ));
    $apply($app, (string) file_get_contents(
        $root . '/package/migrations/2026-08-25-create-subscription-offers.sql'
    ));
    $apply($app, (string) file_get_contents(
        $root . '/package/migrations/2026-08-25-z-create-subscription-activity.sql'
    ));
    require_once $root . '/package/src/ProductNormalizer.php';
    require_once $root . '/package/src/CatalogPersistence.php';
    require_once $root . '/package/src/SubscriptionOffer.php';
    require_once $root . '/package/src/SubscriptionOfferPersistence.php';
    require_once $core . '/includes/addon_admin_tool_form_value_helpers.php';
    require_once $core . '/includes/addon_admin_tool_form_write_helpers.php';
    require_once $core . '/includes/addon_admin_tool_form_create_helpers.php';
    require_once $root . '/package/src/SubscriptionOfferFormBridge.php';

    $product = [
        'id' => 'studio-membership', 'type' => 'simple',
        'title' => 'Studio membership', 'summary' => null,
        'currency' => 'USD', 'state' => 'published',
        'availability' => 'available', 'imageRef' => null,
        'sku' => 'MEMBERSHIP', 'priceMinor' => 2900, 'stock' => null,
        'options' => [], 'variants' => [],
    ];
    mysqli_begin_transaction($app);
    $productCreated = RED_CMS_Store_Lite_Catalog_Persistence::createWithinTransaction(
        $app,
        $product,
        'USD'
    );
    mysqli_commit($app);
    $assert(($productCreated['status'] ?? '') === 'created', 'product fixture created');

    $offer = [
        'id' => 'studio-membership-monthly',
        'productId' => 'studio-membership', 'variantId' => null,
        'title' => 'Studio membership', 'summary' => null,
        'currency' => 'USD', 'priceMinor' => 2900,
        'billingPeriod' => 'monthly', 'state' => 'draft',
        'availability' => 'unavailable', 'buttonLabel' => 'Subscribe monthly',
    ];
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer_Persistence::createWithinTransaction(
            $app,
            $offer,
            'USD'
        )['status'] === 'invalid',
        'write without caller transaction is refused'
    );
    mysqli_begin_transaction($app);
    $createdOffer = RED_CMS_Store_Lite_Subscription_Offer_Persistence::createWithinTransaction(
        $app,
        $offer,
        'USD'
    );
    mysqli_commit($app);
    $assert(($createdOffer['status'] ?? '') === 'created', 'offer created atomically');
    $stored = RED_CMS_Store_Lite_Subscription_Offer_Persistence::read(
        $app,
        $offer['id'],
        'USD'
    );
    $assert(
        ($stored['status'] ?? '') === 'found'
            && ($stored['offer'] ?? null) === $offer,
        'created offer reads exactly'
    );
    mysqli_begin_transaction($app);
    $duplicate = RED_CMS_Store_Lite_Subscription_Offer_Persistence::createWithinTransaction(
        $app,
        $offer,
        'USD'
    );
    mysqli_rollback($app);
    $assert(($duplicate['status'] ?? '') === 'already_exists', 'duplicate refused');

    $published = $offer;
    $published['state'] = 'published';
    $published['availability'] = 'available';
    mysqli_begin_transaction($app);
    $stale = RED_CMS_Store_Lite_Subscription_Offer_Persistence::replaceWithinTransaction(
        $app,
        $published,
        'USD',
        str_repeat('0', 64)
    );
    mysqli_rollback($app);
    $assert(($stale['status'] ?? '') === 'state_conflict', 'stale update refused');
    mysqli_begin_transaction($app);
    $updated = RED_CMS_Store_Lite_Subscription_Offer_Persistence::replaceWithinTransaction(
        $app,
        $published,
        'USD',
        $stored['stateSha256']
    );
    mysqli_commit($app);
    $assert(($updated['status'] ?? '') === 'updated', 'current update committed');
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer_Persistence::read(
            $app,
            $offer['id'],
            'USD'
        )['offer'] === $published,
        'published offer reads exactly'
    );

    $settings = new RED_Addon_Admin_Tool_Form_Runtime_Settings(
        ['catalog.currency' => 'USD'],
        hash('sha256', 'subscription-test-currency')
    );
    $loadedForm = RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::load(
        $app,
        new RED_Addon_Admin_Tool_Form_Value_Request(
            RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::TOOL,
            RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::FORM,
            $stored['recordId'],
            $settings
        )
    )->values();
    $assert(
        $loadedForm
            === RED_CMS_Store_Lite_Subscription_Offer_Form_Values::fromOffer(
                $published
            ),
        'administrator value loader returns the exact offer graph'
    );
    $targetPage = RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::targets(
        $app,
        new RED_Addon_Admin_Tool_Form_Target_Request(
            RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::TOOL,
            RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::FORM,
            1,
            null,
            $settings
        )
    )->pageModel();
    $assert(
        ($targetPage['items'][0]['label'] ?? '') === 'Studio membership'
            && ($targetPage['items'][0]['facts'][2]['value'] ?? '')
                === 'USD 29.00 · monthly',
        'administrator target list exposes bounded subscription facts'
    );
    $editedForm = $loadedForm;
    $editedForm['title'] = 'Studio membership plus';
    $writeRequest = new RED_Addon_Admin_Tool_Form_Write_Request(
        'redcms.store-lite',
        '0.1.50',
        RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::TOOL,
        RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::FORM,
        77,
        $stored['recordId'],
        str_repeat('a', 64),
        str_repeat('b', 64),
        $editedForm,
        $settings
    );
    mysqli_begin_transaction($app);
    $bridgeWritten = RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::write(
        $app,
        $writeRequest
    );
    $activityInside = $value(
        $app,
        'SELECT COUNT(*) FROM RED_Addon_StoreLite_Subscription_Activity'
    );
    mysqli_rollback($app);
    $assert(
        $bridgeWritten === true && $activityInside === '1',
        'administrator write and value-free activity are atomic'
    );
    $initial = RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::initial(
        $app,
        new RED_Addon_Admin_Tool_Form_Initial_Value_Request(
            RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::TOOL,
            RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge::FORM,
            $settings
        )
    )->values();
    $assert(
        $initial['currency'] === 'USD'
            && $initial['billing-period'] === 'monthly'
            && $initial['state'] === 'draft'
            && $initial['button-label'] === 'Subscribe',
        'administrator initial values are safe and currency-bound'
    );

    $missing = $offer;
    $missing['id'] = 'missing-product-monthly';
    $missing['productId'] = 'missing-product';
    mysqli_begin_transaction($app);
    $missingResult = RED_CMS_Store_Lite_Subscription_Offer_Persistence::createWithinTransaction(
        $app,
        $missing,
        'USD'
    );
    mysqli_rollback($app);
    $assert(($missingResult['status'] ?? '') === 'target_unavailable', 'missing product refused');

    $assert(
        $value($app, 'SELECT COUNT(*) FROM RED_Addon_StoreLite_Subscription_Offers') === '1',
        'only one offer row exists'
    );
    $assert(
        $value($app, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='RED_Addon_StoreLite_Subscription_Offers' AND ENGINE='InnoDB'") === '1',
        'offer table is client-local InnoDB'
    );
    $cleanup();
    $assertions++;
    echo 'Store Lite subscription-offer persistence passed: '
        . $assertions . " assertions; cleanup database:0 grant:0 primary:unchanged.\n";
} catch (Throwable $throwable) {
    try {
        $cleanup();
    } catch (Throwable $cleanupFailure) {
        fwrite(STDERR, $cleanupFailure->getMessage() . "\n");
    }
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
