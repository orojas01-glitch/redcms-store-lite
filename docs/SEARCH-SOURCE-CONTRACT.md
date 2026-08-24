# Store Lite Public Search-Source Contract

Version: 0.1.40
Service: `content.search-source.store-lite`
Operation: `documents.list`

Store Lite owns the only knowledge of its catalog, placement, and product
publication tables. A search package may request the next one to eight eligible
public placement documents with an integer cursor. The response contains only
the closed document list, next cursor, and continuation flag.

Each document contains exactly:

- source type `store-lite-product`;
- stable public Product ID;
- two-letter page language;
- bounded plain-text product title and summary;
- bounded non-commercial keywords;
- the placed Product component's canonical public URL; and
- the latest product/page update time.

Price, currency, stock, availability value, SKU, variant commercial facts,
image references, cart/order/payment state, customer data, administrator data,
database record ids, settings, and secrets are never returned. Product state
and availability are used only as eligibility filters.

Eligibility requires a published and available product bound to an active,
started, unexpired Product component placement that has been placed onto an
active, started, unexpired Article destination through the core publish
lifecycle. The component and destination must share the same language and
Section/Category/Subcategory hierarchy, the placement must carry the exact
destination alias in its `Article` field, and both page positions must be
public. Home-only cards with no published destination remain excluded. The
service opens one short-lived client-local read-only transaction per bounded
batch. Missing, disabled, stale, or malformed provider behavior fails closed
at the caller.

Multiple public placements for the same Product ID and language may be emitted
by Store Lite. Site Search chooses the lowest placement cursor as the canonical
document and caps one rebuild at 1,000 eligible placements. Store Lite does not
write another package's index or depend on Site Search.

This contract adds no public route, browser control, mutation, migration,
setting, secret, job, outbound host, price refresh, or client activation.
