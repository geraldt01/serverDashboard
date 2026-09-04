<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('hostname', 255)->nullable();
            $table->string('monitor_token', 64)->unique();
            $table->text('monitor_token_encrypted')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('os_name', 120)->nullable();
            $table->unsignedInteger('total_updates')->default(0);
            $table->unsignedInteger('security_updates')->default(0);
            $table->boolean('reboot_required')->default(false);
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_servers');
    }
};
