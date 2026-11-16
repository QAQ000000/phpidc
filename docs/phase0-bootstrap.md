# Phase 0 Bootstrap Runbook

> The repository currently contains **only** the domain layer (`app/Enums`, `app/Data`, `app/Contracts`, `app/Support`), one core migration, and Pest tests. It is **not** a runnable Laravel application: there is no `artisan`, `bootstrap/`, `config/`, `routes/`, `public/`, `storage/` or `vendor/`.
>
> Until the steps below are executed, `./vendor/bin/pest --group=phase0` cannot run, and `CLAUDE.md` §5's Phase 0 gate is unmet. This document is the exact procedure.

## 0. Prerequisites

| Item | Required | Verified here |
|---|---|---|
| PHP | >= 8.3, with `bcmath`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `redis`, `zip` | PHP 8.4.21, all present |
| Composer | >= 2.2 | present |
| MySQL | 8.0+ | present, service active |
| Redis | 6+ | present, service active |

## 1. Create the skeleton in place

The domain layer must survive, so the skeleton is created in a scratch directory and merged, rather than `composer create-project` over the working tree.

```bash
composer create-project laravel/laravel:^13.0 /tmp/l13 --no-interaction --prefer-dist

# Move only the skeleton scaffolding into the project root.
# Do NOT copy: app/, tests/, database/migrations/, docs/, CLAUDE.md, composer.json
rsync -a --ignore-existing /tmp/l13/artisan \
      /tmp/l13/bootstrap /tmp/l13/config /tmp/l13/public \
      /tmp/l13/routes /tmp/l13/resources /tmp/l13/storage \
      /tmp/l13/artisan /tmp/l13/phpunit.xml /www/wwwroot/Laravel13idc/

# Copy the skeleton's framework migrations, keeping the domain migration separate.
cp /tmp/l13/database/migrations/0001_01_01_000000_create_users_table.php \
   /tmp/l13/database/migrations/0001_01_01_000001_create_cache_table.php \
   /tmp/l13/database/migrations/0001_01_01_000002_create_jobs_table.php \
   /www/wwwroot/Laravel13idc/database/migrations/
```

**Why `--ignore-existing`**: our `composer.json`, `tests/`, `app/` and `database/migrations/` are already authored and must not be overwritten by the skeleton's stubs.

**Migration ordering**: the skeleton's `0001_01_01_*` migrations run before ours (`2026_11_16_*`), so `users`, `cache`, `jobs` and `failed_jobs` exist first. This is exactly why the core migration must not create `jobs`/`failed_jobs`.

## 2. Reconcile `users` with the skeleton

The skeleton creates `users` with `name, email, password`. Our core migration alters it (`phone`, `credit`, `frozen_credit`, `affiliate_balance`, `referred_by`, `anonymized_at`, `kyc_verified_at`, `SoftDeletes`). Verify after migrating:

```bash
php artisan migrate --force
php artisan tinker --execute 'dump(Schema::getColumnListing("users"));'
```

Expected: the seven added columns are present alongside the skeleton's. If any are missing, the skeleton's `users` migration ran *after* ours — stop and fix the filename timestamp, do not patch the schema by hand.

## 3. Install dependencies

```bash
composer install
php artisan key:generate
```

`composer.json` constraints have been verified against Packagist as resolvable on Laravel 13:

| Package | Constraint | Note |
|---|---|---|
| `laravel/framework` | `^13.0` | current v13.42.0 |
| `filament/filament` | `^5.0` | v5.11.4 |
| `bezhansalleh/filament-shield` | `^4.3` | requires `spatie/laravel-permission ^6\|^7\|^8` |
| `spatie/laravel-permission` | `^8.0` | **only 8.x supports Laravel 13**; 6.x/7.x pin `illuminate/* ^11\|^12` |
| `yansongda/laravel-pay` | `^3.7` | v3.7.4 declares `illuminate/support ^8..^13` |
| `pestphp/pest` | `^4.0` | — |
| `pestphp/pest-plugin-laravel` | `^4.1` | **must stay on 4.x**: v5.0.1 requires PHP `^8.4` + `laravel/framework ^13.23.0` |
| `larastan/larastan` | `^3.0` | v3.15.0 supports `illuminate/* ^13` |

If `composer install` fails, the failure is a real incompatibility and must be resolved by pinning, not by loosening to `*`.

## 4. Wire the pre-install bypass

`CLAUDE.md` §3.6 requires `SettingService` and `AppServiceProvider` to check `storage/installed.lock` **before** touching DB or Redis. Implement in this order:

1. `App\Services\SettingService::get()` — return `null` (or the default) immediately when the lock file is absent; never open a DB connection.
2. `AppServiceProvider::boot()` — register nothing that resolves settings when the lock file is absent.
3. `App\Http\Middleware\EnsureInstalledMiddleware` — exempt `/up` and `/install*`; return `404` for `/install*` once the lock file exists.
4. `InstallController` — the only writer of `storage/installed.lock`.

The `phase0` test `boots application, serves /up even before install, and locks /install after installed.lock` is the acceptance criterion.

## 5. Pest configuration

`phpunit.xml` from the skeleton needs:

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
</php>
```

SQLite is used for tests only. Two schema features are MySQL-specific and must be covered by a separate CI job against MySQL 8:

- `DECIMAL(12,2) UNSIGNED` (SQLite ignores `UNSIGNED`)
- composite unique indexes over nullable columns, where MySQL and SQLite differ on NULL handling

Set `tests/Pest.php` to apply `RefreshDatabase` globally, and register the `phase0`/`phase1`/`phase2`/`phase3` groups.

## 6. Factories required before any feature test runs

`tests/Feature/CoreGuardrailsTest.php` references factories that do not exist yet. Create, in this order:

`UserFactory` (skeleton-provided, add `credit`/`frozen_credit`/`affiliate_balance`), `ProductFactory`, `InstanceFactory`, `InvoiceFactory`, `FulfillmentTaskFactory`, `RedemptionCodeFactory`, `AffiliateCommissionFactory`, `PaymentCallbackFactory`.

`RedemptionCodeFactory` must set `code_hash` consistently with `code`:

```php
'code'      => $plain = fake()->sha1(),
'code_hash' => hash_hmac('sha256', $plain, config('app.key')),
```

Using the model's `encrypted` cast means the factory cannot rely on `code` alone to satisfy the unique `(product_id, code_hash)` index.

## 7. Models required by the tests

`User` (extends the skeleton's; add the casts and `SoftDeletes`), `Product`, `ProductOptionGroup`, `ProductOptionItem`, `ProductCustomField`, `Instance`, `InstanceUpstreamBinding`, `Invoice`, `PaymentAttempt`, `PaymentCallback`, `FulfillmentTask`, `FinancialTransaction`, `RedemptionCode`, `Coupon`, `CouponClaim`, `CouponUsage`, `AffiliateRule`, `AffiliateCommission`, `AffiliateWithdrawal`, `AuditLog`, `UpstreamOperation`, `UpstreamApi`, `NotificationTemplate`, `NotificationLog`, `KycVerification`, `Ticket`, `TicketReply`.

Casts to set explicitly, since they are load-bearing:

| Model | Column | Cast |
|---|---|---|
| `Instance` | `encrypted_credentials` | `encrypted:array` |
| `Instance` | `active_config`, `assigned_ips`, `custom_field_values` | `array` |
| `RedemptionCode` | `code` | `encrypted` |
| `UpstreamApi` | `encrypted_secret` | `encrypted` |
| `Invoice` | `line_items` | `array` |
| `FinancialTransaction` | `meta` | `array`, `amount`/`balance_after` as strings |

`amount`-style decimals must be cast to `string`, never to `float`, or the `Money` invariant is defeated at the boundary.

## 8. Acceptance checklist for Phase 0

- [ ] `php artisan --version` prints Laravel 13.x
- [ ] `php artisan migrate:fresh` completes on MySQL 8 and on SQLite
- [ ] `./vendor/bin/pest --group=phase0` is green (enums, `Money`, install bypass)
- [ ] `./vendor/bin/phpstan analyse` is green at level 5
- [ ] `php artisan route:list` shows `/up` and `/install*`
- [ ] `composer audit` reports no advisories
- [ ] `git status` is clean and `composer.lock` is committed

Only then is Phase 0 complete, and only then do the Phase 1 gates mean anything.
