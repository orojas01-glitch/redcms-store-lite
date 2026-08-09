<?php

declare(strict_types=1);

require_once __DIR__ . '/src/ProductFormBridge.php';

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
    $runtime->registerAdminTool(
        RED_CMS_Store_Lite_Product_Form_Bridge::TOOL,
        [RED_CMS_Store_Lite_Product_Form_Bridge::class, 'tool']
    );
    $runtime->registerAdminTool('redcms.store-lite/orders', $notOperational);
    $runtime->registerAdminToolFormTargetLoader(
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        [RED_CMS_Store_Lite_Product_Form_Bridge::class, 'targets']
    );
    $runtime->registerAdminToolFormValueLoader(
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        [RED_CMS_Store_Lite_Product_Form_Bridge::class, 'load']
    );
    $runtime->registerAdminToolFormInitialValueLoader(
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        [RED_CMS_Store_Lite_Product_Form_Bridge::class, 'initial']
    );
    $runtime->registerAdminToolFormCreator(
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        [RED_CMS_Store_Lite_Product_Form_Bridge::class, 'create'],
        RED_CMS_Store_Lite_Product_Form_Bridge::TABLES
    );
    $runtime->registerAdminToolFormWriter(
        RED_CMS_Store_Lite_Product_Form_Bridge::FORM,
        [RED_CMS_Store_Lite_Product_Form_Bridge::class, 'write'],
        RED_CMS_Store_Lite_Product_Form_Bridge::TABLES
    );
};
