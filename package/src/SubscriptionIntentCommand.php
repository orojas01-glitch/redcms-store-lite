<?php

declare(strict_types=1);

/** Pure browser-intent decoder. Server code must resolve the offer again. */
final class RED_CMS_Store_Lite_Subscription_Intent_Command
{
    public static function decode(array $fields): array
    {
        if (array_keys($fields) !== ['offer']
            || !is_string($fields['offer'] ?? null)
            || strlen($fields['offer']) > 64
            || preg_match(
                '/\A[a-z0-9][a-z0-9._-]*\z/D',
                $fields['offer']
            ) !== 1
        ) {
            return ['valid' => false, 'offerId' => '', 'errors' => [
                'subscription_intent_invalid',
            ]];
        }
        return [
            'valid' => true,
            'offerId' => $fields['offer'],
            'errors' => [],
        ];
    }
}
