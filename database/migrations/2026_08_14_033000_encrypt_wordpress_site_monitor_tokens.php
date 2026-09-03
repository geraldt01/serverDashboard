<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->text('monitor_token_encrypted')->nullable()->after('monitor_token');
        });

        DB::table('wordpress_sites')->orderBy('id')->each(function (object $site): void {
            DB::table('wordpress_sites')->where('id', $site->id)->update([
                'monitor_token' => hash('sha256', $site->monitor_token),
                'monitor_token_encrypted' => Crypt::encryptString($site->monitor_token),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->dropColumn('monitor_token_encrypted');
        });
    }
};