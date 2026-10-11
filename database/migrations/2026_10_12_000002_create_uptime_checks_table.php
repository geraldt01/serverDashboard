<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->string('asset_type', 20);
            $table->string('asset_key', 160);
            $table->string('asset_name');
            $table->boolean('is_up');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('detail')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();
            $table->index(['asset_type', 'asset_key', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_checks');
    }
};