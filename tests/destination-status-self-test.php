<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

require_once dirname(__DIR__) . '/package/src/DestinationStatus.php';

$publishedRow = [[
    'ComponentRecordID' => '101',
    'ComponentPublic' => '1',
    'RouteRecordID' => '102',
    'RoutePublic' => '1',
    'Sections' => 'home',
    'Categories' => '',
    'SubCategories' => '',
    'RouteAlias' => 'apple-box',
]];
$published = RED_CMS_Store_Lite_Destination_Status::project(
    $publishedRow,
    1,
    'apple-box',
    'published'
);
$assert(
    $published === [
        'status' => 'published',
        'label' => 'Published',
        'path' => '/apple-box',
        'pathKind' => 'public',
        'reason' => 'One public route and product placement are active.',
    ],
    'one active route and placement project one public destination'
);

$missing = RED_CMS_Store_Lite_Destination_Status::project(
    [],
    0,
    'banana-bunch',
    'published'
);
$assert(
    $missing['status'] === 'missing'
        && $missing['path'] === '/banana-bunch'
        && $missing['pathKind'] === 'proposed',
    'an unclaimed Product-ID path is presented as a missing destination preview'
);

$collision = RED_CMS_Store_Lite_Destination_Status::project(
    [],
    1,
    'classic-shirt',
    'published'
);
$assert(
    $collision['status'] === 'repair_needed'
        && $collision['reason'] ===
            'The proposed alias is already claimed by an Article route.',
    'an existing Article alias is never presented as safe to provision'
);

$draft = RED_CMS_Store_Lite_Destination_Status::project(
    $publishedRow,
    1,
    'apple-box',
    'draft'
);
$assert(
    $draft['status'] === 'repair_needed'
        && $draft['reason'] ===
            'Publish the product before its destination can be public.',
    'an active route cannot make a draft product appear published'
);

$duplicate = RED_CMS_Store_Lite_Destination_Status::project(
    array_merge($publishedRow, [[
        'ComponentRecordID' => '103',
        'ComponentPublic' => '1',
        'RouteRecordID' => '102',
        'RoutePublic' => '1',
        'Sections' => 'home',
        'Categories' => '',
        'SubCategories' => '',
        'RouteAlias' => 'apple-box',
    ]]),
    1,
    'apple-box',
    'published'
);
$assert(
    $duplicate['status'] === 'repair_needed',
    'duplicate Product components require repair instead of choosing one'
);

$nested = $publishedRow;
$nested[0]['Sections'] = 'shop';
$nested[0]['Categories'] = 'apparel';
$nested[0]['SubCategories'] = 'summer';
$nested[0]['RouteAlias'] = 'linen-shirt';
$nestedStatus = RED_CMS_Store_Lite_Destination_Status::project(
    $nested,
    1,
    'linen-shirt',
    'published'
);
$assert(
    $nestedStatus['path'] === '/shop/apparel/summer/linen-shirt',
    'published preview preserves the complete encoded CMS hierarchy'
);

$refused = false;
try {
    RED_CMS_Store_Lite_Destination_Status::project(
        [],
        0,
        '../unsafe',
        'published'
    );
} catch (InvalidArgumentException $exception) {
    $refused = true;
}
$assert($refused, 'unsafe Product IDs fail before a path is projected');

printf(
    "Store Lite destination status passed %d assertions.\n",
    $assertions
);
