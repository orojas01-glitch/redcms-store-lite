-- Allow the hosted Wompi Web Checkout payment method on Store Lite orders.
-- Only the payment-method CHECK changes; statuses and history are unchanged.
ALTER TABLE `RED_Addon_StoreLite_Orders`
  MODIFY `PaymentMethod` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
  /*!80016 ,
    DROP CHECK `chk_storelite_order_payment`,
    ADD CONSTRAINT `chk_storelite_order_payment` CHECK (
      (`PaymentMethod` = 'pay_on_receipt'
        AND `PaymentKind` = 'deferred'
        AND `PaymentStatus` = 'due_on_receipt')
      OR
      (`PaymentMethod` IN ('stripe_checkout', 'paypal', 'nequi', 'wompi_checkout')
        AND `PaymentKind` = 'hosted'
        AND `PaymentStatus` IN (
          'pending', 'paid', 'refunded', 'reversal_reported'
        ))
      OR
      (`PaymentMethod` = 'zelle_manual'
        AND `PaymentKind` = 'manual_transfer'
        AND `PaymentStatus` = 'pending')
    ) */
  /*M!100200 ,
    DROP CONSTRAINT `chk_storelite_order_payment`,
    ADD CONSTRAINT `chk_storelite_order_payment` CHECK (
      (`PaymentMethod` = 'pay_on_receipt'
        AND `PaymentKind` = 'deferred'
        AND `PaymentStatus` = 'due_on_receipt')
      OR
      (`PaymentMethod` IN ('stripe_checkout', 'paypal', 'nequi', 'wompi_checkout')
        AND `PaymentKind` = 'hosted'
        AND `PaymentStatus` IN (
          'pending', 'paid', 'refunded', 'reversal_reported'
        ))
      OR
      (`PaymentMethod` = 'zelle_manual'
        AND `PaymentKind` = 'manual_transfer'
        AND `PaymentStatus` = 'pending')
    ) */;
