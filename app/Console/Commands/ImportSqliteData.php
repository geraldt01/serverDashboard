<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportSqliteData extends Command
{
    protected $signature = 'database:import-sqlite {--sqlite= : Path to the source SQLite database file}';

    protected $description = 'Copy rows from a legacy SQLite database into empty tables without deleting or overwriting data.';

    private const CHUNK_SIZE = 500;

    private const EXCLUDED_TABLES = ['migrations', 'sqlite_sequence'];

    public function handle(): int
    {
        $path = $this->option('sqlite') ?: database_path('database.sqlite');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('SQLite source file does not exist or is not readable: '.$path);

            return self::FAILURE;
        }

        if (app()->environment('production') && DB::connection()->getDriverName() !== 'mysql') {
            $this->error('Production imports must target MySQL.');

            return self::FAILURE;
        }

        config(['database.connections.sqlite_import' => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('sqlite_import');

        $source = DB::connection('sqlite_import');
        $target = DB::connection();
        $sourceSchema = $source->getSchemaBuilder();
        $targetSchema = $target->getSchemaBuilder();

        if ($source->getDriverName() !== 'sqlite') {
            $this->error('The configured source connection is not SQLite.');

            return self::FAILURE;
        }

        $tables = array_values(array_diff($sourceSchema->getTableListing(), self::EXCLUDED_TABLES));
        $plan = [];

        foreach ($tables as $table) {
            if (! $targetSchema->hasTable($table)) {
                $this->error("Target table '{$table}' is missing. Run all migrations before importing.");

                return self::FAILURE;
            }

            $sourceColumns = $sourceSchema->getColumnListing($table);
            $targetColumns = $targetSchema->getColumnListing($table);
            $missingColumns = array_diff($sourceColumns, $targetColumns);

            if ($missingColumns !== []) {
                $this->error("Target table '{$table}' is missing source columns: ".implode(', ', $missingColumns));

                return self::FAILURE;
            }

            $sourceCount = $source->table($table)->count();
            $targetCount = $target->table($table)->count();

            if ($targetCount !== 0) {
                $this->error("Target table '{$table}' already has {$targetCount} rows; all target data tables must be empty before import.");

                return self::FAILURE;
            }

            $plan[$table] = [
                'source_count' => $sourceCount,
            ];
        }

        $this->info('Preflight passed for '.count($tables).' SQLite tables. Existing target data will not be overwritten.');

        try {
            $this->setForeignKeyChecks($target, false);

            $target->transaction(function () use ($source, $target, $plan): void {
                foreach ($plan as $table => $counts) {
                    if ($counts['source_count'] === 0) {
                        $this->line("Skipped {$table} (source table is empty).");
                        continue;
                    }

                    for ($offset = 0; $offset < $counts['source_count']; $offset += self::CHUNK_SIZE) {
                        $rows = $source->table($table)
                            ->offset($offset)
                            ->limit(self::CHUNK_SIZE)
                            ->get()
                            ->map(fn ($row) => (array) $row)
                            ->all();

                        if ($rows !== []) {
                            $target->table($table)->insert($rows);
                        }
                    }

                    $this->info("Imported {$table}: {$counts['source_count']} rows.");
                }
            });
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Import stopped. Completed tables were retained; rerun after resolving the error.');

            return self::FAILURE;
        } finally {
            $this->setForeignKeyChecks($target, true);
        }

        $this->info('SQLite import completed. The source SQLite file was not modified.');

        return self::SUCCESS;
    }

    private function setForeignKeyChecks($connection, bool $enabled): void
    {
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement('SET FOREIGN_KEY_CHECKS = '.($enabled ? '1' : '0'));
        } elseif ($connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA foreign_keys = '.($enabled ? 'ON' : 'OFF'));
        }
    }
}