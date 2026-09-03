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
        Schema::table('wordpress_login_events', function (Blueprint $table) {
            // null = no whitelist configured for the site at the time of login
            $table->boolean('is_authorized')->nullable()->after('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wordpress_login_events', function (Blueprint $table) {
            $table->dropColumn('is_authorized');
        });
    }
};
