<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Laravel\Passport\Passport;

class InstallCommand extends Command
{
    protected $signature = 'nova-mcp:install';

    protected $description = 'Prepare Nova MCP configuration, migrations, and OAuth keys';

    public function handle(Filesystem $files): int
    {
        $privateKeyExists = filled(config('passport.private_key')) || $files->exists(Passport::keyPath('oauth-private.key'));
        $publicKeyExists = filled(config('passport.public_key')) || $files->exists(Passport::keyPath('oauth-public.key'));

        // Replacing one half would invalidate existing Passport tokens across the host application.
        if ($privateKeyExists !== $publicKeyExists) {
            $this->components->error('Passport has an incomplete key pair. Restore its matching public and private keys before installing Nova MCP. No keys were changed.');

            return self::FAILURE;
        }

        if (! $files->exists(config_path('nova-mcp.php'))) {
            $this->components->info('Publishing Nova MCP configuration.');

            if ($this->call('vendor:publish', ['--tag' => 'nova-mcp-config', '--no-interaction' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $client = Passport::client();

        if (! $client->getConnection()->getSchemaBuilder()->hasTable($client->getTable())) {
            // Published migration timestamps change, so match their names instead of the vendor filenames.
            $existingMigrations = $files->glob(database_path('migrations/*_create_oauth_*_table.php'));

            if ($existingMigrations !== []) {
                $this->components->warn('Passport migrations already exist. Review and run them with php artisan migrate.');
            }

            if ($existingMigrations === [] && $this->call('vendor:publish', ['--tag' => 'passport-migrations', '--no-interaction' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if (! $privateKeyExists) {
            $this->components->info('Generating the missing Passport key pair.');
            $files->ensureDirectoryExists(dirname(Passport::keyPath('oauth-private.key')));

            if ($this->call('passport:keys', ['--no-interaction' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->components->info('Nova MCP installation files are ready.');
        $this->line('Review pending migrations, then run: php artisan migrate');
        $this->line('Add this entry to NovaServiceProvider::tools():');
        $this->line('    new \\Mdrbx\\NovaMcp\\NovaMcp,');

        return self::SUCCESS;
    }
}
