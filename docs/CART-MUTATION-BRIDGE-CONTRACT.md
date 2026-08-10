# Store Lite Cart Mutation Bridge Contract

Status: implemented as internal runtime bindings to the RED-CMS atomic public-
mutation runner. Store Lite 0.1.22 adds set-quantity and remove-line beside the
existing 0.1.14 Add binding. No browser control is active for the new routes.

## Declaration

The package declares three unique public POST route/mutation pairs:

- `cart-intent` / `add-to-cart` accepts required `product`, integer `quantity`
  from 1 through 100, and optional `variant`;
- `cart-line-quantity` / `set-cart-line-quantity` accepts the exact 69-byte
  `line` handle and integer `quantity` from 1 through 100; and
- `cart-line-remove` / `remove-cart-line` accepts only the exact 69-byte `line`
  handle.

The contract requires anonymous core subject evidence, core-issued CSRF and
idempotency values, fixed rate limiting, `no-store` responses, server-derived
postconditions, and the exact eight package tables used by cart persistence.

## Runtime boundary

`RED_CMS_Store_Lite_Cart_Mutation_Bridge` registers all three declared routes,
mutation handlers, and state loaders. Its route callback always throws because
only the core dispatcher may invoke a public mutation. The bridge verifies the
exact route/mutation pair again before loading or writing. The handler and
loader receive only typed core command objects plus the caller-owned
transaction connection. They never read request, cookie, session, or server
globals; issue browser authority; begin, commit, or roll back; or emit a
response.

The bridge derives installation currency from current package storage. Add may
fall back to the declared product only before a cart exists; existing-line
operations require the subject cart's currency. It passes only the fields
declared for the selected operation to cart persistence and returns a bounded
state containing only line count and a cart-state SHA-256. All SKU, price,
option, currency, stock, and total facts remain server-derived. A true
set-quantity storage no-op returns the core `unchanged` outcome; all committed
changes return `accepted`.

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

The package disposable persistence suite additionally invokes the typed bridge
for all three pairs. It proves Add regression, accepted quantity replacement,
activity-free `unchanged`, accepted removal, empty post-removal state, and
refusal of a substituted route/mutation pair.

## Next gate

The Cart read model and presenter still expose no mutable controls. The next
gate must add package-owned, data-only Cart control models for core composition;
then RED-CMS can rehearse validated form composition, generic response
rendering, and desktop/mobile mutation QA. Package HTML will not receive or
invent security authority.
