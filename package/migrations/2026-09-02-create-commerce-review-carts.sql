CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Commerce_Carts` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `CartID` char(37) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CartSource` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `State` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `OnboardingStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `Version` int unsigned NOT NULL,
  `IdempotencyKeySHA256` binary(32) NOT NULL,
  `SnapshotVersion` tinyint unsigned NOT NULL,
  `SnapshotSHA256` binary(32) NOT NULL,
  `LastEventEvidenceSHA256` binary(32) DEFAULT NULL,
  `CatalogVersion` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `Currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CustomerName` varchar(160) DEFAULT NULL,
  `CustomerCompany` varchar(160) DEFAULT NULL,
  `CustomerEmail` varchar(254) NOT NULL,
  `CustomerPhone` varchar(40) DEFAULT NULL,
  `SetupSubtotalMinor` bigint unsigned NOT NULL,
  `RecurringSubtotalMinor` bigint unsigned NOT NULL,
  `AmountDueTodayMinor` bigint unsigned NOT NULL,
  `FutureRenewalMinor` bigint unsigned NOT NULL,
  `TaxStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `TaxDueTodayMinor` bigint unsigned DEFAULT NULL,
  `TaxFutureRenewalMinor` bigint unsigned DEFAULT NULL,
  `CreatedAtEpoch` int unsigned NOT NULL,
  `ExpiresAtEpoch` int unsigned NOT NULL,
  `PaidAtEpoch` int unsigned DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_commerce_cart_id` (`CartID`),
  UNIQUE KEY `uq_storelite_commerce_cart_idempotency` (`IdempotencyKeySHA256`),
  UNIQUE KEY `uq_storelite_commerce_cart_snapshot` (`SnapshotSHA256`),
  KEY `idx_storelite_commerce_cart_state` (`State`, `ExpiresAtEpoch`, `RecordID`),
  KEY `idx_storelite_commerce_cart_email` (`CustomerEmail`, `RecordID`),
  CONSTRAINT `chk_storelite_commerce_cart_id` CHECK (
    `CartID` REGEXP '^cart_[a-f0-9]{32}$'
  ),
  CONSTRAINT `chk_storelite_commerce_cart_source` CHECK (
    `CartSource` IN ('sales_assisted','configurator')
  ),
  CONSTRAINT `chk_storelite_commerce_cart_state` CHECK (
    `State` IN ('draft','shared','checkout_pending','paid','expired','canceled','payment_failed')
  ),
  CONSTRAINT `chk_storelite_commerce_cart_onboarding` CHECK (
    `OnboardingStatus` IN ('not_started','pending','in_progress','complete','canceled')
  ),
  CONSTRAINT `chk_storelite_commerce_cart_version` CHECK (`Version` >= 1),
  CONSTRAINT `chk_storelite_commerce_cart_snapshot_version` CHECK (`SnapshotVersion` = 1),
  CONSTRAINT `chk_storelite_commerce_cart_catalog_version` CHECK (
    `CatalogVersion` REGEXP '^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$'
  ),
  CONSTRAINT `chk_storelite_commerce_cart_currency` CHECK (
    `Currency` REGEXP '^[A-Z]{3}$'
  ),
  CONSTRAINT `chk_storelite_commerce_cart_customer` CHECK (
    (`CustomerName` IS NOT NULL AND CHAR_LENGTH(`CustomerName`) BETWEEN 1 AND 160)
    OR (`CustomerCompany` IS NOT NULL AND CHAR_LENGTH(`CustomerCompany`) BETWEEN 1 AND 160)
  ),
  CONSTRAINT `chk_storelite_commerce_cart_email` CHECK (
    CHAR_LENGTH(`CustomerEmail`) BETWEEN 3 AND 254
  ),
  CONSTRAINT `chk_storelite_commerce_cart_phone` CHECK (
    `CustomerPhone` IS NULL OR CHAR_LENGTH(`CustomerPhone`) BETWEEN 1 AND 40
  ),
  CONSTRAINT `chk_storelite_commerce_cart_tax` CHECK (
    (`TaxStatus` = 'not_configured'
      AND `TaxDueTodayMinor` IS NULL
      AND `TaxFutureRenewalMinor` IS NULL
      AND `AmountDueTodayMinor` = `SetupSubtotalMinor` + `RecurringSubtotalMinor`
      AND `FutureRenewalMinor` = `RecurringSubtotalMinor`)
    OR
    (`TaxStatus` = 'calculated'
      AND `TaxDueTodayMinor` IS NOT NULL
      AND `TaxFutureRenewalMinor` IS NOT NULL
      AND `AmountDueTodayMinor` = `SetupSubtotalMinor` + `RecurringSubtotalMinor` + `TaxDueTodayMinor`
      AND `FutureRenewalMinor` = `RecurringSubtotalMinor` + `TaxFutureRenewalMinor`)
  ),
  CONSTRAINT `chk_storelite_commerce_cart_time` CHECK (
    `CreatedAtEpoch` BETWEEN 1 AND 4102444800
    AND `ExpiresAtEpoch` > `CreatedAtEpoch`
    AND `ExpiresAtEpoch` <= 4102444800
    AND (`PaidAtEpoch` IS NULL OR `PaidAtEpoch` BETWEEN `CreatedAtEpoch` AND 4102444800)
  ),
  CONSTRAINT `chk_storelite_commerce_cart_paid` CHECK (
    (`State` = 'paid' AND `PaidAtEpoch` IS NOT NULL AND `OnboardingStatus` <> 'not_started')
    OR (`State` <> 'paid' AND `PaidAtEpoch` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Commerce_Cart_Lines` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `CartRecordID` bigint unsigned NOT NULL,
  `Position` tinyint unsigned NOT NULL,
  `ItemID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `Title` varchar(160) NOT NULL,
  `Quantity` tinyint unsigned NOT NULL,
  `SetupUnitMinor` int unsigned NOT NULL,
  `SetupLineTotalMinor` bigint unsigned NOT NULL,
  `RecurringUnitMinor` int unsigned NOT NULL,
  `RecurringLineTotalMinor` bigint unsigned NOT NULL,
  `RecurringInterval` varchar(8) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `ItemStateSHA256` binary(32) NOT NULL,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_commerce_line_position` (`CartRecordID`, `Position`),
  UNIQUE KEY `uq_storelite_commerce_line_item` (`CartRecordID`, `ItemID`),
  CONSTRAINT `fk_storelite_commerce_line_cart` FOREIGN KEY (`CartRecordID`)
    REFERENCES `RED_Addon_StoreLite_Commerce_Carts` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_commerce_line_position` CHECK (`Position` BETWEEN 1 AND 24),
  CONSTRAINT `chk_storelite_commerce_line_item` CHECK (
    `ItemID` REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'
  ),
  CONSTRAINT `chk_storelite_commerce_line_title` CHECK (
    CHAR_LENGTH(`Title`) BETWEEN 1 AND 160
  ),
  CONSTRAINT `chk_storelite_commerce_line_quantity` CHECK (`Quantity` BETWEEN 1 AND 100),
  CONSTRAINT `chk_storelite_commerce_line_setup` CHECK (
    `SetupUnitMinor` <= 999999999
    AND `SetupLineTotalMinor` = `SetupUnitMinor` * `Quantity`
  ),
  CONSTRAINT `chk_storelite_commerce_line_recurring` CHECK (
    `RecurringUnitMinor` <= 999999999
    AND `RecurringLineTotalMinor` = `RecurringUnitMinor` * `Quantity`
    AND ((`RecurringUnitMinor` = 0 AND `RecurringInterval` IS NULL)
      OR (`RecurringUnitMinor` > 0 AND `RecurringInterval` = 'month'))
  ),
  CONSTRAINT `chk_storelite_commerce_line_nonzero` CHECK (
    `SetupUnitMinor` > 0 OR `RecurringUnitMinor` > 0
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Commerce_Cart_Shares` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `CartRecordID` bigint unsigned NOT NULL,
  `TokenSHA256` binary(32) NOT NULL,
  `CartSnapshotSHA256` binary(32) NOT NULL,
  `Status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `IssuedAtEpoch` int unsigned NOT NULL,
  `ExpiresAtEpoch` int unsigned NOT NULL,
  `RevokedAtEpoch` int unsigned DEFAULT NULL,
  `ConsumedAtEpoch` int unsigned DEFAULT NULL,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_commerce_share_token` (`TokenSHA256`),
  KEY `idx_storelite_commerce_share_cart` (`CartRecordID`, `Status`, `RecordID`),
  CONSTRAINT `fk_storelite_commerce_share_cart` FOREIGN KEY (`CartRecordID`)
    REFERENCES `RED_Addon_StoreLite_Commerce_Carts` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_commerce_share_status` CHECK (
    `Status` IN ('active','revoked','consumed','expired')
  ),
  CONSTRAINT `chk_storelite_commerce_share_time` CHECK (
    `IssuedAtEpoch` BETWEEN 1 AND 4102444800
    AND `ExpiresAtEpoch` > `IssuedAtEpoch`
    AND `ExpiresAtEpoch` <= 4102444800
    AND (`RevokedAtEpoch` IS NULL OR `RevokedAtEpoch` >= `IssuedAtEpoch`)
    AND (`ConsumedAtEpoch` IS NULL OR `ConsumedAtEpoch` >= `IssuedAtEpoch`)
  ),
  CONSTRAINT `chk_storelite_commerce_share_terminal` CHECK (
    (`Status` = 'active' AND `RevokedAtEpoch` IS NULL AND `ConsumedAtEpoch` IS NULL)
    OR (`Status` = 'revoked' AND `RevokedAtEpoch` IS NOT NULL AND `ConsumedAtEpoch` IS NULL)
    OR (`Status` = 'consumed' AND `ConsumedAtEpoch` IS NOT NULL)
    OR (`Status` = 'expired' AND `ConsumedAtEpoch` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Commerce_Cart_Events` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `CartRecordID` bigint unsigned NOT NULL,
  `EventName` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `EventEvidenceSHA256` binary(32) NOT NULL,
  `TransitionSHA256` binary(32) NOT NULL,
  `PreviousState` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `NextState` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `OnboardingStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CartVersion` int unsigned NOT NULL,
  `OccurredAtEpoch` int unsigned NOT NULL,
  `RecordedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_commerce_event_evidence` (`EventEvidenceSHA256`),
  UNIQUE KEY `uq_storelite_commerce_event_version` (`CartRecordID`, `CartVersion`),
  KEY `idx_storelite_commerce_event_cart` (`CartRecordID`, `RecordID`),
  CONSTRAINT `fk_storelite_commerce_event_cart` FOREIGN KEY (`CartRecordID`)
    REFERENCES `RED_Addon_StoreLite_Commerce_Carts` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_commerce_event_name` CHECK (
    `EventName` IN ('cart.shared','checkout.started','checkout.expired','payment.failed','payment.paid','cart.expired','cart.canceled')
  ),
  CONSTRAINT `chk_storelite_commerce_event_previous` CHECK (
    `PreviousState` IN ('draft','shared','checkout_pending','paid','expired','canceled','payment_failed')
  ),
  CONSTRAINT `chk_storelite_commerce_event_next` CHECK (
    `NextState` IN ('draft','shared','checkout_pending','paid','expired','canceled','payment_failed')
  ),
  CONSTRAINT `chk_storelite_commerce_event_onboarding` CHECK (
    `OnboardingStatus` IN ('not_started','pending','in_progress','complete','canceled')
  ),
  CONSTRAINT `chk_storelite_commerce_event_version` CHECK (`CartVersion` >= 2),
  CONSTRAINT `chk_storelite_commerce_event_time` CHECK (
    `OccurredAtEpoch` BETWEEN 1 AND 4102444800
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
