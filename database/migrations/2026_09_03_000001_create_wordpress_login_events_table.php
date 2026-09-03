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
        Schema::create('wordpress_login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->string('site_name', 120);
            $table->string('username', 120);
            $table->string('ip_address', 45);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('logged_in_at')->useCurrent();
            $table->timestamps();

            $table->index(['wordpress_site_id', 'logged_in_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordpress_login_events');
    }
};
