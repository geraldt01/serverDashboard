<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_report_settings', function (Blueprint $table) {
            $table->id();
            $table->string('manager_email')->nullable();
            $table->text('public_token')->nullable();
            $table->char('public_token_hash', 64)->nullable()->unique();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_settings');
    }
};