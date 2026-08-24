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
    fwrite(STDERR, "Destination preview rehearsal refused unsafe input.\n");
    exit(64);
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/class/class_connection.php';
require_once $projectRoot . '/includes/addon_registry_helpers.php';
require_once $projectRoot . '/includes/addon_service_helpers.php';
require_once $projectRoot
    . '/includes/addon_component_destination_route_helpers.php';
require_once $projectRoot
    . '/includes/addon_component_destination_component_helpers.php';

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
            (SELECT COUNT(*) FROM RED_Addon_Component_Revisions),
            (SELECT COUNT(*) FROM RED_Admin_Activity_Log),
            (SELECT COUNT(*) FROM RED_Admin_Capabilities),
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
    ['productId' => 'banana-bunch', 'currency' => 'USD', 'language' => 'sp']
);
$missing = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'missing-product', 'currency' => 'USD', 'language' => 'sp']
);
$invalid = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'banana-bunch', 'currency' => 'usd', 'language' => 'sp']
);
$serviceFingerprintAfter = $fingerprint($connection);

$actorRecordId = 1;
$permission = 'store.products.manage';
$capabilityStatement = mysqli_prepare(
    $connection,
    'INSERT INTO RED_Admin_Capabilities
       (AdminRecordID, Capability, GrantedByAdminRecordID)
     VALUES (?, ?, ?)'
);
mysqli_stmt_bind_param(
    $capabilityStatement,
    'isi',
    $actorRecordId,
    $permission,
    $actorRecordId
);
$capabilityInserted = mysqli_stmt_execute($capabilityStatement);
mysqli_stmt_close($capabilityStatement);
if (!$capabilityInserted) {
    throw new RuntimeException('Destination permission fixture was not created.');
}

$idQuery = mysqli_query(
    $connection,
    'SELECT COALESCE(MAX(RecordID), 0) + 1 FROM RED_Articles'
);
$idRow = $idQuery ? mysqli_fetch_row($idQuery) : null;
if ($idQuery) {
    mysqli_free_result($idQuery);
}
$routeRecordId = is_array($idRow) ? (int) $idRow[0] : 0;
$componentRecordId = $routeRecordId + 1;
$contract = red_theme_active_layout_contract($connection, $projectRoot);
$layout = (string) array_key_first($contract['catalog'] ?? []);
$positions = $layout !== ''
    ? red_admin_article_layout_position_options($connection, $layout, false)
    : [];
$pagePosition = (int) array_key_first($positions);
if ($routeRecordId < 1
    || $componentRecordId > 2147483647
    || $layout === ''
    || $pagePosition < 1
) {
    throw new RuntimeException('Server-derived destination values are unavailable.');
}
$manifest = $package['manifest'] ?? [];
$routeRequest = [
    'previewService' => 'content.destination-preview.store-lite',
    'previewOperation' => 'destination.preview',
    'previewInput' => [
        'productId' => 'banana-bunch',
        'currency' => 'USD',
        'language' => 'sp',
    ],
    'routeRecordId' => $routeRecordId,
    'componentRecordId' => $componentRecordId,
    'title' => 'Banana bunch',
    'alias' => 'banana-bunch',
    'language' => 'sp',
    'layout' => $layout,
    'routePagePosition' => $pagePosition,
    'routePagePositionOrder' => 0,
    'componentPagePosition' => $pagePosition,
    'componentPagePositionOrder' => 1,
    'componentValues' => ['product-id' => 'banana-bunch'],
];
$routePreview = red_addon_component_destination_route_preview(
    $manifest,
    $routeRequest
);
$routePlanRequest = red_addon_component_destination_route_preflight_request(
    $routeRequest,
    $routePreview
);
$routePlan = red_addon_component_destination_preflight(
    $connection,
    $manifest,
    'redcms.store-lite/product',
    $actorRecordId,
    $routePlanRequest
);
$createdRoute = !empty($routePlan['ready'])
    ? red_addon_component_destination_route_create(
        $connection,
        $manifest,
        'redcms.store-lite/product',
        $actorRecordId,
        $routeRequest,
        $routePlan['planHash']
    )
    : [];
$continuedPreview = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'banana-bunch', 'currency' => 'USD', 'language' => 'sp']
);
$replayedRoute = !empty($createdRoute['created'])
    ? red_addon_component_destination_route_create(
        $connection,
        $manifest,
        'redcms.store-lite/product',
        $actorRecordId,
        $routeRequest,
        $routePlan['planHash']
    )
    : [];
$createdComponent = !empty($replayedRoute['created'])
    ? red_addon_component_destination_component_create(
        $connection,
        $manifest,
        'redcms.store-lite/product',
        $actorRecordId,
        $routeRequest,
        $routePlan['planHash']
    )
    : [];
$componentContinuedPreview = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'banana-bunch', 'currency' => 'USD', 'language' => 'sp']
);
if (!mysqli_query(
    $connection,
    "UPDATE RED_Articles SET Active='Y'
     WHERE RecordID=$componentRecordId AND Active='N'"
) || mysqli_affected_rows($connection) !== 1
) {
    throw new RuntimeException('Component drift fixture was not created.');
}
$componentDriftPreview = red_addon_service_invoke(
    'content.destination-preview.store-lite',
    'destination.preview',
    ['productId' => 'banana-bunch', 'currency' => 'USD', 'language' => 'sp']
);
if (!mysqli_query(
    $connection,
    "UPDATE RED_Articles SET Active='N'
     WHERE RecordID=$componentRecordId AND Active='Y'"
) || mysqli_affected_rows($connection) !== 1
) {
    throw new RuntimeException('Component drift fixture was not restored.');
}
$replayedComponent = !empty($createdComponent['created'])
    ? red_addon_component_destination_component_create(
        $connection,
        $manifest,
        'redcms.store-lite/product',
        $actorRecordId,
        $routeRequest,
        $routePlan['planHash']
    )
    : [];
$routeEvidenceQuery = mysqli_query(
    $connection,
    "SELECT CONCAT_WS(':',
        (SELECT COUNT(*) FROM RED_Articles
         WHERE RecordID=$routeRecordId AND Component='Article'
           AND Alias='banana-bunch' AND Language='sp' AND Active='Y'),
        (SELECT COUNT(*) FROM RED_Content_Revisions
         WHERE ContentRecordID=$routeRecordId
           AND RevisionNumber=1 AND Operation='create'),
        (SELECT COUNT(*) FROM RED_Admin_Activity_Log
         WHERE EventName='article.created' AND TargetType='article'
           AND TargetRecordID=$routeRecordId
           AND ActorAdminRecordID=$actorRecordId),
        (SELECT COUNT(*) FROM RED_Articles
         WHERE RecordID=$componentRecordId
           AND Component='redcms.store-lite/product'
           AND Active='N' AND Alias='' AND Article=''
           AND PagePosition=0 AND Language='sp'),
        (SELECT COUNT(*) FROM RED_Addon_StoreLite_Product_Placements
         WHERE ContentRecordID=$componentRecordId),
        (SELECT COUNT(*) FROM RED_Content_Revisions
         WHERE ContentRecordID=$componentRecordId
           AND RevisionNumber=1 AND Operation='create'),
        (SELECT COUNT(*) FROM RED_Addon_Component_Revisions
         WHERE ContentRecordID=$componentRecordId
           AND RevisionNumber=1 AND Operation='baseline'),
        (SELECT COUNT(*) FROM RED_Addon_Component_Destination_Executions
         WHERE PackageID='redcms.store-lite'
           AND PlanSHA256='" . ($routePlan['planHash'] ?? '') . "'
           AND Stage='component_created'
           AND ComponentStateSHA256='"
            . ($createdComponent['componentStateSha256'] ?? '') . "'))"
);
$routeEvidenceRow = $routeEvidenceQuery
    ? mysqli_fetch_row($routeEvidenceQuery)
    : null;
if ($routeEvidenceQuery) {
    mysqli_free_result($routeEvidenceQuery);
}
$routeEvidence = is_array($routeEvidenceRow)
    ? (string) $routeEvidenceRow[0]
    : '';

$cleanupStatements = [
    "DELETE FROM RED_Addon_Component_Destination_Executions
     WHERE PackageID='redcms.store-lite'
       AND PlanSHA256='" . ($routePlan['planHash'] ?? '') . "'",
    "DELETE FROM RED_Addon_Component_Revisions
     WHERE ContentRecordID=$componentRecordId",
    "DELETE FROM RED_Content_Revisions
     WHERE ContentRecordID=$componentRecordId",
    "DELETE FROM RED_Addon_StoreLite_Product_Placements
     WHERE ContentRecordID=$componentRecordId",
    "DELETE FROM RED_Articles WHERE RecordID=$componentRecordId",
    "DELETE FROM RED_Admin_Activity_Log
     WHERE EventName='article.created' AND TargetType='article'
       AND TargetRecordID=$routeRecordId",
    "DELETE FROM RED_Content_Revisions
     WHERE ContentRecordID=$routeRecordId",
    "DELETE FROM RED_Articles WHERE RecordID=$routeRecordId",
    "DELETE FROM RED_Admin_Capabilities
     WHERE AdminRecordID=$actorRecordId
       AND Capability='store.products.manage'",
];
foreach ($cleanupStatements as $cleanupSql) {
    if (!mysqli_query($connection, $cleanupSql)) {
        throw new RuntimeException('Destination route fixture cleanup failed.');
    }
}

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
    || empty($routePlan['ready'])
    || empty($createdRoute['created'])
    || !empty($createdRoute['resumed'])
    || ($createdRoute['stage'] ?? '') !== 'route_created'
    || empty($continuedPreview['success'])
    || ($continuedPreview['data']['intent'] ?? '') !== 'provision'
    || ($continuedPreview['data']['ready'] ?? false) !== true
    || !hash_equals(
        (string) ($preview['data']['planSha256'] ?? ''),
        (string) ($continuedPreview['data']['planSha256'] ?? '')
    )
    || empty($replayedRoute['created'])
    || empty($replayedRoute['resumed'])
    || !hash_equals(
        (string) ($createdRoute['routeStateSha256'] ?? ''),
        (string) ($replayedRoute['routeStateSha256'] ?? '')
    )
    || empty($createdComponent['created'])
    || !empty($createdComponent['resumed'])
    || ($createdComponent['stage'] ?? '') !== 'component_created'
    || !red_addon_valid_sha256(
        $createdComponent['componentStateSha256'] ?? ''
    )
    || empty($componentContinuedPreview['success'])
    || ($componentContinuedPreview['data']['intent'] ?? '') !== 'provision'
    || ($componentContinuedPreview['data']['ready'] ?? false) !== true
    || !hash_equals(
        (string) ($preview['data']['planSha256'] ?? ''),
        (string) ($componentContinuedPreview['data']['planSha256'] ?? '')
    )
    || empty($componentDriftPreview['success'])
    || ($componentDriftPreview['data']['intent'] ?? '') !== 'repair'
    || ($componentDriftPreview['data']['ready'] ?? true) !== false
    || empty($replayedComponent['created'])
    || empty($replayedComponent['resumed'])
    || !hash_equals(
        (string) ($createdComponent['componentStateSha256'] ?? ''),
        (string) ($replayedComponent['componentStateSha256'] ?? '')
    )
    || $routeEvidence !== '1:1:1:1:1:1:1:1'
    || $baselineFingerprint === ''
    || $baselineFingerprint !== $finalFingerprint
) {
    throw new RuntimeException(
        'Destination preview typed-service rehearsal failed: '
            . json_encode([
                'routePlan' => [
                    'ready' => $routePlan['ready'] ?? null,
                    'reason' => $routePlan['reason'] ?? null,
                ],
                'createdRoute' => $createdRoute,
                'continuedPreview' => $continuedPreview,
                'replayedRoute' => $replayedRoute,
                'createdComponent' => $createdComponent,
                'componentContinuedPreview' => $componentContinuedPreview,
                'componentDriftPreview' => $componentDriftPreview,
                'replayedComponent' => $replayedComponent,
                'routeEvidence' => $routeEvidence,
                'baselineFingerprint' => $baselineFingerprint,
                'finalFingerprint' => $finalFingerprint,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
}

echo json_encode([
    'ok' => true,
    'packageVersion' => $package['manifest']['version'] ?? '',
    'intent' => $preview['data']['intent'],
    'writesEnabled' => $preview['data']['writesEnabled'],
    'fingerprintUnchanged' => true,
    'fixtureRestored' => true,
    'routeCreated' => true,
    'routeReplayResumed' => true,
    'componentCreated' => true,
    'componentReplayResumed' => true,
    'assertions' => 33,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
