# Public Cart Presenter Contract

Status: Store Lite 0.1.24 pure presentation boundary.

The presenter accepts one already server-derived cart projection and the exact
installation currency. It returns only core-renderable text: title, summary,
cart facts, and at most twenty-four collection rows. Each line may show the
current product title, up to three shopper-facing option labels, quantity,
integer-minor-unit price, integer-derived line total, and exactly two data-only
mutation presentations: set quantity first and remove line second.

It opens no database, reads no request, cookie, session, or runtime state,
emits no markup, and performs no cart mutation. It passes only the exact
server-derived line identity and current quantity to the separate Cart control
presenter, then attaches the two closed models under the core-owned
`mutationForms` row contract. Unknown fields, malformed identities or money,
mismatched currency or totals, invalid quantities, unsafe strings, and
collection overflow fail closed without a partial view.

Store Lite 0.1.24 supplies the verified identity through the separate database
projection and binds the previously approved controls to each non-empty Cart
component row. Core still owns manifest matching, browser evidence, action
derivation, HTML, dispatch, response handling, and every write. Checkout,
orders, and payment remain separate later gates.
