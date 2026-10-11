<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral tracking and attribution (ADR 0007). Click metadata is minimised:
 * no raw IPs or user agents, only keyed hashes that are cleared after the
 * metadata retention period.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Named tracking links an affiliate creates for a product or campaign.
        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('affiliate_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 60);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['affiliate_profile_id', 'slug']);
        });

        Schema::create('referral_clicks', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('affiliate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('landing_path', 255);
            $table->string('referrer_host', 255)->nullable();
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 64)->nullable();
            $table->string('sub_id', 64)->nullable();
            $table->char('visitor_hash', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            // First click from this visitor for this affiliate within the dedupe window.
            $table->boolean('is_unique')->default(true);
            // False when a first-click policy kept an earlier affiliate's cookie.
            $table->boolean('set_attribution')->default(true);
            $table->timestamp('created_at')->index();

            $table->index(['affiliate_profile_id', 'created_at']);
            $table->index(['visitor_hash', 'affiliate_profile_id', 'created_at'], 'referral_clicks_visitor_lookup');
        });

        // One row per attributed order. Changes are audited (manual attribution).
        Schema::create('order_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_profile_id')->constrained()->restrictOnDelete();
            $table->string('source', 20); // click | coupon | referral_code | manual
            $table->foreignId('referral_click_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('policy', 20);
            $table->unsignedSmallInteger('window_days');
            $table->timestamp('clicked_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 1000)->nullable();
            $table->timestamps();

            $table->index(['affiliate_profile_id', 'created_at']);
        });

        // Order lifecycle events per affiliate. dedupe_key makes recording idempotent.
        Schema::create('referral_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->timestamp('occurred_at');

            $table->index(['affiliate_profile_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_events');
        Schema::dropIfExists('order_attributions');
        Schema::dropIfExists('referral_clicks');
        Schema::dropIfExists('affiliate_links');
    }
};
