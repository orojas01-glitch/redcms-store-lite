<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/package/src/SearchSourceService.php';

$assertions = 0;
$assert = static function ($condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$document = RED_CMS_Store_Lite_Search_Source_Service::projectDocument([
    'ContentRecordID' => 42,
    'ProductID' => 'linen-scarf',
    'Title' => 'Linen <strong>Scarf</strong>',
    'Summary' => '<p>Lightweight blue scarf.</p>',
    'Language' => 'SP',
    'Sections' => 'shop',
    'Categories' => 'accessories',
    'SubCategories' => '',
    'Alias' => 'linen-scarf',
    'SourceUpdatedAt' => '2026-08-23 12:00:00',
    'PriceMinor' => 12900,
    'Currency' => 'USD',
    'Availability' => 'available',
    'Stock' => 4,
]);
$assert(
    $document === [
        'placementCursor' => 42,
        'sourceType' => 'store-lite-product',
        'sourceRecordId' => 'linen-scarf',
        'language' => 'sp',
        'title' => 'Linen Scarf',
        'summary' => 'Lightweight blue scarf.',
        'keywords' => 'linen-scarf Linen Scarf',
        'publicUrl' => '/shop/accessories/linen-scarf',
        'sourceUpdatedAt' => '2026-08-23 12:00:00',
    ],
    'one public placement becomes one bounded search document'
);
$assert(
    !array_key_exists('PriceMinor', $document)
        && !array_key_exists('Currency', $document)
        && !array_key_exists('Availability', $document)
        && !array_key_exists('Stock', $document),
    'commercial and inventory facts are not exposed'
);
$assert(
    RED_CMS_Store_Lite_Search_Source_Service::projectDocument([
        'ContentRecordID' => 0,
        'ProductID' => 'invalid',
        'Title' => 'Invalid',
        'Language' => 'sp',
        'Alias' => 'invalid',
    ]) === null,
    'invalid placement identity fails closed'
);
$assert(
    RED_CMS_Store_Lite_Search_Source_Service::MAX_BATCH === 8,
    'typed-service batches remain beneath the core result envelope'
);

$source = file_get_contents(
    dirname(__DIR__) . '/package/src/SearchSourceService.php'
);
$assert(
    is_string($source)
        && str_contains($source, "product.State='published'")
        && str_contains($source, "product.Availability='available'")
        && str_contains($source, "article.Active='Y'")
        && str_contains($source, "source_section.Active='Y'")
        && str_contains($source, 'MYSQLI_TRANS_START_READ_ONLY'),
    'storage query requires public product, Article, hierarchy, and read-only state'
);
$assert(
    is_string($source)
        && !str_contains($source, 'RED_Addon_StoreLite_Carts')
        && !str_contains($source, 'RED_Addon_StoreLite_Orders')
        && !str_contains($source, 'PriceMinor')
        && !str_contains($source, 'Stock'),
    'provider source reads no cart, order, price, or stock field'
);

printf("Store Lite search-source self-test passed (%d assertions).\n", $assertions);

