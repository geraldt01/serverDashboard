<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wordpress_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('url', 2048)->unique();
            $table->string('monitor_token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('last_plugin_count')->default(0);
            $table->unsignedInteger('last_outdated_count')->default(0);
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordpress_sites');
    }
};
