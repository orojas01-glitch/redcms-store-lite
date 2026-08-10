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
            'path' => 'src/PublicProductPresenter.php',
            'sha256' => hash_file(
                'sha256',
                $packageRoot . '/src/PublicProductPresenter.php'
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
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataLoaders'] ?? []) === [
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataCreators'] ?? []) === [
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataWriters'] ?? []) === [
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['componentDataDeleters'] ?? []) === [
                'redcms.store-lite/product',
            ]
            && ($registrationSnapshot['services'] ?? []) === [
                'commerce.cart',
                'commerce.catalog',
                'commerce.orders',
            ]
            && ($registrationSnapshot['routes'] ?? []) === [
                'redcms.store-lite/cart-intent',
            ]
            && ($registrationSnapshot['publicMutationHandlers'] ?? []) === [
                'redcms.store-lite/add-to-cart',
            ]
            && ($registrationSnapshot['publicMutationStateLoaders'] ?? []) === [
                'redcms.store-lite/add-to-cart',
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
        'entry point registers every declared provider and the product form bridge silently'
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
            ['redcms.store-lite/product'],
        'foundation declares exactly the Product component'
    );
    red_store_lite_foundation_assert(
        ($validatedManifest['provides']['services'] ?? []) === [
            'commerce.catalog',
            'commerce.cart',
            'commerce.orders',
        ],
        'foundation declares exactly the three commerce services'
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
                'Create or review the current Store Lite product catalog.',
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
        ]]
            && ($validatedManifest['routes'] ?? []) === [[
                'id' => 'redcms.store-lite/cart-intent',
                'scope' => 'public',
                'path' => '/addons/redcms/store-lite/cart-intent',
                'methods' => ['POST'],
                'authentication' => 'public',
                'csrf' => 'required',
            ]]
            && ($validatedManifest['publicMutationContracts'] ?? []) === [[
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
            ]]
            && count($validatedManifest['componentEditors'] ?? []) === 1
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
            && ($validatedManifest['settings'] ?? []) === [[
                'key' => 'catalog.currency',
                'label' => 'Catalog currency',
                'type' => 'text',
                'secret' => false,
                'permission' => 'store.settings.manage',
                'default' => null,
            ]]
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
        'foundation declares one currency-bound product form, one closed cart mutation, and no job or network behavior'
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
