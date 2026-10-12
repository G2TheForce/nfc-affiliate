<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments through eSewa and Khalti (ADR 0011), the provider
 * callbacks we receive, payment disputes, and order fulfilment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per attempt to pay an order through a provider. An order can
        // have several (the customer gave up and tried again, or switched wallet).
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            // What the provider knows the attempt by: our transaction_uuid for
            // eSewa, the pidx Khalti returns for Khalti.
            $table->string('provider_reference', 100)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            // initiated | pending | completed | failed | needs_review
            $table->string('status', 16);
            $table->string('provider_status', 40)->nullable();
            // The provider's own transaction id once paid (eSewa ref_id, Khalti transaction_id).
            $table->string('provider_transaction_id', 100)->nullable();
            $table->string('review_reason', 255)->nullable();
            $table->unsignedSmallInteger('check_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['status', 'next_check_at']);
            $table->index(['order_id', 'status']);
        });

        // Every provider callback as received, for replay protection and
        // investigation. Secrets and personal data are not stored.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('kind', 20); // return | failure
            // Hash of the raw callback, so a replayed callback is recognised.
            $table->char('event_key', 64);
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('signature_valid')->nullable();
            $table->json('payload');
            // verified_paid | not_paid | rejected | unknown_attempt
            $table->string('outcome', 20)->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('times_received')->default(1);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->unique(['provider', 'event_key']);
            $table->index('received_at');
        });

        Schema::table('order_payments', function (Blueprint $table) {
            $table->foreignId('payment_attempt_id')->nullable()->unique()->after('order_id')->constrained()->restrictOnDelete();
        });

        Schema::create('order_disputes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 500);
            // open | won | lost
            $table->string('status', 8);
            // Set while open, so the database allows one open dispute per order.
            $table->unsignedBigInteger('open_order_id')->nullable()->unique();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            // Commissions this dispute put on hold, released again if it is won.
            $table->json('held_commission_ids')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'reference']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Hash of the secret the customer's browser gets at checkout. It
            // lets a guest start a payment and see its result, nothing more.
            $table->char('access_token_hash', 64)->nullable()->after('request_hash');
            // unfulfilled | processing | shipped | delivered
            $table->string('fulfilment_status', 16)->default('unfulfilled')->after('status')->index();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->string('carrier', 60)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->timestamp('delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['fulfilment_status']);
            $table->dropColumn(['access_token_hash', 'fulfilment_status', 'processing_at', 'shipped_at', 'carrier', 'tracking_number', 'delivered_at']);
        });
        Schema::dropIfExists('order_disputes');
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_attempt_id');
        });
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payment_attempts');
    }
};
