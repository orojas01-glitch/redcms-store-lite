# Store Lite Cart Mutation Bridge Contract

Status: implemented by Store Lite 0.1.14 as an internal runtime binding to the
RED-CMS atomic public-mutation runner. No production HTTP endpoint, browser
identity bootstrap, form, cookie emission, cart display, or checkout is active.

## Declaration

The package declares one public POST route,
`redcms.store-lite/cart-intent`, and one mutation,
`redcms.store-lite/add-to-cart`. The closed form body contains only:

- required `product` identifier;
- required integer `quantity` from 1 through 100; and
- optional `variant` identifier.

The contract requires anonymous core subject evidence, core-issued CSRF and
idempotency values, fixed rate limiting, `no-store` responses, server-derived
postconditions, and the exact eight package tables used by cart persistence.

## Runtime boundary

`RED_CMS_Store_Lite_Cart_Mutation_Bridge` registers the declared route,
mutation handler, and state loader. Its route callback always throws because
only the core dispatcher may invoke a public mutation. The handler and loader
receive only typed core command objects plus the caller-owned transaction
connection. They never read request, cookie, session, or server globals; issue
browser authority; begin, commit, or roll back; or emit a response.

The bridge derives installation currency from current package storage, passes
only product, quantity, and optional variant intent to cart persistence, and
returns a bounded state containing only line count and a cart-state SHA-256.
All SKU, price, option, currency, stock, and total facts remain server-derived.

The general `commerce.cart` service remains a fail-closed placeholder.

## Atomic proof

The RED-CMS Store Lite rehearsal stages a clean core plus this package in a
fresh disposable database. It proves:

- a published simple product accepts once and exact key replay does not add it
  again;
- reusing the key for changed fields is refused;
- one exact published variable-product variant is accepted;
- an unknown variant rolls back without a cart, line, execution, or audit;
- two package cart activities, two core execution records, and two value-free
  core audits commit with the two accepted mutations; and
- existing desktop/mobile administrator and public rendering checks remain
  unchanged before the schema, grant, server, and staged package are removed.

## Next gate

Gate 2D2 remains separate: core-owned anonymous-subject/CSRF/idempotency
bootstrap, accessible Add-to-cart form composition, a supported server
integration, generic response rendering, and desktop/mobile browser mutation
QA. Package HTML will not receive or invent security authority.
