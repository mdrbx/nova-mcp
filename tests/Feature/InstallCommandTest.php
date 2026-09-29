<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaCoreServiceProvider;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use Mdrbx\NovaMcp\NovaMcpServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InstallCommandTest extends TestCase
{
    private string $directory;

    private ?string $originalKeyPath;

    protected function setUp(): void
    {
        $this->originalKeyPath = Passport::$keyPath;
        $this->directory = sys_get_temp_dir().'/nova-mcp-install-'.bin2hex(random_bytes(8));

        foreach (['config', 'database/migrations', 'storage'] as $path) {
            (new Filesystem)->ensureDirectoryExists($this->directory.'/'.$path);
        }

        Passport::loadKeysFrom($this->directory.'/storage');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        Passport::$keyPath = $this->originalKeyPath;
        Nova::flushState();

        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->directory);
    }

    protected function getPackageProviders($app): array
    {
        return [PassportServiceProvider::class, NovaCoreServiceProvider::class, NovaMcpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useConfigPath($this->directory.'/config');
        $app->useDatabasePath($this->directory.'/database');
        $app->useStoragePath($this->directory.'/storage');
        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'passport.private_key' => null,
            'passport.public_key' => null,
            'nova.guard' => 'web',
        ]);
    }

    public function test_fresh_installation_publishes_files_and_preserves_them_on_rerun_without_migrating(): void
    {
        $this->artisan('nova-mcp:install')
            ->expectsOutputToContain('php artisan migrate')
            ->expectsOutputToContain('new \\Mdrbx\\NovaMcp\\NovaMcp')
            ->assertSuccessful();

        $this->assertFileExists(config_path('nova-mcp.php'));
        $this->assertNotEmpty(glob(database_path('migrations/*_create_oauth_clients_table.php')));
        $privateKey = file_get_contents(Passport::keyPath('oauth-private.key'));
        $publicKey = file_get_contents(Passport::keyPath('oauth-public.key'));
        openssl_sign('installation check', $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, openssl_verify('installation check', $signature, $publicKey, OPENSSL_ALGO_SHA256));
        $this->assertFalse(Schema::hasTable('oauth_clients'));
        $this->assertFalse(Schema::hasTable('nova_mcp_clients'));
        $migrations = glob(database_path('migrations/*.php'));
        $customConfiguration = '<?php return ["path" => "custom-mcp"];';
        file_put_contents(config_path('nova-mcp.php'), $customConfiguration);

        $this->artisan('nova-mcp:install')->assertSuccessful();

        $this->assertSame($customConfiguration, file_get_contents(config_path('nova-mcp.php')));
        $this->assertSame($privateKey, file_get_contents(Passport::keyPath('oauth-private.key')));
        $this->assertSame($publicKey, file_get_contents(Passport::keyPath('oauth-public.key')));
        $this->assertSame($migrations, glob(database_path('migrations/*.php')));
        $this->assertFalse(Schema::hasTable('oauth_clients'));
    }

    public function test_existing_passport_tables_are_kept_without_publishing_duplicate_migrations(): void
    {
        Schema::create('oauth_clients', static function ($table): void {
            $table->id();
        });
        config(['passport.private_key' => 'existing private key', 'passport.public_key' => 'existing public key']);

        $this->artisan('nova-mcp:install')->assertSuccessful();

        $this->assertSame([], glob(database_path('migrations/*_create_oauth_*_table.php')));
        $this->assertTrue(Schema::hasTable('oauth_clients'));
        $this->assertFileDoesNotExist(Passport::keyPath('oauth-private.key'));
        $this->assertFileDoesNotExist(Passport::keyPath('oauth-public.key'));
        $this->assertSame('existing private key', config('passport.private_key'));
        $this->assertSame('existing public key', config('passport.public_key'));
    }

    public function test_existing_pending_passport_migrations_are_not_published_again(): void
    {
        $migration = database_path('migrations/2026_01_01_000001_create_oauth_clients_table.php');
        file_put_contents($migration, '<?php // Host application migration.');
        config(['passport.private_key' => 'private key', 'passport.public_key' => 'public key']);

        $this->artisan('nova-mcp:install')
            ->expectsOutputToContain('Passport migrations already exist')
            ->assertSuccessful();

        $this->assertSame([$migration], glob(database_path('migrations/*.php')));
        $this->assertSame('<?php // Host application migration.', file_get_contents($migration));
        $this->assertFalse(Schema::hasTable('oauth_clients'));
    }

    #[DataProvider('incompleteKeys')]
    public function test_incomplete_key_pairs_fail_before_changing_existing_files(string $source, string $type): void
    {
        if ($source === 'config') {
            config(["passport.{$type}_key" => 'existing key']);
        }

        if ($source === 'file') {
            file_put_contents(Passport::keyPath("oauth-{$type}.key"), 'existing key');
        }

        $this->artisan('nova-mcp:install')
            ->expectsOutputToContain('incomplete key pair')
            ->assertExitCode(Command::FAILURE);

        $this->assertFileDoesNotExist(config_path('nova-mcp.php'));
        $this->assertSame([], glob(database_path('migrations/*.php')));
        $missingType = $type === 'private' ? 'public' : 'private';
        $this->assertFileDoesNotExist(Passport::keyPath("oauth-{$missingType}.key"));

        if ($source === 'config') {
            $this->assertSame('existing key', config("passport.{$type}_key"));
            $this->assertFileDoesNotExist(Passport::keyPath("oauth-{$type}.key"));

            return;
        }

        $this->assertSame('existing key', file_get_contents(Passport::keyPath("oauth-{$type}.key")));
    }

    /** @return array<string, array{string, string}> */
    public static function incompleteKeys(): array
    {
        return [
            'private key file only' => ['file', 'private'],
            'public key file only' => ['file', 'public'],
            'private inline key only' => ['config', 'private'],
            'public inline key only' => ['config', 'public'],
        ];
    }
}
