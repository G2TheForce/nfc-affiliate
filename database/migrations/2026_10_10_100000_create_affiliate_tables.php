<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Published versions of the affiliate terms. Acceptances point here so
        // we can always show exactly which text a member agreed to.
        Schema::create('affiliate_terms_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40)->unique();
            $table->string('title', 200);
            $table->longText('body');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->index();
            $table->timestamps();
        });

        Schema::create('affiliate_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->string('display_name', 120);
            // Assigned on first approval and never reused.
            $table->string('referral_code', 16)->nullable()->unique();
            $table->string('referral_slug', 60)->nullable()->unique();
            $table->char('country_code', 2)->default('NP');
            $table->string('city', 100)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->json('promotion_channels');
            $table->json('website_urls')->nullable();
            $table->text('audience_description')->nullable();
            $table->timestamp('applied_at');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at');
            $table->timestamps();

            $table->index(['status', 'applied_at']);
        });

        // Append-only. public_reason is shown to the member; internal_note never is.
        Schema::create('affiliate_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_profile_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('public_reason', 1000)->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['affiliate_profile_id', 'created_at']);
        });

        Schema::create('affiliate_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('terms_version_id')->constrained('affiliate_terms_versions')->restrictOnDelete();
            $table->timestamp('accepted_at');
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->unique(['affiliate_profile_id', 'terms_version_id'], 'terms_acceptance_unique');
        });

        // Staff-only notes on an affiliate (application review, investigations).
        Schema::create('affiliate_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['affiliate_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_notes');
        Schema::dropIfExists('affiliate_terms_acceptances');
        Schema::dropIfExists('affiliate_status_histories');
        Schema::dropIfExists('affiliate_profiles');
        Schema::dropIfExists('affiliate_terms_versions');
    }
};
