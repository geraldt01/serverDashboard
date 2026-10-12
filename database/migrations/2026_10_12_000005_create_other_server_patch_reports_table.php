<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_server_patch_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('other_server_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_updates')->default(0);
            $table->unsignedInteger('security_updates')->default(0);
            $table->boolean('reboot_required')->default(false);
            $table->text('update_details')->nullable();
            $table->text('security_update_details')->nullable();
            $table->timestamp('reported_at')->index();
            $table->timestamps();
            $table->index(['other_server_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_server_patch_reports');
    }
};