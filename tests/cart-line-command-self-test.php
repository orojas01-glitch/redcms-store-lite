<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_cart_command_assert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

require_once $packageRoot . '/src/CartLineCommand.php';

try {
    $source = file_get_contents($packageRoot . '/src/CartLineCommand.php');
    red_store_lite_cart_command_assert(
        is_string($source)
            && !preg_match(
                '/\b(?:mysqli|PDO|curl|file_put_contents)\b|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION)/',
                $source
            ),
        'command contract has no database, request, runtime, write, or network path'
    );
    red_store_lite_cart_command_assert(
        RED_CMS_Store_Lite_Cart_Line_Command::bounds() === [
            'handleLength' => 69,
            'minQuantity' => 1,
            'maxQuantity' => 100,
        ],
        'command contract publishes exact handle and quantity bounds'
    );

    $identity = hash('sha256', json_encode([
        'productId' => 'classic-tshirt',
        'variantId' => 'classic-tshirt-s-red',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $handle = 'line-' . $identity;
    red_store_lite_cart_command_assert(
        RED_CMS_Store_Lite_Cart_Line_Command::publicHandle($identity)
            === $handle
            && strlen($handle) === 69,
        'complete lowercase line identity becomes one bounded public handle'
    );
    foreach ([
        '',
        str_repeat('a', 63),
        str_repeat('a', 65),
        str_repeat('A', 64),
        str_repeat('g', 64),
        123,
        null,
    ] as $invalidIdentity) {
        red_store_lite_cart_command_assert(
            RED_CMS_Store_Lite_Cart_Line_Command::publicHandle($invalidIdentity)
                === null,
            'malformed internal line identity cannot become a public handle'
        );
    }

    $set = RED_CMS_Store_Lite_Cart_Line_Command::setQuantity([
        'line' => $handle,
        'quantity' => 4,
    ]);
    red_store_lite_cart_command_assert(
        $set === [
            'valid' => true,
            'reason' => 'valid',
            'command' => [
                'operation' => 'set_quantity',
                'lineIdentitySha256' => $identity,
                'quantity' => 4,
            ],
        ],
        'set-quantity intent decodes only line identity and bounded integer quantity'
    );
    foreach ([1, 100] as $quantity) {
        red_store_lite_cart_command_assert(
            RED_CMS_Store_Lite_Cart_Line_Command::setQuantity([
                'line' => $handle,
                'quantity' => $quantity,
            ])['command']['quantity'] === $quantity,
            'set-quantity accepts each inclusive quantity boundary'
        );
    }

    $remove = RED_CMS_Store_Lite_Cart_Line_Command::removeLine([
        'line' => $handle,
    ]);
    red_store_lite_cart_command_assert(
        $remove === [
            'valid' => true,
            'reason' => 'valid',
            'command' => [
                'operation' => 'remove_line',
                'lineIdentitySha256' => $identity,
            ],
        ],
        'remove-line intent has a distinct closed command with no quantity semantics'
    );

    $invalidSets = [
        [],
        ['line' => $handle],
        ['quantity' => 2, 'line' => $handle],
        ['line' => $handle, 'quantity' => 0],
        ['line' => $handle, 'quantity' => 101],
        ['line' => $handle, 'quantity' => '2'],
        ['line' => $handle, 'quantity' => 2.0],
        ['line' => $handle, 'quantity' => true],
        ['line' => $handle, 'quantity' => 2, 'price' => 1],
        ['line' => $identity, 'quantity' => 2],
        ['line' => 'line-' . str_repeat('A', 64), 'quantity' => 2],
        ['line' => 'line-' . str_repeat('a', 63), 'quantity' => 2],
    ];
    foreach ($invalidSets as $intent) {
        red_store_lite_cart_command_assert(
            RED_CMS_Store_Lite_Cart_Line_Command::setQuantity($intent) === [
                'valid' => false,
                'reason' => 'invalid_intent',
                'command' => null,
            ],
            'set-quantity refuses malformed, reordered, unbounded, or expanded input'
        );
    }

    $invalidRemovals = [
        [],
        ['line' => $handle, 'quantity' => 0],
        ['line' => $identity],
        ['line' => 'line-' . str_repeat('A', 64)],
        ['line' => null],
    ];
    foreach ($invalidRemovals as $intent) {
        red_store_lite_cart_command_assert(
            RED_CMS_Store_Lite_Cart_Line_Command::removeLine($intent) === [
                'valid' => false,
                'reason' => 'invalid_intent',
                'command' => null,
            ],
            'remove-line refuses missing, malformed, or quantity-overloaded input'
        );
    }

    echo 'Store Lite cart-line command passed ' . $assertions
        . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
