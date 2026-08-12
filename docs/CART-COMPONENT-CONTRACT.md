# Cart Component Contract

Status: Store Lite 0.1.28 placeable Cart with core-rendered line controls and
guest checkout.

The package declares `redcms.store-lite/cart` as an optional component. One
package-owned `RED_Addon_StoreLite_Cart_Placements` row binds one core
`RED_Articles` content record to a required public title. The relationship is
restrictive in both directions; Store Lite does not own or cascade-delete the
core content record.

The component editor callbacks accept only the exact core component context,
one `cart-title` value from 1 through 160 bytes, and a core-owned active
transaction for create, write, or delete. The placement table stores no cart,
subject, product, browser, checkout, customer, or payment state.

At public render time the package opens its isolated installation database,
requires exactly one uppercase three-letter currency across the current
catalog, and asks the RED-CMS core subject helper for the current anonymous
subject. A valid subject is passed to the package read model. A missing subject
produces a data-only empty-cart projection without creating an identity or
writing state. The pure Cart presenter then returns the bounded generic core
component model: title, summary, item count, total, and at most twenty-four
line items. Current lines carry core-rendered quantity and removal forms. A
non-empty cart also carries the exact twelve-field guest checkout form; an
empty cart does not.

The component reads no request, cookie, session, or raw browser token, emits no
HTML, and exposes no database identifiers. Subject resolution, CSRF,
idempotency, rate limiting, all public-mutation execution, and markup remain
owned by RED-CMS core. Store Lite owns only its typed callbacks and package
tables. Inventory mutation, hosted payments, order administration, customer
notification, and an operational `commerce.cart` service remain later gates.
