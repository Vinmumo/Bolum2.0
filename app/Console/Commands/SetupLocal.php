<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupLocal extends Command
{
    protected $signature = 'bolum:setup';

    protected $description = 'Prepare a local installation without replacing its key or existing database';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This setup command is for local installations. Use your deployment migration procedure for production.');

            return self::FAILURE;
        }
        if (app()->configurationIsCached()) {
            $this->error('Run php artisan config:clear before local setup so your current .env is used.');

            return self::FAILURE;
        }
        $connection = config('database.default');
        if ($connection === 'sqlite') {
            $path = config('database.connections.sqlite.database');
            if ($path !== ':memory:' && ! is_file($path) && ! @touch($path)) {
                $this->error('Cannot create the SQLite file. Check its configured path and directory permissions.');

                return self::FAILURE;
            }
        }
        if (in_array($connection, ['mysql', 'mariadb'], true)) {
            try {
                $pageSize = (int) DB::selectOne('SELECT @@innodb_page_size AS bytes')->bytes;
                if ($pageSize < 16384) {
                    $this->error('Bolum requires InnoDB pages of at least 16 KB for its composite indexes. Use a compatible database instance or the default SQLite setup.');

                    return self::FAILURE;
                }
            } catch (\Throwable) {
                $this->error('Cannot inspect the database. Check the server, credentials and database name in .env. See docs/INSTALLATION.md.');

                return self::FAILURE;
            }
        }
        if (! config('app.key') && $this->call('key:generate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->call('migrate', ['--seed' => true, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $this->info('Local setup complete. Existing keys, balances and prediction history are preserved.');
        $this->line('Start the web server: php artisan serve');
        $this->line('Start a worker in another terminal: php artisan queue:work --tries=3 --timeout=60');

        return self::SUCCESS;
    }
}
