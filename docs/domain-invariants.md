# Domain Invariants (normative)

> **Status**: Normative. Every rule here is testable and MUST be asserted in Pest before the corresponding phase gate passes.
> **Precedence**: `CLAUDE.md` (charter) > this file > `docs/zjmf-api-spec.md` / `docs/external-drivers-spec.md` (wire protocol).
> If a driver spec contradicts an invariant here, the invariant wins and the spec is wrong.
>
> Rule IDs are stable. Reference them in code comments, tests and PR descriptions (`// INV-I3`).

---

## 1. Money

**M1 — Representation.** Money is a fixed-point decimal string with exactly two fractional digits (`"10.00"`). Never `float`, never `int` cents, never `number_format()`. All arithmetic goes through `App\Support\Money` (`bcadd`/`bcsub`/`bcmul`/`bccomp`). MySQL columns are `DECIMAL(n,2) UNSIGNED`.

`Money::of()` accepts decimal strings or whole integer units and rejects floating-point input. Monetary model attributes use `decimal:2` to preserve the two-place string representation after database reloads, including SQLite. This database representation cast is not a price-production step; it must not replace the single rounding point in M3 or business arithmetic in Money.

**M2 — Non-negativity.** Every monetary column is `UNSIGNED`. A negative value is a bug, never a valid state. Direction is expressed by `financial_transactions.type`, not by the sign of `amount`.

**M3 — Rounding.** Rounding is half-up on a non-negative value, applied exactly once, at the point a price is produced (catalog sync, quote computation, coupon discount). Never round an already-rounded intermediate. `Money::round($v)` = `bcadd($v, '0.005', 2)`; it truncates, which is half-up for non-negative inputs.

**M4 — Currency.** One invoice carries exactly one currency (`invoices.currency`, ISO-4217, default `CNY`). No FX conversion exists anywhere in the system. A quote whose upstream currency differs from `invoices.currency` MUST be rejected, not converted.

Human-approved C20 (2026-11-18): the current available/frozen wallet, affiliate balance and Epay settlement support **CNY only**. Reject unsupported-currency new purchases, renewals and upgrades (including zero amounts) before any balance mutation or payment request. Catalogs may retain their source currency. Historical non-CNY invoices require manual reconciliation: do not automatically settle, refund or unfreeze them, nor credit late/duplicate/excess payments into the CNY wallet. Preserve verified callback evidence and deduplication, alert, and ACK after commit using the currency-rejection branch below. No implicit 1:1 conversion or historical ledger rewrite is authorized. See `backend-remediation-decisions.md` C20.

---

## 2. Invoice amount identity (blocking)

**INV-I1 — Canonical computation order.** Computed once, inside `CreateComboInvoiceAction`, and stored; never recomputed at payment time:

The purchase Action receives the product, billing cycle and selected options, obtains a trusted server-side quote and checks its currency. A submitted `totalAmount` alone cannot establish a purchase price.

Human-approved local preview decision (2026-11-19, “改成这样”): classic configuration previews use synchronized local product/option prices without upstream I/O and are estimates. Customer HTTP checkout still obtains a fresh upstream price, currency and availability outside locks. An encrypted five-minute quotation token binds the owner, product/upstream mapping, cycle and selected pricing configuration to the displayed period/setup amounts. A missing token or any amount difference returns a new quote for explicit confirmation before invoice/coupon/wallet/task writes; a submitted token with invalid scope or expiry is rejected. Confirming always rechecks upstream, so another change requires another confirmation. Replay of an already committed purchase remains first and returns its original amount even after token expiry; quotation tokens are not part of the durable caller intent and do not change invoice amounts at payment time. Passwords and custom fields are validated at checkout and are excluded from scripted price-preview requests and tokens. The no-JS form fallback may submit additional fields to the local preview endpoint, which discards them without upstream forwarding or retention.

```
1. gross          = Σ(line.unit_price × line.qty)                 // per line, 2dp, M3
2. subtotal       = gross
3. discount       = coupon(subtotal)                              // 0 <= discount <= subtotal
4. total_amount   = subtotal - discount                           // tax engine reserved, no-op in Phase 1
5. credit_applied = user-selected, 0 <= credit_applied <= total_amount, and <= available credit
6. gateway_amount = total_amount - credit_applied
```

Human-approved review decision A (2026-11-18, `backend-review-fix-decisions.md`): a newly generated automatic renewal explicitly marked `line_items.automatic_renewal.selection_pending` may receive exactly one owner-confirmed wallet/gateway allocation in `CreateComboInvoiceAction`. Keep its total, quote, coupon and due boundary unchanged. Require an unexpired Unpaid current renewal with no attempts, callbacks, money ledger or unresolved task, and revalidate service/account binding under locks. Store the confirmation request and choices; identical replay has no effects, changed choices reject. Freeze once; pure-wallet Paid + fulfillment remain atomic. Payment initiation requires confirmation first. Unmarked historical invoices and already confirmed/terminal invoices cannot be edited by this exception.

**INV-I2 — Hard identity.** Before persisting, assert:

```
bcadd(credit_applied, gateway_amount) === total_amount
bcadd(subtotal, '0.00')               === bcadd(total_amount, discount)    // discount floored at 0
```

`discount_amount` is the coupon discount only. It is **already reflected** in `total_amount`; it is never subtracted a second time from `total_amount` or `gateway_amount`.

**INV-I3 — Zero-amount short circuit.** If `gateway_amount == "0.00"`, no gateway is contacted. `CreateComboInvoiceAction` sets `invoices.status = Paid`, records `credit_settle`, and creates the instance and `fulfillment_tasks` row **in the same `DB::transaction()`**, then dispatches strictly after commit.

**INV-I4 — One product per invoice.** An invoice bills exactly one `products` row. A new-purchase invoice must store `invoices.product_id`; only `invoices.instance_id` may be null before its first settlement. Multi-line invoices exist only as *lines of the same product* (options, setup fee, discount). This keeps `credit_applied` a single-instance freeze. Multi-product carts are out of scope; if ever required, add `invoice_items` and revisit this invariant first.

**INV-I5 — `line_items` schema.**

```json
{
  "items": [
    {"kind":"product","ref_id":88,"label":"ECS S","qty":1,"unit_price":"10.00","amount":"10.00","cycle":"monthly"},
    {"kind":"option","ref_id":101,"label":"Bandwidth","qty":20,"unit_price":"0.50","amount":"10.00","cycle":"monthly"},
    {"kind":"setup_fee","ref_id":88,"label":"Setup","qty":1,"unit_price":"5.00","amount":"5.00","cycle":null},
    {"kind":"discount","ref_id":7,"label":"NEWYEAR","qty":1,"unit_price":"-3.00","amount":"-3.00","cycle":null}
  ],
  "subtotal":"25.00",
  "discount":"3.00",
  "total":"22.00"
}
```

`subtotal`/`discount`/`total` are the audit snapshot of INV-I1. `discount` lines carry a negative `amount` for display only; `subtotal`, `discount`, `total` are non-negative.

---

## 3. Wallet and frozen credit state machine

Three columns, one pool (approved N01/N05). **`credit` = available balance; `frozen_credit` = invoice reservations; `wallet_clearance_frozen` = manual wallet clearance reservations.** Total wallet value includes all three. Invoice operations keep their original two-column meaning and never consume the independent clearance reservation.

| Operation | Transition | Guard |
|---|---|---|
| freeze | `credit -= x`, `frozen += x` | `credit >= x` **and** `x` is the invoice's `credit_applied` |
| settle | `frozen -= x` | `frozen >= x`; runs exactly once when the invoice becomes `Paid` |
| unfreeze | `frozen -= x`, `credit += x` | `frozen >= x`; runs exactly once on expiry/cancel |
| credit in | `credit += x` | — |
| debit | `credit -= x` | `credit >= x` |

**INV-W1 — Non-negative.** `credit >= 0` and `frozen_credit >= 0` after every operation (enforced by `UNSIGNED`).

**INV-W2 — Exactly-once.** Every transition writes one `financial_transactions` row whose `reference_key` is unique:

```
freeze:{invoice_id}      settle:{invoice_id}      unfreeze:{invoice_id}
credit_in:callback:{payment_callback_id}
affiliate:{commission_id}                          payout:{withdrawal_id}
adjust:{uuid}                                     refund:{invoice_id}
```

**INV-W3 — No lost update.** Every balance mutation takes `SELECT ... FOR UPDATE` on the `users` row, or uses a conditional `UPDATE ... WHERE id = ? AND credit >= ?` and asserts `affected_rows = 1`. Reading `credit` to compute a freeze without a lock is a defect.

**INV-W4 — Terminal states are frozen.** `Paid` invoices never unfreeze. `Expired`/`Cancelled` invoices never settle.

**INV-W5 — Ledger types.**

All current wallet and affiliate balance transitions below are denominated in CNY (human-approved C20); a legacy invoice in another currency cannot authorize one of these wallet transitions or an automatic refund record. Preserve the original bill and evidence for manual reconciliation.

| `TransactionType` | `is_credit` | Meaning |
|---|---|---|
| `credit_in` | yes | deposit (gateway late payment, admin top-up, overpayment excess) or wallet refund (C10) |
| `credit_freeze` | no | available → frozen |
| `credit_settle` | no | frozen consumed by a successful payment |
| `credit_unfreeze` | no | frozen → available |
| `payment` | no | gateway settlement, informational |
| `refund_wallet` | yes | reserved legacy type; new wallet refunds use `credit_in` with `refund:{invoice_id}` (C10) |
| `expense` | no | money out: external refund record, external affiliate payout |
| `affiliate_commission` | no | commission credited to `affiliate_balance` |
| `affiliate_clawback` | no | commission reversed |
| `affiliate_payout` | no | `affiliate_balance` → wallet or external |
| `wallet_clearance_hold` | no | available wallet → independent clearance reservation |
| `wallet_clearance_paid` | no | clearance reservation consumed by confirmed external payment |
| `wallet_clearance_release` | no | clearance reservation → available wallet after cancellation before execution or verified nonpayment |
| `adjustment` | per sign of context | admin manual adjustment, requires `audit_logs` row |

There is no "frozen_credit deduction" type. Payment of the credit portion is `credit_settle`.

---

## 4. Payment callback decision table (blocking)

Input: `VerifiedPaymentResult {gateway, gatewayTradeNo, outTradeNo, paidAmount}`. All of it runs inside **one** `DB::transaction()` in `HandlePaymentCallbackAction`, after `EpayGateway` has already verified the signature.

**Resolution order**

1. `payment_callbacks` insert `(gateway, gateway_trade_no)` — `unique`. If the insert violates the constraint → `ignored_duplicate`, stop further processing with zero side effects and select `ackBody`; emit it only after transaction completion (INV-P3). `payment_callbacks` is the dedupe authority; `invoices.gateway_trade_no` is only a support mirror.
2. Resolve the invoice via the exact `payment_attempts (gateway, out_trade_no) -> invoice_id`. If absent, fall back to an exact match of the complete `outTradeNo` against `invoices.invoice_no`; never strip or guess suffixes. If still absent, resolve the callback to `unknown_invoice`: persist the callback row with `invoice_id = NULL` + `invoice_no` populated, alert, and still ack `success` (never make the gateway retry an invoice we cannot resolve).

Then lock `invoices` (`lockForUpdate()`) and branch:

| # | Condition | Outcome | Action |
|---|---|---|---|
| P1 | `gateway != invoice.gateway` | `rejected_gateway_mismatch` | no state change, alert |
| PC (C20) | matched gateway, `invoice.currency != CNY` | `rejected_unsupported_currency` | persist verified evidence and invoice association, alert; no settlement/unfreeze/refund/wallet credit/instance/task mutation; ACK after commit. Runs before every terminal-state and amount branch below |
| P2 | `invoice.status` ∈ {`Expired`,`Cancelled`} | `processed_late_credit` | credit `paidAmount` to wallet (`credit_in`), **never provision**, alert. Frozen amount was already released at expiry — do not unfreeze twice |
| P3 | `invoice.status == Paid` and a second distinct trade arrives | `processed_overpayment` | credit `paidAmount` to wallet, alert (double payment) |
| P7 | `invoice.status ∉ {Unpaid}` and not covered above | `rejected_invalid_state` | no change, alert; this guard runs **before** any amount branch, including for `Refunded` |
| P4 | `paidAmount == gateway_amount` | `processed_paid` | → P8 |
| P5 | `paidAmount > gateway_amount` | `processed_overpayment` | → P8, then credit the difference (`credit_in`, key `overpaid:{callback_id}`), alert |
| P6 | `paidAmount < gateway_amount` | `rejected_short_amount` | **do not** set `Paid`, keep `Unpaid`, alert. Partial payments are never auto-reconciled |
| P8 (Paid path) | in **one** transaction; entered only from P4 or P5 | preserve `processed_paid` from P4 or `processed_overpayment` from P5 | `status=Paid`, `paid_at`, `credit_settle` for `credit_applied`, create the product's `instances` row (`status=Pending`), create `fulfillment_tasks` (`status=pending`, unique `idempotency_key`), then `DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($id))` |

**INV-P1 — Comparison basis.** Amounts are compared against `gateway_amount`, never `total_amount`, because `credit_applied` was already settled from the wallet. Comparing against `total_amount` double-charges the wallet portion.

**INV-P2 — Instance creation point.** A new-purchase instance is created together with `Paid` and its task through the following legitimate settlement entry points: `CreateComboInvoiceAction` when `gateway_amount == "0.00"` (INV-I3), or `HandlePaymentCallbackAction` on the P8 path. `fulfillment_tasks.instance_id` is therefore always non-null for `action=provision`. Neither entry point may create an instance before, or in a transaction separate from, the Paid transition. Human-approved N03 additionally permits `ReconcilePaymentCallbackAction` to apply the original verified, previously unposted receipt through the same locked `PostVerifiedPaymentReceiptAction`; it commits Paid, unique ledgers, fulfillment, reconciliation evidence and the original paid outbox atomically. It never replays the callback or reopens a terminal invoice. No other purchase path creates an instance for a paid order.

**INV-P3 — Ack discipline.** Decide the callback outcome inside the transaction, including rejection and unknown-invoice outcomes, and emit the gateway-facing response **only after commit**. The `payment_callbacks` row is committed in the same transaction as any effect, so a crash mid-way leaves either no row (gateway retries safely) or a row plus effect (retry dedupes). P8 must not overwrite an overpayment outcome chosen by P5.

---

## 5. Locking and transaction order (blocking)

**INV-L1 — Global lock order.** Acquire row locks only in this order; never in reverse, never nested out of order:

```
users → invoices → payment_attempts → instances → redemption_codes → fulfillment_tasks
```

Epay merchant selection additionally takes an application-wide configuration metadata row lock **before** this financial lock sequence. Configuration changes and attempt creation serialize on it; no caller may acquire it after financial row locks. Callback credential resolution releases it before the callback settlement transaction. It does not authorize money writes or HTTP under a transaction.

**INV-L2 — Single writer for money.** `HandlePaymentCallbackAction`, `CreateComboInvoiceAction`, `ExpireUnpaidInvoicesAction`, `RefundInvoiceAction` and `RetryFulfillmentTaskAction` are the only writers of invoice/wallet state. Drivers never write them.

**Human-approved W2 / N01–N02 (2026-11-18):** `ProcessWalletClearanceAction` is additionally a wallet writer for the three explicit clearance transitions only. Amounts are CNY 0.01–10000.00, fees borne by the merchant, with at most one pending/reserved/processing/unknown request per original user. Ordinary requests require the owner's current password and confirmation. A closed user's clearance requires finance reconciliation authority and encrypted evidence of rights to the original user ID and a verified original wallet credit, never a reused contact identifier. No arbitrary credit, invoice reopening or account revival is permitted.

The extended lock order after the existing six layers is financial records (including clearances) → shared external payout receipts → reconciliation cases/entries, mapping reviews and entitlement archives → notification events/deliveries. All participating users are locked in ascending ID order before those layers; source invoices precede financial records. The callback dedupe insert retains its original special savepoint boundary. Source totals are checked under the original owner lock without taking earlier locks after a case lock.

Clearance references are `wallet_clearance:{id}:hold`, `:paid`, `:release`. Each ledger row preserves invoice `frozen_after`; its `meta.wallet_clearance_frozen_after` explicitly records the separate reservation. Unknown transfers retain reservation and execution identity and cannot restart or automatically release. Paid confirmation atomically claims a globally unique `(payout_method, external_reference)` in `external_payout_receipts`, shared with affiliate transfers; exactly one real business FK must own it. History backfill registers existing facts without rewriting the ledger; conflicts fail for reconciliation.

`payment_attention` sources are original verified callbacks, narrowly verified `refund:{invoice_id}` CREDIT_IN entries, or original `unfreeze:{invoice_id}` CREDIT_UNFREEZE releases for closed users. A release requires an original matching freeze, an Expired/Cancelled CNY invoice with no payment/settlement/refund, exact allocation and original owner; it never creates another credit ledger or revives the user. Source identity, original amount and fingerprint are checked; no synthetic callback or operator-selected amount can replace the principal. The unique source credit prevents registering the same obligation twice. For anonymous source clearance, original credit L, verified paid P and valid reserved/processing/unknown holds H obey `P+H ≤ L`; approval rechecks amount ≤ min(credit,L−P−H). Pending does not reserve H. A source closes only after evidence-backed `P=L` and `H=0`, with append-only reconciliation entries. Historical attention remains blocking before source backfill; changing a case status alone cannot release it. Open capacity/closed-account source cases do not block legitimate clearance of already available wallet funds. Unposted receipts never become available merely through source registration.

**Human-approved W3 / N03 (2026-11-18):** payment reconciliation requires `finance.view` and `finance.reconcile`, a stable ULID, expected case revision, reason, encrypted evidence and explicit confirmation. It references the original verified callback and attention facts; the original callback outcome/raw and audit remain unchanged. A current Unpaid invoice can settle through the shared posting action; current Paid/Expired/Cancelled invoices receive the full original receipt once as late/duplicate credit. A closed original identity receives only its original wallet credit and enters awaiting-clearance, with no fulfillment or revival. A closed historical Unpaid allocation can expire and release its verified original freeze for a separate source-bound clearance. Resolution verifies real unique ledger/task evidence or full actual clearance, never an arbitrary status flag. A repeated request with the same intent returns its original result; changed intent or a stale new revision rejects. A disproven conclusion requires an appended reopen; rechecking an existing posting cannot post principal again. Ordinary later refunds and task completion do not invalidate a valid original posting.

**Human-approved B2-01 (2026-11-19):** callback attention stores its nonsecret original source facts in nullable `reconciliation_cases.source_snapshot` in the original transaction. Reconciliation and resolution compare this business proof with the original callback/invoice identity, amount, reason, outcome and fingerprint rather than reading audit output. Migration `000016` registers all legacy audit-only attention sources before business reads switch; complete matching proof may fill an empty snapshot without changing the case's timestamps, revision or disposition entries. Incomplete proof remains a blocking case, including formerly resolved zero-principal cases; conflicting proof or an omitted source fails the conversion. Stop old writers for the transition. Audit retention and mandatory audit writes remain in force; this change does not authorize deleting audit history.

For zero affiliate accrual only, B2-01 additionally records nullable `invoices.affiliate_zero_accrual_at` under the existing user/invoice locks with the mandatory audit in the same transaction. It means the original paid CNY referral snapshot was checked and its commission rounds to zero; it never represents a payment, commission row or ledger movement. Maintenance and closure consume this fact, not the audit row. Migration `000017` fills it only from complete matching original zero-accrual evidence, preserving historical invoice fields; otherwise ordinary revalidation remains required. Positive accrual, maturation, financial amounts and immutable `line_items` are unchanged. See `architecture-second-batch-plan-2026-11-19.md`.

N02 adds `OneTimeDeliverable`, historical capability resolution and `instance_entitlement_archives`. Owner password, confirmation and a stable request key bind a nonsecret delivery snapshot/fingerprint to complete Paid, sold-card and successful provision evidence, with no recurring obligation or unresolved execution. Active instances and sold inventory remain unchanged. Only an archive that still matches complete delivery evidence exempts that instance from the service-termination closure blocker; it does not exempt funds, tasks or other blockers. Historical capability resolution performs no I/O and does not require a still-active supplier credential. Secret rotation must preserve the nonsecret fingerprint while rotating existing delivery secrets and all new financial evidence ciphertext.

**Human-approved N05 (2026-11-18):** wallet capacity is the complete final `credit + frozen_credit + wallet_clearance_frozen` transition, capped at `9999999999.99`. Each persisted column and intermediate ledger snapshot must also fit its column. All writers preflight under the existing owner lock before any partial accounting; callbacks that cannot apply a full receipt retain verified callback/attention evidence and ACK after commit without payment or fulfillment. Historical aggregate excess is never rewritten: legitimate consumption, freezing and releases may keep or reduce the original total, provided each stored column fits. A pure-wallet intermediate hold that cannot fit is rejected for reconciliation, rather than overflowing SQL or silently changing freeze/settle ledger semantics. The independent clearance hold does not change invoice `frozen_credit` semantics; actual clearance execution belongs to the separately staged N01 implementation. See `system-architecture-remediation-plan-2026-11-18.md`.

Human-approved C17 adds `ProcessAffiliateWithdrawalAction` solely for approved affiliate-to-wallet transfers: lock the user, debit affiliate_balance, credit credit, append unique `payout:{withdrawal_id}`, audit the authorized actor/reason, and mark the withdrawal approved in one transaction. It cannot adjust arbitrary wallets or write invoice state.

**INV-L3 — No I/O under lock.** Never hold a DB transaction open across an upstream HTTP call. Pattern: lock → validate → commit a `pending` task → perform I/O in the job → lock again → commit the result.

**INV-L4 — Driver purity.** A driver returns `ProvisionResult`; it does not mutate models. Every `instances` mutation happens in an Action, after the driver returns.

**Human-approved W4 / N06 (2026-11-18):** admission for affiliate transfers, external payouts, wallet clearances, closure and receivable settlement/reversal verifies the complete original clawback/commission/invoice/settlement/reversal/ledger evidence before acquiring financial user locks. MySQL scans use one explicit REPEATABLE READ transaction on the same connection, without row locks; starting them inside an existing financial transaction is rejected. Roots and evidence use keyset batches of 100 and 500, including arbitrarily long history for one root. No aggregate, cached balance or partial scan substitutes for complete evidence verification; B3-01 below permits bounded reuse of a previously completed full verification.

All legitimate evidence writers increment the original user's `receivable_revision` under that user's existing lock: commission accrual/maturation, refund clawback, settlement, reversal, both parties to a cross-owner correction, invalidation and authorized review. Locked financial admission compares the verified user/revision/algorithm and persistent `receivable_reconciliation_required`; it never scans all history while holding the funds lock. A changed version rolls back the proposed operation and recomputes outside locks, with at most two conflict retries. Outstanding debt blocks payout/clearance/closure, while valid settlement/reversal can change it. Detected inconsistent evidence blocks admission and retains a persistent flag until a complete authorized review with `finance.view` and `finance.receivable-review` clears it, increments revision and audits the reason/digest. Existing debt and original ledger entries are never edited by the review.

Foreground verification has a two-second soft budget; an incomplete scan queues one independent `receivable-check` job with a 90-second scan budget, timeout 120 seconds and one queue attempt. Foreground and background completed results use the same original-request cache, expiring at most 600 seconds from the original scan start, without renewal on hits. Each binds user, action, original stable request, canonical intent HMAC, evidence revision, algorithm and digest. APP_KEY changes invalidate it. It does not authorize background money movement: only the original operation can consume a current result after the ordered user lock. Failed/queued/running checks remain explicit states; failure requires explicit original-request retry, and an invalid result cannot automatically clear a persistent flag. Page displays keep their own short shared budget and at most 20 captured roots; individual money operations capture at most two. A separate bounded periodic audit flags bad evidence only if its completed snapshot revision is still current.

**Human-approved B3-01 (2026-11-19):** ordinary admission with no captured roots and no flag-review authority may reuse an encrypted complete valid verification proof from another request. It binds the original user, revision, algorithm, APP_KEY, false persistent flag, digest and totals, expires at most 600 seconds from the full scan start and never renews on a hit. Missing, damaged, expired or mismatched proof requires complete verification. Every financial operation still checks the current user/revision/algorithm/flag under its normal ordered locks; legitimate evidence writes invalidate old proofs by incrementing revision. Captured-root operations (including settlement, reversal, correction and details), authorized review and independent audit continue to use complete verification, with existing exact-original-request completed results retained. Shared proof contains no request amount, password, payout destination or captured-root details. SQL tampering that bypasses legitimate Actions and revision increments may remain undetected by ordinary new requests during an existing proof validity window; a later full verification or independent audit checks the evidence. This is an explicitly approved detection delay, not incremental per-root accounting, and the audit schedule does not promise every-user detection within five minutes.

---

## 6. Idempotency keys

**INV-K1 — Renewal / upgrade invoices.** `invoices.billing_period_key` is a nullable `unique` string, form `{instance_id}:{type}:{period_key}`:
- renew: `12:renew:2026-12-01T00:00:00Z` (period_key = the authenticated, locked due boundary before renewal, in UTC; human-approved C14). This supports several hourly/daily periods in one calendar month. Retries reuse the boundary, never the current request time. An expired/cancelled invoice is not reopened or silently replaced under a new key.
- upgrade/downgrade: `12:upgrade:2026-11-17T10:00:00Z` (the requested change instant)

Human-approved C23 (2026-11-18) permits **explicit recovery of an actually Expired, never-settled current renewal only**. The unique billing_period_key stays on the original root. A successor has nullable renewal_root_id pointing to that root, unique replaces_invoice_id pointing to its immediate predecessor, and NULL billing_period_key / purchase_request_id. Same user, instance, product, currency and authenticated due boundary must hold throughout a complete linear chain. No automatic replacement, branching or terminal-state reopening is permitted.

The owner must request a new trusted quote and explicitly confirm its customer amount, coupon and wallet/gateway split with a stable ULID and short-lived encrypted token. Revalidate binding/configuration/due boundary, price, coupon and allocation before committing; changes require another quote. Verify each predecessor's original freeze/unfreeze evidence and reject settled/unresolved upstream operations. Under the user lock, lock the invoice family before the instance/tasks; only the current tip may settle or create a renewal task. CreateComboInvoiceAction remains the money writer, with Paid + task atomic and dispatch after commit. Old late payments retain P2 wallet-only treatment and never pay a successor. No old wallet ledger or coupon usage is rewritten.

A committed recovery request stores encrypted caller intent at line_items.recovery.encrypted_request and participates in key rotation. Same-key/same-intent replays its original result even after quote expiry; changed intent rejects. A different key for a predecessor with a child only locates that child. Another actual expiry requires the owner to quote and confirm the new tip. Ordinary due-boundary lookup returns the current tip, while an existing request-ID replay returns its original invoice. Operators may inspect evidence but cannot authorize customer wallet spending. See backend-remediation-decisions.md C23.

A duplicate scheduler tick reuses the existing root/current tip. Silent duplicate invoicing is a P0 defect.

**INV-K2 — Fulfillment tasks.** `fulfillment_tasks.idempotency_key` is `{invoice_id}:{action}:{instance_id}` (unique). Re-dispatch never creates a second row.

**INV-K3 — Schedulers.** Every scheduled command uses `->withoutOverlapping()` and `->onOneServer()`. A cron without both is a defect.

**INV-K4 — Coupons (human-approved C16).** Coupon application happens inside the invoice-creation transaction after locking the user: `SELECT ... FOR UPDATE` the `coupons` row, verify entitlement in `coupon_claims`, re-check `used_count < total_quota` (null quota is unlimited), `per_user_limit` against this user's `coupon_usages`, `valid_from/until`, and `claim_conditions`, then increment and insert usage. `coupon_usages` is `unique(invoice_id)` — one coupon per invoice, double-counting impossible. A claim is unique per user/coupon and does not reserve usage quota. Cancellation, expiry and refunds do not restore consumed quota automatically. See approved C16–C18 in `operations-expansion-decisions.md` for settlement scope.

**INV-K5 — New-purchase requests (human-approved C22).** Every new-purchase HTTP/Action call requires a stable ULID `request_id`. Persist its uppercase form in nullable `invoices.purchase_request_id` with `unique(user_id, purchase_request_id)`; legacy/non-purchase invoices retain NULL. Canonical versioned caller intent includes product, cycle, sorted configuration/custom fields, hostname/password, coupon and wallet/gateway choices; encrypt it as `line_items.purchase.encrypted_request`, include it in key rotation, and never persist plaintext sensitive hashes. Existing same-user/same-key requests must compare intent and return the original invoice before current rules/quotes/coupon usage/money/fulfillment; changed intent is rejected. Repeat the lookup as a locking read after acquiring the user lock inside the creation transaction (INV-L1/W3). Return terminal invoices unchanged; only a new key creates another purchase. Quotation I/O stays outside locks (INV-L3); the composite unique index is the durable backstop. See approved C22 for exact normalization and deployment boundaries.

---

## 7. Refund and resource recovery (blocking)

**INV-R1 — Service precedes money or is explicitly waived.** `RefundInvoiceAction` refuses to run unless one of:
- the linked instance is `Cancelled`/`Deleted` (upstream termination confirmed), **or**
- an admin passes `keepService = true` with an explicit `actor` authorized by `Gate::forUser($actor)` for the invoice-scoped `refund-invoice` ability, and a non-empty `reason`. Persist `actor_id`, `action=invoice.refund`, `subject_type=Invoice::class`, `subject_id` and that supplied reason in `audit_logs`. Missing authority or reason is rejected, even when the invoice has no instance association; a generated reason is not consent.

A refund that leaves a running VM behind is a net loss and a P0 defect.

**INV-R2 — Both modes are ledgered.** `refund_wallet` → `credit_in` with key `refund:{invoice_id}`. `external_record` → `expense` with the same key. Refunds never silently adjust a balance.

**INV-R3 — Redemption codes are never reclaimed.** Once a card key has been delivered to a customer it is compromised; the code stays `sold`, the instance becomes `Cancelled`, and the loss is recorded as `expense`. Reclaiming a delivered plaintext key is not a recovery.

**INV-R4 — Commission clawback (human-approved C11, 2026-11-17).** Refund transitions the commission to `clawed_back` and records `clawed_back_at`. A `pending` commission has not entered `affiliate_balance`: cancel it without deducting that balance or creating a receivable. For an `available` commission, recover `min(affiliate_balance, commission.amount)` from the affiliate balance and record the remainder as a receivable; never write a negative balance or discard the difference. Do not debit the referrer's ordinary wallet.

Every first clawback appends one `affiliate_clawback` ledger row with unique key `clawback:{commission_id}` and the original commission amount. Its `meta` stores `commission_id`, `previous_status`, `affiliate_balance_before`, `affiliate_balance_after`, `recovered_amount` and `receivable_amount`. For pending cancellation both recovered and receivable amounts are `0.00`. An already clawed-back commission is not reversed again. Future maturation, payout and receivable settlement workflows must account for these receivables; recording one does not settle it or authorize automatic wallet collection. Receivables require an operator alert.

---

## 8. Instance lifecycle and upstream binding

**INV-S1 — Status vocabulary.** `Pending → Active → Suspended → Cancelled|Deleted`, plus `ProvisionFailed` and `Fraud`. `Suspended` can only be entered from `Active`; `Active` from `Pending` or `Suspended`. Every transition is written to `audit_logs`.

Human-approved review decision B (2026-11-18, `backend-review-fix-decisions.md`): only an explicitly authorized/confirmed administrator `terminate` hot swap may rebind a Deleted logical service to an authenticated new Active/Suspended host. Reauthenticate old Deleted state, verify target product/cycle/currency and both accounts, reject refunds/unresolved finances/tasks, archive old binding, stale old tasks, rotate callback token and audit the terminal rebinding atomically. Ordinary sync, renewal and workers cannot revive terminal services; Deleted + keep_orphan and refunded services remain rejected.

**INV-S2 — Self-hosted vs upstream termination.** Self-hosted drivers (`redemption`, `proxmox`, `sub2api`) auto-terminate after `overdue_terminate_days`. Upstream-proxied (`zjmf_api`) stays `Suspended` until upstream reports `Deleted`. The rule is keyed on the driver capability, never on the string `zjmf_api`.

**INV-S3 — Hot swap never orphans silently.** Changing `driver_type`/`upstream_api_id` on an instance:
1. writes the previous binding to `instance_upstream_bindings` (`unbound_at`, `reason`),
2. requires an explicit admin decision for the old resource: `terminate` or `keep_orphan`,
3. on `keep_orphan` raises an admin alert and records the orphan,
4. marks every `pending`/`running` task for that instance `stale` in the same transaction.

**Human-approved W3 / N03–N04 (2026-11-18):** keep-orphan rebinding atomically creates a unique case linked to its original binding. Historical unbackfilled orphans remain closure blockers. New actual provisioning and replacement bindings retain the authenticated driver's nonsecret endpoint identity; NULL historical endpoints are never inferred from a mutable current account. Orphan resolution requires `upstream.view` plus `upstream.orphan-reconcile`, authenticated original-host Deleted evidence or completed transfer with identified recipient/resource/responsibility acceptance and evidence. Accepted cancellation is insufficient. Queries run outside locks, followed by source/account/credential revalidation under ordered owner locks; credentials are never sent to a hand-supplied endpoint. Original binding history is unchanged; conclusions and reopen evidence append separately.

Mapping review requires `upstream.view` plus `upstream.mapping-review`, a stable request, reason and a five-minute encrypted review token. Authentication only reads current host, complete configuration, synchronized account/product/catalog/cycle/CNY and costs; login is the sole permitted POST. Partial upgrade-only options cannot prove full configuration and labels cannot substitute for IDs. Applying reauthenticates and rechecks owner/binding/account/catalog/unfinished business under ordered locks, records an immutable mapping review, rebuilds future configuration IDs and preserves original invoices, recurring price and due date. If the complete target price cannot match the retained baseline, upgrade/downgrade stays blocked by `price_requires_review`; the owner must preview and confirm the exact server-computed future price with current password, confirmation and a stable request. Price confirmation changes neither historical invoices, due date nor remote configuration.

**INV-S4 — Current binding uniqueness.** `instances` has `unique(upstream_api_id, upstream_instance_id)`. Two local instances may never point at the same upstream host.

**INV-S5 — Pre-sync before power state change.** `/host/header` is queried before `suspend`/`unsuspend`; if upstream already reports the target state, reconcile locally without calling `/provision/default`.

---

## 9. Fulfillment task lease and retry

**INV-T1 — Lease.** A task is claimed by setting `status=running, locked_by={worker_id}, locked_at=now(), lease_expires_at=now()+lease`. Lease must exceed the job timeout (`INV-Q1`).

**INV-T2 — Claim count and recovery (human-approved C21).** `attempts` is the number of successful worker claims, incremented exactly once in the transaction setting running/worker/lease after validating the current binding, payment and execution eligibility. A crash consumes its claim. Results, duplicate messages and the 1-minute sweeper do not increment it. The sweeper re-dispatches due `pending` tasks older than 60s without changing their already-due `available_at`. Expired `running` leases are cleared and returned to pending with backoff based on the consumed claim count (60/300/900 seconds, capped at 900); live leases and future backoffs are untouched. Retryable results also persist pending/backoff and dispatch only after commit. Short-lived dispatch deduplication is an optimization; database locks and the current lease remain the execution authority. Recovery revalidates payment/binding and retains all remote-intent reconciliation guards; it cannot authorize blind procurement or payment replay.

**INV-T3 — Terminal failure (human-approved C21).** At most `max_attempts` claims (default 5) are allowed. The final claim may complete successfully; its failed result or expired lease becomes `failed` and alerts, with no automatic next claim. Permanent failures stop earlier. Framework `tries=3` is a separate queue-delivery budget, not permission to exceed the business claim limit. An authorized manual retry explicitly resets `attempts` with a reason and audit via `RetryFulfillmentTaskAction`; never reset historical counts in bulk. See `backend-remediation-decisions.md` C21.

**INV-T4 — Driver-result mapping.**

| Driver result | Task | Instance |
|---|---|---|
| success | `success` | `Pending → Active` |
| retryable failure (5xx, timeout, 405 after re-auth) | `pending` with backoff | unchanged |
| permanent failure (4xx validation, `ProvisionFailed`) | `failed` | `ProvisionFailed` + alert |
| already in target state | `success` | reconciled locally, no upstream call |

**INV-T5 — `stale`.** Hot swap or cancellation marks queued tasks `stale`; a stale task never executes.

---

## 10. Redemption code pool

**INV-C1 — Deduplicable at import.** `redemption_codes.code_hash = hash_hmac('sha256', plaintext, APP_KEY)` with `unique(product_id, code_hash)`. The plaintext is stored with the `encrypted` cast, which is randomized and therefore **not** deduplicable — the hash is the unique key.

**INV-C2 — Atomic claim.** `ProvisionInstanceAction` owns inventory writes, never the driver. Inside `DB::transaction()`: lock the `instances` row first, look up its bound `redemption_codes` by unique `instance_id`, and reuse that reservation or sold code. Only if none exists, lock one available code ordered by `id` with no active lease, and set `instance_id`, `status=reserved` and `reserved_until=now()+15m` together before commit. The pure `RedemptionCodeDriver` reads the bound code and returns credentials in its DTO. Before making credentials visible, the Action locks instance then code, revalidates the binding and reservation lease, and atomically persists `status=sold` with `instances.encrypted_credentials`. A retry must reuse the existing binding; it must not claim a second code for the same instance.

**INV-C3 — Reap reservations.** An Action-backed sweeper locks instance then code, and returns expired `reserved` rows to `available` only if delivery has not completed, clearing their `instance_id` and lease together. A sold or delivered code is never released (INV-R3). Without reservation reaping, a crashed provision permanently burns stock.

---

## 11. Timeouts and queue hygiene

**INV-Q1 — Timeout ordering.** Per driver: `upstream HTTP timeout (30s, 5s for cart/ontrialmax)` < `job timeout (180s)` < `queue retry_after (240s)`. A `retry_after` lower than the job timeout causes duplicate execution.

**INV-Q2 — Retries.** `tries = 3`, backoff `[60, 300, 900]`, `->withoutOverlapping()` per instance where applicable. `failed_jobs` must exist and be monitored; a queue failure that the sweeper cannot see is invisible.

**INV-Q3 — Callback never blocks on upstream.** `/api/host/sync` responds immediately and reconciles asynchronously. The signed payload carries no timestamp, so the handler may not hold the connection for an upstream round trip (upstream would time out, retry, and the `rand_str` dedupe would then drop the retry permanently).

**INV-Q4 — Replay key scoping.** The `rand_str` dedupe key is `host_sync:{instance_id}:{rand_str}` with `SET NX EX 600`. A bare `rand_str` key lets one instance's callback poison another's.

**Human-approved W4 bounded maintenance:** minute scheduling only dispatches local maintenance work; affiliate accrual and maturation have independent jobs. Each of eight kinds has queue uniqueness 180 seconds, execution exclusion 30 seconds, timeout 25 seconds and one queue attempt. At most 100 units share one five-second soft budget; a current local transaction or two-second lock wait may finish before yielding. Interleaved oldest/rotating candidates, independent cursors and per-item failure backoff preserve progress without changing business claim counts, leases, unknown-state guards or upstream concurrency. Losing Redis cursor state restarts from durable business predicates; it does not authorize duplicate business effects. Legacy manual commands retain their existing complete-batch/error behavior. See `maintenance-and-operations-2026-11-18.md` for workers, timeout limits and measured capacity.

Notification recipient expansion preserves user/binding cursors and fixed bounds, resumes without duplicate delivery rows and consumes the same total recipient budget across partial commits and failures. No authorized operations recipient leaves the event awaiting configuration. Read-only operational checks report bounded safe IDs; independent external watchdog monitoring must also observe database/Redis failure and a stopped checker without depending on scheduler, worker or notification delivery.

---

## 12. Upstream ambiguity and reconciliation

**INV-U1 — Log every upstream call.** `upstream_operations` records `upstream_api_id, instance_id, invoice_id, method, path, request (redacted), http_status, response (truncated), duration_ms, correlation_key`. Secrets and passwords are masked before insert.

**INV-U2 — Ambiguous P4/P5.** If a `/cart/settle` or `/apply_credit` response is lost, reconciliation re-runs the P2 `/cart/clear` idempotency probe: `400 + domainstatus=Active` means already provisioned → adopt `hostid`; `200 + invoiceid` means the invoice exists → jump to P5. Never re-run P3 blindly.

**INV-U3 — Rollback is verified.** A failed `use_credit_limit` rollback (`use_credit=0`) that itself fails leaves "upstream paid, local unpaid". This must produce an admin alert plus an `upstream_operations` row; it is never retried silently.

---

## 13. Audit

**INV-A1 — What is mandatory.** `audit_logs` row for: any admin money mutation, manual invoice status change, refund, hot swap, binding change, balance adjustment, settings change of an encrypted key, coupon grant, KYC override, and user anonymization.

**INV-A2 — Shape.** `actor_id, action, subject_type, subject_id, before (json), after (json), reason, ip, request_id, created_at`. `reason` is required for every admin-initiated financial or lifecycle action.

Human-approved usability simplification (2026-11-19, “开始修复”): routine HTTP operations may generate a stable action-specific audit description when the optional operator note is absent or blank. A required audit field does not generally require handwritten input. Preserve the actual actor, subject, request context and change evidence. Explicit keep-service refund waivers under INV-R1 still require the operator's real reason and consent; reversals and uncertain financial/machine/notification outcomes retain their original evidence and release conditions. Generated descriptions used in a durable request identity must remain deterministic for the same operation. See `usability-simplification-2026-11-19.md`.

---

## 14. PR checklist

**Human-approved D01–D05 (2026-11-18):** the targeted additions in `remaining-capabilities-decisions-2026-11-18.md` govern the following extensions. Wallet top-up invoices are the sole no-product exception to INV-I4: external-only positive CNY amount, independent user/topup_request_id uniqueness, no coupon/commission/fulfillment, and atomic Paid + unique topup credit + durable event in HandlePaymentCallbackAction. CreateWalletTopupInvoiceAction may create only these invoices; generic RefundInvoiceAction must reject them. Historical callbacks resolve the original soft-deleted financial owner, never a newly registered identity.

External affiliate payouts freeze available affiliate funds in affiliate_frozen_balance before manual execution; approval is not payment. Dedicated RequestExternalAffiliateWithdrawalAction and ProcessExternalAffiliateWithdrawalAction are the only new external-payout writers. Only confirmed payment consumes frozen funds; confirmed non-payment releases them; unknown stays frozen. The ordinary wallet is unchanged. New affiliate_freeze/affiliate_unfreeze types express these positive-value movements, and affiliate_payout expresses consumption of frozen external funds. Existing wallet payouts keep their approved semantics.

RecordAffiliateReceivableSettlementAction and ReverseAffiliateReceivableSettlementAction append externally_received debt settlements and complete-entry reversals, with affiliate_receivable_settle/affiliate_receivable_reverse ledger types. Neither touches wallet or affiliate balances. Remaining debt is original clawback receivable minus confirmed settlements plus reversals; original ledger is immutable and outstanding/invalid debt blocks payouts.

All participant users are locked in ascending ID order before financial rows. After the existing financial lock sequence, acquire affiliate withdrawal/receivable records in stable ID order, then notification events and deliveries. Notification recording must never acquire a new earlier user lock from the tail of a financial transaction. Durable business events and delivery rows commit with business state; send intent, leases and uncertain outcomes prevent blind replay. User closure shares the user lock, blocks unresolved obligations and cancels/scrubs unsent notification recipients while retaining audit/financial identity. See the approved module proposals for exact predicates, retained evidence, unique keys, permissions and recovery boundaries.

**Human-approved W4 operational event outbox:** final fulfillment failure, exhausted recovery, unconfirmed upstream rollback and uncertain notification results retain a unique immutable `operational_events` fact with only allowlisted source IDs/status. Normal source state and fact commit together. The observed-failure transaction wrapper may preserve only these four already-observed execution failures in a separate short transaction after its own rollback; proposed financial effects cannot survive rollback through it. Persistence/logging failures retain a separate safe PHP structured journal and propagate the original error without secret exception text. A later transaction atomically creates one derived notification event and its projection marker; it acquires no financial user locks. Uncertainty while sending an uncertainty alert retains a suppressed fact, ending recursive alert delivery. An absent recipient, unprojected fact or unavailable checker remains visible to independent operations monitoring; outbox persistence alone does not prove alert delivery.

Every PR touching money, lifecycle, or a driver must answer:

- [ ] INV-I2 identity asserted, and rounded exactly once (M3)?
- [ ] Wallet mutation locked (`FOR UPDATE` or conditional update) — INV-W3?
- [ ] `financial_transactions.reference_key` set, exactly-once — INV-W2?
- [ ] Callback branch has a row in the §4 table, and a Pest case?
- [ ] Lock order respected — INV-L1? No I/O under lock — INV-L3?
- [ ] Idempotency key defined for any new billable event — INV-K1..K2 / K5?
- [ ] Instance creation/termination point explicit — INV-P2 / INV-R1?
- [ ] Driver returns a DTO and mutates nothing — INV-L4?
- [ ] Timeouts follow INV-Q1? Scheduler has `withoutOverlapping` + `onOneServer` — INV-K3?
- [ ] Timeout/audit/upstream log emitted for new external calls — INV-U1?
