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
        Schema::table('other_servers', function (Blueprint $table) {
            $table->string('php_version', 40)->nullable()->after('os_name');
            $table->boolean('php_update_available')->default(false)->after('php_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('other_servers', function (Blueprint $table) {
            $table->dropColumn(['php_version', 'php_update_available']);
        });
    }
};
