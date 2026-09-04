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
        Schema::table('ec2_patch_statuses', function (Blueprint $table) {
            $table->unsignedInteger('security_count')->default(0)->after('missing_count');
            $table->string('os_version', 120)->nullable()->after('reboot_required');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ec2_patch_statuses', function (Blueprint $table) {
            $table->dropColumn(['security_count', 'os_version']);
        });
    }
};
