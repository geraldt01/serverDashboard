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
        Schema::create('wordpress_plugin_updates', function (Blueprint $table) {
            $table->id();
            $table->string('site_name', 120);
            $table->string('plugin_name', 255);
            $table->string('current_version', 80);
            $table->string('latest_version', 80);
            $table->string('status', 20)->default('unknown');
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->index(['site_name', 'plugin_name', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordpress_plugin_updates');
    }
};
