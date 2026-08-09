<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_presenter_assert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_presenter_simple(): array
{
    return [
        'id' => 'banana-pack',
        'type' => 'simple',
        'title' => 'Bananas, six-pack',
        'summary' => 'A simple product sold by pack.',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'imageRef' => 'media:banana-pack',
        'sku' => 'BANANA-6',
        'priceMinor' => 399,
        'stock' => 24,
        'options' => [],
        'variants' => [],
    ];
}

function red_store_lite_presenter_variable(): array
{
    return [
        'id' => 'classic-tshirt',
        'type' => 'variable',
        'title' => 'Classic <T-shirt>',
        'summary' => 'Size & color choices.',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'imageRef' => 'media:classic-tshirt',
        'options' => [
            [
                'key' => 'size',
                'label' => 'Size',
                'values' => [
                    ['id' => 's', 'label' => 'Small'],
                    ['id' => 'm', 'label' => 'Medium'],
                ],
            ],
            [
                'key' => 'color',
                'label' => 'Color',
                'values' => [
                    ['id' => 'red', 'label' => 'Red'],
                    ['id' => 'blue', 'label' => 'Blue'],
                ],
            ],
        ],
        'variants' => [
            [
                'id' => 'classic-tshirt-s-red',
                'sku' => 'TSHIRT-S-RED',
                'options' => ['size' => 's', 'color' => 'red'],
                'priceMinor' => 2499,
                'availability' => 'available',
                'stock' => 4,
            ],
            [
                'id' => 'classic-tshirt-m-blue',
                'sku' => 'TSHIRT-M-BLUE',
                'options' => ['size' => 'm', 'color' => 'blue'],
                'priceMinor' => 2599,
                'availability' => 'available',
                'stock' => 6,
            ],
        ],
    ];
}

require_once $packageRoot . '/src/PublicProductPresenter.php';

try {
    $source = file_get_contents(
        $packageRoot . '/src/PublicProductPresenter.php'
    );
    red_store_lite_presenter_assert(
        is_string($source)
            && !preg_match(
                '/\b(?:mysqli|PDO|curl|file_put_contents|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION))\b/',
                $source
            ),
        'public presenter has no database, request, runtime, write, or network dependency'
    );

    $simple = RED_CMS_Store_Lite_Public_Product_Presenter::present(
        red_store_lite_presenter_simple(),
        'USD'
    );
    red_store_lite_presenter_assert(
        $simple === [
            'title' => 'Bananas, six-pack',
            'summary' => 'A simple product sold by pack.',
            'facts' => [
                ['label' => 'Price', 'value' => 'USD 3.99'],
                ['label' => 'Availability', 'value' => 'Available'],
            ],
        ],
        'simple product becomes one exact public fact-card model'
    );

    $variable = RED_CMS_Store_Lite_Public_Product_Presenter::present(
        red_store_lite_presenter_variable(),
        'USD'
    );
    red_store_lite_presenter_assert(
        $variable === [
            'title' => 'Classic <T-shirt>',
            'summary' => 'Size & color choices.',
            'facts' => [
                ['label' => 'Price', 'value' => 'USD 24.99–USD 25.99'],
                ['label' => 'Availability', 'value' => 'Available'],
                ['label' => 'Size', 'value' => 'Small, Medium'],
                ['label' => 'Color', 'value' => 'Red, Blue'],
            ],
        ],
        'variable product exposes only its bounded price range and option labels'
    );

    $zeroStock = red_store_lite_presenter_simple();
    $zeroStock['stock'] = 0;
    red_store_lite_presenter_assert(
        RED_CMS_Store_Lite_Public_Product_Presenter::present(
            $zeroStock,
            'USD'
        )['facts'][1]['value'] === 'Unavailable',
        'zero simple-product stock is unavailable even when the catalog flag is available'
    );

    $noSellableVariant = red_store_lite_presenter_variable();
    foreach ($noSellableVariant['variants'] as &$variant) {
        $variant['stock'] = 0;
    }
    unset($variant);
    red_store_lite_presenter_assert(
        RED_CMS_Store_Lite_Public_Product_Presenter::present(
            $noSellableVariant,
            'USD'
        )['facts'][1]['value'] === 'Unavailable',
        'a variable product requires at least one available in-stock variant'
    );

    $draft = red_store_lite_presenter_simple();
    $draft['state'] = 'draft';
    red_store_lite_presenter_assert(
        RED_CMS_Store_Lite_Public_Product_Presenter::present(
            $draft,
            'USD'
        ) === null,
        'draft products produce no public view model'
    );

    $malformed = red_store_lite_presenter_simple();
    $malformed['priceMinor'] = '399';
    red_store_lite_presenter_assert(
        RED_CMS_Store_Lite_Public_Product_Presenter::present(
            $malformed,
            'USD'
        ) === null,
        'malformed product values fail closed without a partial public model'
    );

    red_store_lite_presenter_assert(
        RED_CMS_Store_Lite_Public_Product_Presenter::present(
            red_store_lite_presenter_simple(),
            'COP'
        ) === null,
        'installation-currency drift produces no public view model'
    );

    printf(
        "Store Lite public product presenter self-test passed: %d assertions.\n",
        $assertions
    );
} catch (Throwable $throwable) {
    fwrite(
        STDERR,
        $throwable->getMessage() . ' (after ' . $assertions . " assertions)\n"
    );
    exit(1);
}
