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

### 1.4 Initiate Payment (`charge(Invoice $invoice, string $amount, string $outTradeNo)`)

- **Strict type rule**: `$amount` is a validated fixed-point decimal string (`/^\d+\.\d{2}$/`). Pass it directly as `money` — NEVER call `number_format()` under `strict_types=1`.
- **Order number and ownership**: `InitiatePaymentAction` mints a fresh `out_trade_no` (e.g. `{invoice_no}{attempt_seq}`), inserts a `payment_attempts` row (`gateway`, `out_trade_no`, `amount`, `status=created`) and commits before calling the adapter. `charge()` sends the exact injected `$outTradeNo` and echoes it on `GatewayChargeResult::outTradeNo`; it never inserts or updates models. HTTP runs outside database locks (F10, INV-L3). A failed or ambiguous call leaves its attempt intact; a customer retry creates a new attempt rather than reusing a gateway order number. Callback resolution uses the exact attempt mapping, with complete invoice_no as its only fallback.
- **Redirect / API mode**: `POST` (`application/x-www-form-urlencoded`) to `{epay_api_url}/submit.php` or `{epay_api_url}/mapi.php`.
- **Request fields**: `pid`, `type` (`alipay`|`wxpay`), `out_trade_no`, `notify_url`, `return_url`, `name`, `money` (`$amount`), `clientip`, `sign`, `sign_type` (`MD5`).
- **Response (`mapi.php`)**: `code === 1` indicates success; read `payurl` or `qrcode` into `GatewayChargeResult`.

### 1.5 Adapter Boundary (`verifyCallback(Request $request): VerifiedPaymentResult`)

`EpayGateway::verifyCallback()` **ONLY** handles protocol verification — it MUST NOT read or write `invoices`, `users`, `payment_callbacks`, `payment_attempts` or `fulfillment_tasks` (INV-L2):

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

## 2. Sub2API Recharge Card Key Drivers — local pool and remote issuance

- **Billing Cycle**: `onetime`; local price authority via `LocalPriced`, no `RenewableResource`.
- **local_pool**: `Sub2ApiDriver` delegates to the pure `RedemptionCodeDriver`; no upstream account. Import/reservation/sold delivery remains Action-owned (INV-C1..C3).
- **remote_api**: `Sub2ApiRemoteDriver`, only for active SUB2API accounts with `config.mode=remote_api` and `config.protocol=balance-codes-v1`. The administrator key lives in `upstream_apis.encrypted_secret` (F09). No customer model API key or global SettingService credential is substituted.
- **Verified protocol**: `x-api-key`, JSON, `GET /api/v1/admin/redeem-codes/stats`, `POST /api/v1/admin/redeem-codes/generate` with `{count:1,type:balance,value:integer}` and a durable `Idempotency-Key`; success `code=0` and exactly one `data[]` record. Validate positive ID, nonempty code, type, face value and unused status. `GET /api/v1/admin/redeem-codes/{id}` is read-only recovery. See [source evidence and live verification](sub2api-remote.md).
- **Pricing/face value**: first delivery uses fixed-face SKUs (`products.provisioning_rules.sub2api_face_value`, positive integer string). Sale price remains `local_prices.onetime`; face value and endpoint are encrypted in the purchase snapshot before payment. Discounts, wallet split, later product edits and customer input cannot change the issued amount.
- **Durable handoff**: `ProvisionRemoteCardAction` commits the task's remote intent before HTTP; the driver mutates no models. HTTP runs outside locks, through `CallUpstreamAction`, with response strings omitted from logs to protect admin keys/cards (F10, INV-L3, INV-U1).
- **No blind replay**: timeouts, 5xx, invalid/mismatched responses and interrupted requested intents remain blocked for manual reconciliation, even after a generic task retry. Explicit rejection also retains its intent. Do not depend on the upstream default 24h idempotency TTL for indefinite safety.
- **Delivery/refunds**: encrypted result evidence is retained independently of worker lease. Only current Paid/lease/binding/endpoint matches can commit an encrypted sold `redemption_codes` row plus encrypted instance credentials and Active state. A refunded or rebound request never delivers. Remote sold cards reuse existing non-reclaimable refund-loss behavior (INV-R3).
- **Actual redemption**: the customer logs into the Sub2API site and uses its authenticated `/api/v1/redeem` endpoint. CMS does not store customer Sub2API passwords/JWTs or automatically debit/credit existing upstream users. The separate `create-and-redeem` API is outside this delivery scope.

Deployment/operator commands, the one-unit live result and remaining limits are documented in `docs/sub2api-remote.md`. Unverified/legacy connection configs continue to be rejected.

---

## 3. Legacy Magic Financial KYC Driver (`App\Drivers\Kyc\ZjmfLegacyKycDriver`) — Phase 3

> **Verification Note**: the exact legacy ZJMF certify endpoint path and callback signature must be verified against the legacy ZJMF instance before Phase 3 implementation.

- **Dynamic Settings (`system_settings` group: `kyc`)**: `kyc.zjmf_base_url`, `kyc.zjmf_api_key` (`is_encrypted = true`).
- **Privacy Rule**: store ONLY a masked summary (`{"name":"*国松","id_last4":"1234"}`) in `kyc_verifications.masked_summary`; NEVER log plaintext ID numbers.
- **Single source of truth (human-approved C15)**: drivers verify protocol and return `KycInitiateResult` / `KycStatusResult`, never mutate models. Initiation Actions own pending history; the state-application Action is the sole writer of verified/failed transitions and `users.kyc_verified_at`. It locks the user then the matching current verification and changes history/flag atomically. Current verified sets the flag; current failed clears it. Old attempts and duplicate/contradictory terminal callbacks cannot replace current results. Callbacks and authenticated queries share this Action; browser return pages are never proof. See `cms-identity-decisions.md`.

---

### 3.1 Approved direct idcsmartali polling adapter

The human selected `certification/idcsmartali` and approved the polling-only difference on 2026-11-17. `App\Drivers\Kyc\IdcsmartAliKycDriver` connects directly to the supplier's `certapi.php`, using encrypted `kyc.idcsmartali_api` / `kyc.idcsmartali_key` settings, a configured HTTPS endpoint and enabled scene. This is separate from the still-unverified classic ZJMF KYC endpoint above.

The source-verified chain is initialize (status=200 and certify_id), then certify (url), then query (status=200 means verified). InitiateKycAction commits the masked pending intent before HTTP and persists the returned external ID before a separate user-requested authorization step. An uncertain initialize is never automatically repeated. QueryKycAction obtains the result outside locks and applies it through the approved C15 writer; non-success query states stay pending. Browser return does not verify. There is no callback route; handleCallback explicitly rejects calls because no signed supplier callback protocol exists.

Current source evidence, configuration keys, isolated TLS tests, privacy and operator limits are in `identity-provider-integration.md`. The user requested skipping the unavailable real supplier; production remains disabled, with no real SMS or paid identity initiation performed.

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
- `IdcsmartSmsDriver` implements the existing SmsDriverInterface using the human-selected classic plugin's domestic form protocol. Runtime credentials, HTTPS endpoint, sign and approved template map come from SettingService; its accepted response is not proof of phone ownership. Real transport configuration and enabled binding are required before PhoneBindingAction can issue a challenge. See `identity-provider-integration.md`; the unavailable real supplier remains disabled at the user's request.
- **Fan-out**: `NotificationManager` renders `notification_templates` per channel, applies `variable_mapping`, and enqueues one `SendNotificationJob` per verified binding. A channel failure is recorded in `notification_logs` and does not cancel the other channels.
- **Fallback**: when a user has no verified `mail` binding, the manager falls back to `users.email` and records the fallback. Billing and overdue notifications are never silently dropped (charter §3.7).
