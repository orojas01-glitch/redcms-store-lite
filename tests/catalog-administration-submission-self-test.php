<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_submission_assert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_submission_simple(): array
{
    return [
        'id' => 'banana-pack',
        'type' => 'simple',
        'title' => 'Bananas, six-pack',
        'summary' => '',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'imageRef' => '',
        'sku' => 'BANANA-6',
        'priceMinor' => '399',
        'stock' => '',
        'options' => [],
        'variants' => [],
    ];
}

function red_store_lite_submission_variable(): array
{
    return [
        'id' => 'classic-tshirt',
        'type' => 'variable',
        'title' => 'Classic T-shirt',
        'summary' => 'A shirt with size and color choices.',
        'currency' => 'USD',
        'state' => 'draft',
        'availability' => 'available',
        'imageRef' => 'media:classic-tshirt',
        'sku' => '',
        'priceMinor' => '',
        'stock' => '',
        'options' => [[
            'key' => 'size',
            'label' => 'Size',
            'values' => [
                ['id' => 'small', 'label' => 'Small'],
                ['id' => 'large', 'label' => 'Large'],
            ],
        ], [
            'key' => 'color',
            'label' => 'Color',
            'values' => [
                ['id' => 'black', 'label' => 'Black'],
                ['id' => 'white', 'label' => 'White'],
            ],
        ]],
        'variants' => [[
            'id' => 'small-black',
            'sku' => 'TSHIRT-S-BLACK',
            'options' => ['size' => 'small', 'color' => 'black'],
            'priceMinor' => '2499',
            'availability' => 'available',
            'stock' => '4',
            'imageRef' => '',
        ], [
            'id' => 'large-white',
            'sku' => 'TSHIRT-L-WHITE',
            'options' => ['size' => 'large', 'color' => 'white'],
            'priceMinor' => '2699',
            'availability' => 'unavailable',
            'stock' => '',
            'imageRef' => 'media:tshirt-white',
        ]],
    ];
}

function red_store_lite_submission_fields(
    string $mode,
    array $product,
    string $expectedState = ''
): array {
    return [
        'product' => $product,
        'planSha256' => str_repeat('a', 64),
        'expectedStateSha256' => $expectedState,
        'mode' => $mode,
    ];
}

require_once $packageRoot . '/src/CatalogAdministrationSubmission.php';

try {
    $source = file_get_contents(
        $packageRoot . '/src/CatalogAdministrationSubmission.php'
    );
    red_store_lite_submission_assert(
        is_string($source)
            && !preg_match(
                '/\b(?:mysqli|PDO|curl|file_put_contents|include|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION))\b/',
                $source
            ),
        'submission decoder has no database, request-global, runtime, or network path'
    );
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::bounds() === [
            'maxEncodedBytes' => 262144,
            'maxNodes' => 4096,
            'maxDepth' => 7,
        ],
        'browser submission size, node, and depth bounds are fixed'
    );

    $createFields = red_store_lite_submission_fields(
        'create',
        red_store_lite_submission_simple()
    );
    $create = RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
        $createFields,
        'USD'
    );
    red_store_lite_submission_assert(
        $create['accepted'] === true
            && $create['mode'] === 'create'
            && $create['productId'] === 'banana-pack'
            && $create['product']['priceMinor'] === 399
            && $create['product']['stock'] === null
            && $create['product']['summary'] === null
            && $create['product']['imageRef'] === null
            && $create['expectedStateSha256'] === ''
            && $create['planSha256'] === str_repeat('a', 64)
            && $create['errors'] === []
            && $create['reason'] === 'accepted',
        'create submission converts canonical browser scalars to one normalized product'
    );
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $createFields,
            'USD'
        ) === $create,
        'unchanged create submission decodes deterministically'
    );

    $replaceFields = red_store_lite_submission_fields(
        'replace',
        red_store_lite_submission_variable(),
        str_repeat('b', 64)
    );
    $replace = RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
        $replaceFields,
        'USD'
    );
    red_store_lite_submission_assert(
        $replace['accepted'] === true
            && $replace['mode'] === 'replace'
            && $replace['productId'] === 'classic-tshirt'
            && $replace['product']['sku'] === null
            && $replace['product']['priceMinor'] === null
            && $replace['product']['stock'] === null
            && $replace['product']['variants'][0]['priceMinor'] === 2499
            && $replace['product']['variants'][0]['stock'] === 4
            && $replace['product']['variants'][0]['imageRef'] === null
            && $replace['product']['variants'][1]['stock'] === null
            && $replace['expectedStateSha256'] === str_repeat('b', 64),
        'replace submission normalizes bounded variable product fields and state evidence'
    );

    $withCsrf = $createFields;
    $withCsrf['csrf_token'] = 'must-be-consumed-by-core';
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $withCsrf,
            'USD'
        )['reason'] === 'invalid_submission',
        'package decoder refuses a CSRF field because core must consume it first'
    );
    $extraRoot = $createFields;
    $extraRoot['actorRecordId'] = '1';
    unset($extraRoot['planSha256']);
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $extraRoot,
            'USD'
        )['reason'] === 'invalid_submission',
        'missing and substituted root evidence fails closed'
    );

    $badEvidence = [
        red_store_lite_submission_fields(
            'create',
            red_store_lite_submission_simple(),
            str_repeat('b', 64)
        ),
        red_store_lite_submission_fields(
            'replace',
            red_store_lite_submission_variable()
        ),
        array_merge($createFields, ['mode' => 'delete']),
        array_merge($createFields, ['planSha256' => str_repeat('A', 64)]),
    ];
    red_store_lite_submission_assert(
        array_reduce(
            $badEvidence,
            static fn(bool $valid, array $fields): bool => $valid
                && RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
                    $fields,
                    'USD'
                )['accepted'] === false,
            true
        ),
        'mode, state, and plan evidence must match the exact create or replace shape'
    );

    $badIntegers = ['01', '1.5', '-1', ' 1', 399];
    red_store_lite_submission_assert(
        array_reduce(
            $badIntegers,
            static function (bool $valid, mixed $price) use ($createFields): bool {
                $fields = $createFields;
                $fields['product']['priceMinor'] = $price;
                return $valid
                    && RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
                        $fields,
                        'USD'
                    )['accepted'] === false;
            },
            true
        ),
        'non-canonical browser money values fail before product execution'
    );

    $unknownProduct = $createFields;
    $unknownProduct['product']['clientPrice'] = 'free';
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $unknownProduct,
            'USD'
        )['reason'] === 'invalid_submission',
        'unknown browser product fields fail without partial normalization'
    );
    $nonList = $replaceFields;
    $nonList['product']['options'] = [
        'size' => $nonList['product']['options'][0],
    ];
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $nonList,
            'USD'
        )['reason'] === 'invalid_submission',
        'browser option and variant collections must use canonical list indexes'
    );

    $wrongCurrency = $createFields;
    $wrongCurrency['product']['currency'] = 'COP';
    $currencyFailure =
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $wrongCurrency,
            'USD'
        );
    red_store_lite_submission_assert(
        $currencyFailure['accepted'] === false
            && $currencyFailure['product'] === null
            && $currencyFailure['errors'] === ['currency_invalid']
            && $currencyFailure['reason'] === 'invalid_product',
        'installation currency mismatch returns one bounded value-free error code'
    );

    $invalidTitle = $createFields;
    $invalidTitle['product']['title'] = "private-marker\nvalue";
    $titleFailure =
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $invalidTitle,
            'USD'
        );
    red_store_lite_submission_assert(
        $titleFailure['product'] === null
            && $titleFailure['errors'] === ['title_invalid']
            && !str_contains(
                json_encode($titleFailure, JSON_THROW_ON_ERROR),
                'private-marker'
            ),
        'invalid product response contains codes but no submitted field values'
    );

    $oversized = $createFields;
    $oversized['product']['summary'] = str_repeat('x', 262145);
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $oversized,
            'USD'
        )['reason'] === 'invalid_submission',
        'encoded browser submission bytes are bounded before normalization'
    );

    $deep = $createFields;
    $deep['product']['unknown'] = [[[[[[['too-deep']]]]]]];
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $deep,
            'USD'
        )['reason'] === 'invalid_submission',
        'nested browser submission depth is bounded before field traversal'
    );

    $recursive = $createFields;
    $recursive['product']['cycle'] =& $recursive;
    red_store_lite_submission_assert(
        RED_CMS_Store_Lite_Catalog_Administration_Submission::decode(
            $recursive,
            'USD'
        )['reason'] === 'invalid_submission',
        'recursive caller arrays fail closed without traversal'
    );

    echo 'Store Lite catalog administration submission passed ' .
        $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
