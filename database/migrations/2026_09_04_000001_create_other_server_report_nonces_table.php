<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_server_report_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('other_server_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 64)->unique();
            $table->timestamp('seen_at')->useCurrent();
            $table->index('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_server_report_nonces');
    }
};
