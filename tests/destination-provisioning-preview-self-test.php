<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

require_once dirname(__DIR__) .
    '/package/src/DestinationProvisioningPreview.php';

$source = file_get_contents(
    dirname(__DIR__) . '/package/src/DestinationProvisioningPreview.php'
);
$assert(
    is_string($source)
        && !preg_match(
            '/\b(?:mysqli|PDO|curl|file_put_contents|include|require|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION))\b/',
            $source
        ),
    'preview has no database, request, network, filesystem-write, or runtime path'
);

$product = [
    'id' => 'banana-bunch',
    'state' => 'published',
];
$stateSha256 = str_repeat('a', 64);
$missing = [
    'status' => 'missing',
    'label' => 'Missing',
    'path' => '/banana-bunch',
    'pathKind' => 'proposed',
    'reason' => 'No lifecycle-managed destination exists yet.',
];
$preview = RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
    41,
    $product,
    $stateSha256,
    $missing
);
$assert(
    $preview['intent'] === 'provision'
        && $preview['label'] === 'Ready to provision'
        && $preview['ready'] === true
        && $preview['requiresConfirmation'] === true
        && $preview['writesEnabled'] === false
        && $preview['path'] === '/banana-bunch'
        && $preview['blockers'] === [],
    'a published product with an unclaimed path receives a preview-only plan'
);
$assert(
    $preview['operations'] === [
        'core.article-route.create',
        'redcms.store-lite/product-component.create',
        'core.component.publish',
        'content.search.refresh',
    ],
    'the preview fixes the four future lifecycle operations in order'
);
$assert(
    preg_match('/\A[a-f0-9]{64}\z/D', $preview['planSha256']) === 1
        && RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
            41,
            $product,
            $stateSha256,
            $missing
        ) === $preview,
    'identical product and destination evidence reconstructs one plan hash'
);

$routeCreated = [
    'status' => 'route_created',
    'label' => 'Provisioning in progress',
    'path' => '/banana-bunch',
    'pathKind' => 'expected',
    'reason' => 'The guarded Article route is ready for component creation.',
];
$routeCreatedPreview =
    RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
        41,
        $product,
        $stateSha256,
        $routeCreated
    );
$assert(
    $routeCreatedPreview['intent'] === 'provision'
        && $routeCreatedPreview['ready'] === true
        && $routeCreatedPreview['writesEnabled'] === false
        && hash_equals(
            $preview['planSha256'],
            $routeCreatedPreview['planSha256']
        ),
    'the exact route-only checkpoint retains the original provisioning plan'
);

$componentCreated = $routeCreated;
$componentCreated['status'] = 'component_created';
$componentCreated['reason'] =
    'The guarded inactive Product component is ready for publication.';
$componentCreatedPreview =
    RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
        41,
        $product,
        $stateSha256,
        $componentCreated
    );
$assert(
    $componentCreatedPreview['intent'] === 'provision'
        && $componentCreatedPreview['ready'] === true
        && $componentCreatedPreview['writesEnabled'] === false
        && hash_equals(
            $preview['planSha256'],
            $componentCreatedPreview['planSha256']
        ),
    'the exact inactive component checkpoint retains the original plan'
);

$draftComponentPreview =
    RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
        41,
        array_merge($product, ['state' => 'draft']),
        $stateSha256,
        $componentCreated
    );
$assert(
    $draftComponentPreview['intent'] === 'blocked'
        && $draftComponentPreview['ready'] === false
        && $draftComponentPreview['blockers'] === ['product_not_published'],
    'an intermediate destination cannot continue for an unpublished product'
);

$published = $missing;
$published['status'] = 'published';
$published['label'] = 'Published';
$published['pathKind'] = 'public';
$publishedPreview =
    RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
        41,
        $product,
        $stateSha256,
        $published
    );
$assert(
    $publishedPreview['intent'] === 'none'
        && $publishedPreview['label'] === 'No action needed'
        && $publishedPreview['operations'] === []
        && $publishedPreview['requiresConfirmation'] === false,
    'an existing published destination never receives a provisioning intent'
);

$draft = $product;
$draft['state'] = 'draft';
$draftPreview = RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
    41,
    $draft,
    $stateSha256,
    $missing
);
$assert(
    $draftPreview['intent'] === 'blocked'
        && $draftPreview['label'] === 'Publish product first'
        && $draftPreview['blockers'] === ['product_not_published']
        && $draftPreview['operations'] === [],
    'a draft product is blocked before any provisioning operation is planned'
);

$repair = $missing;
$repair['status'] = 'repair_needed';
$repair['label'] = 'Repair needed';
$repair['pathKind'] = 'expected';
$repairPreview = RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
    41,
    $product,
    $stateSha256,
    $repair
);
$assert(
    $repairPreview['intent'] === 'repair'
        && $repairPreview['label'] === 'Repair first'
        && $repairPreview['blockers'] === ['destination_repair_required']
        && $repairPreview['ready'] === false,
    'collision or partial-state evidence routes to repair instead of provision'
);

$changedProduct = $product;
$changedProduct['state'] = 'archived';
$changed = RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
    41,
    $changedProduct,
    $stateSha256,
    $missing
);
$assert(
    !hash_equals($preview['planSha256'], $changed['planSha256'])
        && $changed['label'] === 'Restore product first',
    'product-state drift changes the plan and the blocking instruction'
);

$refused = false;
try {
    RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
        41,
        $product,
        $stateSha256,
        array_merge($missing, ['path' => '/../unsafe'])
    );
} catch (InvalidArgumentException $exception) {
    $refused = true;
}
$assert($refused, 'unsafe proposed paths fail before a plan is generated');

printf(
    "Store Lite destination provisioning preview passed %d assertions.\n",
    $assertions
);
