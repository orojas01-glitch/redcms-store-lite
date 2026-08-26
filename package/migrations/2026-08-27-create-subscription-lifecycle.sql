CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Subscriptions` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `IntentRecordID` bigint unsigned NOT NULL,
  `IntentReference` char(37) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `OfferRecordID` bigint unsigned NOT NULL,
  `SubjectRecordID` int unsigned NOT NULL,
  `OfferStateSHA256` binary(32) NOT NULL,
  `Provider` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CheckoutSessionRefSHA256` binary(32) NOT NULL,
  `ProviderSubscriptionRefSHA256` binary(32) DEFAULT NULL,
  `SubscriptionStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `EntitlementStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CurrentPeriodEndEpoch` int unsigned DEFAULT NULL,
  `LastEventEvidenceSHA256` binary(32) NOT NULL,
  `CreatedAtEpoch` int unsigned NOT NULL,
  `CheckoutExpiresAtEpoch` int unsigned NOT NULL,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_subscription_intent` (`IntentRecordID`),
  UNIQUE KEY `uq_storelite_subscription_reference` (`IntentReference`),
  UNIQUE KEY `uq_storelite_subscription_checkout` (`CheckoutSessionRefSHA256`),
  UNIQUE KEY `uq_storelite_subscription_provider_ref` (`ProviderSubscriptionRefSHA256`),
  UNIQUE KEY `uq_storelite_subscription_last_event` (`LastEventEvidenceSHA256`),
  KEY `idx_storelite_subscription_subject` (`SubjectRecordID`, `RecordID`),
  KEY `idx_storelite_subscription_offer` (`OfferRecordID`, `RecordID`),
  CONSTRAINT `fk_storelite_subscription_intent`
    FOREIGN KEY (`IntentRecordID`)
    REFERENCES `RED_Addon_StoreLite_Subscription_Intents` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_subscription_lifecycle_offer`
    FOREIGN KEY (`OfferRecordID`)
    REFERENCES `RED_Addon_StoreLite_Subscription_Offers` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_subscription_lifecycle_subject`
    FOREIGN KEY (`SubjectRecordID`)
    REFERENCES `RED_Addon_Public_Mutation_Subjects` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_subscription_reference`
    CHECK (`IntentReference` REGEXP '^sint_[a-f0-9]{32}$'),
  CONSTRAINT `chk_storelite_subscription_provider`
    CHECK (`Provider` = 'stripe_checkout'),
  CONSTRAINT `chk_storelite_subscription_status`
    CHECK (`SubscriptionStatus` IN ('pending','active','past_due','canceled','expired')),
  CONSTRAINT `chk_storelite_subscription_entitlement`
    CHECK (`EntitlementStatus` IN ('inactive','active','revoked')),
  CONSTRAINT `chk_storelite_subscription_state_agreement` CHECK (
    (`SubscriptionStatus` = 'pending' AND `EntitlementStatus` = 'inactive'
      AND `ProviderSubscriptionRefSHA256` IS NULL)
    OR (`SubscriptionStatus` = 'active' AND `EntitlementStatus` = 'active'
      AND `ProviderSubscriptionRefSHA256` IS NOT NULL
      AND `CurrentPeriodEndEpoch` IS NOT NULL)
    OR (`SubscriptionStatus` = 'past_due' AND `EntitlementStatus` = 'revoked'
      AND `ProviderSubscriptionRefSHA256` IS NOT NULL)
    OR (`SubscriptionStatus` = 'canceled' AND `EntitlementStatus` = 'revoked'
      AND `ProviderSubscriptionRefSHA256` IS NOT NULL)
    OR (`SubscriptionStatus` = 'expired' AND `EntitlementStatus` = 'inactive')
  ),
  CONSTRAINT `chk_storelite_subscription_times` CHECK (
    `CreatedAtEpoch` BETWEEN 1 AND 4102444800
    AND `CheckoutExpiresAtEpoch` = `CreatedAtEpoch` + 1800
    AND (`CurrentPeriodEndEpoch` IS NULL
      OR `CurrentPeriodEndEpoch` BETWEEN 1 AND 4102444800)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Subscription_Status_History` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `SubscriptionRecordID` bigint unsigned NOT NULL,
  `EventName` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `SubscriptionStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `EntitlementStatus` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ProviderSubscriptionRefSHA256` binary(32) DEFAULT NULL,
  `EventEvidenceSHA256` binary(32) NOT NULL,
  `TransitionSHA256` binary(32) NOT NULL,
  `CurrentPeriodEndEpoch` int unsigned DEFAULT NULL,
  `OccurredAtEpoch` int unsigned NOT NULL,
  `RecordedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_subscription_event_evidence` (`EventEvidenceSHA256`),
  KEY `idx_storelite_subscription_history` (`SubscriptionRecordID`, `RecordID`),
  CONSTRAINT `fk_storelite_subscription_history_parent`
    FOREIGN KEY (`SubscriptionRecordID`)
    REFERENCES `RED_Addon_StoreLite_Subscriptions` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_subscription_history_event`
    CHECK (`EventName` IN (
      'checkout.prepared','subscription.activated','subscription.renewed',
      'subscription.past_due','subscription.canceled','subscription.expired'
    )),
  CONSTRAINT `chk_storelite_subscription_history_time`
    CHECK (`OccurredAtEpoch` BETWEEN 1 AND 4102444800)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
