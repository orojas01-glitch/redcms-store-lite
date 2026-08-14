ALTER TABLE `RED_Addon_StoreLite_Cart_Activity`
  MODIFY `EventName` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
  /*!80016 ,
    DROP CHECK `chk_storelite_cart_activity_event`,
    ADD CONSTRAINT `chk_storelite_cart_activity_event` CHECK (
      `EventName` IN (
        'cart.line.created',
        'cart.line.updated',
        'cart.line.quantity-set',
        'cart.line.removed'
      )
    ) */
  /*M!100200 ,
    DROP CONSTRAINT `chk_storelite_cart_activity_event`,
    ADD CONSTRAINT `chk_storelite_cart_activity_event` CHECK (
      `EventName` IN (
        'cart.line.created',
        'cart.line.updated',
        'cart.line.quantity-set',
        'cart.line.removed'
      )
    ) */;
