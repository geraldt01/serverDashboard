<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_servers', function (Blueprint $table) {
            $table->text('critical_services')->nullable();
        });

        Schema::create('other_server_patch_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('other_server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('server_name', 120);
            $table->string('instance_id', 32);
            $table->string('region', 32);
            $table->text('role_arn')->nullable();
            $table->string('account_id', 12)->nullable();
            $table->string('active_target', 100)->nullable()->unique();
            $table->string('status', 32)->default('queued')->index();
            $table->string('alert_email');
            $table->json('services');
            $table->json('plan')->nullable();
            $table->string('command_id', 64)->nullable();
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('alert_sent_at')->nullable();
            $table->text('alert_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_server_patch_runs');
        Schema::table('other_servers', function (Blueprint $table) {
            $table->dropColumn('critical_services');
        });
    }
};