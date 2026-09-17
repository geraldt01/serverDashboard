<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_servers', function (Blueprint $table) {
            $table->text('security_update_details')->nullable()->after('security_updates');
        });
    }

    public function down(): void
    {
        Schema::table('other_servers', function (Blueprint $table) {
            $table->dropColumn('security_update_details');
        });
    }
};
