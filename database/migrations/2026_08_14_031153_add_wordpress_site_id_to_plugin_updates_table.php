<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_plugin_updates', function (Blueprint $table) {
            $table->foreignId('wordpress_site_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['wordpress_site_id', 'plugin_name', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_plugin_updates', function (Blueprint $table) {
            $table->dropIndex(['wordpress_site_id', 'plugin_name', 'id']);
            $table->dropConstrainedForeignId('wordpress_site_id');
        });
    }
};