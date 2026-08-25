# Store Lite Payment-Event Lifecycle Rehearsal

Status: P3B-4 remains implemented in Store Lite 0.1.47 as a disposable acceptance
gate. It changes no payment policy, provider integration, route, secret,
production installation, or retained client database. The same wrapper now
finishes with the separately scoped 42-assertion destination-preview/core-route/component
service rehearsal and restores its synthetic product fixture before cleanup.

## Purpose

The P3B-3 focused test proves the writer and typed service in isolation. P3B-4
proves that the same service is governed by the real RED-CMS package lifecycle
and that disabling a package stops execution without deleting business data.

The shell wrapper stages a fresh copy of the clean RED-CMS core and the exact
current Store Lite payload in a temporary project. It creates a uniquely named
database, grants the configured application account access only to that
database, imports the clean core schema, applies current core migrations, and
then delegates the lifecycle to the PHP rehearsal.

## Rehearsed Sequence

The PHP rehearsal performs this closed sequence:

1. discover and integrity-validate Store Lite 0.1.47 without executing it;
2. install all eleven package migrations into `installed_disabled`;
3. create one synthetic hosted-payment order and immutable creation fact;
4. enable Store Lite through the Owner-authorized atomic core transition;
5. bootstrap the enabled request-local `commerce.orders` owner;
6. force the history append to fail and prove the provisional order update
   rolls back;
7. apply paid once and prove exact duplicate evidence replays without a row;
8. apply a confirmed full refund and refuse a later out-of-order reversal;
9. disable Store Lite and prove service invocation stops while the exact order
   and history fingerprint remains unchanged;
10. re-enable Store Lite, prove identical registrar evidence and restored
    ownership, and replay the retained refund without another row; and
11. finish with two enable facts, one disable fact, and unchanged retained
    business evidence.

## Cleanup And Isolation

The wrapper owns cleanup even when the rehearsal fails. It revokes the scoped
grant, drops only the validated disposable database, removes the temporary
project, terminates its rehearsal-only macOS sleep-prevention process, removes
temporary credential files, and compares the configured primary database dump
hash before and after.

Successful cleanup reports exactly:

```text
database:0 grant:0 staged-project:0 process:0 primary:unchanged
```

The wrapper refuses an existing or unbounded database name, a core checkout
that already contains an `addons/` directory, a missing pinned local runtime,
or any Store Lite version other than 0.1.47. It never targets
`demo.red-sphere.com` or another retained installation.

## Command

From the Store Lite repository:

```sh
tests/payment-event-lifecycle-rehearsal.sh
```

Set `RED_CMS_CORE_ROOT` only when the clean RED-CMS checkout is not the default
sibling directory. Local database administrator credentials may be supplied
through the existing `RED_ACCEPTANCE_DB_ADMIN_USER` and
`RED_ACCEPTANCE_DB_ADMIN_PASS` acceptance variables.

## Explicit Exclusions

P3B-4 does not expose a webhook or public payment endpoint, verify a provider
signature, resolve a secret, contact a provider, create a checkout session,
change inventory, notify a customer, deploy Store Lite, or authorize a payment
adapter. Those remain separate P3C/provider and per-client deployment gates.
