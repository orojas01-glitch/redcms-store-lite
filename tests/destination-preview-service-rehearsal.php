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
        '/\Aredcms_(?:store_lite_browser|sl_payment_lifecycle)_[A-Za-z0-9_]+\z/D',
        $databaseName
    ) !== 1
) {
    fwrite(STDERR, "Destination preview rehearsal refused unsafe input.\n");
    exit(64);
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/class/class_connection.php';
require_once $projectRoot . '/includes/addon_registry_helpers.php';
require_once $projectRoot . '/includes/addon_service_helpers.php';

$packageId = 'redcms.store-lite';
$catalog = red_addon_discover(
    $projectRoot,
    ['cmsVersion' => '5.1.0', 'phpVersion' => PHP_VERSION]
);
$package = $catalog['packages'][$packageId] ?? null;
if (empty($catalog['valid']) || !is_array($package)) {
    throw new RuntimeException('Staged Store Lite package is unavailable.');
}
$registry = red_addon_runtime_register_package($package);
red_addon_runtime_set_request_context(
    new RED_Addon_Runtime_Context(
        [$packageId],
        [$packageId => $registry]
    )
);

$db = new connection(DBHOST, DBUSER, DBPASS, DBNAME);
$connection = $db->connection;
$fingerprint = static function (mysqli $connection): string {
    $query = mysqli_query(
        $connection,
        "SELECT CONCAT_WS(':',
            (SELECT COUNT(*) FROM RED_Addon_StoreLite_Products),
            (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Options),
            (SELECT COUNT(*)
             FROM RED_Addon_StoreLite_Product_Option_Values),
            (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Variants),
            (SELECT COUNT(*)
             FROM RED_Addon_StoreLite_Product_Variant_Selections),
            (SELECT COUNT(*)
             FROM RED_Addon_StoreLite_Product_Placements),
            (SELECT COUNT(*) FROM RED_Articles),
            (SELECT COUNT(*) FROM RED_Content_Revisions),
            (SELECT COUNT(*) FROM RED_Admin_Activity_Log),
            (SELECT COUNT(*)
             FROM RED_Addon_Component_Destination_Executions))"
    );
    $row = $query ? mysqli_fetch_row($query) : null;
    if ($query) {
        mysqli_free_result($query);
    }
    return is_array($row) ? (string) $row[0] : '';
};
$baselineFingerprint = $fingerprint($connection);
$existing = RED_CMS_Store_Lite_Catalog_Persistence::read(
    $connection,
    'banana-bunch',
    'USD'
);
$seeded = false;
if (($existing['status'] ?? '') === 'not_found') {
    $created = RED_CMS_Store_Lite_Catalog_Persistence::create(
        $connection,
        [
            'id' => 'banana-bunch',
            'type' => 'simple',
            'title' => 'Banana bunch',
            'summary' => 'Six ripe bananas.',
            'currency' => 'USD',
            'state' => 'published',
            'availability' => 'available',
            'imageRef' => null,
            'sku' => 'BANANA-BUNCH',
            'priceMinor' => 599,
            'stock' => 40,
        ],
        'USD'
    );
    if (($created['status'] ?? '') !== 'created') {
        throw new RuntimeException('Synthetic preview product was not created.');
    }
    $seeded = true;
} elseif (($existing['status'] ?? '') !== 'found') {
    throw new RuntimeException('Preview product state is unavailable.');
}
$serviceFingerprintBefore = $fingerprint($connection);

$preview = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'banana-bunch', 'currency' => 'USD']
);
$missing = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'missing-product', 'currency' => 'USD']
);
$invalid = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'banana-bunch', 'currency' => 'usd']
);
$serviceFingerprintAfter = $fingerprint($connection);

if ($seeded) {
    $statement = mysqli_prepare(
        $connection,
        'DELETE FROM RED_Addon_StoreLite_Products WHERE ProductID=?'
    );
    $productId = 'banana-bunch';
    mysqli_stmt_bind_param($statement, 's', $productId);
    $deleted = mysqli_stmt_execute($statement)
        && mysqli_stmt_affected_rows($statement) === 1;
    mysqli_stmt_close($statement);
    if (!$deleted) {
        throw new RuntimeException('Synthetic preview product was not removed.');
    }
}
$finalFingerprint = $fingerprint($connection);
$db->close();

if (empty($preview['invoked'])
    || empty($preview['success'])
    || ($preview['package'] ?? '') !== $packageId
    || ($preview['data'] ?? null) !== [
        'schema' => 1,
        'planSha256' => $preview['data']['planSha256'] ?? null,
        'intent' => 'provision',
        'ready' => true,
        'requiresConfirmation' => true,
        'writesEnabled' => false,
        'path' => '/banana-bunch',
    ]
    || preg_match(
        '/\A[a-f0-9]{64}\z/D',
        (string) ($preview['data']['planSha256'] ?? '')
    ) !== 1
    || empty($missing['invoked'])
    || !empty($missing['success'])
    || ($missing['error'] ?? '')
        !== 'destination_preview_product_unavailable'
    || empty($invalid['invoked'])
    || !empty($invalid['success'])
    || ($invalid['error'] ?? '') !== 'destination_preview_request_invalid'
    || $serviceFingerprintBefore === ''
    || $serviceFingerprintBefore !== $serviceFingerprintAfter
    || $baselineFingerprint === ''
    || $baselineFingerprint !== $finalFingerprint
) {
    throw new RuntimeException(
        'Destination preview typed-service rehearsal failed.'
    );
}

echo json_encode([
    'ok' => true,
    'packageVersion' => $package['manifest']['version'] ?? '',
    'intent' => $preview['data']['intent'],
    'writesEnabled' => $preview['data']['writesEnabled'],
    'fingerprintUnchanged' => true,
    'fixtureRestored' => true,
    'assertions' => 12,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
