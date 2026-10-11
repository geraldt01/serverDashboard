<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec2_patch_statuses', function (Blueprint $table) {
            $table->string('aws_region', 32)->nullable()->after('instance_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ec2_patch_statuses', function (Blueprint $table) {
            $table->dropIndex(['aws_region']);
            $table->dropColumn('aws_region');
        });
    }
};