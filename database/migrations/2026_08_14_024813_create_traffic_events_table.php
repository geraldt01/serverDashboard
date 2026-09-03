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
        Schema::create('traffic_events', function (Blueprint $table) {
            $table->id();
            $table->string('site_name', 120);
            $table->unsignedBigInteger('visits')->default(0);
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['site_name', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_events');
    }
};
