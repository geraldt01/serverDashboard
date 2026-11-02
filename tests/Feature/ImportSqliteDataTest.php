<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportSqliteDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_sqlite_rows_and_refuses_a_second_run(): void
    {
        $sourcePath = $this->createSqliteSource();

        try {
            $this->artisan('database:import-sqlite', ['--sqlite' => $sourcePath])
                ->assertExitCode(0);

            $this->assertSame(2, DB::table('users')->count());
            $this->assertDatabaseHas('users', ['id' => 2, 'email' => 'second@example.test']);

            $this->artisan('database:import-sqlite', ['--sqlite' => $sourcePath])
                ->assertExitCode(1);

            $this->assertSame(2, DB::table('users')->count());
        } finally {
            @unlink($sourcePath);
        }
    }

    public function test_it_refuses_a_partially_populated_target_without_changing_rows(): void
    {
        $sourcePath = $this->createSqliteSource();
        DB::table('users')->insert([
            'name' => 'Existing account',
            'email' => 'existing@example.test',
            'password' => 'not-a-real-password-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->artisan('database:import-sqlite', ['--sqlite' => $sourcePath])
                ->assertExitCode(1);

            $this->assertSame(1, DB::table('users')->count());
            $this->assertDatabaseHas('users', ['email' => 'existing@example.test']);
        } finally {
            @unlink($sourcePath);
        }
    }

    private function createSqliteSource(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sqlite-import-');
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration VARCHAR(255), batch INTEGER)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1, 'First account', 'first@example.test', 'hash-one', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users VALUES (2, 'Second account', 'second@example.test', 'hash-two', '2026-01-02 00:00:00', '2026-01-02 00:00:00')");
        $pdo = null;

        return $path;
    }
}