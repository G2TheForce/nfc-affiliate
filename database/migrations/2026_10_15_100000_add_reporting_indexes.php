<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the date-range queries behind dashboards and reports, found
 * by the Phase 8 load test (docs/load-and-concurrency.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('paid_at');
            $table->index(['status', 'placed_at']);
        });
        Schema::table('referral_clicks', function (Blueprint $table) {
            // Covers programme-wide click totals and per-affiliate counts by date.
            $table->index(['created_at', 'affiliate_profile_id', 'is_unique']);
        });
        Schema::table('refunds', function (Blueprint $table) {
            $table->index('completed_at');
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->index(['status', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropIndex(['status', 'placed_at']);
        });
        Schema::table('referral_clicks', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'affiliate_profile_id', 'is_unique']);
        });
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['completed_at']);
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex(['status', 'paid_at']);
        });
    }
};
