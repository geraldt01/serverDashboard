<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_server_patch_runs', function (Blueprint $table) {
            $table->string('install_command_id', 64)->nullable()->after('command_id');
            $table->string('health_check_command_id', 64)->nullable()->after('install_command_id');
            $table->text('install_output')->nullable()->after('output');
            $table->foreignId('install_requested_by')->nullable()->after('alert_email')->constrained('users')->nullOnDelete();
            $table->timestamp('install_requested_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('other_server_patch_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('install_requested_by');
            $table->dropColumn(['install_command_id', 'health_check_command_id', 'install_output', 'install_requested_at']);
        });
    }
};