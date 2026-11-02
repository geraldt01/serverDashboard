<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('wordpress_sites')) {
            if (DB::connection()->getDriverName() !== 'mysql') {
                throw new \RuntimeException('The existing wordpress_sites table needs manual migration recovery.');
            }

            if (DB::table('wordpress_sites')->whereRaw('CHAR_LENGTH(url) > 700')->exists()) {
                throw new \RuntimeException('wordpress_sites contains URLs longer than 700 characters; no data was changed.');
            }

            DB::statement('ALTER TABLE `wordpress_sites` MODIFY `url` VARCHAR(700) NOT NULL');

            $indexNames = collect(DB::select('SHOW INDEX FROM `wordpress_sites`'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            if (! in_array('wordpress_sites_url_unique', $indexNames, true)) {
                Schema::table('wordpress_sites', function (Blueprint $table) {
                    $table->unique('url');
                });
            }

            if (! in_array('wordpress_sites_monitor_token_unique', $indexNames, true)) {
                Schema::table('wordpress_sites', function (Blueprint $table) {
                    $table->unique('monitor_token');
                });
            }

            return;
        }

        Schema::create('wordpress_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('url', 700)->unique();
            $table->string('monitor_token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('last_plugin_count')->default(0);
            $table->unsignedInteger('last_outdated_count')->default(0);
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wordpress_sites');
    }
};
