# External Drivers Integration Spec

> Companion spec to `CLAUDE.md`, `docs/domain-invariants.md` and `docs/zjmf-api-spec.md`.
> This file describes wire protocols and per-driver configuration. Money, state and concurrency rules live in `docs/domain-invariants.md` and win over anything here.
>
> **Credential Ownership Rule (F09)**:
> - **Global singletons** (`payment.*`, `telegram.*`, `mail.*`, `sms.*`, `oauth.*`, `kyc.*`) live in `system_settings` and are read via `SettingService::get()`.
> - **Multi-account resource connections** (multiple ZJMF accounts, PVE clusters, Sub2API instances) live in `upstream_apis` (`encrypted_secret`, `config`).

---

## 1. Epay (彩虹易支付标准协议 — `App\Drivers\Payment\EpayGateway`)

### 1.1 Dynamic Settings (`system_settings` group: `payment`)

- `payment.epay_api_url`: base URL without a trailing slash (e.g. `https://pay.example.com`)
- `payment.epay_pid`: merchant id (`pid`)
- `payment.epay_key`: merchant MD5 secret key (`is_encrypted = true`)
- Unified Gateway Identifiers: `epay_alipay` (channel `alipay`), `epay_wechat` (channel `wxpay`)

### 1.2 Instance construction

One class serves both channels, so **the identifier and channel are injected by `DriverManager`, never inferred inside the adapter**:

```php
new EpayGateway(
    identifier: 'epay_wechat',   // persisted on invoices.gateway
    channel: 'wxpay',            // protocol-level `type` field
    apiUrl: $settings->get('payment.epay_api_url'),
    pid: $settings->get('payment.epay_pid'),
    key: $settings->get('payment.epay_key'),
);
```

`getIdentifier(): string` returns the injected identifier and is the *only* source of `invoices.gateway` / `VerifiedPaymentResult.gateway`. Previously the adapter was expected to return a gateway name it had no way to know.

### 1.3 Signature Algorithm (MD5)

1. Filter out keys `sign`, `sign_type`, and any parameter whose value is `null` or `""`.
2. Sort the remaining parameters by key in ASCII ascending order (`ksort($params, SORT_STRING)`).
3. Join as `k1=v1&k2=v2...` (**raw values, NOT URL-encoded**).
4. Append `$epayKey` directly to the end of the query string (no `&key=` separator) and compute **lowercase** MD5:
   ```php
   $sign = md5($queryString . $merchantKey);
   ```

### 1.4 Initiate Payment (`charge(Invoice $invoice, string $amount)`)

- **Strict type rule**: `$amount` is a validated fixed-point decimal string (`/^\d+\.\d{2}$/`). Pass it directly as `money` — NEVER call `number_format()` under `strict_types=1`.
- **Order number**: `charge()` does **not** reuse `invoice_no`. It mints a fresh `out_trade_no` (e.g. `{invoice_no}{attempt_seq}`), inserts a `payment_attempts` row (`gateway`, `out_trade_no`, `amount`, `status=created`), and returns it on `GatewayChargeResult::outTradeNo`. Without this, a customer retrying a timed-out payment is rejected by epay as a duplicate order number.
- **Redirect / API mode**: `POST` (`application/x-www-form-urlencoded`) to `{epay_api_url}/submit.php` or `{epay_api_url}/mapi.php`.
- **Request fields**: `pid`, `type` (`alipay`|`wxpay`), `out_trade_no`, `notify_url`, `return_url`, `name`, `money` (`$amount`), `clientip`, `sign`, `sign_type` (`MD5`).
- **Response (`mapi.php`)**: `code === 1` indicates success; read `payurl` or `qrcode` into `GatewayChargeResult`.

### 1.5 Adapter Boundary (`verifyCallback(Request $request): VerifiedPaymentResult`)

`EpayGateway` **ONLY** handles protocol verification — it MUST NOT touch `invoices`, `users`, `payment_callbacks`, `payment_attempts` or `fulfillment_tasks` (INV-L2):

1. Recompute the MD5 signature over incoming params (excluding `sign` and `sign_type`).
2. Compare with `hash_equals()` **after normalizing case**: `strtolower((string) $request->input('sign'))` against the computed lowercase digest. Some epay gateways return an uppercase digest; a case-sensitive `hash_equals()` would reject every valid callback from them.
3. Assert `$request->input('trade_status') === 'TRADE_SUCCESS'`.
4. Return:
   ```php
   new VerifiedPaymentResult(
       gateway:        $this->getIdentifier(),          // e.g. 'epay_wechat'
       gatewayTradeNo: $request->input('trade_no'),     // gateway's own id
       outTradeNo:     $request->input('out_trade_no'), // our payment_attempts key
       paidAmount:     Money::of($request->input('money')),
       rawPayload:     $request->all(),
       ackBody:        'success',
   );
   ```
5. All DB locking, `payment_callbacks` deduplication, `frozen_credit` settlement, late/overpaid/unknown-invoice handling and `DB::afterCommit()` task dispatch are executed exclusively by `App\Actions\HandlePaymentCallbackAction`, following `docs/domain-invariants.md` §4.

### 1.6 Identifier hygiene

`VerifiedPaymentResult.gateway` must equal `invoices.gateway` for a callback to be accepted (branch P1). Consequently a callback arriving at the wrong channel endpoint is rejected and alerted rather than silently marking an invoice paid.

---

## 2. Sub2API Recharge Card Key Driver (`App\Drivers\SelfHosted\Sub2ApiDriver`) — Phase 3

> **Verification Note**: exact remote admin API paths must be verified against the deployed Sub2API instance before implementing `remote_api` mode. `local_pool` mode works immediately via `RedemptionCodeDriver`.

- **Billing Cycle**: `onetime` (implements `FulfillmentDriver` only, NOT `RenewableResource`).
- **`local_pool` Mode**: an admin pre-imports keys into `redemption_codes`; `Sub2ApiDriver` delegates to `RedemptionCodeDriver`. Because `code` is encrypted with a randomized IV, imports are deduplicated on `code_hash` (`unique(product_id, code_hash)`) — importing the same batch twice is a no-op, not a duplicate (INV-C1).
- **`remote_api` Mode**: reads account credentials from `upstream_apis` (`base_url`, `encrypted_secret`, `config` JSON). Every call is logged to `upstream_operations`.
- **Reservations**: a claimed code carries a `reserved_until` lease; a sweeper returns expired reservations to `available` so a crashed provisioning does not burn stock (INV-C2, INV-C3).
- **No renewal**: because it is not a `RenewableResource`, the overdue scheduler skips it entirely (INV-S2).

---

## 3. Legacy Magic Financial KYC Driver (`App\Drivers\Kyc\ZjmfLegacyKycDriver`) — Phase 3

> **Verification Note**: the exact legacy ZJMF certify endpoint path and callback signature must be verified against the legacy ZJMF instance before Phase 3 implementation.

- **Dynamic Settings (`system_settings` group: `kyc`)**: `kyc.zjmf_base_url`, `kyc.zjmf_api_key` (`is_encrypted = true`).
- **Privacy Rule**: store ONLY a masked summary (`{"name":"*国松","id_last4":"1234"}`) in `kyc_verifications.masked_summary`; NEVER log plaintext ID numbers.
- **Single source of truth**: `kyc_verifications` rows are the KYC history and the only writer is the driver's callback handler. On a `verified` transition it also sets `users.kyc_verified_at`; nothing else may set that column. Rejecting an application clears it. This keeps the denormalized flag and the record from disagreeing.

---

## 4. Proxmox Driver (`App\Drivers\SelfHosted\ProxmoxDriver`) — Phase 3

- Implements `FulfillmentDriver`, `RenewableResource`, `ConfigurableResource`, `LocalInventory`, `PowerControllable`, `LocalPriced`.
- **Resource reservation**: `pve_ip_allocations` and VMID allocation are reserved under `lockForUpdate()` before the UPID is issued; every upstream failure releases the reservation (compensating release, idempotent).
- **UPID polling** is bounded by the same INV-Q1 timeout ladder; a poll that times out leaves the task retryable, never `failed` on the first attempt.
- `network_mode` (`static_pool` | `nat_shared` | `dhcp`) determines how `primary_ip` / `assigned_ips` are filled; `dhcp` instances have no allocated IP and must not be treated as a missing reservation.

---

## 5. Notification Drivers — Phase 1 onward

- `TelegramBotDriver` implements `BotDriverInterface`.
- `LogSmsDriver` implements `SmsDriverInterface`; it writes to the log and returns `true`, so development never sends real SMS.
- `LogMailDriver` implements `MailDriverInterface` — mail previously had no contract at all, while the fan-out assumed one.
- **Fan-out**: `NotificationManager` renders `notification_templates` per channel, applies `variable_mapping`, and enqueues one `SendNotificationJob` per verified binding. A channel failure is recorded in `notification_logs` and does not cancel the other channels.
- **Fallback**: when a user has no verified `mail` binding, the manager falls back to `users.email` and records the fallback. Billing and overdue notifications are never silently dropped (charter §3.7).
