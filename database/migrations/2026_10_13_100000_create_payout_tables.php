<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payouts (ADR 0009): where an affiliate wants to be paid, payout requests
 * that reserve their available balance, and the commissions each one covers.
 * Account details are stored encrypted; only a masked form is ever shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payout_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_profile_id')->unique()->constrained()->restrictOnDelete();
            // bank_transfer | esewa | khalti
            $table->string('type', 20);
            $table->string('account_name', 120);
            // Encrypted JSON: bank name, branch and account number, or wallet id.
            $table->text('details');
            // Safe to show: bank or wallet name and the last four digits.
            $table->string('masked', 120);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('affiliate_profile_id')->constrained()->restrictOnDelete();
            // Set only while the payout is open (requested or approved), so the
            // database itself allows one open payout per affiliate.
            $table->unsignedBigInteger('open_affiliate_profile_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            // requested | approved | paid | rejected | failed | cancelled
            $table->string('status', 16);
            // Copy of the payout method when requested: later edits never
            // redirect a payout that is already in progress.
            $table->string('method_type', 20);
            $table->string('account_name', 120);
            $table->text('destination');
            $table->string('destination_masked', 120);
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            // Bank or wallet transaction reference for the transfer.
            $table->string('payment_reference', 100)->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // A transfer reference can settle only one payout per method.
            $table->unique(['method_type', 'payment_reference']);
            $table->index(['status', 'requested_at']);
            $table->index(['affiliate_profile_id', 'status']);
        });

        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->restrictOnDelete();
            $table->foreignId('commission_id')->constrained()->restrictOnDelete();
            // The commission's net amount when the payout was requested.
            $table->unsignedBigInteger('amount_minor');

            $table->unique(['payout_id', 'commission_id']);
            $table->index('commission_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('available_at');
        });

        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->foreignId('payout_id')->nullable()->after('refund_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_id');
        });
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('affiliate_payout_methods');
    }
};
