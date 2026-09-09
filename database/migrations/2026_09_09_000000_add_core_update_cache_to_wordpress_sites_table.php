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
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->string('last_core_current_version', 20)->nullable()->after('last_outdated_count');
            $table->string('last_core_latest_version', 20)->nullable()->after('last_core_current_version');
            $table->string('last_core_status', 20)->nullable()->after('last_core_latest_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->dropColumn(['last_core_current_version', 'last_core_latest_version', 'last_core_status']);
        });
    }
};
