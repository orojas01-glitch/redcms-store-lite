CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Subscription_Activity` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `EventName` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `OfferID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ActorAdminRecordID` int unsigned NOT NULL,
  `PreviousStateSHA256` binary(32) DEFAULT NULL,
  `StateSHA256` binary(32) NOT NULL,
  `OccurredAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  KEY `idx_storelite_subscription_activity` (`OfferID`, `RecordID`),
  CONSTRAINT `chk_storelite_subscription_activity_event` CHECK (`EventName` IN ('subscription.created', 'subscription.updated')),
  CONSTRAINT `chk_storelite_subscription_activity_id` CHECK (`OfferID` REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_subscription_activity_actor` CHECK (`ActorAdminRecordID` BETWEEN 1 AND 2147483647),
  CONSTRAINT `chk_storelite_subscription_activity_state` CHECK (
    (`EventName` = 'subscription.created' AND `PreviousStateSHA256` IS NULL)
    OR
    (`EventName` = 'subscription.updated' AND `PreviousStateSHA256` IS NOT NULL AND `PreviousStateSHA256` <> `StateSHA256`)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
