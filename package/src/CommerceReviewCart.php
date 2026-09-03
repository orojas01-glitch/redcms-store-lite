<?php

declare(strict_types=1);

/**
 * Pure provider-neutral contract for a payable service review cart.
 *
 * The browser supplies no prices, totals, tax, provider identifiers, or state.
 * A trusted caller supplies already-authoritative line terms and this contract
 * derives the immutable dual-axis commercial snapshot.
 */
final class RED_CMS_Store_Lite_Commerce_Review_Cart
{
    private const MAX_MINOR = 999999999;
    private const MAX_TOTAL_MINOR = 2399999997600;

    public static function normalize(array $draft, array $policy): array
    {
        if (!self::exactKeys($policy, [
            'salesAssistedTtlSeconds',
            'configuratorTtlSeconds',
            'maximumLines',
            'maximumQuantity',
        ])
            || !is_int($policy['salesAssistedTtlSeconds'] ?? null)
            || !is_int($policy['configuratorTtlSeconds'] ?? null)
            || !is_int($policy['maximumLines'] ?? null)
            || !is_int($policy['maximumQuantity'] ?? null)
            || $policy['salesAssistedTtlSeconds'] < 1800
            || $policy['salesAssistedTtlSeconds'] > 2592000
            || $policy['configuratorTtlSeconds'] < 1800
            || $policy['configuratorTtlSeconds'] > 604800
            || $policy['maximumLines'] < 1
            || $policy['maximumLines'] > 24
            || $policy['maximumQuantity'] < 1
            || $policy['maximumQuantity'] > 100
        ) {
            return self::invalid('commerce_cart_policy_refused');
        }

        if (!self::exactKeys($draft, [
            'cartId',
            'source',
            'idempotencyKeySha256',
            'createdAtEpoch',
            'expiresAtEpoch',
            'currency',
            'catalogVersion',
            'customer',
            'lines',
        ])
            || !self::cartId($draft['cartId'] ?? null)
            || !in_array(
                $draft['source'] ?? null,
                ['sales_assisted', 'configurator'],
                true
            )
            || !self::sha256($draft['idempotencyKeySha256'] ?? null)
            || !self::timestamp($draft['createdAtEpoch'] ?? null)
            || !self::timestamp($draft['expiresAtEpoch'] ?? null)
            || !is_string($draft['currency'] ?? null)
            || preg_match('/\A[A-Z]{3}\z/D', $draft['currency']) !== 1
            || !is_string($draft['catalogVersion'] ?? null)
            || preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/D',
                $draft['catalogVersion']
            ) !== 1
            || !is_array($draft['customer'] ?? null)
            || !is_array($draft['lines'] ?? null)
            || !array_is_list($draft['lines'])
        ) {
            return self::invalid('commerce_cart_draft_refused');
        }

        $ttl = $draft['source'] === 'sales_assisted'
            ? $policy['salesAssistedTtlSeconds']
            : $policy['configuratorTtlSeconds'];
        if ($draft['expiresAtEpoch'] !== $draft['createdAtEpoch'] + $ttl) {
            return self::invalid('commerce_cart_expiry_refused');
        }

        $customer = self::customer($draft['customer']);
        if ($customer === null) {
            return self::invalid('commerce_cart_customer_refused');
        }
        if (count($draft['lines']) < 1
            || count($draft['lines']) > $policy['maximumLines']
        ) {
            return self::invalid('commerce_cart_lines_refused');
        }

        $lines = [];
        $seen = [];
        $setupSubtotal = 0;
        $recurringSubtotal = 0;
        foreach ($draft['lines'] as $index => $line) {
            $normalized = self::line(
                $line,
                $index + 1,
                $policy['maximumQuantity']
            );
            if ($normalized === null || isset($seen[$normalized['itemId']])) {
                return self::invalid('commerce_cart_line_refused');
            }
            $seen[$normalized['itemId']] = true;
            $setupSubtotal += $normalized['setupLineTotalMinor'];
            $recurringSubtotal += $normalized['recurringLineTotalMinor'];
            if ($setupSubtotal > self::MAX_TOTAL_MINOR
                || $recurringSubtotal > self::MAX_TOTAL_MINOR
            ) {
                return self::invalid('commerce_cart_total_refused');
            }
            $lines[] = $normalized;
        }

        $amountDueToday = $setupSubtotal + $recurringSubtotal;
        if ($amountDueToday > self::MAX_TOTAL_MINOR) {
            return self::invalid('commerce_cart_due_today_refused');
        }

        $cart = [
            'schema' => 1,
            'cartId' => $draft['cartId'],
            'source' => $draft['source'],
            'state' => 'draft',
            'onboardingStatus' => 'not_started',
            'version' => 1,
            'idempotencyKeySha256' => $draft['idempotencyKeySha256'],
            'createdAtEpoch' => $draft['createdAtEpoch'],
            'expiresAtEpoch' => $draft['expiresAtEpoch'],
            'currency' => $draft['currency'],
            'catalogVersion' => $draft['catalogVersion'],
            'customer' => $customer,
            'lines' => $lines,
            'setupSubtotalMinor' => $setupSubtotal,
            'recurringSubtotalMinor' => $recurringSubtotal,
            'amountDueTodayMinor' => $amountDueToday,
            'futureRenewalMinor' => $recurringSubtotal,
            'taxStatus' => 'not_configured',
            'taxDueTodayMinor' => null,
            'taxFutureRenewalMinor' => null,
        ];
        $cart['snapshotSha256'] = self::hash($cart);
        if (!self::sha256($cart['snapshotSha256'])) {
            return self::invalid('commerce_cart_snapshot_refused');
        }

        return [
            'valid' => true,
            'cart' => $cart,
            'errors' => [],
        ];
    }

    public static function accepted(array $cart): bool
    {
        if (!self::exactKeys($cart, [
            'schema',
            'cartId',
            'source',
            'state',
            'onboardingStatus',
            'version',
            'idempotencyKeySha256',
            'createdAtEpoch',
            'expiresAtEpoch',
            'currency',
            'catalogVersion',
            'customer',
            'lines',
            'setupSubtotalMinor',
            'recurringSubtotalMinor',
            'amountDueTodayMinor',
            'futureRenewalMinor',
            'taxStatus',
            'taxDueTodayMinor',
            'taxFutureRenewalMinor',
            'snapshotSha256',
        ])
            || ($cart['schema'] ?? null) !== 1
            || !self::cartId($cart['cartId'] ?? null)
            || !in_array($cart['source'] ?? null, ['sales_assisted', 'configurator'], true)
            || ($cart['state'] ?? null) !== 'draft'
            || ($cart['onboardingStatus'] ?? null) !== 'not_started'
            || ($cart['version'] ?? null) !== 1
            || !self::sha256($cart['idempotencyKeySha256'] ?? null)
            || !self::sha256($cart['snapshotSha256'] ?? null)
            || !is_array($cart['lines'] ?? null)
            || !array_is_list($cart['lines'])
            || !is_array($cart['customer'] ?? null)
        ) {
            return false;
        }
        $snapshot = $cart['snapshotSha256'];
        unset($cart['snapshotSha256']);
        return hash_equals($snapshot, self::hash($cart));
    }

    private static function customer(array $value): ?array
    {
        if (!self::exactKeys($value, ['name', 'company', 'email', 'phone'])) {
            return null;
        }
        $name = self::text($value['name'] ?? null, 160);
        $company = self::text($value['company'] ?? null, 160);
        $email = self::text($value['email'] ?? null, 254);
        $phone = self::nullableText($value['phone'] ?? null, 40);
        if (($name === '' && $company === '')
            || $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $phone === false
        ) {
            return null;
        }
        return [
            'name' => $name,
            'company' => $company,
            'email' => strtolower($email),
            'phone' => $phone,
        ];
    }

    private static function line(
        mixed $value,
        int $position,
        int $maximumQuantity
    ): ?array {
        if (!is_array($value)
            || !self::exactKeys($value, [
                'itemId',
                'title',
                'quantity',
                'setupUnitMinor',
                'recurringUnitMinor',
                'recurringInterval',
                'itemStateSha256',
            ])
            || !is_string($value['itemId'] ?? null)
            || preg_match(
                '/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',
                $value['itemId']
            ) !== 1
            || ($title = self::text($value['title'] ?? null, 160)) === ''
            || !is_int($value['quantity'] ?? null)
            || $value['quantity'] < 1
            || $value['quantity'] > $maximumQuantity
            || !is_int($value['setupUnitMinor'] ?? null)
            || !is_int($value['recurringUnitMinor'] ?? null)
            || $value['setupUnitMinor'] < 0
            || $value['setupUnitMinor'] > self::MAX_MINOR
            || $value['recurringUnitMinor'] < 0
            || $value['recurringUnitMinor'] > self::MAX_MINOR
            || !self::sha256($value['itemStateSha256'] ?? null)
        ) {
            return null;
        }
        if ($value['setupUnitMinor'] === 0
            && $value['recurringUnitMinor'] === 0
        ) {
            return null;
        }
        if (($value['recurringUnitMinor'] > 0
                && ($value['recurringInterval'] ?? null) !== 'month')
            || ($value['recurringUnitMinor'] === 0
                && ($value['recurringInterval'] ?? null) !== null)
        ) {
            return null;
        }
        $setupTotal = $value['setupUnitMinor'] * $value['quantity'];
        $recurringTotal = $value['recurringUnitMinor'] * $value['quantity'];
        if ($setupTotal > self::MAX_TOTAL_MINOR
            || $recurringTotal > self::MAX_TOTAL_MINOR
        ) {
            return null;
        }
        return [
            'position' => $position,
            'itemId' => $value['itemId'],
            'title' => $title,
            'quantity' => $value['quantity'],
            'setupUnitMinor' => $value['setupUnitMinor'],
            'setupLineTotalMinor' => $setupTotal,
            'recurringUnitMinor' => $value['recurringUnitMinor'],
            'recurringLineTotalMinor' => $recurringTotal,
            'recurringInterval' => $value['recurringInterval'],
            'itemStateSha256' => $value['itemStateSha256'],
        ];
    }

    private static function text(mixed $value, int $maximumLength): string
    {
        if (!is_string($value) || str_contains($value, "\0")) {
            return '';
        }
        $value = trim($value);
        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
        return $length <= $maximumLength ? $value : '';
    }

    private static function nullableText(
        mixed $value,
        int $maximumLength
    ): string|null|false {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = self::text($value, $maximumLength);
        return $normalized === '' ? false : $normalized;
    }

    private static function cartId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\Acart_[a-f0-9]{32}\z/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    private static function timestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 4102444800;
    }

    private static function exactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected;
    }

    private static function hash(array $value): string
    {
        $normalize = static function (mixed $candidate) use (&$normalize): mixed {
            if (!is_array($candidate)) {
                return $candidate;
            }
            if (array_is_list($candidate)) {
                return array_map($normalize, $candidate);
            }
            ksort($candidate, SORT_STRING);
            foreach ($candidate as $key => $item) {
                $candidate[$key] = $normalize($item);
            }
            return $candidate;
        };
        return hash(
            'sha256',
            json_encode(
                $normalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    private static function invalid(string $reason): array
    {
        return [
            'valid' => false,
            'cart' => null,
            'errors' => [$reason],
        ];
    }
}
