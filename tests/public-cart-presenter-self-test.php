<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
require_once dirname(__DIR__) . '/package/src/PublicCartPresenter.php';

function red_store_lite_cart_presenter_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

try {
    $empty = RED_CMS_Store_Lite_Public_Cart_Presenter::present([
        'currency' => 'USD', 'lines' => [],
    ], 'USD');
    red_store_lite_cart_presenter_assert(
        $empty === [
            'title' => 'Your cart',
            'summary' => 'Your cart is empty.',
            'facts' => [
                ['label' => 'Items', 'value' => '0'],
                ['label' => 'Total', 'value' => 'USD 0.00'],
            ],
        ],
        'empty cart returns a closed display-only view'
    );

    $cart = [
        'currency' => 'USD',
        'lines' => [[
            'title' => 'Banana pack',
            'options' => [],
            'quantity' => 2,
            'unitPriceMinor' => 399,
            'currency' => 'USD',
            'lineTotalMinor' => 798,
        ], [
            'title' => 'Classic T-shirt',
            'options' => ['Size: Small', 'Color: Black'],
            'quantity' => 1,
            'unitPriceMinor' => 2499,
            'currency' => 'USD',
            'lineTotalMinor' => 2499,
        ]],
    ];
    $view = RED_CMS_Store_Lite_Public_Cart_Presenter::present($cart, 'USD');
    red_store_lite_cart_presenter_assert(
        is_array($view)
            && $view['summary'] === '3 items · USD 32.97'
            && $view['facts'][1]['value'] === 'USD 32.97'
            && count($view['collection']['items']) === 2,
        'simple and variable lines derive exact quantity and total facts'
    );
    red_store_lite_cart_presenter_assert(
        $view['collection']['items'][1] === [
            'title' => 'Classic T-shirt',
            'facts' => [
                ['label' => 'Options', 'value' => 'Size: Small · Color: Black'],
                ['label' => 'Quantity', 'value' => '1'],
                ['label' => 'Unit price', 'value' => 'USD 24.99'],
                ['label' => 'Line total', 'value' => 'USD 24.99'],
            ],
        ],
        'variable line retains bounded shopper-facing option labels'
    );

    $invalidTotal = $cart;
    $invalidTotal['lines'][0]['lineTotalMinor'] = 799;
    $wrongCurrency = $cart;
    $wrongCurrency['currency'] = 'COP';
    $unknown = $cart;
    $unknown['lines'][0]['sku'] = 'BANANA-6';
    $unsafe = $cart;
    $unsafe['lines'][0]['title'] = "Banana\npack";
    red_store_lite_cart_presenter_assert(
        RED_CMS_Store_Lite_Public_Cart_Presenter::present($invalidTotal, 'USD') === null
            && RED_CMS_Store_Lite_Public_Cart_Presenter::present($wrongCurrency, 'USD') === null
            && RED_CMS_Store_Lite_Public_Cart_Presenter::present($unknown, 'USD') === null
            && RED_CMS_Store_Lite_Public_Cart_Presenter::present($unsafe, 'USD') === null,
        'mismatched totals, currency drift, unknown fields, and control characters fail closed'
    );

    $overflow = $cart;
    $overflow['lines'] = array_fill(0, 25, $cart['lines'][0]);
    red_store_lite_cart_presenter_assert(
        RED_CMS_Store_Lite_Public_Cart_Presenter::present($overflow, 'USD') === null,
        'more than twenty-four visible lines fail closed'
    );

    printf("Store Lite public cart presenter self-test passed: %d assertions.\n", $assertions);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
