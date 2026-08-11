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
    $bananaIdentity = hash('sha256', json_encode([
        'productId' => 'banana-pack',
        'variantId' => null,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $shirtIdentity = hash('sha256', json_encode([
        'productId' => 'classic-shirt',
        'variantId' => 'small-black',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
            'lineIdentitySha256' => $bananaIdentity,
            'quantity' => 2,
            'unitPriceMinor' => 399,
            'currency' => 'USD',
            'lineTotalMinor' => 798,
        ], [
            'title' => 'Classic T-shirt',
            'options' => ['Size: Small', 'Color: Black'],
            'lineIdentitySha256' => $shirtIdentity,
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
            'mutationForms' => [[
                'route' => 'redcms.store-lite/cart-line-quantity',
                'mutation' => 'redcms.store-lite/set-cart-line-quantity',
                'submitLabel' => 'Update quantity',
                'fields' => [[
                    'key' => 'line',
                    'control' => 'hidden',
                    'value' => 'line-' . $shirtIdentity,
                ], [
                    'key' => 'quantity',
                    'control' => 'number',
                    'label' => 'Quantity',
                    'value' => 1,
                ]],
            ], [
                'route' => 'redcms.store-lite/cart-line-remove',
                'mutation' => 'redcms.store-lite/remove-cart-line',
                'submitLabel' => 'Remove item',
                'fields' => [[
                    'key' => 'line',
                    'control' => 'hidden',
                    'value' => 'line-' . $shirtIdentity,
                ]],
            ]],
        ],
        'variable line binds the exact current quantity and remove presentations'
    );
    red_store_lite_cart_presenter_assert(
        $view['collection']['items'][0]['mutationForms'][0]['fields'][0]['value']
            === 'line-' . $bananaIdentity
            && $view['collection']['items'][0]['mutationForms'][0]['fields'][1]['value']
                === 2
            && $view['collection']['items'][0]['mutationForms'][1]['fields'][0]['value']
                === 'line-' . $bananaIdentity
            && !array_key_exists(
                'lineIdentitySha256',
                $view['collection']['items'][0]
            ),
        'simple line exposes its identity only through two complete public handles'
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

    $missingIdentity = $cart;
    unset($missingIdentity['lines'][0]['lineIdentitySha256']);
    $reorderedIdentity = $cart;
    $identity = $reorderedIdentity['lines'][0]['lineIdentitySha256'];
    unset($reorderedIdentity['lines'][0]['lineIdentitySha256']);
    $reorderedIdentity['lines'][0]['lineIdentitySha256'] = $identity;
    $publicHandleIdentity = $cart;
    $publicHandleIdentity['lines'][0]['lineIdentitySha256'] =
        'line-' . $bananaIdentity;
    $uppercaseIdentity = $cart;
    $uppercaseIdentity['lines'][0]['lineIdentitySha256'] =
        strtoupper($bananaIdentity);
    $shortIdentity = $cart;
    $shortIdentity['lines'][0]['lineIdentitySha256'] =
        substr($bananaIdentity, 0, 63);
    red_store_lite_cart_presenter_assert(
        RED_CMS_Store_Lite_Public_Cart_Presenter::present(
            $missingIdentity,
            'USD'
        ) === null
            && RED_CMS_Store_Lite_Public_Cart_Presenter::present(
                $reorderedIdentity,
                'USD'
            ) === null
            && RED_CMS_Store_Lite_Public_Cart_Presenter::present(
                $publicHandleIdentity,
                'USD'
            ) === null
            && RED_CMS_Store_Lite_Public_Cart_Presenter::present(
                $uppercaseIdentity,
                'USD'
            ) === null
            && RED_CMS_Store_Lite_Public_Cart_Presenter::present(
                $shortIdentity,
                'USD'
            ) === null,
        'missing, reordered, decorated, uppercase, or short identities fail closed'
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
