# Cloud Product Sales CMS — Agent Engineering Charter (`CLAUDE.md`)

> **Mandatory Read**: Every AI coding agent (Claude Code, DeepSeek Harness, etc.) MUST read this file, `docs/domain-invariants.md`, `docs/zjmf-api-spec.md`, and `docs/external-drivers-spec.md` before modifying any code.
>
> **Precedence**: this charter > `docs/domain-invariants.md` > the two driver specs. A driver spec describes a wire protocol; an invariant describes a rule that must hold regardless of protocol. When they disagree, the invariant wins and the spec is wrong.

## 0. Rule Registry (`F` numbers)

Rule IDs are stable references used across docs, code comments and tests. Do not renumber.

| ID | Rule | Where enforced |
|----|------|----------------|
| F01 | Identity & financial audit retention | §3.1, `audit_logs`, `restrictOnDelete` |
| F02 | Reliable transactional handoff / outbox | §3.2, `fulfillment_tasks.idempotency_key` |
| F03 | Money as fixed-point decimal strings | §3.3, `App\Support\Money`, INV-M1..M4 |
| F04 | Combo payment & callback edge cases | §3.3, INV-I1..I5, INV-P1..P3, INV-K5 |
| F05 | Magic financial (`zjmf_api`) order chain | §3.4, `docs/zjmf-api-spec.md` |
| F06 | Overdue lifecycle, refunds, coupons, affiliates | §3.5, INV-R1..R4, INV-K1..K4 |
| F07 | Dynamic settings & credential ownership | §3.6, F09 in the drivers spec |
| F08 | PVE & notification fan-out | §3.7 |
| F09 | Credential ownership split (settings vs `upstream_apis`) | §3.6, drivers spec header |
| F10 | Driver purity & capability resolution | §2, INV-L4 |

## 1. Tech Stack & Locked Packages

- **Runtime & Core**: PHP 8.3+, Laravel 13, MySQL 8.0+, Redis
- **Current Admin & Client Console** (delivery-state clarification, FIX-017, 2026-11-18): Filament v5 (`filament/filament:^5.0`) at `/admin`; the delivered `/console` customer portal uses Blade. The original Multi-Panel client was a phase target, not a delivered feature. This records existing implementation; a client-panel migration is separate work.
- **RBAC**: `bezhansalleh/filament-shield:^4.3` + `spatie/laravel-permission:^8.0`
  - Shield 4.3 requires `spatie/laravel-permission ^6|^7|^8`; Laravel 13 is only supported by `^8.0`, so the constraint is pinned there.
- **Storefront & CMS (SEO + GEO)**: Laravel Blade + Livewire v4 SSR (Phase 3)
  - SEO routes: `/store/{category:slug}/{product:slug}`, `/news/{category:slug}/{article:slug}`, `/help/{category:slug}/{article:slug}` + auto `sitemap.xml`
  - GEO support: `/llms.txt` + `.md` suffix / `Accept: text/markdown` middleware
  - `cms_articles` carries **no** `type` column: type is a property of the category, and the route prefix comes from the category. Article slugs are unique per category, matching the URL.
- **Multi-Channel Auth (Phase 3)**: `laravel/socialite` + `socialiteproviders/wechat-web`, `qq`, `telegram`, GitHub, Google via `user_oauth_accounts`
- **Payments**: `EpayGateway` (Phase 1: `epay_wechat`, `epay_alipay`) + `yansongda/laravel-pay` (Phase 3); `PaymentGatewayInterface` extensible for USDT
- **PVE SDK (Phase 3)**: `corsinvest/cv4pve-api-php:^9.0`
- **HTML Sanitizer (Phase 2)**: `ezyang/htmlpurifier`
- **QA Gates**: Pest (`./vendor/bin/pest`) + Larastan (`./vendor/bin/phpstan analyse`)
  - Test stack is **Pest 4** (`pestphp/pest:^4.0` + `pest-plugin-laravel:^4.1`). `pest-plugin-laravel ^5` demands PHP 8.4 + Laravel ≥ 13.23; adopting it is a separate, deliberate upgrade.
- **Autoloading**: strict PSR-4, **one class/interface/enum per file**. `composer.json` MUST NOT use `autoload.files`.

## 2. Directory Isolation & Dependency Rules

Allowed dependency direction: **`Entry (Controller/Resource) -> Actions -> Contracts/DriverManager -> Drivers`**.

- `app/Contracts/` — Segregated capability contracts, one file each:
  `FulfillmentDriver`, `RenewableResource`, `ConfigurableResource`, `SyncableCatalog`, `LiveQuotable`, `UpstreamAccount`, `PowerControllable`, `HasCustomPanelWidgets`, `LocalPriced`, `LocalInventory`, `KycDriverInterface`, `PaymentGatewayInterface`, `BotDriverInterface`, `SmsDriverInterface`, `MailDriverInterface`
- `app/Data/` — DTOs, one file each: `ProvisionResult`, `ProvisionOutcome`, `QuoteResult`, `SyncCatalogResult`, `KycInitiateResult`, `KycStatusResult` (human-approved C15), `GatewayChargeResult`, `VerifiedPaymentResult`, `InstanceInfoResult`
- `app/Enums/` — `BillingCycle` (18 keys), `InstanceStatus`, `InvoiceStatus`, `InvoiceType`, `FulfillmentTaskStatus`, `NotificationChannel`, `TransactionType`, `DriverType`, `ProvisionCommand` (12 `func`s), `PaymentCallbackOutcome`, `CmsArticleType`, `CmsContentFormat`, `DownloadAccessLevel`
- `app/Support/` — `Money` (the only sanctioned arithmetic on money)
- `app/Drivers/Upstream/` — `ZjmfFinanceDriver` (classic `zjmf_api`; V10 deferred)
- `app/Drivers/SelfHosted/` — `RedemptionCodeDriver` (`onetime`), `ProxmoxDriver`, `Sub2ApiDriver` (`onetime`)
- `app/Drivers/Kyc/` — `ZjmfLegacyKycDriver`
- `app/Drivers/Notification/` — `TelegramBotDriver`, `LogSmsDriver`, `LogMailDriver`
- `app/Drivers/Payment/` — `EpayGateway`, `YansongdaGateway`
- `app/Actions/` — Transactional business actions (`CreateComboInvoiceAction`, `InitiatePaymentAction`, `HandlePaymentCallbackAction`, `ProvisionInstanceAction`, `SuspendInstanceAction`, `ExpireUnpaidInvoicesAction`, `DispatchStalePendingTasksAction`, `UpgradeCalculatorAction`, `DowngradeCalculatorAction`, `RefundInvoiceAction`, `RetryFulfillmentTaskAction`, `HotSwapInstanceDriverAction`, `AccrueAffiliateCommissionAction`, `MatureAffiliateCommissionsAction`)
- `app/Jobs/` — Queue jobs only (`ExecuteFulfillmentTaskJob`, `SendNotificationJob`, `ReconcileUpstreamOrderJob`, `ReconcileHostSyncJob`)
- `app/Console/Commands/` — Scheduled/batch entry points (`DispatchStalePendingTasksCommand`, `ProcessInstanceLifecycleCommand`, `ReapRedemptionReservationsCommand`). A command is a thin wrapper: it resolves and calls the matching `*Action`, so the logic stays unit-testable without booting the console kernel.

**Strict Boundaries**:

- Never branch on driver string names in Controllers, Resources, or Actions. Resolve via `DriverManager` using `instances.driver_type` + `instances.upstream_api_id` and inspect capability interfaces (`$driver instanceof RenewableResource`). `DriverType` is the single registry of driver identifiers.
- On callbacks, payment adapters ONLY verify signatures/protocol and return `VerifiedPaymentResult`; `HandlePaymentCallbackAction` owns the callback transaction, `payment_callbacks` deduplication, `frozen_credit` settlement, and task creation. On charge initiation, `InitiatePaymentAction` creates and commits the payment attempt before calling the adapter outside the transaction. Adapters never mutate models.
- A scheduled/batch process is a **Command**, never a class in `app/Actions/` named `*Job`. A per-unit queued unit of work is a **Job**, never `*Action`.
- Drivers return DTOs and never mutate models (F10 / INV-L4). Every model mutation happens in an Action.
- Model attributes cast to enums (invoice/instance/task status, callback outcome, driver type and billing cycle) return enum cases, including through Eloquent `value()`. Strict model assertions compare enum cases; serialized API values and raw database assertions use their string values. Monetary model attributes return two-place decimal strings via `decimal:2`; business arithmetic remains exclusively in `Money`.

## 3. Core Business & Architecture Invariants

### 3.1 Identity & Financial Audit Retention (F01)

- Users with financial, fulfillment, or affiliate records MUST NEVER be physically deleted. All financial/audit foreign keys use `restrictOnDelete()`: `invoices`, `payment_callbacks`, `payment_attempts`, `fulfillment_tasks`, `coupon_usages`, `coupon_claims`, `affiliate_commissions`, `affiliate_withdrawals`, `financial_transactions`, `audit_logs`, `upstream_operations`, `instances`.
- Account closure uses `SoftDeletes` + PII anonymization (`anonymized_at`), preserving all audit & idempotency trails.
  Approved N01/N02 additionally allow evidence-backed manual wallet clearance and owner-confirmed one-time entitlement archives. Archives preserve Active/sold delivery facts and only exempt their matching completed one-time service from the termination blocker; all other closure blockers stay enforced. Closed-user clearance uses the original user and verified credit source, encrypted rights evidence and shared unique external payout receipts. See `docs/domain-invariants.md` §5 for amounts, states, ledger references and source limits.
- `financial_transactions` is append-only and **must** be written for every balance movement, with a unique `reference_key` (INV-W2). It is never updated or deleted.
- Every admin-initiated financial or lifecycle action writes an `audit_logs` row with a non-empty `reason` (INV-A1, INV-A2).

### 3.2 Reliable Transactional Handoff / Outbox (F02)

- Marking `invoices.status = 'Paid'` and inserting `fulfillment_tasks` (`status = 'pending'`, unique `idempotency_key`) MUST happen inside the **same `DB::transaction()`**.
- Queue jobs MUST be dispatched strictly via `DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($task->id))`.
- A 1-minute scheduler (`DispatchStalePendingTasksCommand`, `withoutOverlapping()` + `onOneServer()`) reclaims:
  - `pending` tasks older than 60s, and
  - `running` tasks whose `lease_expires_at` has passed (INV-T1..T3).
  Human-approved C21: `attempts` counts successful worker claims, incremented atomically with each lease. Due `pending` tasks are only re-dispatched, without incrementing attempts or postponing `available_at`; expired `running` tasks release their lease and back off using the already-consumed count. Results and recovery never increment again. At most `max_attempts` claims (default 5) are allowed: the final claim may succeed; failure or lease expiry at that limit becomes `failed` and alerts. Retries dispatch after commit, with the sweeper compensating lost delivery. Existing upstream ambiguity guards and audited manual reset remain mandatory; no historical task counts are reset automatically. See `docs/backend-remediation-decisions.md` C21.

### 3.3 Money, Combo Payment & Callback Edge Cases (F03, F04)

- Represent money as fixed-point decimal strings (`"10.00"`) with `bcmath` through `App\Support\Money`. NEVER use `float`, and never `number_format()` under `strict_types=1`. Every monetary column is `DECIMAL(12,2) UNSIGNED`; direction lives in `financial_transactions.type`, never in a sign.
- **Current currency boundary (human-approved C20)**: wallets, affiliate balances and Epay settle only CNY, including zero-amount purchases. Non-CNY catalogs remain valid references, but purchases/renewals/upgrades are blocked before money changes. Historical non-CNY callbacks are verified, deduplicated, recorded, alerted and ACKed after commit as `rejected_unsupported_currency`; they do not settle or credit the CNY wallet. Automatic refunds/expiry unfreezes also wait for manual reconciliation. No FX or blanket historical balance repair; see `docs/backend-remediation-decisions.md`.
- **Canonical invoice computation** (INV-I1), computed once in `CreateComboInvoiceAction` and stored:
  ```
  subtotal       = Σ(unit_price × qty)
  discount       = coupon(subtotal)
  total_amount   = subtotal - discount
  credit_applied = min(requested_credit, total_amount, available_credit)
  gateway_amount = total_amount - credit_applied
  ```
  Before persisting, assert `credit_applied + gateway_amount === total_amount`. `discount_amount` is already inside `total_amount` and is never subtracted twice.
  Human-approved review decision A permits one owner-confirmed wallet/gateway allocation for a newly marked automatic Unpaid renewal before any payment attempt, callback or financial ledger. Preserve total/quote/coupon/boundary, revalidate binding under ordered locks, freeze once and keep pure-wallet Paid+task atomic. Confirm before payment initiation; no historical inference or later editing. See `docs/backend-review-fix-decisions.md` and INV-I1.
- **Purchase idempotency (approved C22, INV-K5)**: New purchases require a stable ULID in `input.request_id`; persist `purchase_request_id` with `unique(user_id, purchase_request_id)`. Compare encrypted canonical caller intent before validation/quotation and again after the user lock; same intent returns the original invoice, changed intent rejects, terminal invoices never reopen. Rotate `line_items.purchase.encrypted_request` with other durable secrets. Historical and non-purchase keys remain NULL; existing database migration requires separate deployment authorization.
- **Purchase inputs** (C22 / INV-K5): `CreateComboInvoiceAction` receives the user, product, `BillingCycle`, selected options and wallet/gateway choices, plus a stable ULID `input.request_id` for every new purchase. A user-scoped unique `purchase_request_id` and encrypted versioned request identity make retries return the original invoice; changed intent under the same key is rejected before side effects. Check before current validation/quotation and again after the user lock. Terminal invoices never reopen; explicit new purchases use new keys. Include the identity in key rotation. Product, cycle and configuration determine a trusted server-side quote; a client-provided `totalAmount` is never the price authority. The new-purchase invoice always stores `product_id`; only `instance_id` may be absent before settlement. Human-approved C22 requires a stable caller ULID `input.request_id`, persisted as `purchase_request_id` with `unique(user_id, purchase_request_id)` (INV-K5). Compare encrypted, versioned canonical caller intent before revalidation/quotation, then recheck after locking the user inside the invoice transaction. Same intent returns the original invoice even when terminal; changed intent rejects, and a new purchase requires a new key. Rotate `line_items.purchase.encrypted_request` with the other durable ciphertexts.
- **Zero-amount (`0.00`) / Pure Wallet**: skip gateway, commit `Paid` + `credit_settle` + `instances` + `fulfillment_tasks` atomically.
- **Combo payment**: Freeze `credit_applied` into `users.frozen_credit` on invoice creation, in the same transaction, under `SELECT ... FOR UPDATE` on the `users` row; charge ONLY `total_amount - credit_applied` to gateway; on `Paid` record `credit_settle` (frozen consumed) exactly once; on expiry/cancel record `credit_unfreeze` exactly once.
- **Wallet concurrency** (INV-W3): no balance mutation may read-then-write without a lock. Use `lockForUpdate()` or a conditional `UPDATE ... WHERE credit >= ?` with an affected-rows assertion. Global lock order is `users → invoices → payment_attempts → instances → redemption_codes → fulfillment_tasks` (INV-L1).
  Human-approved N05 additionally checks complete final wallet capacity across `credit`, invoice `frozen_credit` and independent `wallet_clearance_frozen`, with every stored intermediate column in range. Historical excess is not adjusted; legitimate non-increasing transitions remain possible. Unpostable verified receipts retain callback/attention evidence without partial settlement. See `docs/system-architecture-remediation-plan-2026-11-18.md`.
- **Duplicate & Late Webhooks**: `HandlePaymentCallbackAction` inserts `(gateway, gateway_trade_no)` into `payment_callbacks` (`unique`) and locks `invoices` (`lockForUpdate()`), following the decision table in `docs/domain-invariants.md` §4. Every branch has a `PaymentCallbackOutcome`. Amounts are always compared against `gateway_amount`, never `total_amount` (INV-P1).
  - Duplicate callbacks → `ignored_duplicate`, idempotent `ackBody`.
  - Late callbacks on `Expired`/`Cancelled` invoices NEVER provision; they credit `paidAmount` to `users.credit` (`credit_in`) once and alert.
  - Overpayment credits the difference; short payment rejects and leaves the invoice `Unpaid`.
  - Unknown invoice numbers are recorded with `invoice_id = NULL` rather than throwing (which would make the gateway retry forever).
- **Payment attempts**: `InitiatePaymentAction` mints a fresh `out_trade_no`, stores a `payment_attempts` row and commits it before invoking `charge($invoice, $amount, $outTradeNo)`. The adapter uses that exact number and returns it in `GatewayChargeResult`; it never creates the row. Failed or ambiguous initiation preserves the attempt for diagnosis; an explicit retry creates a new attempt. `invoices.gateway_trade_no` is a support mirror and carries **no** unique index; `payment_callbacks` is the dedupe authority. Callback resolution matches `(gateway, out_trade_no)` exactly, then the complete `invoice_no`; it never guesses or strips a suffix.
- **Card Key Persistence & Retry**: Encrypt `redemption_codes.code` (`encrypted` cast) and `instances.encrypted_credentials` (`encrypted:array` cast). Because the `encrypted` cast is randomized, deduplication uses the deterministic `redemption_codes.code_hash` (HMAC-SHA256, unique per product). `ProvisionInstanceAction` owns reservation: inside `DB::transaction()`, **lock `instances` first (`lockForUpdate()`)**, reuse its already-bound code, or claim an available code under a row lock and set `instance_id`, `status=reserved`, and `reserved_until` together. After commit, the pure `RedemptionCodeDriver` reads that binding and returns a DTO. The Action revalidates the reservation under locks, then atomically writes `sold` and encrypted instance credentials; delivery is visible only after commit. Expired reservations can be released only if delivery has not completed. A sold code is never reclaimed (INV-C2, INV-C3, INV-R3).

### 3.4 Magic Financial (`zjmf_api` Only — Phase 2) (F05)

- ALL requests use `application/x-www-form-urlencoded` (`Http::asForm()`). Cache JWT `6900s` (`Bearer` | `raw_jwt` fallback); health check via `GET /cart/credit`.
- **P1->P6 Order Chain**: `GET /user_info` -> `POST /cart/clear` -> `POST /cart/add_to_shop` (send BOTH `currencyid` & `currecncyid`) -> `POST /cart/settle` (`1001`=done, `200`=`invoiceid`) -> `POST /apply_credit` (`use_credit=1`, `1001`=paid; fallback `/apply_credit_limit` or rollback `use_credit=0`).
- Every request and response is recorded in `upstream_operations` with secrets redacted (INV-U1). Ambiguous P4/P5 outcomes are reconciled by re-running the P2 `/cart/clear` probe, never by blindly re-running P3 (INV-U2).
- **Operations (`/provision/default`)**: `id={dcimid}, is_api=1, func={cmd}`. The 12 commands are the `ProvisionCommand` enum; never pass a raw string. Success: `$res['status'] === 'success' || $res['status'] === 200`.
- **Terminate / Renew / Upgrade**: `POST /host/cancel` (`type=Immediate`); `POST /host/renew` + `/apply_credit`; `/upgrade/upgrade_config_post` -> `/upgrade/checkout_config_upgrade` + `/apply_credit`.
- **Cycle price columns (human-approved C19)**: the cycle field (`monthly`, etc.) is its **period price**, read through `BillingCycle::priceKey()`. The paired `*setupfee` field is an independent one-time setup fee, read through `setupFeeKey()`. Preserve both in catalog and quote snapshots; exclude setup from `recurring_amount`. Live `total` includes setup; discounted `sale_price` excludes `sale_setupfee_total`. See `docs/classic-zjmf-live-acceptance.md` for real-supplier evidence and the approved correction.
- **Hot-Swap (`InstanceResource`)**: Existing instances resolve driver & account from `instances.driver_type` + `instances.upstream_api_id` (NEVER by mutating shared product rows). Rebinding requires explicit admin confirmation, writes the previous binding to `instance_upstream_bindings`, requires an explicit disposition for the old resource (`terminate` | `keep_orphan` + alert), and marks pending/running tasks `stale` in the same transaction (INV-S3). `instances` enforces `unique(upstream_api_id, upstream_instance_id)` (INV-S4).
  Human-approved review decision B adds only an administrator-confirmed terminate rebinding from Deleted to a verified new Active/Suspended host; reject refunded/unresolved services, reauthenticate old Deleted and both bindings/accounts, and audit the exception. Normal status transitions remain terminal. See `docs/backend-review-fix-decisions.md` and INV-S1.
- **Callback `/api/host/sync`**: Verify 3-field MD5 sig (sort array first, NEVER pass `ksort()` bool to `json_encode()`), deduplicate `rand_str` in Redis under the key `host_sync:{instance_id}:{rand_str}` (`SET NX EX 600`), then respond immediately and reconcile asynchronously (INV-Q3, INV-Q4). NEVER overwrite `suspend_reason` with an empty value.

### 3.5 Overdue Lifecycle, Refunds, Coupons & Affiliates (F06)

- **Overdue**: Scheduler targets `RenewableResource` drivers ONLY (skips `onetime`). T-7 days generate a renewal invoice (`billing_period_key` = `{instance_id}:renew:{due_boundary_utc}`, human-approved C14; short-cycle reminder windows are capped to one period) & notify; T-0 pre-sync `/host/header` before `suspend`/`unsuspend`. Self-hosted auto-`terminate` after `overdue_terminate_days`; upstream-proxied stays `Suspended` until upstream reports `Deleted`. The split is decided by `DriverType::isSelfHosted()`, never by a driver string comparison.
- **Duplicate renewal/upgrade invoicing** is prevented by the unique `billing_period_key`, not by scheduler luck (INV-K1). Human-approved C23 keeps that key on one renewal root and permits an explicit owner-confirmed recovery of an actually Expired, never-settled current tip through nullable `renewal_root_id` and unique `replaces_invoice_id`. Successors keep NULL billing/purchase keys. Re-quote and confirm the price and wallet/coupon allocation; reject changed binding/boundary or unresolved operations, verify original unfreeze evidence, and serialize the chain after the user lock. Only the current tip may settle; old late payments remain wallet-only. Retain encrypted replay identity through key rotation and keep operator recovery inspection read-only. See `docs/backend-remediation-decisions.md` C23; existing database migration and real supplier renewal are separately authorized deployment/acceptance actions.
- **Downgrade & Refund**: Downgrade reduces future `recurring_amount` only (zero refund, bounds in `downgrade_bounds`) and is computed by `DowngradeCalculatorAction`; it never calls the upstream `upgrade` chain. `RefundInvoiceAction` refuses to run until the instance is `Cancelled`/`Deleted`, unless an admin explicitly passes `keepService` with a non-empty `reason` and an explicit `actor`, authorized through the invoice-scoped `refund-invoice` Gate ability. The Action records `actor_id`, `action=invoice.refund`, the invoice subject and the supplied reason in `audit_logs` (INV-R1, INV-A1, INV-A2). Refund supports `refund_wallet` (`credit_in`) or `external_record` (`expense`); both claw back frozen affiliate commissions. A delivered redemption code is never reclaimed: it stays `sold` and the loss is recorded as `expense` (INV-R3).
- **Coupons**: `coupon_claims` records the claim act; `coupon_usages` is `unique(invoice_id)`. Application locks the coupon row and re-checks quota/limits inside the invoice transaction (INV-K4).
- **Affiliates**: Independent `AffiliateRuleResource`; single-tier (`A -> B`), default 7-day freeze. `AccrueAffiliateCommissionAction` writes `pending`; `MatureAffiliateCommissionsAction` (scheduled) promotes `pending -> available` at `available_at`. `affiliate_withdrawals` supports `wallet` or `external`, and each payout writes a `financial_transactions` row (`affiliate_payout`), so commissions appear in the ledger.

### 3.6 Dynamic Settings, Credential Ownership & Web Installer (`/install`) (F07, F09)

- **Minimal `.env`**: Holds ONLY bootstrap vars (`APP_KEY`, `APP_URL`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE`).
- **Credential Ownership**: Global singletons (`payment.*`, `telegram.*`, `mail.*`, `sms.*`, `oauth.*`, `kyc.*`) live in `system_settings` (`Crypt::encryptString()`, cached in Redis by `SettingService`). Multi-account resource credentials live in `upstream_apis`.
  Epay preserves encrypted immutable settings profiles per payment attempt. Configuration changes and payment selection use a configuration metadata lock before financial row locks, with fresh profile reads from `system_settings`; callbacks select history only through an exact attempt mapping, then the pure adapter verifies it. This prevents worker caches or merchant replacement from changing in-flight payment credentials. See `docs/backend-review-payment-affiliate-fixes.md`.
- **Cache holds ciphertext-equivalent secrets**: `SettingService` caches values *after decryption in process memory only*, never writes decrypted secrets to a shared Redis key. If a Redis cache entry is unavoidable it stores the encrypted blob.
- **`APP_KEY` is immutable after install.** Rotating it makes `system_settings`, `upstream_apis`, `redemption_codes.code` and `instances.encrypted_credentials` undecryptable. Provide `php artisan app:reencrypt-secrets --old-key=...` before any rotation, and refuse to boot with a mismatched key rather than failing lazily mid-order.
- **Pre-Install Bypass & `/up`**: `SettingService` and `AppServiceProvider` MUST check `file_exists(storage_path('installed.lock'))` before touching DB/Redis. `EnsureInstalledMiddleware` MUST exempt `/up` and `/install*` from install redirects; once `installed.lock` exists, `/install*` returns `404`.

### 3.7 Phase 3 PVE & Notification Rules (F08)

- **PVE (Phase 3)**: Before implementing `ProxmoxDriver`, add `pve_nodes` + `pve_ip_allocations` (atomic `lockForUpdate()` IP/VMID reservation, UPID polling, and compensating release on timeout/failure).
- **Notifications**: `NotificationManager` fans out events to ALL verified `user_notification_bindings` via per-channel queue jobs (`mail`, `telegram`, `sms`). If a user has no verified binding for a channel, the manager falls back to the account email for `mail` and records the fallback in `notification_logs`; it never silently drops a billing or overdue notification.
- **Failure isolation**: a failing channel does not cancel the others; each channel's result is written to `notification_logs` (`sent` / `failed` + `error_message`).

## 4. Exact JSON Column Schemas

**Approved D01–D05 extensions (2026-11-18):** `docs/remaining-capabilities-decisions-2026-11-18.md` and its four linked module proposals define the human-approved scope for wallet top-ups (no product/fulfillment; Paid + wallet credit + durable event atomic), owner account-contact anonymization with retained financial identity, frozen manual external affiliate payouts, append-only receivable settlement/reversal, and notification event/delivery outbox. These are targeted additions to F01/F02/F04/F06/F08, not approval to weaken existing product settlement, audit, driver purity or money rules. Preserve CNY-only settlement, transaction-free external I/O, old strict assertions and existing financial history.

Every schema below is normative. Additions are allowed; renaming or removing a key is a breaking change requiring a migration.

- `products.provisioning_rules`:
  ```json
  {"hostname_mode":"upstream_auto","hostname_prefix":"ecs-","hostname_regex":"^[a-z0-9-]{4,32}$","password_min_len":12,"password_max_len":20,"password_regex":"..."}
  ```
  `hostname_regex` and `password_regex` are stored **without delimiters** and evaluated as `'/'.str_replace('/', '\/', $re).'/'`. They are validated with `@preg_match()` on save, so an invalid pattern can never reach checkout. When upstream returns a `password_rule` in `/host/header`, the effective rule is the **intersection** of local and upstream constraints; a value that satisfies only one of them is invalid.

- `products.upstream_base_prices` / `products.local_prices` / `product_option_items.*_prices` — keyed by `BillingCycle` value, each an object with `price`, `setup_fee`, `enabled`:
  ```json
  {"monthly":{"price":"10.00","setup_fee":"0.00","enabled":true},
   "annually":{"price":"100.00","setup_fee":"0.00","enabled":true}}
  ```
  A missing key or `enabled:false` means the cycle is not offered. `local_prices` is authoritative when the driver implements `LocalPriced`; otherwise `upstream_base_prices` is marked up by `markup_percent` and rounded once (INV-M3).

- `product_option_groups.downgrade_bounds`:
  ```json
  {"allow_downgrade":true,"min_value":1,"allowed_item_ids":[101,102]}
  ```

- `instances.active_config` — explicit local/upstream id pairing plus currency, because upgrade math and `configoption{}` assembly need both:
  ```json
  {"billing_cycle":"monthly","currency":"CNY",
   "options":[{"local_group_id":12,"local_item_id":101,"upstream_group_id":50,"upstream_sub_id":501,
               "label":"Bandwidth","value":20,"qty":1,"unit_price":"5.00"}]}
  ```
  `value` is the numeric quantity for `slider`/`quantity` groups and the chosen `local_item_id` for `select`/`toggle` groups.

- `coupons.claim_conditions`:
  ```json
  {"registered_days_gt":0,"registered_days_lt":null,"require_kyc":false,"require_bound_channels":["telegram"]}
  ```

- `notification_templates.variable_mapping`:
  ```json
  {"username":"name","instance_name":"product","due_date":"time"}
  ```

- `invoices.line_items` — see `docs/domain-invariants.md` INV-I5. `subtotal`/`discount`/`total` are the stored audit snapshot of INV-I1 and must reconcile with the invoice columns.

## 5. Phased Execution Runbook & Cumulative Pest Gates

**Delivery-state clarification (FIX-017, 2026-11-18):** the Phase 1–3 entries below retain the original work plan and target class names. An empty Specs suite proves only those executable specifications. It does not certify every extension, a production deployment, or real supplier acceptance. Current delivery, isolated/live evidence and deferred work are indexed by `docs/release-0.1.0-rc.1.md` and `docs/backend-fix007-017-verification.md`. F01–F10 and all normative financial/driver rules still apply.

0. **Phase 0 (Baseline)** — **COMPLETE**. Composer `>= 2.2`, Laravel 13 skeleton, Git init, `SettingService` (pre-install bypass), `/install` + `EnsureInstalledMiddleware` (`/up` exempt), core enums/DTOs/contracts, core migration (34 tables), `Money`, `audit_logs`, models and factories for the Phase 1 closed loop. The queue tables (`jobs`, `failed_jobs`, `job_batches`) and cache tables come from the skeleton's own `0001_01_01_*` migrations and MUST NOT be recreated in the core migration. See `docs/phase0-bootstrap.md`. Gate: `./vendor/bin/pest --group=phase0` & `./vendor/bin/phpstan analyse` (level 5).
1. **Phase 1 (First E2E Closed Loop)**: Auth + RBAC -> Local Product & Quote -> Combo Invoice & Freeze -> `EpayGateway` + `payment_attempts` + `HandlePaymentCallbackAction` + Outbox -> `RedemptionCodeDriver` -> `/console` -> `FulfillmentTaskResource` retry + `TelegramBotDriver` failure alerts + `ProcessInstanceLifecycleCommand` scheduler skeleton. Gate: `./vendor/bin/pest` (all specs for Phase 1 moved to `tests/Feature`).
2. **Phase 2 (Classic ZJMF)**: `ZjmfFinanceDriver`, tiered sync, `upstream_operations` logging, `/api/host/sync`, `InstanceResource` hot-swap (with binding history) & `/provision/default` via `ProvisionCommand`. Gate: `./vendor/bin/pest`.
3. **Phase 3 (Extensions & Growth)**: `ProxmoxDriver`, `Sub2ApiDriver`, `ZjmfLegacyKycDriver`, `YansongdaGateway`, `user_oauth_accounts`, `Coupons` + `coupon_claims`, `AffiliateRuleResource` + `MatureAffiliateCommissionsAction`, `Tickets`, SSR Storefront + CMS/GEO. Gate: full `./vendor/bin/pest` with `tests/Specs` empty.

**Two test modes — read this before running anything:**

| Command | Meaning |
|---|---|
| `./vendor/bin/pest` | The only gate. Must be green. Red here is a real regression. |
| `./vendor/bin/pest --group=spec` | Pending specifications. Red by design until implemented. |
| `./vendor/bin/phpstan analyse` | Must be 0 errors, no ignores. |
| `./vendor/bin/pint --test` | Must be clean. |

`tests/Specs/FulfillmentSpecsTest.php` holds the unimplemented cases and is excluded from the default run by the `spec` group. `SpecIntegrityTest` asserts its exact case count, so a case cannot be deleted or skipped — only implemented and moved.

**Gate discipline**: a phase gate is only meaningful if the tests are runnable. The repository MUST contain a working skeleton (`artisan`, `bootstrap/app.php`, `config/`, `routes/`, `vendor/`) before Phase 0 is declared complete. A test file referencing classes that do not exist is a specification, not a gate. Working rules for agents are in `docs/agent-guardrails.md`.

## 6. Definition of Done

A change is done when: `php -l` passes, `pint --test` passes, `phpstan analyse` passes at the configured level, every affected invariant in `docs/domain-invariants.md` has a Pest case, and the PR checklist in that file is answered.
