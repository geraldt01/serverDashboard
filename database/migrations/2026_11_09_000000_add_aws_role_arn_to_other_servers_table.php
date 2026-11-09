<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_servers', function (Blueprint $table) {
            $table->string('aws_role_arn', 2048)->nullable()->after('aws_instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('other_servers', function (Blueprint $table) {
            $table->dropColumn('aws_role_arn');
        });
    }
};