# Magic Financial (`zjmf_api` 3.7.6 Classic) — Integration Spec

> Scope: Classic ZJMF Finance (`zjmf_finance_api.type = zjmf_api`, `is_resource = 0`). **V10 (`console/v1/*`) is excluded.**
>
> **This file describes the wire protocol only.** Rules about money, state and concurrency live in `docs/domain-invariants.md`. Where the two disagree, the invariant wins and this file is corrected.
> Every requirement below is implemented by `App\Drivers\Upstream\ZjmfFinanceDriver`; command names come from `App\Enums\ProvisionCommand`.

## 1. Transport & Auth

- **Encoding**: ALL `GET`, `POST`, `PUT`, `DELETE` requests MUST use `application/x-www-form-urlencoded` (`Http::asForm()`). NEVER send a JSON body.
- **HTTP Layer**: Only parse JSON when HTTP status is `200`. Non-200 or cURL error returns `{status: 400}`. Timeout defaults to `30s` (`5s` for `cart/ontrialmax`). These values must satisfy INV-Q1: `upstream timeout (30s) < job timeout (180s) < queue retry_after (240s)`.
- **Login**: `POST {host}/zjmf_api_login`
  - Body: `username` (email if it contains `@`, else phone; 4–20 chars), `password` (Client Center `api_password`, NOT the login password).
  - Success: `{"status":200,"jwt":"...","msg":"鉴权成功"}` (upstream TTL `7200s`). Cache locally for `6900s` (soft expiry).
  - Header: `Authorization: Bearer {jwt}` (configurable `raw_jwt` fallback if the upstream has the 3.7.6 `Check.php:106` `list()=explode` bug).
  - Re-auth trigger: business `status === 405` -> force-refresh the JWT once and replay once. A second `405` is a permanent failure for this attempt.
- **Health & Balance**: `GET {host}/cart/credit` -> returns `data.credit` (string) & `data.currency{id,code,prefix,suffix}`. (Avoid `/cart/summary` — it leaks a plaintext key and has a `bcdiv` div-by-zero bug.)
- **Logging**: every request/response pair is written to `upstream_operations` with credentials, passwords and JWTs redacted (INV-U1). The JWT itself is never logged.

## 2. Catalog, Stock & Live Pricing

- **Catalog**: `GET {host}/cart/all` -> `data.products[]` (groups -> products with `id, type, name, description`), `count`, `currency` (real-time, no TTL cache).
- **Lightweight Stock & Version Probe**: `GET {host}/api/product/proinfo?pids[]=...` -> `data.info[]{id, name, location_version, stock_control, qty}`, `data.currency`. Compare `location_version` to decide whether a full sync is needed.
- **Full Product Config**: `GET {host}/cart/get_product_config?pid={id}`
  - Returns `flag`, `products`, `customfields[]`, `product_pricings[]`, `config_groups[].options[].sub[].pricings[]`, `advanced[]`, `config_links[]`.
  - **`billing_cycle` (18 keys)**: `free, onetime, hour, day, ontrial, monthly, quarterly, semiannually, annually, biennially, triennially, fourly, fively, sixly, sevenly, eightly, ninely, tenly`. Mirrored by `App\Enums\BillingCycle`.
  - **`price_type` (17 pairs)**: `onetime/osetupfee`, `hour/hsetupfee`, `day/dsetupfee`, `ontrial/ontrialfee`, `monthly/msetupfee`, `quarterly/qsetupfee`, `semiannually/ssetupfee`, `annually/asetupfee`, `biennially/bsetupfee`, `triennially/tsetupfee`, `fourly/foursetupfee`, `fively/fivesetupfee`, `sixly/sixsetupfee`, `sevenly/sevensetupfee`, `eightly/eightsetupfee`, `ninely/ninesetupfee`, `tenly/tensetupfee`. (`-1` = disabled.)
  - **Semantics (important)**: the `*setupfee` column named by a cycle is that cycle's **period price**, not a one-off setup fee. Read it via `BillingCycle::priceKey()`. An actual one-off setup fee does not exist upstream and is always `"0.00"` in `QuoteResult`.
  - Upstream `day` / `hour` `pay_type` maps to `recurring` on sync.
- **Currency**: the quote's currency comes from `data.currency.code`. A quote whose currency differs from `invoices.currency` is rejected, never converted (INV-M4).
- **Pre-Checkout Stock Check**: `GET {host}/cart/stock_control?pid={id}` -> verify `hidden == 0` and (`stock_control == 0 || qty > 0`).
- **Live Price Quote**: `POST {host}/cart/get_total` with `pid, billingcycle, configoption{}, customfield{}, currencyid, qty` -> `data.products{total, setupfee_total, sale_total, bates, ...}`.

## 3. P1 -> P6 Order & Payment Chain

1. **P1 (`GET /user_info`)**: Fetch `data.user.currency` as the upstream `currencyid`.
2. **P2 (`POST /cart/clear`)**: Send `{downstream_url, downstream_token, downstream_id}` (idempotency probe).
   - `status === 400` and `domainstatus === 'Active'` -> already provisioned; save `hostid` as `dcimid`.
   - `status === 200` and `invoiceid` present -> skip P3/P4 and jump to P5 to pay the existing invoice.
   - This probe is the only sanctioned recovery path after an ambiguous P4/P5 (INV-U2).
3. **P3 (`POST /cart/add_to_shop`)**: Send `pid` (`upstream_pid`), `billingcycle`, `host`, `password`, `currencyid`, `currecncyid` (send BOTH keys to satisfy the upstream `CartController.php:2667` typo), `qty=1`, `configoption{}`, `customfield{}`.
   - `host` and `password` are validated against the **intersection** of `products.provisioning_rules` and upstream `password_rule` before this call, never after.
4. **P4 (`POST /cart/settle`)**: Send `downstream_url/token/id` + `cart_data`.
   - `status === 1001` -> zero-amount / already paid & provisioned (`data.hostid[0]`).
   - `status === 200` -> returns `data.invoiceid` (proceed to P5).
   - Any other outcome, or a lost response, is `ambiguous`: record it in `upstream_operations` and reconcile via P2.
5. **P5 (`POST /apply_credit`)**: Send `{invoiceid, use_credit: 1, enough: 1, downstream_url, downstream_token, downstream_id}`.
   - `status === 1001` -> paid & provisioned (`data.hostid[0]`).
6. **P6 Fallback (`POST /apply_credit_limit`)**: Optional credit-limit pay (`use_credit_limit=1`, `enough=0`). On failure, roll back via `POST /apply_credit` with `{invoiceid, use_credit: 0}`.
   - A rollback that itself fails leaves "upstream paid, local unpaid". That is an alert plus an `upstream_operations` row; it is never retried silently (INV-U3).

> **Note on `status === 1001`**: in `cart/settle`, `/apply_credit` and `/apply_credit_limit`, `1001` means **paid & complete**. In `OrderController::checkOrder()`, `1001` means **payment failed** (`1000` = success). Never conflate them.

> **Amounts**: the upstream charge is the `gateway_amount` we computed in INV-I1. The upstream cost we expect to pay is stored on `invoices.upstream_expected_cost` for margin reconciliation.

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
    | `RESCUE_SYSTEM` | `rescueSystem` | `system` |
  - **Success Check**: `$res['status'] === 'success' || $res['status'] === 200`.
  - `REINSTALL` and `RESCUE_SYSTEM` are destructive: they require explicit user confirmation and an `audit_logs` row.
- **Terminate**: `POST {host}/host/cancel` with `{id: dcimid, type: "Immediate", reason: "立即删除"}`.
- **Renew**: `POST {host}/host/renew` (`hostid={dcimid}`, `billingcycles={cycle}`) -> then `POST /apply_credit` (`invoiceid`, `use_credit=1`, `1001` = paid).
- **Upgrade / Downgrade**: `POST /upgrade/upgrade_config_post` -> `POST /upgrade/checkout_config_upgrade` -> `POST /apply_credit`.
  - This chain is for **upgrades only**. A downgrade never touches it: it reduces the future `recurring_amount` through `DowngradeCalculatorAction` and nothing else (INV-R1 / charter §3.5).
- **Full Instance Info & Non-Cloud Widgets**: `GET {host}/host/header?host_id={dcimid}&source=API`
  - Returns `data.host_data` (`dedicatedip, assignedips[], username, password, port, os, domainstatus, nextduedate, suspendreason, cancel_control, allow_upgrade_config, password_rule`), `module_button` (`{control:[], console:[]}`), `module_client_area`, `module_client_main_area`, `custom_field_data[]`.
  - Mapped into `App\Data\InstanceInfoResult`; `panelWidgets` carries the `module_*` blocks for `HasCustomPanelWidgets`.
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

- `probeVersions()` is the cheap tier; a `location_version` change triggers `syncProductConfig()` for the affected product only.
- A sync never mutates `instances.driver_type` / `instances.upstream_api_id`. Rebinding is an explicit admin action with binding history (INV-S3).
- Options and custom fields carry `sync_source = upstream` when they originate from ZJMF. Locally-created rows are never overwritten by a sync.
