<?php

declare(strict_types=1);

return static function (RED_Addon_Runtime_Registry $runtime): void {
    $notOperational = static function (): never {
        throw new RuntimeException(
            'RED-CMS Store Lite is a non-operational package foundation.'
        );
    };

    $runtime->registerComponent('redcms.store-lite/product', $notOperational);
    $runtime->registerService('commerce.catalog', $notOperational);
    $runtime->registerService('commerce.cart', $notOperational);
    $runtime->registerService('commerce.orders', $notOperational);
    $runtime->registerAdminTool('redcms.store-lite/products', $notOperational);
    $runtime->registerAdminTool('redcms.store-lite/orders', $notOperational);
};
