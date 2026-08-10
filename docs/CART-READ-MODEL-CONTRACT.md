# Cart Read Model Contract

Status: Store Lite 0.1.18 internal read-only projection.

The caller supplies an explicit database connection, one positive
core-resolved anonymous subject record ID, and the exact installation
currency. Store Lite loads only the subject-owned cart and returns the closed
projection accepted by the pure public Cart presenter: current product title,
ordered shopper-facing option labels, quantity, stored integer unit price,
currency, and stored integer line total.

The reader exposes no cart, product, variant, or subject database identifiers.
It returns at most twenty-four lines, performs no write or transaction, and
reads no request, cookie, session, server global, or runtime configuration.
Missing carts become an empty projection. Currency drift, broken product or
variant relationships, missing variant selections, invalid labels, malformed
quantities or money, and display overflow fail closed without partial data.

Core subject resolution, Cart component placement, quantity updates, line
removal, checkout, orders, and payments remain separate boundaries.
