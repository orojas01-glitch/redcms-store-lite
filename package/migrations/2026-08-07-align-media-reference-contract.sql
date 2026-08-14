ALTER TABLE `RED_Addon_StoreLite_Products`
  MODIFY `ImageReference` varchar(126) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL
  /*!80016 ,
    DROP CHECK `chk_storelite_product_image`,
    ADD CONSTRAINT `chk_storelite_product_image` CHECK (`ImageReference` IS NULL OR `ImageReference` REGEXP '^media:[a-z0-9._-]{1,120}$') */
  /*M!100200 ,
    DROP CONSTRAINT `chk_storelite_product_image`,
    ADD CONSTRAINT `chk_storelite_product_image` CHECK (`ImageReference` IS NULL OR `ImageReference` REGEXP '^media:[a-z0-9._-]{1,120}$') */;

ALTER TABLE `RED_Addon_StoreLite_Product_Variants`
  MODIFY `ImageReference` varchar(126) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL
  /*!80016 ,
    DROP CHECK `chk_storelite_variant_image`,
    ADD CONSTRAINT `chk_storelite_variant_image` CHECK (`ImageReference` IS NULL OR `ImageReference` REGEXP '^media:[a-z0-9._-]{1,120}$') */
  /*M!100200 ,
    DROP CONSTRAINT `chk_storelite_variant_image`,
    ADD CONSTRAINT `chk_storelite_variant_image` CHECK (`ImageReference` IS NULL OR `ImageReference` REGEXP '^media:[a-z0-9._-]{1,120}$') */;
