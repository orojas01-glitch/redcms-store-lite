<?php

declare(strict_types=1);

require_once __DIR__ . '/PublicSubscriptionButtonPresenter.php';
require_once __DIR__ . '/SubscriptionIntentCommand.php';
require_once __DIR__ . '/SubscriptionIntentPersistence.php';

/** Subscription-intent binding for the core-owned public mutation runner. */
final class RED_CMS_Store_Lite_Subscription_Intent_Bridge
{
    public const ROUTE =
        RED_CMS_Store_Lite_Public_Subscription_Button_Presenter::ROUTE;
    public const MUTATION =
        RED_CMS_Store_Lite_Public_Subscription_Button_Presenter::MUTATION;
    public const TABLES =
        RED_CMS_Store_Lite_Subscription_Intent_Persistence::TABLES;

    public static function route(): never
    {
        throw new RuntimeException(
            'Store Lite subscription intents require the core-owned dispatcher.'
        );
    }

    public static function load(
        mysqli $connection,
        RED_Addon_Public_Mutation_Command $command
    ): RED_Addon_Public_Mutation_State {
        self::operation($command);
        $intent = RED_CMS_Store_Lite_Subscription_Intent_Command::decode(
            $command->fields()
        );
        if (empty($intent['valid'])) {
            throw new RuntimeException(
                'Store Lite subscription intent input was refused.'
            );
        }
        $state = RED_CMS_Store_Lite_Subscription_Intent_Persistence::read(
            $connection,
            $command->subjectRecordId(),
            $intent['offerId'],
            self::currency($command)
        );
        if (empty($state['loaded'])
            || !in_array(
                $state['status'] ?? '',
                ['absent', 'requested'],
                true
            )
            || !self::sha256($state['offerStateSha256'] ?? null)
            || !self::sha256($state['intentStateSha256'] ?? null)
        ) {
            throw new RuntimeException(
                'Store Lite subscription intent state is unavailable.'
            );
        }
        return new RED_Addon_Public_Mutation_State(
            $command->subjectRecordId(),
            [
                'offerId' => $intent['offerId'],
                'offerStateSha256' => $state['offerStateSha256'],
                'intentRecordId' => $state['intentRecordId'],
                'intentStateSha256' => $state['intentStateSha256'],
                'status' => $state['status'],
            ]
        );
    }

    public static function execute(
        mysqli $connection,
        RED_Addon_Public_Mutation_Execution_Request $request
    ): RED_Addon_Public_Mutation_Execution_Result {
        self::operation($request);
        $intent = RED_CMS_Store_Lite_Subscription_Intent_Command::decode(
            $request->fields()
        );
        if (empty($intent['valid'])) {
            throw new RuntimeException(
                'Store Lite subscription intent input was refused.'
            );
        }
        $recorded = RED_CMS_Store_Lite_Subscription_Intent_Persistence::
            requestWithinTransaction(
                $connection,
                $request->subjectRecordId(),
                $intent['offerId'],
                self::currency($request)
            );
        if (empty($recorded['accepted'])
            || !in_array(
                $recorded['status'] ?? '',
                ['created', 'updated', 'unchanged'],
                true
            )
        ) {
            throw new RuntimeException(
                'Store Lite subscription intent was refused.'
            );
        }
        $command = new RED_Addon_Public_Mutation_Command(
            $request->packageId(),
            $request->routeId(),
            $request->mutationId(),
            $request->subjectRecordId(),
            $request->fields(),
            $request->runtimeSettings()
        );
        $state = self::load($connection, $command);
        return $recorded['status'] === 'unchanged'
            ? RED_Addon_Public_Mutation_Execution_Result::unchanged($state)
            : RED_Addon_Public_Mutation_Execution_Result::accepted($state);
    }

    private static function operation(object $command): void
    {
        if ($command->routeId() !== self::ROUTE
            || $command->mutationId() !== self::MUTATION
        ) {
            throw new RuntimeException(
                'Store Lite subscription intent binding is unavailable.'
            );
        }
    }

    private static function currency(object $command): string
    {
        $settings = $command->runtimeSettings();
        if (!$settings->declared()
            || array_keys($settings->values()) !== ['catalog.currency']
        ) {
            throw new RuntimeException(
                'Store Lite subscription currency is unavailable.'
            );
        }
        $currency = $settings->value('catalog.currency');
        if (!is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
        ) {
            throw new RuntimeException(
                'Store Lite subscription currency is unavailable.'
            );
        }
        return $currency;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
