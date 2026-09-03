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
        Schema::create('ec2_patch_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id', 64);
            $table->string('instance_name', 255);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('installed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->boolean('reboot_required')->default(false);
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->index(['instance_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ec2_patch_statuses');
    }
};
