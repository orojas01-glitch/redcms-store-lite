<?php

declare(strict_types=1);

/**
 * Pure browser-intent boundary for existing Store Lite cart lines.
 *
 * A line handle is a bounded public reference, not an authorization token.
 * The later caller must resolve the current core-owned subject and may use the
 * decoded identity only inside that subject's cart.
 */
final class RED_CMS_Store_Lite_Cart_Line_Command
{
    private const HANDLE_PREFIX = 'line-';
    private const MIN_QUANTITY = 1;
    private const MAX_QUANTITY = 100;

    public static function bounds(): array
    {
        return [
            'handleLength' => 69,
            'minQuantity' => self::MIN_QUANTITY,
            'maxQuantity' => self::MAX_QUANTITY,
        ];
    }

    public static function publicHandle(mixed $lineIdentitySha256): ?string
    {
        return self::validIdentity($lineIdentitySha256)
            ? self::HANDLE_PREFIX . $lineIdentitySha256
            : null;
    }

    public static function setQuantity(array $intent): array
    {
        if (array_keys($intent) !== ['line', 'quantity']
            || !is_int($intent['quantity'] ?? null)
            || $intent['quantity'] < self::MIN_QUANTITY
            || $intent['quantity'] > self::MAX_QUANTITY
        ) {
            return self::refusal();
        }
        $identity = self::identityFromHandle($intent['line'] ?? null);
        if ($identity === null) {
            return self::refusal();
        }
        return [
            'valid' => true,
            'reason' => 'valid',
            'command' => [
                'operation' => 'set_quantity',
                'lineIdentitySha256' => $identity,
                'quantity' => $intent['quantity'],
            ],
        ];
    }

    public static function removeLine(array $intent): array
    {
        if (array_keys($intent) !== ['line']) {
            return self::refusal();
        }
        $identity = self::identityFromHandle($intent['line'] ?? null);
        if ($identity === null) {
            return self::refusal();
        }
        return [
            'valid' => true,
            'reason' => 'valid',
            'command' => [
                'operation' => 'remove_line',
                'lineIdentitySha256' => $identity,
            ],
        ];
    }

    private static function identityFromHandle(mixed $handle): ?string
    {
        if (!is_string($handle)
            || preg_match('/\Aline-[a-f0-9]{64}\z/D', $handle) !== 1
        ) {
            return null;
        }
        $identity = substr($handle, strlen(self::HANDLE_PREFIX));
        return self::validIdentity($identity) ? $identity : null;
    }

    private static function validIdentity(mixed $identity): bool
    {
        return is_string($identity)
            && preg_match('/\A[a-f0-9]{64}\z/D', $identity) === 1;
    }

    private static function refusal(): array
    {
        return [
            'valid' => false,
            'reason' => 'invalid_intent',
            'command' => null,
        ];
    }
}
