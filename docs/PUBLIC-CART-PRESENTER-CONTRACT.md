# Public Cart Presenter Contract

Status: Store Lite 0.1.17 pure presentation boundary.

The presenter accepts one already server-derived cart projection and the exact
installation currency. It returns only core-renderable text: title, summary,
cart facts, and at most twenty-four collection rows. Each line may show the
current product title, up to three shopper-facing option labels, quantity,
integer-minor-unit price, and integer-derived line total.

It opens no database, reads no request, cookie, session, or runtime state,
emits no markup, and performs no cart mutation. Unknown fields, malformed
money, mismatched currency or totals, invalid quantities, unsafe strings, and
collection overflow fail closed without a partial view.

Store Lite 0.1.18 supplies the separate database projection, and 0.1.19 binds
both boundaries to a read-only Cart component. Quantity changes, line removal,
checkout, orders, and payment remain separate later gates.
