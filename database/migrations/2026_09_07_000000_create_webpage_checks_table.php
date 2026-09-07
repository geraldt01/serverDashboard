<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webpage_checks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('url', 2048);
            $table->boolean('is_active')->default(true);
            $table->text('required_elements')->nullable();
            $table->string('last_status', 20)->default('unknown');
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->unsignedInteger('last_response_time_ms')->nullable();
            $table->unsignedInteger('broken_images_count')->default(0);
            $table->unsignedInteger('broken_videos_count')->default(0);
            $table->unsignedInteger('missing_elements_count')->default(0);
            $table->json('issues')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webpage_checks');
    }
};
