<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/package/src/DestinationPreviewService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$preview = [
    'intent' => 'provision',
    'label' => 'Ready to provision',
    'ready' => true,
    'requiresConfirmation' => true,
    'writesEnabled' => false,
    'path' => '/banana-bunch',
    'operations' => [
        'core.article-route.create',
        'redcms.store-lite/product-component.create',
        'core.component.publish',
        'content.search.refresh',
    ],
    'blockers' => [],
    'planSha256' => str_repeat('a', 64),
    'reason' => 'Four guarded lifecycle operations are ready for review.',
];
$envelope = RED_CMS_Store_Lite_Destination_Preview_Service::envelope($preview);
$assert(
    $envelope === [
        'schema' => 1,
        'planSha256' => str_repeat('a', 64),
        'intent' => 'provision',
        'ready' => true,
        'requiresConfirmation' => true,
        'writesEnabled' => false,
        'path' => '/banana-bunch',
    ],
    'service exposes exactly the seven-field core preview envelope'
);
$assert(
    !array_key_exists('label', $envelope)
        && !array_key_exists('operations', $envelope)
        && !array_key_exists('blockers', $envelope)
        && !array_key_exists('reason', $envelope),
    'operator copy and package operation details remain outside core input'
);
$assert(
    !array_key_exists('productId', $envelope)
        && !array_key_exists('priceMinor', $envelope)
        && !array_key_exists('currency', $envelope)
        && !array_key_exists('stock', $envelope),
    'product and commercial facts are not exposed'
);

$refused = false;
try {
    RED_CMS_Store_Lite_Destination_Preview_Service::envelope(
        array_merge($preview, ['writesEnabled' => true])
    );
} catch (InvalidArgumentException $exception) {
    $refused = true;
}
$assert($refused, 'write-enabled preview evidence fails closed');

$refused = false;
try {
    RED_CMS_Store_Lite_Destination_Preview_Service::envelope(
        array_merge($preview, ['path' => '/../unsafe'])
    );
} catch (InvalidArgumentException $exception) {
    $refused = true;
}
$assert($refused, 'unsafe preview paths fail closed at the service envelope');

$source = file_get_contents(
    dirname(__DIR__) . '/package/src/DestinationPreviewService.php'
);
$assert(
    is_string($source)
        && str_contains($source, 'MYSQLI_TRANS_START_READ_ONLY')
        && str_contains($source, 'Catalog_Persistence::read')
        && str_contains($source, 'Destination_Status::read')
        && str_contains($source, 'Destination_Provisioning_Preview::build')
        && !preg_match(
            '/\b(?:INSERT|UPDATE|DELETE|REPLACE|COMMIT)\b/i',
            $source
        ),
    'service derives current evidence only inside a read-only snapshot'
);
$assert(
    RED_CMS_Store_Lite_Destination_Preview_Service::SERVICE
        === 'content.destination-preview.store-lite'
        && RED_CMS_Store_Lite_Destination_Preview_Service::OPERATION
            === 'destination.preview',
    'typed service and operation identities are fixed'
);

printf(
    "Store Lite destination preview service passed %d assertions.\n",
    $assertions
);
