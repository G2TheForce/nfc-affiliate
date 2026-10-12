<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Signals for staff to review (spec section 15). A flag never changes
        // money by itself: reviewers hold or void commission with the usual tools.
        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('affiliate_profile_id')->constrained()->restrictOnDelete();
            // high_conversion | clicks_without_orders | refund_rate | rapid_earnings | large_payout | payout_after_detail_change
            $table->string('signal', 32);
            // low | medium | high
            $table->string('severity', 8);
            // What was measured and the threshold it crossed.
            $table->json('details');
            // Optional record the flag is about, e.g. a payout.
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 26)->nullable();
            // One flag per signal, affiliate and period (or subject), so daily runs do not repeat it.
            $table->string('dedupe_key', 120)->unique();
            // open | dismissed | confirmed
            $table->string('status', 10)->default('open');
            $table->timestamp('detected_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'detected_at']);
            $table->index(['affiliate_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
    }
};
