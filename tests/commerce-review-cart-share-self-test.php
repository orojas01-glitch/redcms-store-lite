<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__) . '/package';
require_once $packageRoot . '/src/CommerceReviewCartShare.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$now = 1788395230;
$token = rtrim(strtr(base64_encode(str_repeat("\x2a", 32)), '+/', '-_'), '=');
$cart = [
    'cartId' => 'cart_' . str_repeat('a', 32),
    'state' => 'draft',
    'expiresAtEpoch' => $now + 86400,
    'snapshotSha256' => hash('sha256', 'cart-snapshot'),
];

try {
    $source = (string) file_get_contents(
        $packageRoot . '/src/CommerceReviewCartShare.php'
    );
    $assert(
        !preg_match('/\b(?:mysqli|PDO|curl|fopen|file_put_contents|getenv)\b|\$_(?:GET|POST|SERVER|SESSION)/', $source),
        'share contract has no runtime side effect path'
    );
    $assert(strlen($token) === 43, '32 bytes encode to a 43-character token');

    $planned = RED_CMS_Store_Lite_Commerce_Review_Cart_Share::plan(
        $cart,
        $token,
        $now
    );
    $assert(($planned['valid'] ?? false) === true, 'draft cart can be shared');
    $assert($planned['targetState'] === 'shared', 'share moves cart to shared');
    $assert($planned['transientToken'] === $token, 'token is returned only for transient URL construction');
    $assert(
        !array_key_exists('token', $planned['shareRecord'])
            && !in_array($token, $planned['shareRecord'], true),
        'persistable share record excludes the raw token'
    );
    $assert(
        $planned['shareRecord']['tokenSha256'] === hash('sha256', $token),
        'only token hash is persistable'
    );
    $assert(
        $planned['shareRecord']['expiresAtEpoch'] === $cart['expiresAtEpoch'],
        'share cannot outlive the cart'
    );
    $assert(
        RED_CMS_Store_Lite_Commerce_Review_Cart_Share::tokenSha256($token)
            === $planned['shareRecord']['tokenSha256'],
        'token resolution is deterministic'
    );

    $invalid = [];
    $invalid[] = [$cart, 'guessable-token', $now];
    $expired = $cart;
    $expired['expiresAtEpoch'] = $now;
    $invalid[] = [$expired, $token, $now];
    foreach (['paid', 'expired', 'canceled'] as $state) {
        $terminal = $cart;
        $terminal['state'] = $state;
        $invalid[] = [$terminal, $token, $now];
    }
    foreach ($invalid as [$candidate, $candidateToken, $candidateNow]) {
        $assert(
            RED_CMS_Store_Lite_Commerce_Review_Cart_Share::plan(
                $candidate,
                $candidateToken,
                $candidateNow
            )['valid'] === false,
            'weak, expired, paid, expired-state, and canceled links fail closed'
        );
    }

    echo 'Store Lite commerce review cart share contract passed '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
