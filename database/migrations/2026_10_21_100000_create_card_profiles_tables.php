<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital tap profiles: the public page a customer's NFC card opens
 * (/p/{handle}), with daily tap and contact-save counts. One per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('handle', 30)->unique();
            $table->string('display_name', 80);
            $table->string('headline', 100)->nullable();
            $table->string('company', 100)->nullable();
            $table->string('bio', 500)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('location', 100)->nullable();
            $table->json('socials')->nullable();
            $table->string('theme', 16)->default('aurora');
            $table->boolean('is_published')->default(false);
            $table->unsignedBigInteger('taps_total')->default(0);
            $table->unsignedBigInteger('saves_total')->default(0);
            $table->timestamps();
        });

        Schema::create('card_profile_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_profile_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('taps')->default(0);
            $table->unsignedInteger('saves')->default(0);

            $table->unique(['card_profile_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_profile_daily_stats');
        Schema::dropIfExists('card_profiles');
    }
};
