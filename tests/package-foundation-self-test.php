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
$assertions = 0;
$temporaryRoot = sys_get_temp_dir() . '/redcms-store-lite-foundation-' .
    bin2hex(random_bytes(8));

function red_store_lite_foundation_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_foundation_copy(string $source, string $target): void
{
    if (!mkdir($target, 0700, true) && !is_dir($target)) {
        throw new RuntimeException('Could not create disposable package path.');
    }
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $sourcePath = $source . '/' . $entry;
        $targetPath = $target . '/' . $entry;
        if (is_dir($sourcePath)) {
            red_store_lite_foundation_copy($sourcePath, $targetPath);
            continue;
        }
        if (!is_file($sourcePath) || !copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Could not stage package file: ' . $entry);
        }
    }
}

function red_store_lite_foundation_remove(string $path, string $root): void
{
    $root = rtrim($root, '/');
    if (!str_starts_with($path, $root . '/') && $path !== $root) {
        throw new RuntimeException('Refusing cleanup outside disposable root.');
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            red_store_lite_foundation_remove($path . '/' . $entry, $root);
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Could not remove disposable directory.');
        }
        return;
    }
    if (file_exists($path) && !unlink($path)) {
        throw new RuntimeException('Could not remove disposable file.');
    }
}

try {
    red_store_lite_foundation_assert(
        is_string($coreRoot) && is_dir($coreRoot),
        'RED-CMS core root resolves'
    );
    red_store_lite_foundation_assert(
        is_string($packageRoot) && is_dir($packageRoot),
        'Store Lite package root resolves'
    );
    red_store_lite_foundation_assert(
        !str_starts_with($packageRoot, rtrim($coreRoot, '/') . '/'),
        'Store Lite source remains outside the clean core checkout'
    );
    red_store_lite_foundation_assert(
        !file_exists($coreRoot . '/addons'),
        'clean RED-CMS checkout has no deployed add-on directory'
    );

    $sourceManifest = json_decode(
        (string) file_get_contents($packageRoot . '/addon.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    red_store_lite_foundation_assert(
        ($sourceManifest['version'] ?? '') === '0.1.44',
        'source manifest declares the resumable preview-service release'
    );
    $mediaMigrationSql = file_get_contents(
        $packageRoot . '/migrations/2026-08-07-align-media-reference-contract.sql'
    );
    red_store_lite_foundation_assert(
        is_string($mediaMigrationSql),
        'media-reference compatibility migration is readable'
    );
    $mysql57Projection = preg_replace(
        [
            '/\/\*!80016[\s\S]*?\*\//',
            '/\/\*M!100200[\s\S]*?\*\//',
        ],
        '',
        (string) $mediaMigrationSql
    );
    red_store_lite_foundation_assert(
        is_string($mysql57Projection)
            && substr_count(
                $mysql57Projection,
                'MODIFY `ImageReference` varchar(126)'
            ) === 2
            && !str_contains($mysql57Projection, 'DROP CHECK')
            && !str_contains($mysql57Projection, 'DROP CONSTRAINT'),
        'MySQL 5.7 projection expands both media columns without unsupported check DDL'
    );
    red_store_lite_foundation_assert(
        str_contains((string) $mediaMigrationSql, '/*!80016 ,')
            && str_contains((string) $mediaMigrationSql, 'DROP CHECK')
            && str_contains((string) $mediaMigrationSql, '/*M!100200 ,')
            && str_contains((string) $mediaMigrationSql, 'DROP CONSTRAINT'),
        'enforcing database families retain explicit version-gated check replacement'
    );
    $cartActivityMigrationSql = file_get_contents(
        $packageRoot . '/migrations/2026-08-10-expand-cart-activity-events.sql'
    );
    red_store_lite_foundation_assert(
        is_string($cartActivityMigrationSql),
        'cart-activity compatibility migration is readable'
    );
    $cartActivityMysql57Projection = preg_replace(
        [
            '/\/\*!80016[\s\S]*?\*\//',
            '/\/\*M!100200[\s\S]*?\*\//',
        ],
        '',
        (string) $cartActivityMigrationSql
    );
    red_store_lite_foundation_assert(
        is_string($cartActivityMysql57Projection)
            && str_contains(
                $cartActivityMysql57Projection,
                'MODIFY `EventName` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL'
            )
            && !str_contains($cartActivityMysql57Projection, 'DROP CHECK')
            && !str_contains($cartActivityMysql57Projection, 'DROP CONSTRAINT'),
        'MySQL 5.7 cart-activity projection remains executable without check DDL'
    );
    red_store_lite_foundation_assert(
        str_contains((string) $cartActivityMigrationSql, '/*!80016 ,')
            && str_contains((string) $cartActivityMigrationSql, 'DROP CHECK')
            && str_contains((string) $cartActivityMigrationSql, '/*M!100200 ,')
            && str_contains((string) $cartActivityMigrationSql, 'DROP CONSTRAINT'),
        'cart-activity check replacement remains version-gated for enforcing databases'
    );
    $paymentHistoryMigrationSql = file_get_contents(
        $packageRoot . '/migrations/2026-08-16-expand-payment-event-history.sql'
    );
    red_store_lite_foundation_assert(
        is_string($paymentHistoryMigrationSql),
        'payment-event history compatibility migration is readable'
    );
    $paymentHistoryMysql57Projection = preg_replace(
        [
            '/\/\*!80016[\s\S]*?\*\//',
            '/\/\*M!100200[\s\S]*?\*\//',
        ],
        '',
        (string) $paymentHistoryMigrationSql
    );
    red_store_lite_foundation_assert(
        is_string($paymentHistoryMysql57Projection)
            && substr_count(
                $paymentHistoryMysql57Projection,
                'MODIFY `PaymentStatus` varchar(20)'
            ) === 2
            && str_contains(
                $paymentHistoryMysql57Projection,
                'ADD COLUMN `EventEvidenceSHA256` binary(32) DEFAULT NULL'
            )
            && str_contains(
                $paymentHistoryMysql57Projection,
                'ADD UNIQUE KEY `uq_storelite_order_history_event_evidence`'
            )
            && !str_contains($paymentHistoryMysql57Projection, 'DROP CHECK')
            && !str_contains($paymentHistoryMysql57Projection, 'DROP CONSTRAINT'),
        'MySQL 5.7 projection adds payment evidence without unsupported check DDL'
    );
    red_store_lite_foundation_assert(
        substr_count((string) $paymentHistoryMigrationSql, '/*!80016 ,') === 2
            && substr_count((string) $paymentHistoryMigrationSql, '/*M!100200 ,') === 2
            && str_contains(
                (string) $paymentHistoryMigrationSql,
                "'payment.reversal_reported'"
            )
            && str_contains(
                (string) $paymentHistoryMigrationSql,
                '`EventOccurredAt` BETWEEN 1 AND 4102444800'
            ),
        'enforcing databases receive the closed P3B payment-state and history checks'
    );
    $mysql57MigrationSetSafe = true;
    foreach (glob($packageRoot . '/migrations/*.sql') ?: [] as $migrationPath) {
        $migrationSql = file_get_contents($migrationPath);
        $projection = is_string($migrationSql)
            ? preg_replace(
                [
                    '/\/\*!80016[\s\S]*?\*\//',
                    '/\/\*M!100200[\s\S]*?\*\//',
                ],
                '',
                $migrationSql
            )
            : null;
        if (!is_string($projection)
            || str_contains($projection, 'DROP CHECK')
            || str_contains($projection, 'DROP CONSTRAINT')
        ) {
            $mysql57MigrationSetSafe = false;
            break;
        }
    }
    red_store_lite_foundation_assert(
        $mysql57MigrationSetSafe,
        'all MySQL 5.7 migration projections exclude unsupported check DDL'
    );
    red_store_lite_foundation_assert(
        ($sourceManifest['integrity']['files'] ?? []) === [[
            'path' => 'addon.php',
            'sha256' => hash_file('sha256', $packageRoot . '/addon.php'),
        ], [
            'path' => 'migrations/2026-08-07-align-media-reference-contract.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-07-align-media-reference-contract.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-07-create-catalog.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-07-create-catalog.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-08-create-product-activity.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-08-create-product-activity.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-08-create-product-placements.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-08-create-product-placements.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-08-z-create-carts.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-08-z-create-carts.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-10-create-cart-placements.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-10-create-cart-placements.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-10-expand-cart-activity-events.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-10-expand-cart-activity-events.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-12-create-orders.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-12-create-orders.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-13-add-order-fulfillment-status-index.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-13-add-order-fulfillment-status-index.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-13-add-order-payment-status-index.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-13-add-order-payment-status-index.sql'
            ),
        ], [
            'path' => 'migrations/2026-08-16-expand-payment-event-history.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-16-expand-payment-event-history.sql'
            ),
        ], [
            'path' => 'src/CatalogAdministration.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CatalogAdministration.php'
            ),
        ], [
            'path' => 'src/CatalogAdministrationAction.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CatalogAdministrationAction.php'
            ),
        ], [
            'path' => 'src/CatalogAdministrationSubmission.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CatalogAdministrationSubmission.php'
            ),
        ], [
            'path' => 'src/CatalogPersistence.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CatalogPersistence.php'
            ),
        ], [
            'path' => 'src/DestinationProvisioningPreview.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/DestinationProvisioningPreview.php'
            ),
        ], [
            'path' => 'src/DestinationPreviewService.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/DestinationPreviewService.php'
            ),
        ], [
            'path' => 'src/DestinationStatus.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/DestinationStatus.php'
            ),
        ], [
            'path' => 'src/CartComponentBridge.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CartComponentBridge.php'
            ),
        ], [
            'path' => 'src/CartLineCommand.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CartLineCommand.php'
            ),
        ], [
            'path' => 'src/CartLineResolver.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CartLineResolver.php'
            ),
        ], [
            'path' => 'src/CartMutationBridge.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CartMutationBridge.php'
            ),
        ], [
            'path' => 'src/CartPersistence.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CartPersistence.php'
            ),
        ], [
            'path' => 'src/CartReadModel.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CartReadModel.php'
            ),
        ], [
            'path' => 'src/CheckoutMutationBridge.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/CheckoutMutationBridge.php'
            ),
        ], [
            'path' => 'src/GuestCheckoutCommand.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/GuestCheckoutCommand.php'
            ),
        ], [
            'path' => 'src/GuestOrderSnapshot.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/GuestOrderSnapshot.php'
            ),
        ], [
            'path' => 'src/OrderPersistence.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/OrderPersistence.php'
            ),
        ], [
            'path' => 'src/PaymentEventPersistence.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PaymentEventPersistence.php'
            ),
        ], [
            'path' => 'src/PaymentEventService.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PaymentEventService.php'
            ),
        ], [
            'path' => 'src/PaymentEventTransition.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PaymentEventTransition.php'
            ),
        ], [
            'path' => 'src/ProductComponentBridge.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/ProductComponentBridge.php'
            ),
        ], [
            'path' => 'src/ProductFormValues.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/ProductFormValues.php'
            ),
        ], [
            'path' => 'src/ProductFormBridge.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/ProductFormBridge.php'
            ),
        ], [
            'path' => 'src/ProductNormalizer.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/ProductNormalizer.php'
            ),
        ], [
            'path' => 'src/SearchSourceService.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/SearchSourceService.php'
            ),
        ], [
            'path' => 'src/PublicCartControlPresenter.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PublicCartControlPresenter.php'
            ),
        ], [
            'path' => 'src/PublicCartFormPresenter.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PublicCartFormPresenter.php'
            ),
        ], [
            'path' => 'src/PublicCartPresenter.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PublicCartPresenter.php'
            ),
        ], [
            'path' => 'src/PublicGuestCheckoutPresenter.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PublicGuestCheckoutPresenter.php'
            ),
        ], [
            'path' => 'src/PublicProductPresenter.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PublicProductPresenter.php'
            ),
        ], [
            'path' => 'src/SubscriptionOffer.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/SubscriptionOffer.php'
            ),
        ]],
        'source manifest pins the exact package inventory checksums'
    );

    $stagedPackage = $temporaryRoot . '/addons/redcms/store-lite';
    red_store_lite_foundation_copy($packageRoot, $stagedPackage);

    require_once $coreRoot . '/includes/addon_manifest_helpers.php';
    require_once $coreRoot . '/includes/addon_enable_preflight_helpers.php';

    $exactValidation = red_addon_validate_manifest(
        'redcms.store-lite',
        $temporaryRoot,
        ['cmsVersion' => '5.1.0', 'phpVersion' => PHP_VERSION]
    );
    red_store_lite_foundation_assert(
        !empty($exactValidation['valid']),
        'exact source payload passes the RED-CMS manifest and integrity contract'
    );

    require_once $coreRoot . '/includes/addon_runtime_helpers.php';
    $registrar = require $packageRoot . '/addon.php';
    $registry = new RED_Addon_Runtime_Registry(
        'redcms.store-lite',
        $sourceManifest
    );
    ob_start();
    $registrar($registry);
    $registrationOutput = (string) ob_get_clean();
    $registry->assertComplete();
    $registrationSnapshot = $registry->snapshot()['registrations'] ?? [];
    red_store_lite_foundation_assert(
        $registrationOutput === ''
            && ($registrationSnapshot['components'] ?? []) === [
                'redcms.store-lite/cart',
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataLoaders'] ?? []) === [
                'redcms.store-lite/cart',
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataCreators'] ?? []) === [
                'redcms.store-lite/cart',
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataWriters'] ?? []) === [
                'redcms.store-lite/cart',
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataDeleters'] ?? []) === [
                'redcms.store-lite/cart',
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['services'] ?? []) === [
                'commerce.cart',
                'commerce.catalog',
                'commerce.orders',
                'content.destination-preview.store-lite',
                'content.search-source.store-lite',
            ]
            && ($registrationSnapshot['routes'] ?? []) === [
                'redcms.store-lite/cart-intent',
                'redcms.store-lite/cart-line-quantity',
                'redcms.store-lite/cart-line-remove',
                'redcms.store-lite/guest-checkout',
            ]
            && ($registrationSnapshot['publicMutationHandlers'] ?? []) === [
                'redcms.store-lite/add-to-cart',
                'redcms.store-lite/create-guest-order',
                'redcms.store-lite/remove-cart-line',
                'redcms.store-lite/set-cart-line-quantity',
            ]
            && ($registrationSnapshot['publicMutationStateLoaders'] ?? []) === [
                'redcms.store-lite/add-to-cart',
                'redcms.store-lite/create-guest-order',
                'redcms.store-lite/remove-cart-line',
                'redcms.store-lite/set-cart-line-quantity',
            ]
            && ($registrationSnapshot['adminTools'] ?? []) === [
                'redcms.store-lite/orders',
                'redcms.store-lite/products',
            ]
            && ($registrationSnapshot['adminToolFormValueLoaders'] ?? []) === [
                'redcms.store-lite/product-editor',
            ]
            && ($registrationSnapshot['adminToolFormTargetLoaders'] ?? []) === [
                'redcms.store-lite/product-editor',
            ]
            && ($registrationSnapshot['adminToolFormWriters'] ?? []) === [
                'redcms.store-lite/product-editor',
            ],
        'entry point registers every declared provider and editor bridge silently'
    );
    red_store_lite_foundation_assert(
        $registry->handler('services', 'commerce.orders') === [
            RED_CMS_Store_Lite_Payment_Event_Service::class,
            'handle',
        ],
        'commerce.orders retains the operational P3B payment-event service'
    );
    red_store_lite_foundation_assert(
        $registry->handler(
            'services',
            'content.destination-preview.store-lite'
        ) === [
            RED_CMS_Store_Lite_Destination_Preview_Service::class,
            'handle',
        ],
        'Store Lite registers the bounded destination-preview service'
    );
    red_store_lite_foundation_assert(
        $registry->handler(
            'services',
            'content.search-source.store-lite'
        ) === [
            RED_CMS_Store_Lite_Search_Source_Service::class,
            'handle',
        ],
        'Store Lite registers the bounded public search-source service'
    );

    $marker = $temporaryRoot . '/entrypoint-executed';
    $entrypoint = (string) file_get_contents($stagedPackage . '/addon.php');
    $instrumented = "<?php\nfile_put_contents(" . var_export($marker, true) .
        ", 'executed');\n" . preg_replace('/\\A<\\?php\\s*/', '', $entrypoint);
    if (file_put_contents($stagedPackage . '/addon.php', $instrumented) === false) {
        throw new RuntimeException('Could not instrument disposable entry point.');
    }

    $manifestPath = $stagedPackage . '/addon.json';
    $manifest = json_decode(
        (string) file_get_contents($manifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $manifest['integrity']['files'][0]['sha256'] = hash_file(
        'sha256',
        $stagedPackage . '/addon.php'
    );
    if (file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    ) === false) {
        throw new RuntimeException('Could not update disposable manifest.');
    }

    $validation = red_addon_validate_manifest(
        'redcms.store-lite',
        $temporaryRoot,
        ['cmsVersion' => '5.1.0', 'phpVersion' => PHP_VERSION]
    );
    red_store_lite_foundation_assert(
        !empty($validation['valid']),
        'Store Lite payload passes the RED-CMS manifest and integrity contract'
    );
    red_store_lite_foundation_assert(
        !file_exists($marker),
        'manifest validation does not execute addon.php'
    );

    $validatedManifest = $validation['manifest'] ?? [];
    red_store_lite_foundation_assert(
        ($validatedManifest['provides']['components'] ?? []) ===
            ['redcms.store-lite/product', 'redcms.store-lite/cart'],
        'foundation declares exactly the Product and Cart components'
    );
    red_store_lite_foundation_assert(
        ($validatedManifest['provides']['services'] ?? []) === [
            'commerce.catalog',
            'commerce.cart',
            'commerce.orders',
            'content.destination-preview.store-lite',
            'content.search-source.store-lite',
        ],
        'foundation declares commerce plus bounded destination and search services'
    );
    red_store_lite_foundation_assert(
        ($validatedManifest['provides']['adminTools'] ?? []) === [
            'redcms.store-lite/products',
            'redcms.store-lite/orders',
        ],
        'foundation declares exactly the Products and Orders administrator tools'
    );
    red_store_lite_foundation_assert(
        ($validatedManifest['adminToolContracts'] ?? []) === [[
            'tool' => 'redcms.store-lite/products',
            'label' => 'Products',
            'description' =>
                'Create or review products, destination status, and read-only provisioning readiness.',
            'icon' => 'products',
            'permission' => 'store.products.manage',
            'mode' => 'read-only',
        ], [
            'tool' => 'redcms.store-lite/orders',
            'label' => 'Orders',
            'description' => 'Review current Store Lite order status.',
            'icon' => 'orders',
            'permission' => 'store.orders.view',
            'mode' => 'read-only',
        ]],
        'Products and Orders tools remain read-only and separately permissioned'
    );
    red_store_lite_foundation_assert(
        ($validatedManifest['migrations'] ?? []) === [[
            'id' => '2026-08-07-create-catalog',
            'path' => 'migrations/2026-08-07-create-catalog.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-07-create-catalog.sql'
            ),
        ], [
            'id' => '2026-08-07-update-media-reference-contract',
            'path' => 'migrations/2026-08-07-align-media-reference-contract.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-07-align-media-reference-contract.sql'
            ),
        ], [
            'id' => '2026-08-08-create-product-activity',
            'path' => 'migrations/2026-08-08-create-product-activity.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-08-create-product-activity.sql'
            ),
        ], [
            'id' => '2026-08-08-create-product-placements',
            'path' => 'migrations/2026-08-08-create-product-placements.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-08-create-product-placements.sql'
            ),
        ], [
            'id' => '2026-08-08-create-carts',
            'path' => 'migrations/2026-08-08-z-create-carts.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-08-z-create-carts.sql'
            ),
        ], [
            'id' => '2026-08-10-create-cart-placements',
            'path' => 'migrations/2026-08-10-create-cart-placements.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-10-create-cart-placements.sql'
            ),
        ], [
            'id' => '2026-08-10-expand-cart-activity-events',
            'path' => 'migrations/2026-08-10-expand-cart-activity-events.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-10-expand-cart-activity-events.sql'
            ),
        ], [
            'id' => '2026-08-12-create-orders',
            'path' => 'migrations/2026-08-12-create-orders.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-12-create-orders.sql'
            ),
        ], [
            'id' => '2026-08-13-add-order-fulfillment-status-index',
            'path' => 'migrations/2026-08-13-add-order-fulfillment-status-index.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-13-add-order-fulfillment-status-index.sql'
            ),
        ], [
            'id' => '2026-08-13-add-order-payment-status-index',
            'path' => 'migrations/2026-08-13-add-order-payment-status-index.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-13-add-order-payment-status-index.sql'
            ),
        ], [
            'id' => '2026-08-16-expand-payment-event-history',
            'path' => 'migrations/2026-08-16-expand-payment-event-history.sql',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/migrations/2026-08-16-expand-payment-event-history.sql'
            ),
        ]]
            && ($validatedManifest['routes'] ?? []) === [[
                'id' => 'redcms.store-lite/cart-intent',
                'scope' => 'public',
                'path' => '/addons/redcms/store-lite/cart-intent',
                'methods' => ['POST'],
                'authentication' => 'public',
                'csrf' => 'required',
            ], [
                'id' => 'redcms.store-lite/cart-line-quantity',
                'scope' => 'public',
                'path' => '/addons/redcms/store-lite/cart-line-quantity',
                'methods' => ['POST'],
                'authentication' => 'public',
                'csrf' => 'required',
            ], [
                'id' => 'redcms.store-lite/cart-line-remove',
                'scope' => 'public',
                'path' => '/addons/redcms/store-lite/cart-line-remove',
                'methods' => ['POST'],
                'authentication' => 'public',
                'csrf' => 'required',
            ], [
                'id' => 'redcms.store-lite/guest-checkout',
                'scope' => 'public',
                'path' => '/addons/redcms/store-lite/guest-checkout',
                'methods' => ['POST'],
                'authentication' => 'public',
                'csrf' => 'required',
            ]]
            && array_slice(
                $validatedManifest['publicMutationContracts'] ?? [],
                0,
                3
            ) === [[
                'route' => 'redcms.store-lite/cart-intent',
                'mutation' => 'redcms.store-lite/add-to-cart',
                'scope' => 'public',
                'authentication' => 'public',
                'method' => 'POST',
                'csrf' => 'required',
                'encoding' => 'application/x-www-form-urlencoded',
                'maxBodyBytes' => 512,
                'requestFields' => [[
                    'key' => 'product',
                    'type' => 'identifier',
                    'required' => true,
                    'minLength' => 1,
                    'maxLength' => 64,
                ], [
                    'key' => 'quantity',
                    'type' => 'positive-integer',
                    'required' => true,
                    'minimum' => 1,
                    'maximum' => 100,
                ], [
                    'key' => 'variant',
                    'type' => 'identifier',
                    'required' => false,
                    'minLength' => 1,
                    'maxLength' => 64,
                ]],
                'subject' => 'anonymous',
                'idempotency' => 'core-issued-key',
                'privacy' => 'no-store',
                'rateLimit' => 'required',
                'tables' => [
                    'RED_Addon_StoreLite_Products',
                    'RED_Addon_StoreLite_Product_Options',
                    'RED_Addon_StoreLite_Product_Option_Values',
                    'RED_Addon_StoreLite_Product_Variants',
                    'RED_Addon_StoreLite_Product_Variant_Selections',
                    'RED_Addon_StoreLite_Carts',
                    'RED_Addon_StoreLite_Cart_Lines',
                    'RED_Addon_StoreLite_Cart_Activity',
                ],
                'postcondition' => 'server-derived-state',
                'audit' => 'commerce.cart.item-added',
                'outcomes' => ['accepted', 'unchanged'],
            ], [
                'route' => 'redcms.store-lite/cart-line-quantity',
                'mutation' => 'redcms.store-lite/set-cart-line-quantity',
                'scope' => 'public',
                'authentication' => 'public',
                'method' => 'POST',
                'csrf' => 'required',
                'encoding' => 'application/x-www-form-urlencoded',
                'maxBodyBytes' => 256,
                'requestFields' => [[
                    'key' => 'line',
                    'type' => 'identifier',
                    'required' => true,
                    'minLength' => 69,
                    'maxLength' => 69,
                ], [
                    'key' => 'quantity',
                    'type' => 'positive-integer',
                    'required' => true,
                    'minimum' => 1,
                    'maximum' => 100,
                ]],
                'subject' => 'anonymous',
                'idempotency' => 'core-issued-key',
                'privacy' => 'no-store',
                'rateLimit' => 'required',
                'tables' => [
                    'RED_Addon_StoreLite_Products',
                    'RED_Addon_StoreLite_Product_Options',
                    'RED_Addon_StoreLite_Product_Option_Values',
                    'RED_Addon_StoreLite_Product_Variants',
                    'RED_Addon_StoreLite_Product_Variant_Selections',
                    'RED_Addon_StoreLite_Carts',
                    'RED_Addon_StoreLite_Cart_Lines',
                    'RED_Addon_StoreLite_Cart_Activity',
                ],
                'postcondition' => 'server-derived-state',
                'audit' => 'commerce.cart.quantity-set',
                'outcomes' => ['accepted', 'unchanged'],
            ], [
                'route' => 'redcms.store-lite/cart-line-remove',
                'mutation' => 'redcms.store-lite/remove-cart-line',
                'scope' => 'public',
                'authentication' => 'public',
                'method' => 'POST',
                'csrf' => 'required',
                'encoding' => 'application/x-www-form-urlencoded',
                'maxBodyBytes' => 256,
                'requestFields' => [[
                    'key' => 'line',
                    'type' => 'identifier',
                    'required' => true,
                    'minLength' => 69,
                    'maxLength' => 69,
                ]],
                'subject' => 'anonymous',
                'idempotency' => 'core-issued-key',
                'privacy' => 'no-store',
                'rateLimit' => 'required',
                'tables' => [
                    'RED_Addon_StoreLite_Products',
                    'RED_Addon_StoreLite_Product_Options',
                    'RED_Addon_StoreLite_Product_Option_Values',
                    'RED_Addon_StoreLite_Product_Variants',
                    'RED_Addon_StoreLite_Product_Variant_Selections',
                    'RED_Addon_StoreLite_Carts',
                    'RED_Addon_StoreLite_Cart_Lines',
                    'RED_Addon_StoreLite_Cart_Activity',
                ],
                'postcondition' => 'server-derived-state',
                'audit' => 'commerce.cart.item-removed',
                'outcomes' => ['accepted', 'unchanged'],
            ]]
            && count($validatedManifest['publicMutationContracts'] ?? []) === 4
            && ($validatedManifest['publicMutationContracts'][3] ?? null)
                === ($sourceManifest['publicMutationContracts'][3] ?? null)
            && count($validatedManifest['componentEditors'] ?? []) === 2
            && (($validatedManifest['componentEditors'][0]['component'] ?? '')
                === 'redcms.store-lite/product')
            && (($validatedManifest['componentEditors'][0]['permissions'] ?? [])
                === array_fill_keys(
                    ['create', 'view', 'edit', 'delete', 'publish', 'restore'],
                    'store.products.manage'
                ))
            && (($validatedManifest['componentEditors'][0]['fields'] ?? []) === [[
                'key' => 'product-id',
                'label' => 'Product ID',
                'type' => 'text',
                'required' => true,
                'help' =>
                    'Enter the exact public Product ID from the Store Lite catalog.',
                'minLength' => 1,
                'maxLength' => 64,
            ]])
            && (($validatedManifest['componentEditors'][1]['component'] ?? '')
                === 'redcms.store-lite/cart')
            && (($validatedManifest['componentEditors'][1]['permissions'] ?? [])
                === array_fill_keys(
                    ['create', 'view', 'edit', 'delete', 'publish', 'restore'],
                    'store.products.manage'
                ))
            && (($validatedManifest['componentEditors'][1]['fields'] ?? []) === [[
                'key' => 'cart-title',
                'label' => 'Cart title',
                'type' => 'text',
                'required' => true,
                'help' => 'Heading shown above the current visitor\'s cart.',
                'minLength' => 1,
                'maxLength' => 160,
            ]])
            && ($validatedManifest['settings'] ?? [])
                === ($sourceManifest['settings'] ?? null)
            && count($validatedManifest['adminToolFormContracts'] ?? []) === 1
            && (($validatedManifest['adminToolFormContracts'][0]['form'] ?? '')
                === 'redcms.store-lite/product-editor')
            && (($validatedManifest['adminToolFormContracts'][0]['runtimeSettings'] ?? [])
                === ['catalog.currency'])
            && (($validatedManifest['adminToolFormContracts'][0]['create'] ?? [])
                === [
                    'label' => 'Add product',
                    'description' =>
                        'Create one draft Store Lite product and its variants.',
                ])
            && count($validatedManifest['adminToolFormContracts'][0]['fields'] ?? [])
                === 13
            && ($validatedManifest['jobs'] ?? []) === []
            && ($validatedManifest['outboundHosts'] ?? []) === [],
        'foundation declares two bounded component editors, one currency-bound product form, four closed commerce mutations, and no job or network behavior'
    );

    $profile = red_addon_enable_preflight_activation_profile($validatedManifest);
    $blockerCodes = array_map(
        static fn(array $blocker): string => (string) ($blocker['code'] ?? ''),
        $profile['blockers'] ?? []
    );
    red_store_lite_foundation_assert(
        empty($profile['eligible'])
            && ($profile['gates']['liveData'] ?? '') === 'blocked'
            && in_array('live_data_contract_required', $blockerCodes, true)
            && in_array('supported_activation_profile_required', $blockerCodes, true),
        'richer Store Lite package remains fail-closed and not enableable'
    );

    echo 'Store Lite package foundation passed ' . $assertions .
        " assertions.\n";
} finally {
    if (is_dir($temporaryRoot)) {
        red_store_lite_foundation_remove($temporaryRoot, $temporaryRoot);
    }
}
