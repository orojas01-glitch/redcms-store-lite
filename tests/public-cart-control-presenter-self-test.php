<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_cart_control_assert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_cart_control_has_key(array $value, string $key): bool
{
    if (array_key_exists($key, $value)) {
        return true;
    }
    foreach ($value as $child) {
        if (is_array($child)
            && red_store_lite_cart_control_has_key($child, $key)
        ) {
            return true;
        }
    }
    return false;
}

require_once $packageRoot . '/src/PublicCartControlPresenter.php';

try {
    $source = file_get_contents(
        $packageRoot . '/src/PublicCartControlPresenter.php'
    );
    red_store_lite_cart_control_assert(
        is_string($source)
            && !preg_match(
                '/\b(?:mysqli|PDO|curl|file_put_contents|echo|print|header)\b|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION)/',
                $source
            ),
        'control presenter has no database, request, output, write, or network path'
    );

    $identity = hash('sha256', json_encode([
        'productId' => 'classic-tshirt',
        'variantId' => 'classic-tshirt-s-red',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $handle = 'line-' . $identity;
    $model = RED_CMS_Store_Lite_Public_Cart_Control_Presenter::present([
        'lineIdentitySha256' => $identity,
        'quantity' => 4,
    ]);
    red_store_lite_cart_control_assert(
        $model === [
            'quantityForm' => [
                'route' => 'redcms.store-lite/cart-line-quantity',
                'mutation' => 'redcms.store-lite/set-cart-line-quantity',
                'submitLabel' => 'Update quantity',
                'fields' => [[
                    'key' => 'line',
                    'control' => 'hidden',
                    'value' => $handle,
                ], [
                    'key' => 'quantity',
                    'control' => 'number',
                    'label' => 'Quantity',
                    'value' => 4,
                ]],
            ],
            'removeForm' => [
                'route' => 'redcms.store-lite/cart-line-remove',
                'mutation' => 'redcms.store-lite/remove-cart-line',
                'submitLabel' => 'Remove item',
                'fields' => [[
                    'key' => 'line',
                    'control' => 'hidden',
                    'value' => $handle,
                ]],
            ],
        ],
        'one current line becomes exact separate quantity and removal form models'
    );
    red_store_lite_cart_control_assert(
        is_array($model)
            && !red_store_lite_cart_control_has_key(
                $model,
                'lineIdentitySha256'
            )
            && !red_store_lite_cart_control_has_key($model, 'recordId')
            && !red_store_lite_cart_control_has_key($model, 'cart')
            && !red_store_lite_cart_control_has_key($model, 'subject'),
        'presentation exposes neither internal identity keys nor database or subject identity'
    );
    red_store_lite_cart_control_assert(
        RED_CMS_Store_Lite_Cart_Line_Command::setQuantity([
            'line' => $model['quantityForm']['fields'][0]['value'],
            'quantity' => $model['quantityForm']['fields'][1]['value'],
        ])['valid'] === true
            && RED_CMS_Store_Lite_Cart_Line_Command::removeLine([
                'line' => $model['removeForm']['fields'][0]['value'],
            ])['valid'] === true,
        'both presented field sets satisfy the existing pure cart-line command boundary'
    );

    $manifest = json_decode(
        (string) file_get_contents($packageRoot . '/addon.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $pairs = [];
    foreach ($manifest['publicMutationContracts'] ?? [] as $contract) {
        $pairs[] = ($contract['route'] ?? '') . "\0"
            . ($contract['mutation'] ?? '');
    }
    red_store_lite_cart_control_assert(
        in_array(
            $model['quantityForm']['route'] . "\0"
                . $model['quantityForm']['mutation'],
            $pairs,
            true
        )
            && in_array(
                $model['removeForm']['route'] . "\0"
                    . $model['removeForm']['mutation'],
                $pairs,
                true
            ),
        'presented route and mutation pairs are exact declared package contracts'
    );

    foreach ([1, 100] as $quantity) {
        $boundary = RED_CMS_Store_Lite_Public_Cart_Control_Presenter::present([
            'lineIdentitySha256' => $identity,
            'quantity' => $quantity,
        ]);
        red_store_lite_cart_control_assert(
            is_array($boundary)
                && $boundary['quantityForm']['fields'][1]['value']
                    === $quantity,
            'control presenter accepts each inclusive quantity boundary'
        );
    }

    $invalid = [
        [],
        ['lineIdentitySha256' => $identity],
        ['quantity' => 4, 'lineIdentitySha256' => $identity],
        ['lineIdentitySha256' => $identity, 'quantity' => 4, 'price' => 100],
        ['lineIdentitySha256' => '', 'quantity' => 4],
        ['lineIdentitySha256' => str_repeat('a', 63), 'quantity' => 4],
        ['lineIdentitySha256' => str_repeat('a', 65), 'quantity' => 4],
        ['lineIdentitySha256' => str_repeat('A', 64), 'quantity' => 4],
        ['lineIdentitySha256' => str_repeat('g', 64), 'quantity' => 4],
        ['lineIdentitySha256' => 123, 'quantity' => 4],
        ['lineIdentitySha256' => $identity, 'quantity' => 0],
        ['lineIdentitySha256' => $identity, 'quantity' => 101],
        ['lineIdentitySha256' => $identity, 'quantity' => '4'],
        ['lineIdentitySha256' => $identity, 'quantity' => 4.0],
        ['lineIdentitySha256' => $identity, 'quantity' => true],
    ];
    foreach ($invalid as $line) {
        red_store_lite_cart_control_assert(
            RED_CMS_Store_Lite_Public_Cart_Control_Presenter::present($line)
                === null,
            'malformed, reordered, expanded, or unbounded line projection fails closed'
        );
    }

    echo 'Store Lite public Cart control presenter passed ' . $assertions
        . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
