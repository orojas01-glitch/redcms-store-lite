ALTER TABLE `RED_Addon_StoreLite_Orders`
  MODIFY `PaymentStatus` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
  /*!80016 ,
    DROP CHECK `chk_storelite_order_payment`,
    DROP CHECK `chk_storelite_order_initial_status`,
    ADD CONSTRAINT `chk_storelite_order_payment` CHECK (
      (`PaymentMethod` = 'pay_on_receipt'
        AND `PaymentKind` = 'deferred'
        AND `PaymentStatus` = 'due_on_receipt')
      OR
      (`PaymentMethod` IN ('stripe_checkout', 'paypal', 'nequi')
        AND `PaymentKind` = 'hosted'
        AND `PaymentStatus` IN (
          'pending', 'paid', 'refunded', 'reversal_reported'
        ))
      OR
      (`PaymentMethod` = 'zelle_manual'
        AND `PaymentKind` = 'manual_transfer'
        AND `PaymentStatus` = 'pending')
    ),
    ADD CONSTRAINT `chk_storelite_order_initial_status` CHECK (
      (`OrderStatus` = 'pending'
        AND `PaymentStatus` IN ('pending', 'due_on_receipt')
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`OrderStatus` = 'paid'
        AND `PaymentStatus` = 'paid'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`OrderStatus` = 'refunded'
        AND `PaymentStatus` = 'refunded'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`OrderStatus` = 'paid'
        AND `PaymentStatus` = 'reversal_reported'
        AND `FulfillmentStatus` = 'blocked')
    ) */
  /*M!100200 ,
    DROP CONSTRAINT `chk_storelite_order_payment`,
    DROP CONSTRAINT `chk_storelite_order_initial_status`,
    ADD CONSTRAINT `chk_storelite_order_payment` CHECK (
      (`PaymentMethod` = 'pay_on_receipt'
        AND `PaymentKind` = 'deferred'
        AND `PaymentStatus` = 'due_on_receipt')
      OR
      (`PaymentMethod` IN ('stripe_checkout', 'paypal', 'nequi')
        AND `PaymentKind` = 'hosted'
        AND `PaymentStatus` IN (
          'pending', 'paid', 'refunded', 'reversal_reported'
        ))
      OR
      (`PaymentMethod` = 'zelle_manual'
        AND `PaymentKind` = 'manual_transfer'
        AND `PaymentStatus` = 'pending')
    ),
    ADD CONSTRAINT `chk_storelite_order_initial_status` CHECK (
      (`OrderStatus` = 'pending'
        AND `PaymentStatus` IN ('pending', 'due_on_receipt')
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`OrderStatus` = 'paid'
        AND `PaymentStatus` = 'paid'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`OrderStatus` = 'refunded'
        AND `PaymentStatus` = 'refunded'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`OrderStatus` = 'paid'
        AND `PaymentStatus` = 'reversal_reported'
        AND `FulfillmentStatus` = 'blocked')
    ) */;

ALTER TABLE `RED_Addon_StoreLite_Order_Status_History`
  MODIFY `PaymentStatus` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD COLUMN `EventEvidenceSHA256` binary(32) DEFAULT NULL AFTER `SnapshotSHA256`,
  ADD COLUMN `TransitionSHA256` binary(32) DEFAULT NULL AFTER `EventEvidenceSHA256`,
  ADD COLUMN `EventOccurredAt` bigint unsigned DEFAULT NULL AFTER `TransitionSHA256`,
  ADD UNIQUE KEY `uq_storelite_order_history_event_evidence` (`EventEvidenceSHA256`)
  /*!80016 ,
    DROP CHECK `chk_storelite_order_history_event`,
    DROP CHECK `chk_storelite_order_history_status`,
    DROP CHECK `chk_storelite_order_history_actor`,
    ADD CONSTRAINT `chk_storelite_order_history_event` CHECK (
      `EventName` IN (
        'order.created',
        'payment.paid',
        'payment.refund_confirmed',
        'payment.reversal_reported'
      )
    ),
    ADD CONSTRAINT `chk_storelite_order_history_status` CHECK (
      (`EventName` = 'order.created'
        AND `OrderStatus` = 'pending'
        AND `PaymentStatus` IN ('pending', 'due_on_receipt')
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`EventName` = 'payment.paid'
        AND `OrderStatus` = 'paid'
        AND `PaymentStatus` = 'paid'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`EventName` = 'payment.refund_confirmed'
        AND `OrderStatus` = 'refunded'
        AND `PaymentStatus` = 'refunded'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`EventName` = 'payment.reversal_reported'
        AND `OrderStatus` = 'paid'
        AND `PaymentStatus` = 'reversal_reported'
        AND `FulfillmentStatus` = 'blocked')
    ),
    ADD CONSTRAINT `chk_storelite_order_history_actor` CHECK (
      (`EventName` = 'order.created'
        AND `ActorType` = 'anonymous'
        AND `ActorRecordID` BETWEEN 1 AND 4294967295
        AND `EventEvidenceSHA256` IS NULL
        AND `TransitionSHA256` IS NULL
        AND `EventOccurredAt` IS NULL)
      OR
      (`EventName` LIKE 'payment.%'
        AND `ActorType` = 'service'
        AND `ActorRecordID` = 0
        AND `EventEvidenceSHA256` IS NOT NULL
        AND `TransitionSHA256` IS NOT NULL
        AND `EventOccurredAt` BETWEEN 1 AND 4102444800)
    ) */
  /*M!100200 ,
    DROP CONSTRAINT `chk_storelite_order_history_event`,
    DROP CONSTRAINT `chk_storelite_order_history_status`,
    DROP CONSTRAINT `chk_storelite_order_history_actor`,
    ADD CONSTRAINT `chk_storelite_order_history_event` CHECK (
      `EventName` IN (
        'order.created',
        'payment.paid',
        'payment.refund_confirmed',
        'payment.reversal_reported'
      )
    ),
    ADD CONSTRAINT `chk_storelite_order_history_status` CHECK (
      (`EventName` = 'order.created'
        AND `OrderStatus` = 'pending'
        AND `PaymentStatus` IN ('pending', 'due_on_receipt')
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`EventName` = 'payment.paid'
        AND `OrderStatus` = 'paid'
        AND `PaymentStatus` = 'paid'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`EventName` = 'payment.refund_confirmed'
        AND `OrderStatus` = 'refunded'
        AND `PaymentStatus` = 'refunded'
        AND `FulfillmentStatus` = 'unfulfilled')
      OR
      (`EventName` = 'payment.reversal_reported'
        AND `OrderStatus` = 'paid'
        AND `PaymentStatus` = 'reversal_reported'
        AND `FulfillmentStatus` = 'blocked')
    ),
    ADD CONSTRAINT `chk_storelite_order_history_actor` CHECK (
      (`EventName` = 'order.created'
        AND `ActorType` = 'anonymous'
        AND `ActorRecordID` BETWEEN 1 AND 4294967295
        AND `EventEvidenceSHA256` IS NULL
        AND `TransitionSHA256` IS NULL
        AND `EventOccurredAt` IS NULL)
      OR
      (`EventName` LIKE 'payment.%'
        AND `ActorType` = 'service'
        AND `ActorRecordID` = 0
        AND `EventEvidenceSHA256` IS NOT NULL
        AND `TransitionSHA256` IS NOT NULL
        AND `EventOccurredAt` BETWEEN 1 AND 4102444800)
    ) */;
