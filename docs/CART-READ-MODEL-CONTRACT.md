# Cart Read Model Contract

Status: Store Lite 0.1.24 internal read-only projection.

The caller supplies an explicit database connection, one positive
core-resolved anonymous subject record ID, and the exact installation
currency. Store Lite loads only the subject-owned cart and returns the closed
projection accepted by the pure public Cart presenter: current product title,
ordered shopper-facing option labels, server-derived line identity, quantity,
stored integer unit price, currency, and stored integer line total.

The reader exposes no cart, product, variant, or subject database identifiers.
Its lowercase line-identity SHA-256 is an opaque public reference, not a
database identifier, secret, authorization result, or bearer capability. The
reader verifies it against the current product and optional variant public
identities before returning any projection.
It returns at most twenty-four lines, performs no write or transaction, and
reads no request, cookie, session, server global, or runtime configuration.
Missing carts become an empty projection. Currency drift, broken product or
variant relationships, missing variant selections, invalid labels, malformed
quantities or money, and display overflow fail closed without partial data.

Store Lite 0.1.19 binds this reader to a separately documented Cart component.
Store Lite 0.1.24 uses the verified identity to attach data-only quantity and
remove presentations to each row. Core subject resolution, browser evidence,
form composition, dispatch, checkout, orders, and payments remain separate
boundaries.
