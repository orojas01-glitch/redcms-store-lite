CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Product_Activity` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `EventName` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ProductID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ActorAdminRecordID` int unsigned NOT NULL,
  `PreviousStateSHA256` binary(32) DEFAULT NULL,
  `StateSHA256` binary(32) NOT NULL,
  `OccurredAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  KEY `idx_storelite_product_activity` (`ProductID`, `RecordID`),
  CONSTRAINT `chk_storelite_product_activity_event` CHECK (`EventName` IN ('product.created', 'product.updated')),
  CONSTRAINT `chk_storelite_product_activity_id` CHECK (`ProductID` REGEXP '^[a-z][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_product_activity_actor` CHECK (`ActorAdminRecordID` BETWEEN 1 AND 2147483647),
  CONSTRAINT `chk_storelite_product_activity_state` CHECK (
    (`EventName` = 'product.created' AND `PreviousStateSHA256` IS NULL)
    OR
    (`EventName` = 'product.updated' AND `PreviousStateSHA256` IS NOT NULL AND `PreviousStateSHA256` <> `StateSHA256`)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
