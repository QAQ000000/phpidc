# Magic Financial (`zjmf_api` 3.7.6 Classic) — Integration Spec

> Scope: Classic ZJMF Finance (`zjmf_finance_api.type = zjmf_api`, `is_resource = 0`). **V10 (`console/v1/*`) is excluded.**
>
> **This file describes the wire protocol only.** Rules about money, state and concurrency live in `docs/domain-invariants.md`. Where the two disagree, the invariant wins and this file is corrected.
> This is the target protocol, not a declaration that every workflow is implemented. Current delivery scope and verification limits are in `docs/classic-zjmf-integration.md`; command names come from `App\Enums\ProvisionCommand`.

## 1. Transport & Auth

- **Encoding**: ALL `GET`, `POST`, `PUT`, `DELETE` requests MUST use `application/x-www-form-urlencoded` (`Http::asForm()`). NEVER send a JSON body.
- **HTTP Layer**: Only parse JSON when HTTP status is `200`. Non-200 or cURL error returns `{status: 400}`. Timeout defaults to `30s` (`5s` for `cart/ontrialmax`). These values must satisfy INV-Q1: `upstream timeout (30s) < job timeout (180s) < queue retry_after (240s)`.
- **Login**: `POST {host}/zjmf_api_login`
  - Body: `username` (email if it contains `@`, else phone; 4–20 chars), `password` (Client Center `api_password`, NOT the login password).
  - Success: `{"status":200,"jwt":"...","msg":"鉴权成功"}` (upstream TTL `7200s`). Cache locally for `6900s` (soft expiry).
  - Header: `Authorization: Bearer {jwt}` (configurable `raw_jwt` fallback if the upstream has the 3.7.6 `Check.php:106` `list()=explode` bug).
  - Re-auth trigger: business `status === 405` -> force-refresh the JWT once and replay once. A second `405` ends this attempt; later task retries follow INV-T4.
- **Health & Balance**: `GET {host}/cart/credit` -> returns `data.credit` (string) & `data.currency{id,code,prefix,suffix}`. (Avoid `/cart/summary` — it leaks a plaintext key and has a `bcdiv` div-by-zero bug.)
- **Logging**: every request/response pair is written to `upstream_operations` with credentials, passwords and JWTs redacted (INV-U1). The JWT itself is never logged.

## 2. Catalog, Stock & Live Pricing

- **Catalog**: `GET {host}/cart/all` -> `data.products[]` (groups -> products with `id, type, name, description`), `count`, `currency` (real-time, no TTL cache).
- **Lightweight Stock & Version Probe**: `GET {host}/api/product/proinfo?pids[]=...` -> `data.info[]{id, name, location_version, stock_control, qty}`, `data.currency`. Compare `location_version` to decide whether a full sync is needed.
- **Full Product Config**: `GET {host}/cart/get_product_config?pid={id}`
  - Returns `data` containing `flag`, `products`, `customfields[]`, `product_pricings[]`, `config_groups[].options[].sub[].pricings[]`, `advanced[]`, `config_links[]`.
  - Classic first compatibility batch / approved S1 preserves the nonnegative integer `products.p_uid` as `provisioning_rules.classic_catalog.product_publisher_uid`. The reference upstream requires a bound upstream host for developer applications (`p_uid > 0`); current new sales refuse these applications, unknown publisher snapshots and all `ontrial` cycles before payment. Existing paid fulfillment and purchase-request replay retain their original inputs. No arbitrary `server_group` or product-name heuristic proves support; see `classic-compatibility-batch-one-plan-2026-11-19.md`.
  - Quantity `qty_stage`, when supplied, follows the reference theme's absolute multiple or min/max endpoint rule (`value % stage == 0 || value == min || value == max`); zero/one impose no additional step. This is the verified frontend convention, not a claim that every upstream backend enforces it. Missing/null `qty_stage` retains the existing `qty_step` offset convention. Numeric advanced bounds include `[0,0]` and are interpreted using the configuration type, never boundary truthiness. Tiered period/setup pricing is unchanged.
  - Before a host exists, read `data.products.password` (JSON with `show` and `rule.upper/lower/num/special`) and `data.products.host` (JSON with `show` and `rule.len_num`). When password entry is enabled, enforce 6–20 bytes plus the declared character rules alongside local provisioning rules. Missing/unreadable password rules reject checkout before any wallet mutation. Re-check upstream rules before P3.
  - **`billing_cycle` (18 keys)**: `free, onetime, hour, day, ontrial, monthly, quarterly, semiannually, annually, biennially, triennially, fourly, fively, sixly, sevenly, eightly, ninely, tenly`. Mirrored by `App\Enums\BillingCycle`.
  - **`price_type` (17 pairs)**: `onetime/osetupfee`, `hour/hsetupfee`, `day/dsetupfee`, `ontrial/ontrialfee`, `monthly/msetupfee`, `quarterly/qsetupfee`, `semiannually/ssetupfee`, `annually/asetupfee`, `biennially/bsetupfee`, `triennially/tsetupfee`, `fourly/foursetupfee`, `fively/fivesetupfee`, `sixly/sixsetupfee`, `sevenly/sevensetupfee`, `eightly/eightsetupfee`, `ninely/ninesetupfee`, `tenly/tensetupfee`. (`-1` = disabled.)
  - **Semantics (human-approved C19)**: the first field in each pair is the period price (`BillingCycle::priceKey()`), the second is the independent one-time setup fee (`setupFeeKey()`). Catalog snapshots preserve both; a negative period price disables the cycle. An enabled cycle must have an explicit, nonnegative setup fee. `QuoteResult.downstreamPrice` excludes setup; `setupFee` carries the marked-up setup charge; `upstreamBaseCost` includes the full upstream cost. Setup never enters instance `recurring_amount`.
  - Upstream `day` / `hour` `pay_type` maps to `recurring` on sync.
- **Currency**: the quote's currency comes from `currency.code` in `/cart/get_total` (some integrations wrap it in `data`). A quote whose currency differs from `invoices.currency` is rejected, never converted (INV-M4).
- **Pre-Checkout Stock Check**: `GET {host}/cart/stock_control?pid={id}` -> `data.product`; verify `hidden == 0` and (`stock_control == 0 || qty > 0`).
- **Live Price Quote**: `POST {host}/cart/get_total` with `pid, billingcycle, configoption{}, customfield{}, currencyid, qty` -> top-level `products{total, setupfee_total, sale_price, sale_setupfee_total, type, ...}` and `currency`. Without a discount, the period cost is `total - setupfee_total`; when `type` is nonempty, it is `sale_price` and the setup cost is `sale_setupfee_total`. Require explicit amounts and reject setup exceeding a non-discounted total. Apply markup separately to period/setup; retain their full upstream sum for pre-payment reconciliation. The client also accepts a `data` wrapper. Decode monetary JSON numbers as decimal strings before PHP can convert them to floating point.

## 3. P1 -> P6 Order & Payment Chain

1. **P1 (`GET /user_info`)**: Require `status === 200` and fetch `user.currency` as the upstream `currencyid`; also accept the existing `data.user.currency` wrapper. The top-level form was verified against a real classic supplier during CDN acceptance.
2. **P2 (`POST /cart/clear`)**: Send `{downstream_url, downstream_token, downstream_id}` (idempotency probe).
   - `status === 400` and `domainstatus === 'Active'` -> already provisioned; save `hostid` as `dcimid`.
   - `status === 200` and `invoiceid` present -> skip P3/P4 and jump to P5 to pay the existing invoice.
   - This probe is the only sanctioned recovery path after an ambiguous P4/P5 (INV-U2).
3. **P3 (`POST /cart/add_to_shop`)**: Send `pid` (`upstream_pid`), `billingcycle`, `host`, `password`, `currencyid`, `currecncyid` (send BOTH keys to satisfy the upstream `CartController.php:2667` typo), `qty=1`, `configoption{}`, `customfield{}`.
   - `host` and `password` are validated against both `products.provisioning_rules` and the product configuration rules above before this call, never after. `/host/header` is only available after a host exists.
4. **P4 (`POST /cart/settle`)**: Send `downstream_url/token/id` + `cart_data`.
   - `status === 1001` -> zero-amount / paid (`data.hostid[0]`); confirm host `Active` before delivery.
   - `status === 200` -> returns `data.invoiceid` (proceed to P5).
   - Any other outcome, or a lost response, is `ambiguous`: record it in `upstream_operations` and reconcile via P2.
5. **Before P5**: `GET /use_credit_page?invoiceid={id}` -> `data.total` (the upstream invoice's `subtotal`) and `data.currency.code`. Require exact equality with the saved `invoices.upstream_expected_cost` and invoice currency; otherwise stop and request operator review.
   **P5 (`POST /apply_credit`)**: Send `{invoiceid, use_credit: 1, enough: 1, downstream_url, downstream_token, downstream_id}`.
   - `status === 1001` -> paid (`data.hostid[0]`); confirm host `Active` before delivery. Pending creation retries through P2.
6. **P6 Fallback (`POST /apply_credit_limit`)**: Disabled by default; an operator must explicitly enable it per upstream account. After an explicit P5 failure, optional credit-limit pay (`use_credit_limit=1`, `enough=0`). On explicit failure, roll back via `POST /apply_credit` with `{invoiceid, use_credit: 0}`. A transport-ambiguous payment is reconciled through P2 instead of switching payment methods.
   - A rollback that itself fails leaves "upstream paid, local unpaid". That is an alert plus an `upstream_operations` row; it is never retried silently (INV-U3).

> **Note on `status === 1001`**: in `cart/settle`, `/apply_credit` and `/apply_credit_limit`, `1001` means **payment complete**. Host creation can still be asynchronous; confirm `Active` using authenticated `/host/header` or a later P2 probe. In `OrderController::checkOrder()`, `1001` means **payment failed** (`1000` = success). Never conflate them.

> **Amounts (human approved 2026-11-17)**: the upstream invoice is reconciled against `invoices.upstream_expected_cost`. Customer `gateway_amount` is only the external portion of the downstream price. Paying entirely from the customer wallet does not make the upstream cost zero; no FX conversion is performed.

## 4. Instance Operations (`/provision/default` & Lifecycle)

- **Unified Command Endpoint**: `POST {host}/provision/default`
  - Base params: `id={dcimid}`, `is_api=1`, `func={func}`
  - **The 12 `func` values are the `ProvisionCommand` enum** — never pass a raw string:
    | Enum case | `func` | Extra params |
    |---|---|---|
    | `SUSPEND` | `suspend` | `reason` |
    | `UNSUSPEND` | `unsuspend` | |
    | `POWER_ON` | `on` | |
    | `POWER_OFF` | `off` | |
    | `REBOOT` | `reboot` | |
    | `HARD_OFF` | `hard_off` | |
    | `HARD_REBOOT` | `hard_reboot` | |
    | `VNC` | `vnc` | |
    | `REINSTALL` | `reinstall` | `os`, `port`, `format_data_disk` |
    | `STATUS` | `status` | |
    | `CRACK_PASSWORD` | `crack_pass` | `password` |
    | `RESCUE_SYSTEM` | `rescue_system` (frozen domain value remains `rescueSystem`) | `system`, `temp_pass` |
  - **Success Check**: `$res['status'] === 'success' || $res['status'] === 200`.
  - `REINSTALL` and `RESCUE_SYSTEM` are destructive: they require explicit user confirmation and an `audit_logs` row.
  - [Verified customer controls and direct VNC](classic-zjmf-machine-controls.md): reinstall uses current header cloud_os IDs paired with cloud_os_group; rescue spelling is translated only at the wire boundary. A 200 is acceptance, not completion. Local bundled noVNC connects browser WSS directly to the remote endpoint, without a local proxy. Custom exitRescue remains outside the frozen command set.
- **Terminate**: `POST {host}/host/cancel` with `{id: dcimid, type: "Immediate", reason}`. A 200 only records a cancellation request; keep the task pending until an authenticated header reports Deleted. Never use acceptance as proof for refunds.
- **Renew quote (approved C13)**: read-only `GET /host/renewpage` with explicit `hostid` and `billingcycles`. For the existing cycle, use `data.host.amount` and `data.currency.code`; `data.pay_type`/cycle duration describe the period. Do not use POST renewal as a quotation.
- **Renew execution**: persist create_requested before `POST /host/renew` (`hostid`, `billingcycles`), save `data.invoiceid`, verify `GET /use_credit_page` cost/currency, persist payment_requested, then `POST /apply_credit` (`invoiceid`, `use_credit=1`, `enough=1`). Confirm the expected due extension after payment. No blind create/pay replay; new-purchase P2 recovery is not a renewal recovery contract.
- **Upgrade / Downgrade**: `POST /upgrade/upgrade_config_post` validates/caches selection; `GET /upgrade/upgrade_config_page?hid=...` returns `data.total`/currency; then `POST /upgrade/checkout_config_upgrade` creates an invoice (200/data.invoiceid) or returns zero-paid 1001. Check invoice cost/currency before apply_credit. Serialize prepare/page/checkout per account+host because the upstream selection cache is shared. Confirm delivered changes with `GET /upgrade/index/{hid}` (`data.host` rows oid/subid/qty/option_type). A lost creation response is retained for manual reconciliation, never blindly recreated.
  - This chain is for **upgrades only**. A downgrade never touches it: it reduces the future `recurring_amount` through `DowngradeCalculatorAction` and nothing else (INV-R1 / charter §3.5).
- **Full Instance Info & Non-Cloud Widgets**: `GET {host}/host/header?host_id={dcimid}&source=API`
  - Returns `data.host_data` (`dedicatedip, assignedips[], username, password, port, os, domainstatus, nextduedate, suspendreason, cancel_control, allow_upgrade_config, password_rule`), `module_button` (`{control:[], console:[]}`), `module_client_area`, `module_client_main_area`, `custom_field_data[]`.
  - **Due-date timezones (FIX-001)**: the source-verified and live-observed `nextduedate` is Unix seconds; positive timestamps become offset-bearing ISO 8601 in `InstanceInfoResult.nextDueDate`, never a UTC wall-clock string without an offset. Explicit offsets in string values retain their instant. Existing timezone-naive string compatibility uses `config('app.timezone')`; this is not a claim that every supplier emits such strings. Missing/zero dates stay null. All snapshot consumers use InstanceDueDate to normalize into the application timezone before writing timezone-less DATETIME columns; the current project timezone is Asia/Shanghai. Renewal keys and operation boundaries remain explicit UTC. See [FIX-001 verification and historical repair limits](backend-fix001-timezone.md).
  - Host fields are mapped into `App\Data\InstanceInfoResult`. The current driver does not copy arbitrary `module_*` HTML into `panelWidgets`; panel login is fetched separately, only after an authenticated customer POST, through the existing `HasCustomPanelWidgets` capability.
- **Verified Kangle/EasyPanel login**: when the authenticated Active header advertises `module_client_area[].key=information`, `POST {host}/zjmf_api/provision/custom/content` with form fields `id={dcimid}, key=information` returns `status=200, data.html`. The verified supplier HTML contains a POST form to an HTTPS `/vhost/index.php?c=session&a=login` endpoint, with hidden `token`, `username`, `passwd` fields. Extract exactly one matching form and rebuild an escaped local handoff page; never render supplier HTML or scripts. Redact the entire `html` value in operation logs (INV-U1). Unsupported/ambiguous forms have no usable login entry. This is a separate content endpoint, not a new `ProvisionCommand`. See [protocol and actual browser acceptance](classic-zjmf-panel-login.md).
- **Verified existing redemption delivery**: an Active header advertising `module_client_area[].key=redeem` permits the same content endpoint with `key=redeem`. For the verified Sub2API template, extract exactly one readonly text input `#sub2api-redeem-code` with a nonempty value and an optional safe HTTPS `/redeem` link. Return structured `redemption_code` data in the existing `InstanceInfoResult.panelWidgets`; ApplyInstanceSnapshotAction persists only card_key/redeem_url through the encrypted credentials cast after binding validation. This reads an existing code; it neither issues nor redeems one. Raw HTML is redacted from logs and never rendered. See [host 4066 real acceptance](classic-zjmf-ai-delivery.md).
- **Verified cloud telemetry**: an authenticated Active header with `module_power_status=true` permits read-only `POST /provision/default` using `ProvisionCommand::STATUS`; `status=200, data.status=on|off|unknown` is machine state, separate from billing domainstatus. Advertised cpu/disk/memory/flow chart types are read through `GET /provision/chart/{host_id}?type=...&start=...&end=...` (explicit 24h millisecond window). Verified `data` fields are unit, chart_type (area/line/bar), label[] and list[][] of time/value. Return structured widgets through the existing capability; never execute upstream chart scripts. Failed/malformed series stay visibly unavailable, null samples are not zeroes. Explicit customer reads use the standard 30s per-call timeout; asynchronous syncInfo retains 3s. See [host 4253 browser and latency acceptance](classic-zjmf-cloud4253-acceptance.md).
  - [Independent power status read](classic-zjmf-power-status-performance.md): an authenticated owner POST may use the previously synchronized power capability and the current instance binding to issue STATUS directly, without reloading header or charts. The Action revalidates ownership/binding/state/account after I/O. This reads no cached power value and changes no lifecycle state. Mutating commands still perform their existing real-time validations. Real sample: 3.26s versus the previous approximately 26s full aggregate.
- **Pre-Sync Rule**: always call `/host/header` before `suspend` or `unsuspend`. If upstream already reports the target state (or `Deleted`), return `ProvisionResult::reconciled()` with the observed snapshot in `rawResponse` without calling `/provision/default`. `SuspendInstanceAction` (or the corresponding lifecycle Action) reconciles local state and writes its audit record after the driver returns; the driver never mutates the instance. HTTP runs outside the Action's database transactions (INV-S5, INV-L3, INV-L4).

## 5. Downstream Callback Registration & `/api/host/sync`

- **Register Callback**: `POST {host}/host/setdownstream` with `id` (upstream `dcimid`), `pid` (`upstream_pid`), `downstream_url`, `downstream_token` (32-char md5), `downstream_id` (local instance id).
- **Receive Callback**: `POST /api/host/sync`
  - Payload contains `id` (local instance id), `host_id` (upstream id), `rand_str` (6 chars), `signature`, plus host fields.
  - **Signature**: sort the array first, then JSON-encode (`ksort()` returns `bool` in PHP — never pass its result to `json_encode()`):
    ```php
    $params = ['id' => $id, 'token' => $token, 'rand_str' => $randStr];
    ksort($params, SORT_STRING);
    $signature = strtoupper(md5(json_encode($params)));
    ```
  - **Replay protection**: dedupe key is `host_sync:{instance_id}:{rand_str}` written with `SET NX EX 600`. A bare `rand_str` key would let one instance's callback poison another's (INV-Q4).
  - **Response discipline**: only 3 fields are signed and there is no timestamp, so the handler verifies the signature and the replay key, then **responds immediately** and reconciles asynchronously (INV-Q3). It MUST NOT hold the connection for a `/host/header` round trip: upstream would time out, retry, and the retry would then be dropped by the replay key, losing the sync permanently. The re-query happens in `ReconcileHostSyncJob` with a 3s timeout.
  - **Bug guard**: upstream does NOT send `suspendreason` in `pushHostInfo` by default. NEVER overwrite `suspend_reason` with null/empty on sync. If upstream does report a deletion, the instance moves to `Deleted` and its pending tasks are marked `stale`.

## 6. Sync Cadence

- `probeVersions()` is the cheap tier; a `location_version` change or missing trustworthy publisher metadata triggers `syncProductConfig()` for the affected product only.
- `syncProductConfig()` returns the approved pure CatalogSnapshotResult (C12); SyncClassicCatalogAction validates the mapping/version and persists stable options/fields, hiding missing upstream rows. SyncCatalogResult remains the Action's actual committed counts.
- A sync never mutates `instances.driver_type` / `instances.upstream_api_id`. Rebinding is an explicit admin action with binding history (INV-S3).
- Options and custom fields carry `sync_source = upstream` when they originate from ZJMF. Locally-created rows are never overwritten by a sync.

## 7. Expansion implementation and limits

Approved C12–C14, operator commands, actual local acceptance, recovery stages and outstanding real-supplier checks are in `docs/classic-zjmf-expansion-decisions.md` and `docs/classic-zjmf-integration.md`. No classic supplier mutation was performed in this implementation round. Hot swap binds an existing authenticated target; it does not migrate disks. Target option mappings require review before configuration changes.
