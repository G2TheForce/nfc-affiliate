<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns: dated promotions limited to chosen products and affiliates.
 * A campaign's commission comes from a commission rule with scope
 * "campaign"; the order remembers which campaign it came through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 120);
            $table->string('description', 2000)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // Informational: shown against the commission earned so far.
            $table->unsignedBigInteger('budget_minor')->nullable();
            $table->boolean('is_active')->default(true);
            // When false, only the affiliates in campaign_affiliate take part.
            $table->boolean('all_affiliates')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at']);
        });

        Schema::create('campaign_affiliate', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_profile_id')->constrained()->cascadeOnDelete();
            $table->primary(['campaign_id', 'affiliate_profile_id']);
        });

        // Empty means the campaign covers every product.
        Schema::create('campaign_product', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['campaign_id', 'product_id']);
        });

        foreach (['affiliate_links', 'coupons', 'order_attributions', 'commission_rules'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['commission_rules', 'order_attributions', 'coupons', 'affiliate_links'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('campaign_id');
            });
        }
        Schema::dropIfExists('campaign_product');
        Schema::dropIfExists('campaign_affiliate');
        Schema::dropIfExists('campaigns');
    }
};
