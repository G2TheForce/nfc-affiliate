<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal built-in store (ADR 0007): catalog, affiliate coupons and orders,
 * enough to run the real referral journey end to end. Money is BIGINT minor
 * units (paisa) with an explicit currency (ADR 0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // Stored upper-case; matched case-insensitively at checkout.
            $table->string('code', 40)->unique();
            $table->foreignId('affiliate_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('discount_type', 10); // percent | fixed
            // Basis points for percent (1000 = 10%), minor units for fixed.
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('min_order_minor')->default(0);
            $table->unsignedBigInteger('max_discount_minor')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            // Whether orders using this coupon may earn commission (Phase 4).
            $table->boolean('earns_commission')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Empty means the coupon applies to every product.
        Schema::create('coupon_product', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'product_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('order_number', 32)->unique();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_email', 255)->index();
            $table->string('customer_phone', 20);
            $table->string('shipping_city', 100);
            $table->string('shipping_address', 255);
            $table->string('status', 24)->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->foreignId('coupon_id')->nullable()->constrained()->restrictOnDelete();
            // attributed | none | blocked; reason explains a blocked attribution.
            $table->string('attribution_status', 16)->default('none')->index();
            $table->string('attribution_block_reason', 40)->nullable();
            // Client-generated key so a double-submitted checkout creates one order.
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('placed_at')->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
        });

        // Name, SKU and price are snapshots: later catalog edits never change an order.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('sku', 64);
            $table->string('name', 200);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_subtotal_minor');
            // The share of the order discount allocated to this line.
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            // Keyed hash of the user id or normalised email, for per-customer limits.
            $table->char('customer_key', 64);
            $table->unsignedBigInteger('discount_minor');
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['coupon_id', 'customer_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
