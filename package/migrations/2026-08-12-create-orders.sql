CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Orders` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `OrderID` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `SourceCartRecordID` bigint unsigned NOT NULL,
  `SubjectRecordID` int unsigned NOT NULL,
  `IdempotencyKeySHA256` binary(32) NOT NULL,
  `SourceCartStateSHA256` binary(32) NOT NULL,
  `SnapshotVersion` tinyint unsigned NOT NULL,
  `SnapshotSHA256` binary(32) NOT NULL,
  `Currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CustomerName` varchar(120) NOT NULL,
  `CustomerEmail` varchar(254) NOT NULL,
  `CustomerPhone` varchar(32) DEFAULT NULL,
  `FulfillmentMethod` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `FulfillmentFeeMinor` int unsigned NOT NULL,
  `DeliveryLine1` varchar(160) DEFAULT NULL,
  `DeliveryLine2` varchar(160) DEFAULT NULL,
  `DeliveryCity` varchar(160) DEFAULT NULL,
  `DeliveryRegion` varchar(160) DEFAULT NULL,
  `DeliveryPostalCode` varchar(32) DEFAULT NULL,
  `DeliveryCountryCode` char(2) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `DeliveryInstructions` varchar(500) DEFAULT NULL,
  `PaymentMethod` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `PaymentKind` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `OrderStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `PaymentStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `FulfillmentStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `QuantityTotal` smallint unsigned NOT NULL,
  `SubtotalMinor` bigint unsigned NOT NULL,
  `TotalMinor` bigint unsigned NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `StatusUpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_order_id` (`OrderID`),
  UNIQUE KEY `uq_storelite_order_idempotency` (`IdempotencyKeySHA256`),
  UNIQUE KEY `uq_storelite_order_source_cart` (`SourceCartRecordID`),
  KEY `idx_storelite_order_subject` (`SubjectRecordID`, `RecordID`),
  KEY `idx_storelite_order_status` (`OrderStatus`, `RecordID`),
  CONSTRAINT `chk_storelite_order_id` CHECK (
    `OrderID` REGEXP '^ord_[a-f0-9]{32}$'
  ),
  CONSTRAINT `chk_storelite_order_source_cart` CHECK (`SourceCartRecordID` > 0),
  CONSTRAINT `chk_storelite_order_subject` CHECK (
    `SubjectRecordID` BETWEEN 1 AND 4294967295
  ),
  CONSTRAINT `chk_storelite_order_snapshot_version` CHECK (`SnapshotVersion` = 1),
  CONSTRAINT `chk_storelite_order_currency` CHECK (`Currency` REGEXP '^[A-Z]{3}$'),
  CONSTRAINT `chk_storelite_order_customer_name` CHECK (
    CHAR_LENGTH(`CustomerName`) BETWEEN 1 AND 120
  ),
  CONSTRAINT `chk_storelite_order_customer_email` CHECK (
    CHAR_LENGTH(`CustomerEmail`) BETWEEN 3 AND 254
  ),
  CONSTRAINT `chk_storelite_order_customer_phone` CHECK (
    `CustomerPhone` IS NULL OR CHAR_LENGTH(`CustomerPhone`) BETWEEN 7 AND 32
  ),
  CONSTRAINT `chk_storelite_order_fulfillment` CHECK (
    (`FulfillmentMethod` = 'pickup'
      AND `FulfillmentFeeMinor` = 0
      AND `DeliveryLine1` IS NULL
      AND `DeliveryLine2` IS NULL
      AND `DeliveryCity` IS NULL
      AND `DeliveryRegion` IS NULL
      AND `DeliveryPostalCode` IS NULL
      AND `DeliveryCountryCode` IS NULL
      AND `DeliveryInstructions` IS NULL)
    OR
    (`FulfillmentMethod` = 'delivery'
      AND `CustomerPhone` IS NOT NULL
      AND `DeliveryLine1` IS NOT NULL
      AND `DeliveryCity` IS NOT NULL
      AND `DeliveryRegion` IS NOT NULL
      AND `DeliveryCountryCode` REGEXP '^[A-Z]{2}$')
  ),
  CONSTRAINT `chk_storelite_order_payment` CHECK (
    (`PaymentMethod` = 'pay_on_receipt'
      AND `PaymentKind` = 'deferred'
      AND `PaymentStatus` = 'due_on_receipt')
    OR
    (`PaymentMethod` IN ('stripe_checkout', 'paypal', 'nequi')
      AND `PaymentKind` = 'hosted'
      AND `PaymentStatus` = 'pending')
    OR
    (`PaymentMethod` = 'zelle_manual'
      AND `PaymentKind` = 'manual_transfer'
      AND `PaymentStatus` = 'pending')
  ),
  CONSTRAINT `chk_storelite_order_initial_status` CHECK (
    `OrderStatus` = 'pending' AND `FulfillmentStatus` = 'unfulfilled'
  ),
  CONSTRAINT `chk_storelite_order_quantity` CHECK (
    `QuantityTotal` BETWEEN 1 AND 2400
  ),
  CONSTRAINT `chk_storelite_order_totals` CHECK (
    `SubtotalMinor` <= 2399999997600
    AND `TotalMinor` = `SubtotalMinor` + `FulfillmentFeeMinor`
    AND `TotalMinor` <= 2400999997599
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Order_Lines` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `OrderRecordID` bigint unsigned NOT NULL,
  `Position` tinyint unsigned NOT NULL,
  `ProductID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `VariantID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `SKU` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `Title` varchar(160) NOT NULL,
  `Quantity` tinyint unsigned NOT NULL,
  `UnitPriceMinor` int unsigned NOT NULL,
  `Currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `LineTotalMinor` bigint unsigned NOT NULL,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_order_line_position` (`OrderRecordID`, `Position`),
  KEY `idx_storelite_order_line_product` (`ProductID`, `VariantID`),
  CONSTRAINT `fk_storelite_order_line_order` FOREIGN KEY (`OrderRecordID`)
    REFERENCES `RED_Addon_StoreLite_Orders` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_order_line_position` CHECK (`Position` BETWEEN 1 AND 24),
  CONSTRAINT `chk_storelite_order_line_product` CHECK (
    `ProductID` REGEXP '^[a-z][a-z0-9._-]{0,63}$'
  ),
  CONSTRAINT `chk_storelite_order_line_variant` CHECK (
    `VariantID` IS NULL OR `VariantID` REGEXP '^[a-z][a-z0-9._-]{0,63}$'
  ),
  CONSTRAINT `chk_storelite_order_line_sku` CHECK (
    `SKU` REGEXP '^[A-Z0-9][A-Z0-9._-]{0,63}$'
  ),
  CONSTRAINT `chk_storelite_order_line_title` CHECK (
    CHAR_LENGTH(`Title`) BETWEEN 1 AND 160
  ),
  CONSTRAINT `chk_storelite_order_line_quantity` CHECK (`Quantity` BETWEEN 1 AND 100),
  CONSTRAINT `chk_storelite_order_line_price` CHECK (`UnitPriceMinor` <= 999999999),
  CONSTRAINT `chk_storelite_order_line_currency` CHECK (`Currency` REGEXP '^[A-Z]{3}$'),
  CONSTRAINT `chk_storelite_order_line_total` CHECK (
    `LineTotalMinor` = `UnitPriceMinor` * `Quantity`
    AND `LineTotalMinor` <= 99999999900
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Order_Line_Options` (
  `OrderLineRecordID` bigint unsigned NOT NULL,
  `Position` tinyint unsigned NOT NULL,
  `Label` varchar(160) NOT NULL,
  PRIMARY KEY (`OrderLineRecordID`, `Position`),
  CONSTRAINT `fk_storelite_order_option_line` FOREIGN KEY (`OrderLineRecordID`)
    REFERENCES `RED_Addon_StoreLite_Order_Lines` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_order_option_position` CHECK (`Position` BETWEEN 1 AND 3),
  CONSTRAINT `chk_storelite_order_option_label` CHECK (
    CHAR_LENGTH(`Label`) BETWEEN 1 AND 160
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Order_Status_History` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `OrderRecordID` bigint unsigned NOT NULL,
  `EventName` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `OrderStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `PaymentStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `FulfillmentStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ActorType` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ActorRecordID` int unsigned NOT NULL,
  `SnapshotSHA256` binary(32) NOT NULL,
  `OccurredAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_order_created_history` (`OrderRecordID`, `EventName`),
  CONSTRAINT `fk_storelite_order_history_order` FOREIGN KEY (`OrderRecordID`)
    REFERENCES `RED_Addon_StoreLite_Orders` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_order_history_event` CHECK (`EventName` = 'order.created'),
  CONSTRAINT `chk_storelite_order_history_status` CHECK (
    `OrderStatus` = 'pending'
    AND `PaymentStatus` IN ('pending', 'due_on_receipt')
    AND `FulfillmentStatus` = 'unfulfilled'
  ),
  CONSTRAINT `chk_storelite_order_history_actor` CHECK (
    `ActorType` = 'anonymous'
    AND `ActorRecordID` BETWEEN 1 AND 4294967295
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
