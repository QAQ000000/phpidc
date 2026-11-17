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

---

## 2. Invoice amount identity (blocking)

**INV-I1 — Canonical computation order.** Computed once, inside `CreateComboInvoiceAction`, and stored; never recomputed at payment time:

The purchase Action receives the product, billing cycle and selected options, obtains a trusted server-side quote and checks its currency. A submitted `totalAmount` alone cannot establish a purchase price.

```
1. gross          = Σ(line.unit_price × line.qty)                 // per line, 2dp, M3
2. subtotal       = gross
3. discount       = coupon(subtotal)                              // 0 <= discount <= subtotal
4. total_amount   = subtotal - discount                           // tax engine reserved, no-op in Phase 1
5. credit_applied = user-selected, 0 <= credit_applied <= total_amount, and <= available credit
6. gateway_amount = total_amount - credit_applied
```

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

Two columns, one pool. **`credit` = available balance; `frozen_credit` = reserved, unavailable balance.** Total wallet value = `credit + frozen_credit`.

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

| `TransactionType` | `is_credit` | Meaning |
|---|---|---|
| `credit_in` | yes | deposit (gateway late payment, admin top-up, overpayment excess) |
| `credit_freeze` | no | available → frozen |
| `credit_settle` | no | frozen consumed by a successful payment |
| `credit_unfreeze` | no | frozen → available |
| `payment` | no | gateway settlement, informational |
| `refund_wallet` | yes | refund into wallet |
| `expense` | no | money out: external refund record, external affiliate payout |
| `affiliate_commission` | no | commission credited to `affiliate_balance` |
| `affiliate_clawback` | no | commission reversed |
| `affiliate_payout` | no | `affiliate_balance` → wallet or external |
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
| P2 | `invoice.status` ∈ {`Expired`,`Cancelled`} | `processed_late_credit` | credit `paidAmount` to wallet (`credit_in`), **never provision**, alert. Frozen amount was already released at expiry — do not unfreeze twice |
| P3 | `invoice.status == Paid` and a second distinct trade arrives | `processed_overpayment` | credit `paidAmount` to wallet, alert (double payment) |
| P7 | `invoice.status ∉ {Unpaid}` and not covered above | `rejected_invalid_state` | no change, alert; this guard runs **before** any amount branch, including for `Refunded` |
| P4 | `paidAmount == gateway_amount` | `processed_paid` | → P8 |
| P5 | `paidAmount > gateway_amount` | `processed_overpayment` | → P8, then credit the difference (`credit_in`, key `overpaid:{callback_id}`), alert |
| P6 | `paidAmount < gateway_amount` | `rejected_short_amount` | **do not** set `Paid`, keep `Unpaid`, alert. Partial payments are never auto-reconciled |
| P8 (Paid path) | in **one** transaction; entered only from P4 or P5 | preserve `processed_paid` from P4 or `processed_overpayment` from P5 | `status=Paid`, `paid_at`, `credit_settle` for `credit_applied`, create the product's `instances` row (`status=Pending`), create `fulfillment_tasks` (`status=pending`, unique `idempotency_key`), then `DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($id))` |

**INV-P1 — Comparison basis.** Amounts are compared against `gateway_amount`, never `total_amount`, because `credit_applied` was already settled from the wallet. Comparing against `total_amount` double-charges the wallet portion.

**INV-P2 — Instance creation point.** A new-purchase instance is created together with `Paid` and its task in exactly one of two legitimate settlement entry points: `CreateComboInvoiceAction` when `gateway_amount == "0.00"` (INV-I3), or `HandlePaymentCallbackAction` on the P8 path. `fulfillment_tasks.instance_id` is therefore always non-null for `action=provision`. Neither entry point may create an instance before, or in a transaction separate from, the Paid transition. No other purchase path creates an instance for a paid order.

**INV-P3 — Ack discipline.** Decide the callback outcome inside the transaction, including rejection and unknown-invoice outcomes, and emit the gateway-facing response **only after commit**. The `payment_callbacks` row is committed in the same transaction as any effect, so a crash mid-way leaves either no row (gateway retries safely) or a row plus effect (retry dedupes). P8 must not overwrite an overpayment outcome chosen by P5.

---

## 5. Locking and transaction order (blocking)

**INV-L1 — Global lock order.** Acquire row locks only in this order; never in reverse, never nested out of order:

```
users → invoices → payment_attempts → instances → redemption_codes → fulfillment_tasks
```

**INV-L2 — Single writer for money.** `HandlePaymentCallbackAction`, `CreateComboInvoiceAction`, `ExpireUnpaidInvoicesAction`, `RefundInvoiceAction` and `RetryFulfillmentTaskAction` are the only writers of invoice/wallet state. Drivers never write them.

**INV-L3 — No I/O under lock.** Never hold a DB transaction open across an upstream HTTP call. Pattern: lock → validate → commit a `pending` task → perform I/O in the job → lock again → commit the result.

**INV-L4 — Driver purity.** A driver returns `ProvisionResult`; it does not mutate models. Every `instances` mutation happens in an Action, after the driver returns.

---

## 6. Idempotency keys

**INV-K1 — Renewal / upgrade invoices.** `invoices.billing_period_key` is a nullable `unique` string, form `{instance_id}:{type}:{period_key}`:
- renew: `12:renew:2026-12` (period_key = the period start being billed, `Y-m`)
- upgrade/downgrade: `12:upgrade:2026-11-17T10:00:00Z` (the requested change instant)

A duplicate scheduler tick hits the unique constraint and is swallowed as a no-op. Silent duplicate invoicing is a P0 defect.

**INV-K2 — Fulfillment tasks.** `fulfillment_tasks.idempotency_key` is `{invoice_id}:{action}:{instance_id}` (unique). Re-dispatch never creates a second row.

**INV-K3 — Schedulers.** Every scheduled command uses `->withoutOverlapping()` and `->onOneServer()`. A cron without both is a defect.

**INV-K4 — Coupons.** Coupon application happens inside the invoice-creation transaction: `SELECT ... FOR UPDATE` the `coupons` row, re-check `used_count < total_quota`, `per_user_limit` against `coupon_claims`, `valid_from/until`, `claim_conditions`, then increment. `coupon_usages` is `unique(invoice_id)` — one coupon per invoice, double-counting impossible.

---

## 7. Refund and resource recovery (blocking)

**INV-R1 — Service precedes money or is explicitly waived.** `RefundInvoiceAction` refuses to run unless one of:
- the linked instance is `Cancelled`/`Deleted` (upstream termination confirmed), **or**
- an admin passes `keepService = true` with an explicit `actor` authorized by `Gate::forUser($actor)` for the invoice-scoped `refund-invoice` ability, and a non-empty `reason`. Persist `actor_id`, `action=invoice.refund`, `subject_type=Invoice::class`, `subject_id` and that supplied reason in `audit_logs`. Missing authority or reason is rejected, even when the invoice has no instance association; a generated reason is not consent.

A refund that leaves a running VM behind is a net loss and a P0 defect.

**INV-R2 — Both modes are ledgered.** `refund_wallet` → `credit_in` with key `refund:{invoice_id}`. `external_record` → `expense` with the same key. Refunds never silently adjust a balance.

**INV-R3 — Redemption codes are never reclaimed.** Once a card key has been delivered to a customer it is compromised; the code stays `sold`, the instance becomes `Cancelled`, and the loss is recorded as `expense`. Reclaiming a delivered plaintext key is not a recovery.

**INV-R4 — Commission clawback.** Refund transitions the commission to `clawed_back` and reverses `affiliate_balance`. If the balance was already withdrawn, the reversal makes `affiliate_balance` negative — which `UNSIGNED` forbids, so the amount must instead be recorded as a receivable (`affiliate_commissions.status = clawed_back` + a `financial_transactions` `affiliate_clawback` row) and the negative-effect rule must be resolved explicitly, never by clamping to zero.

---

## 8. Instance lifecycle and upstream binding

**INV-S1 — Status vocabulary.** `Pending → Active → Suspended → Cancelled|Deleted`, plus `ProvisionFailed` and `Fraud`. `Suspended` can only be entered from `Active`; `Active` from `Pending` or `Suspended`. Every transition is written to `audit_logs`.

**INV-S2 — Self-hosted vs upstream termination.** Self-hosted drivers (`redemption`, `proxmox`, `sub2api`) auto-terminate after `overdue_terminate_days`. Upstream-proxied (`zjmf_api`) stays `Suspended` until upstream reports `Deleted`. The rule is keyed on the driver capability, never on the string `zjmf_api`.

**INV-S3 — Hot swap never orphans silently.** Changing `driver_type`/`upstream_api_id` on an instance:
1. writes the previous binding to `instance_upstream_bindings` (`unbound_at`, `reason`),
2. requires an explicit admin decision for the old resource: `terminate` or `keep_orphan`,
3. on `keep_orphan` raises an admin alert and records the orphan,
4. marks every `pending`/`running` task for that instance `stale` in the same transaction.

**INV-S4 — Current binding uniqueness.** `instances` has `unique(upstream_api_id, upstream_instance_id)`. Two local instances may never point at the same upstream host.

**INV-S5 — Pre-sync before power state change.** `/host/header` is queried before `suspend`/`unsuspend`; if upstream already reports the target state, reconcile locally without calling `/provision/default`.

---

## 9. Fulfillment task lease and retry

**INV-T1 — Lease.** A task is claimed by setting `status=running, locked_by={worker_id}, locked_at=now(), lease_expires_at=now()+lease`. Lease must exceed the job timeout (`INV-Q1`).

**INV-T2 — Reclaim.** The 1-minute sweeper reclaims `pending` older than 60s **and** `running` with `lease_expires_at < now()`. Reclaim = `status=pending, attempts+1, available_at=now()+backoff`.

**INV-T3 — Terminal failure.** When `attempts >= max_attempts` (default 5) the task becomes `failed` and alerts; it is never retried automatically again. `RetryFulfillmentTaskResource` resets `attempts` explicitly.

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

---

## 12. Upstream ambiguity and reconciliation

**INV-U1 — Log every upstream call.** `upstream_operations` records `upstream_api_id, instance_id, invoice_id, method, path, request (redacted), http_status, response (truncated), duration_ms, correlation_key`. Secrets and passwords are masked before insert.

**INV-U2 — Ambiguous P4/P5.** If a `/cart/settle` or `/apply_credit` response is lost, reconciliation re-runs the P2 `/cart/clear` idempotency probe: `400 + domainstatus=Active` means already provisioned → adopt `hostid`; `200 + invoiceid` means the invoice exists → jump to P5. Never re-run P3 blindly.

**INV-U3 — Rollback is verified.** A failed `use_credit_limit` rollback (`use_credit=0`) that itself fails leaves "upstream paid, local unpaid". This must produce an admin alert plus an `upstream_operations` row; it is never retried silently.

---

## 13. Audit

**INV-A1 — What is mandatory.** `audit_logs` row for: any admin money mutation, manual invoice status change, refund, hot swap, binding change, balance adjustment, settings change of an encrypted key, coupon grant, KYC override, and user anonymization.

**INV-A2 — Shape.** `actor_id, action, subject_type, subject_id, before (json), after (json), reason, ip, request_id, created_at`. `reason` is required for every admin-initiated financial or lifecycle action.

---

## 14. PR checklist

Every PR touching money, lifecycle, or a driver must answer:

- [ ] INV-I2 identity asserted, and rounded exactly once (M3)?
- [ ] Wallet mutation locked (`FOR UPDATE` or conditional update) — INV-W3?
- [ ] `financial_transactions.reference_key` set, exactly-once — INV-W2?
- [ ] Callback branch has a row in the §4 table, and a Pest case?
- [ ] Lock order respected — INV-L1? No I/O under lock — INV-L3?
- [ ] Idempotency key defined for any new billable event — INV-K1..K2?
- [ ] Instance creation/termination point explicit — INV-P2 / INV-R1?
- [ ] Driver returns a DTO and mutates nothing — INV-L4?
- [ ] Timeouts follow INV-Q1? Scheduler has `withoutOverlapping` + `onOneServer` — INV-K3?
- [ ] Timeout/audit/upstream log emitted for new external calls — INV-U1?
