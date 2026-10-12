<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing materials staff share with affiliates: banners, photos, videos,
 * PDFs, zipped media kits, or ready-to-post text. Files live on the private
 * "local" disk and are only served through the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('title', 120);
            $table->string('description', 2000)->nullable();
            $table->string('kind', 16); // image | video | document | archive | text
            // Suggested post text; the whole asset for kind "text".
            $table->text('caption')->nullable();
            $table->string('path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('download_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_assets');
    }
};
