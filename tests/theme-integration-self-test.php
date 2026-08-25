<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$cssPath = $root . '/examples/themes/starter-reference/store-lite-product.css';
$previewPath = $root . '/examples/themes/starter-reference/product-detail-preview.html';
$manifestPath = $root . '/package/addon.json';
$css = file_get_contents($cssPath);
$preview = file_get_contents($previewPath);
$manifest = json_decode(
    (string) file_get_contents($manifestPath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$assert(is_string($css) && $css !== '', 'theme recipe is readable');
$assert(is_string($preview) && $preview !== '', 'local visual preview is readable');
$assert(
    ($manifest['assets']['public'] ?? null) === []
        && ($manifest['assets']['admin'] ?? null) === [],
    'Store Lite operational package remains asset-free'
);
$assert(
    str_contains(
        $css,
        '[data-red-addon-component="redcms.store-lite/product"]'
    ) && str_contains(
        $css,
        '[data-red-addon-component="redcms.store-lite/subscription"]'
    ),
    'recipe is scoped to the Product and Subscription components'
);
$assert(
    str_contains($css, '.starter-component--article')
        && str_contains($css, ':has('),
    'destination enhancement requires the paired product component'
);
$assert(
    str_contains($css, 'input[name="quantity"]')
        && str_contains($css, 'select[name="variant"]')
        && str_contains($css, 'button[type="submit"]'),
    'recipe covers the fixed core-owned purchase controls'
);
$assert(
    str_contains($css, ':focus-visible')
        && str_contains($css, 'button:disabled'),
    'keyboard focus and disabled states are explicit'
);
$assert(
    str_contains($css, '@media (max-width: 60rem)')
        && str_contains($css, '@media (max-width: 40rem)'),
    'desktop composition has tablet and mobile fallbacks'
);
$assert(
    str_contains($css, 'container-name: store-product')
        && str_contains($css, '@container store-product (max-width: 32rem)'),
    'narrow cards stack controls independently from viewport width'
);
$assert(
    str_contains($css, '@media (prefers-reduced-motion: reduce)')
        && str_contains($css, 'transition: none'),
    'reduced-motion behavior is explicit'
);
$assert(
    !preg_match('/(^|[}\s,])(body|html|:root)\s*[{,]/m', $css),
    'recipe does not redefine global document selectors'
);
$assert(
    !str_contains($css, 'classic-dog-scarf')
        && !str_contains($css, 'demo.red-sphere.com')
        && !str_contains($css, '/classic-dog-scarf'),
    'recipe contains no client or product identity'
);
$assert(
    str_contains($preview, 'href="store-lite-product.css"')
        && str_contains(
            $preview,
            'data-red-addon-component="redcms.store-lite/product"'
        )
        && str_contains($preview, 'class="red-addon-component__facts"')
        && str_contains($preview, 'class="red-addon-public-mutation-form"')
        && str_contains(
            $preview,
            'data-red-addon-component="redcms.store-lite/subscription"'
        )
        && str_contains($preview, '>Subscribe monthly</button>'),
    'preview mirrors product and subscription semantic hooks'
);
$assert(
    !str_contains($preview, '<script')
        && !str_contains($preview, 'http://')
        && !str_contains($preview, 'https://'),
    'preview is local, passive, and network independent'
);

printf("Store Lite theme-integration self-test passed (%d assertions).\n", $assertions);
