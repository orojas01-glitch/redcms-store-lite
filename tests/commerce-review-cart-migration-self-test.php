<?php

declare(strict_types=1);

$migration = dirname(__DIR__)
    . '/package/migrations/2026-09-02-create-commerce-review-carts.sql';
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $sql = file_get_contents($migration);
    $assert(is_string($sql) && $sql !== '', 'append-only migration is readable');
    $assert(
        substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 4,
        'migration creates exactly four package-owned tables'
    );
    foreach ([
        'RED_Addon_StoreLite_Commerce_Carts',
        'RED_Addon_StoreLite_Commerce_Cart_Lines',
        'RED_Addon_StoreLite_Commerce_Cart_Shares',
        'RED_Addon_StoreLite_Commerce_Cart_Events',
    ] as $table) {
        $assert(str_contains($sql, '`' . $table . '`'), $table . ' is declared');
    }
    $assert(
        !preg_match('/ALTER\s+TABLE\s+`?RED_Addon_StoreLite_(?:Carts|Cart_Lines|Orders)/i', $sql),
        'existing storefront cart and order tables remain unchanged'
    );
    $assert(
        str_contains($sql, "'draft','shared','checkout_pending','paid','expired','canceled','payment_failed'"),
        'cart state allowlist is complete'
    );
    $assert(
        str_contains($sql, "'not_started','pending','in_progress','complete','canceled'"),
        'onboarding remains separate from payment state'
    );
    $assert(
        str_contains($sql, '`SetupUnitMinor`')
            && str_contains($sql, '`RecurringUnitMinor`')
            && str_contains($sql, '`AmountDueTodayMinor`')
            && str_contains($sql, '`FutureRenewalMinor`'),
        'setup, recurring, due-today, and renewal axes remain separate'
    );
    $assert(
        str_contains($sql, '`TokenSHA256` binary(32) NOT NULL')
            && !preg_match('/`(?:Raw)?Token`\s/i', $sql),
        'share table stores only token hashes'
    );
    $assert(
        str_contains($sql, 'UNIQUE KEY `uq_storelite_commerce_cart_idempotency`')
            && str_contains($sql, 'UNIQUE KEY `uq_storelite_commerce_share_token`')
            && str_contains($sql, 'UNIQUE KEY `uq_storelite_commerce_event_evidence`'),
        'idempotency, share token, and event evidence are replay unique'
    );
    $assert(
        substr_count($sql, 'ENGINE=InnoDB') === 4,
        'every new table is transactional InnoDB'
    );

    echo 'Store Lite commerce review cart migration passed '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
