<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Commission accounting (ADR 0003, ADR 0008): rules, verified payments and
 * refunds, commission snapshots, adjustments and the append-only ledger.
 * Money is BIGINT minor units with an explicit currency; rates are basis points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('affiliate_profiles', function (Blueprint $table) {
            $table->foreignId('commission_plan_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 120);
            // global | plan | category | product | affiliate
            $table->string('scope', 16);
            $table->foreignId('commission_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('affiliate_profile_id')->nullable()->constrained()->restrictOnDelete();
            // percent | fixed | exclude
            $table->string('kind', 10);
            $table->unsignedInteger('rate_bp')->nullable();
            // Per unit sold, in minor units of `currency`.
            $table->unsignedBigInteger('fixed_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            // Bumped on every edit; commissions snapshot the version they used.
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'is_active']);
        });

        // Refunds name order lines, so lines get a public id too.
        Schema::table('order_items', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->after('id');
        });
        DB::table('order_items')->whereNull('public_id')->orderBy('id')->each(function (object $item) {
            DB::table('order_items')->where('id', $item->id)->update(['public_id' => (string) Str::ulid()]);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->unique('public_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('placed_at');
            $table->unsignedBigInteger('refunded_minor')->default(0)->after('total_minor');
        });

        // A payment is recorded only from verified evidence: a staff member
        // confirming money received with its reference (manual), or a
        // provider status check that confirmed it (Phase 7; redirects are never proof).
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('method', 30);
            $table->string('reference', 100);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('note', 500)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['provider', 'reference']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->char('currency', 3);
            $table->string('reason', 500);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');

            $table->unique(['refund_id', 'order_item_id']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('affiliate_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('rule_version');
            $table->string('rule_scope', 16);
            $table->string('kind', 10);
            $table->unsignedBigInteger('base_minor');
            $table->unsignedInteger('rate_bp')->nullable();
            $table->unsignedBigInteger('fixed_minor')->nullable();
            $table->unsignedInteger('quantity');
            // Amount at creation; reversals accumulate in reversed_minor.
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('reversed_minor')->default(0);
            $table->char('currency', 3);
            // pending | approved | available | paid | reversed
            $table->string('status', 16);
            $table->timestamp('eligible_at');
            $table->timestamp('held_at')->nullable();
            $table->string('hold_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('snapshot');
            $table->timestamps();

            // One commission per order line and affiliate: replayed payment
            // events cannot create a second one.
            $table->unique(['order_item_id', 'affiliate_profile_id']);
            $table->index(['status', 'eligible_at']);
            $table->index(['affiliate_profile_id', 'status']);
            $table->index('order_id');
        });

        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('affiliate_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained()->restrictOnDelete();
            // Signed: positive credits the available balance, negative debits it.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 1000);
            // pending_approval | approved | rejected
            $table->string('status', 20)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();
        });

        // Append-only. Each row moves a positive amount from one bucket to
        // another; NULL is outside the ledger (money earned or written off).
        // Balances are sums: to_bucket adds, from_bucket subtracts.
        Schema::create('commission_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commission_adjustment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('from_bucket', 16)->nullable();
            $table->string('to_bucket', 16)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['affiliate_profile_id', 'to_bucket']);
            $table->index(['affiliate_profile_id', 'from_bucket']);
            $table->index('created_at');
        });

        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // clean | drift
            $table->string('result', 10);
            $table->unsignedInteger('commissions_checked');
            $table->unsignedInteger('affiliates_checked');
            $table->unsignedInteger('entries_checked');
            $table->json('issues');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
        Schema::dropIfExists('commission_ledger_entries');
        Schema::dropIfExists('commission_adjustments');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('order_payments');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'refunded_minor']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
        Schema::dropIfExists('commission_rules');
        Schema::table('affiliate_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_plan_id');
        });
        Schema::dropIfExists('commission_plans');
    }
};
