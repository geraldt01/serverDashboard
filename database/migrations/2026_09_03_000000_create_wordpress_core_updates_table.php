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
        Schema::create('wordpress_core_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_name', 120);
            $table->string('current_version', 20);
            $table->string('latest_version', 20);
            $table->string('status', 20)->default('unknown');
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->index(['wordpress_site_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordpress_core_updates');
    }
};
