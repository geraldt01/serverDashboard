<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_report_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 64)->unique();
            $table->timestamp('seen_at')->useCurrent();
            $table->index('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_report_nonces');
    }
};