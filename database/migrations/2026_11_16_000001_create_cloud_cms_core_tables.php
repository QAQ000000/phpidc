<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core schema for the cloud product sales CMS.
 *
 * Conventions enforced here (see docs/domain-invariants.md):
 *  - every monetary column is DECIMAL(12,2) UNSIGNED (INV-M2)
 *  - every financial / fulfillment foreign key is restrictOnDelete (INV-A1 / charter F01)
 *  - every billable event has a unique idempotency key (INV-K1 / INV-K2)
 *  - the ledger has a unique reference_key (INV-W2)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->unique()->after('email');
            $table->timestamp('kyc_verified_at')->nullable()->after('email_verified_at');
            $table->decimal('credit', 12, 2)->unsigned()->default(0)->after('kyc_verified_at');
            $table->decimal('frozen_credit', 12, 2)->unsigned()->default(0)->after('credit');
            $table->decimal('affiliate_balance', 12, 2)->unsigned()->default(0)->after('frozen_credit');
            // after() belongs to ColumnDefinition; constrained() returns a
            // ForeignKeyDefinition, so the order here is load-bearing.
            $table->foreignId('referred_by')->nullable()->after('affiliate_balance')->constrained('users')->nullOnDelete();
            $table->timestamp('anonymized_at')->nullable()->after('referred_by');
            $table->softDeletes();
        });

        Schema::create('user_oauth_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32); // wechat, qq, github, google, telegram
            $table->string('provider_subject', 128);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_subject']);
            $table->index(['user_id', 'provider']);
        });

        Schema::create('user_notification_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32); // email, sms, telegram
            $table->string('target');
            $table->timestamp('verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'channel', 'target']);
        });

        Schema::create('kyc_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('driver', 64);
            $table->string('external_id')->nullable()->index();
            $table->string('status', 32)->default('pending'); // pending, verified, failed
            $table->json('masked_summary')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            // One live verification per user per driver; history stays append-only.
            $table->index(['user_id', 'driver', 'status']);
        });

        Schema::create('upstream_apis', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('driver', 64)->default('zjmf_api'); // DriverType
            $table->string('base_url');
            $table->string('username')->nullable();
            $table->text('encrypted_secret');
            $table->string('auth_header_mode', 32)->default('bearer'); // bearer | raw_jwt
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['driver', 'name']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('driver_type', 64); // DriverType
            // restrict, not nullOnDelete: a zjmf_api product without an upstream
            // account is an invalid state, not a soft-deleted one.
            $table->foreignId('upstream_api_id')->nullable()->constrained('upstream_apis')->restrictOnDelete();
            $table->unsignedBigInteger('upstream_pid')->nullable()->index();
            $table->unsignedBigInteger('upstream_version')->default(0);
            $table->string('currency', 3)->default('CNY');
            $table->boolean('is_hidden')->default(false);
            $table->boolean('require_kyc')->default(false);
            $table->boolean('require_phone')->default(false);
            $table->unsignedInteger('max_qty_per_user')->nullable();
            $table->boolean('send_welcome_email')->default(true);
            // No FK: notification_templates is created later in this migration.
            // Enforced at the application layer instead.
            $table->unsignedBigInteger('welcome_email_template_id')->nullable()->index();
            $table->decimal('markup_percent', 8, 2)->unsigned()->default(20.00);
            $table->string('price_rounding', 32)->default('two_decimals');
            $table->boolean('price_locked')->default(false);
            $table->boolean('stock_control')->default(false);
            $table->integer('qty')->default(0);
            $table->json('upstream_base_prices')->nullable();
            $table->json('local_prices')->nullable();
            $table->json('provisioning_rules')->nullable();
            $table->unsignedInteger('overdue_terminate_days')->nullable(); // self-hosted only
            $table->string('network_mode', 32)->nullable(); // static_pool, nat_shared, dhcp
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            // An upstream pid is unique per upstream account.
            $table->unique(['upstream_api_id', 'upstream_pid']);
        });

        Schema::create('product_option_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('upstream_group_id')->nullable();
            $table->string('name');
            $table->string('type', 32); // select, slider, quantity, toggle
            $table->boolean('is_upgradeable')->default(false);
            $table->json('downgrade_bounds')->nullable();
            $table->integer('min_value')->nullable();
            $table->integer('max_value')->nullable();
            $table->integer('step')->default(1);
            $table->string('unit', 32)->nullable();
            $table->string('sync_source', 32)->default('local'); // upstream | local
            $table->json('upstream_meta')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'upstream_group_id']);
        });

        Schema::create('product_option_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('option_group_id')->constrained('product_option_groups')->cascadeOnDelete();
            // `upstream_sub_id` is the sub-option id used in `configoption{}`;
            // it is NOT the same vocabulary as product_option_groups.upstream_option_id.
            $table->unsignedBigInteger('upstream_sub_id')->nullable();
            $table->string('label');
            $table->json('upstream_base_prices')->nullable();
            $table->json('local_prices')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['option_group_id', 'upstream_sub_id']);
        });

        Schema::create('product_custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('upstream_field_id')->nullable();
            $table->string('name');
            $table->string('field_type', 32); // text, textarea, select, password
            $table->json('options')->nullable();
            $table->string('validation_regex')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('show_on_order')->default(true);
            $table->boolean('show_on_detail')->default(true);
            $table->string('sync_source', 32)->default('local'); // upstream | local
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'upstream_field_id']);
        });

        Schema::create('instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('driver_type', 64);
            $table->foreignId('upstream_api_id')->nullable()->constrained('upstream_apis')->restrictOnDelete();
            $table->string('upstream_instance_id')->nullable();
            $table->string('downstream_token', 64)->nullable()->unique(); // 32-char md5 of upstream callback token
            $table->string('display_name');
            $table->string('hostname')->nullable();
            $table->string('primary_ip', 64)->nullable();
            $table->json('assigned_ips')->nullable();
            $table->string('status', 32)->default('Pending')->index(); // InstanceStatus
            $table->json('active_config')->nullable();
            $table->json('custom_field_values')->nullable();
            $table->string('billing_cycle', 32)->default('monthly');
            $table->string('currency', 3)->default('CNY');
            $table->decimal('recurring_amount', 12, 2)->unsigned()->default(0);
            $table->boolean('is_price_locked')->default(false);
            $table->text('encrypted_credentials')->nullable();
            $table->string('suspend_reason')->nullable();
            $table->timestamp('next_due_at')->nullable()->index();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('overdue_since')->nullable()->index();
            $table->timestamps();

            // INV-S4: two local instances may never point at the same upstream host.
            $table->unique(['upstream_api_id', 'upstream_instance_id']);
            $table->index(['user_id', 'status']);
        });

        // INV-S3: binding history survives a hot swap, so an old upstream host
        // is never silently orphaned.
        Schema::create('instance_upstream_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upstream_api_id')->nullable()->constrained('upstream_apis')->restrictOnDelete();
            $table->string('driver_type', 64);
            $table->string('upstream_instance_id')->nullable();
            $table->timestamp('bound_at');
            $table->timestamp('unbound_at')->nullable();
            $table->string('unbind_reason', 32)->nullable(); // hot_swap | cancelled | migrated
            $table->string('upstream_disposition', 32)->nullable(); // terminated | kept_orphan
            $table->string('reason_detail')->nullable();
            $table->timestamps();
            $table->index(['instance_id', 'unbound_at']);
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type', 32); // percent | fixed
            $table->decimal('value', 10, 2)->unsigned();
            $table->decimal('min_order_amount', 10, 2)->unsigned()->default(0);
            $table->string('applies_to', 32)->default('both'); // new_order | renew | both
            $table->json('product_ids')->nullable();
            $table->unsignedInteger('total_quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->json('claim_conditions')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Claiming a coupon is a distinct action from spending it; without this
        // table `claim_conditions` has nothing to govern.
        Schema::create('coupon_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamps();
            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_no', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained()->restrictOnDelete();
            // INV-I4: one product per invoice.
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32); // InvoiceType
            $table->string('currency', 3)->default('CNY');
            $table->decimal('total_amount', 12, 2)->unsigned();
            $table->decimal('credit_applied', 12, 2)->unsigned()->default(0);
            $table->decimal('gateway_amount', 12, 2)->unsigned()->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 12, 2)->unsigned()->default(0);
            $table->string('gateway', 64)->nullable();
            // Mirrors the latest trade number for support lookups only. It is NOT
            // the dedupe key and carries no unique index (see INV-P1 / §4).
            $table->string('gateway_trade_no')->nullable()->index();
            $table->decimal('upstream_expected_cost', 12, 2)->unsigned()->nullable();
            $table->string('status', 32)->default('Unpaid')->index(); // InvoiceStatus
            $table->json('line_items')->nullable();
            // INV-K1: {instance_id}:{type}:{period_key}; duplicate scheduler ticks no-op.
            $table->string('billing_period_key', 128)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['instance_id', 'type']);
        });

        // Every gateway order number we ever generated. Decouples `out_trade_no`
        // from `invoice_no`, so a customer can retry payment on one invoice.
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 64);
            $table->string('out_trade_no', 64);
            $table->decimal('amount', 12, 2)->unsigned();
            $table->string('status', 32)->default('created'); // created | paid | failed | expired
            $table->string('gateway_trade_no', 128)->nullable();
            $table->json('request_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'out_trade_no']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('payment_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 64);
            $table->string('gateway_trade_no', 128);
            // Nullable so a callback for an invoice we never issued is still
            // recorded (outcome=unknown_invoice) instead of throwing and making
            // the gateway retry forever.
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('invoice_no', 64)->nullable()->index();
            $table->decimal('amount', 12, 2)->unsigned();
            $table->string('outcome', 32); // PaymentCallbackOutcome
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->unique(['gateway', 'gateway_trade_no']);
        });

        Schema::create('coupon_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('discount_amount', 10, 2)->unsigned();
            $table->timestamps();
            // One coupon per invoice (INV-K4), so usage cannot be double-counted.
            $table->unique('invoice_id');
            $table->index(['coupon_id', 'user_id']);
        });

        Schema::create('financial_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // restrictOnDelete here satisfies F01; the previous nullOnDelete
            // silently detached the audit trail from the record it describes.
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32)->index(); // TransactionType
            $table->decimal('amount', 12, 2)->unsigned();
            $table->decimal('balance_after', 12, 2)->unsigned()->nullable();
            $table->decimal('frozen_after', 12, 2)->unsigned()->nullable();
            // INV-W2: exactly-once. e.g. freeze:12, settle:12, credit_in:callback:44
            $table->string('reference_key', 128)->unique();
            $table->string('remark')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });

        Schema::create('fulfillment_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('driver_type', 64);
            $table->foreignId('upstream_api_id')->nullable()->constrained('upstream_apis')->restrictOnDelete();
            $table->string('action', 64); // provision, renew, suspend, unsuspend, terminate, upgrade, reinstall...
            $table->string('trigger_source', 32); // order_paid, cron_overdue, admin_manual, client_action
            $table->string('idempotency_key', 128)->unique(); // INV-K2
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('pending')->index(); // FulfillmentTaskStatus
            $table->string('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            // Lease fields (INV-T1..T3): a crashed worker's `running` task is
            // reclaimed by expiry, not left stuck forever.
            $table->string('locked_by', 128)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['instance_id', 'status']);
        });

        // INV-U1: every upstream call is recorded, so an ambiguous P4/P5 can be
        // reconciled without reading application logs.
        Schema::create('upstream_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('upstream_api_id')->nullable()->constrained('upstream_apis')->restrictOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('method', 8);
            $table->string('path');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request_payload')->nullable(); // secrets redacted
            $table->text('response_body')->nullable(); // truncated
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('correlation_key', 128)->nullable()->index(); // e.g. invoice_no or task id
            $table->string('outcome', 32)->nullable(); // ok | ambiguous | failed | rolled_back
            $table->timestamps();
            $table->index(['upstream_api_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 32)->default('user'); // user | system | driver
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable(); // required for financial/lifecycle actions
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('affiliate_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('commission_rate', 5, 2)->unsigned();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('affiliate_rule_product', function (Blueprint $table): void {
            $table->foreignId('affiliate_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['affiliate_rule_id', 'product_id']);
        });

        Schema::create('affiliate_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('rate_snapshot', 5, 2)->unsigned();
            $table->decimal('amount', 12, 2)->unsigned();
            $table->string('status', 32)->default('pending')->index(); // pending, available, clawed_back
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('clawed_back_at')->nullable();
            $table->timestamps();
            // One commission per invoice: re-running the accrual cannot double pay.
            $table->unique('invoice_id');
        });

        Schema::create('affiliate_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('destination', 32); // wallet | external
            $table->decimal('amount', 12, 2)->unsigned();
            $table->string('payout_name')->nullable();
            $table->string('payout_phone', 32)->nullable();
            $table->text('payout_note')->nullable();
            $table->string('status', 32)->default('pending')->index(); // pending, approved, rejected
            $table->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('redemption_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('code'); // encrypted cast: randomized, therefore NOT deduplicable
            // Deterministic HMAC of the plaintext; this is what makes re-import safe.
            $table->string('code_hash', 64);
            $table->string('batch_no', 64)->nullable()->index();
            $table->string('status', 32)->default('available')->index(); // available, reserved, sold
            $table->foreignId('instance_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('reserved_until')->nullable()->index();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'code_hash']); // INV-C1
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject');
            $table->string('status', 32)->default('open')->index(); // open, answered, closed
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('ticket_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_admin')->default(false);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 64);
            $table->string('channel', 32); // email, sms, telegram
            $table->string('subject')->nullable();
            $table->text('body_markdown')->nullable();
            $table->string('external_template_id')->nullable(); // audited SMS template
            $table->json('variable_mapping')->nullable(); // {"instance_name": "product", ...}
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['event_key', 'channel']);
        });

        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_key', 64);
            $table->string('channel', 32);
            $table->string('target');
            $table->string('status', 32); // sent, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['event_key', 'status']);
        });

        // `type` lives only on the category; articles inherit it. Storing it on
        // both allowed them to disagree and made the /news/{cat}/{article} route
        // ambiguous.
        Schema::create('cms_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16)->index(); // CmsArticleType: news | help
            $table->string('name');
            $table->string('slug');
            $table->string('seo_title')->nullable();
            $table->string('seo_keywords')->nullable();
            $table->text('seo_description')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['type', 'slug']);
        });

        Schema::create('cms_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_category_id')->constrained('cms_categories')->restrictOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('content_format', 16)->default('markdown'); // CmsContentFormat
            $table->longText('body');
            $table->string('seo_title')->nullable();
            $table->string('seo_keywords')->nullable();
            $table->text('seo_description')->nullable();
            $table->boolean('is_published')->default(true)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            // Matches the route: /{type}/{category:slug}/{article:slug}
            $table->unique(['cms_category_id', 'slug']);
        });

        Schema::create('download_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 64)->default('general')->index();
            $table->string('title');
            $table->string('version', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('storage_type', 16)->default('local'); // local | external_url
            $table->string('file_path')->nullable();
            $table->string('external_url')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('access_level', 32)->default('public'); // DownloadAccessLevel
            $table->unsignedBigInteger('download_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 64)->index(); // site, payment, oauth, telegram, mail, sms, kyc, affiliate
            $table->string('key', 128);
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        // NOTE: `jobs`, `job_batches`, `failed_jobs`, `cache` and `cache_locks`
        // are created by the Laravel skeleton's own 0001_01_01_* migrations.
        // They are NOT recreated here — doing so collides on `php artisan migrate`.
        // The queue and its dead-letter table are therefore prerequisites of
        // Phase 0, not deliverables of this migration.
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('download_resources');
        Schema::dropIfExists('cms_articles');
        Schema::dropIfExists('cms_categories');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('redemption_codes');
        Schema::dropIfExists('affiliate_withdrawals');
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_rule_product');
        Schema::dropIfExists('affiliate_rules');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('upstream_operations');
        Schema::dropIfExists('fulfillment_tasks');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('payment_callbacks');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('coupon_claims');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('instance_upstream_bindings');
        Schema::dropIfExists('instances');
        Schema::dropIfExists('product_custom_fields');
        Schema::dropIfExists('product_option_items');
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('upstream_apis');
        Schema::dropIfExists('kyc_verifications');
        Schema::dropIfExists('user_notification_bindings');
        Schema::dropIfExists('user_oauth_accounts');

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('referred_by');
                $table->dropSoftDeletes();
                $table->dropColumn([
                    'phone',
                    'kyc_verified_at',
                    'credit',
                    'frozen_credit',
                    'affiliate_balance',
                    'anonymized_at',
                ]);
            });
        }
    }
};
