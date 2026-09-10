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
            $table->string('last_php_version', 20)->nullable()->after('last_core_status');
            $table->string('last_php_recommended_version', 20)->nullable()->after('last_php_version');
            $table->string('last_php_status', 20)->nullable()->after('last_php_recommended_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->dropColumn(['last_php_version', 'last_php_recommended_version', 'last_php_status']);
        });
    }
};
