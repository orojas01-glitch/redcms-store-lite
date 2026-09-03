<?php

declare(strict_types=1);

/** Pure contract for one opaque, expiring commerce-cart share link. */
final class RED_CMS_Store_Lite_Commerce_Review_Cart_Share
{
    public static function plan(
        array $cart,
        string $token,
        int $issuedAtEpoch
    ): array {
        if (!self::exactKeys(
            $cart,
            ['cartId', 'state', 'expiresAtEpoch', 'snapshotSha256']
        )
            || !self::cartId($cart['cartId'] ?? null)
            || !in_array(
                $cart['state'] ?? null,
                ['draft', 'shared', 'payment_failed'],
                true
            )
            || !self::timestamp($cart['expiresAtEpoch'] ?? null)
            || !self::timestamp($issuedAtEpoch)
            || $issuedAtEpoch >= $cart['expiresAtEpoch']
            || !self::sha256($cart['snapshotSha256'] ?? null)
            || !self::token($token)
        ) {
            return self::invalid('commerce_cart_share_refused');
        }
        $tokenSha256 = self::tokenSha256($token);
        if ($tokenSha256 === null) {
            return self::invalid('commerce_cart_share_token_refused');
        }
        return [
            'valid' => true,
            'targetState' => 'shared',
            'shareRecord' => [
                'cartId' => $cart['cartId'],
                'tokenSha256' => $tokenSha256,
                'cartSnapshotSha256' => $cart['snapshotSha256'],
                'status' => 'active',
                'issuedAtEpoch' => $issuedAtEpoch,
                'expiresAtEpoch' => $cart['expiresAtEpoch'],
            ],
            'transientToken' => $token,
            'rawTokenPersisted' => false,
            'errors' => [],
        ];
    }

    public static function tokenSha256(string $token): ?string
    {
        return self::token($token) ? hash('sha256', $token) : null;
    }

    private static function token(string $value): bool
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $value) !== 1) {
            return false;
        }
        $padded = strtr($value, '-_', '+/') . '=';
        $decoded = base64_decode($padded, true);
        return is_string($decoded) && strlen($decoded) === 32;
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

    private static function invalid(string $reason): array
    {
        return [
            'valid' => false,
            'targetState' => null,
            'shareRecord' => null,
            'transientToken' => null,
            'rawTokenPersisted' => false,
            'errors' => [$reason],
        ];
    }
}
