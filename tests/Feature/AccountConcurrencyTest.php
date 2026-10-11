<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountConcurrencyTest extends TestCase
{
    public function test_concurrent_account_commands_create_one_personal_account_and_preserve_the_winner_credentials(): void
    {
        $directory = sys_get_temp_dir().'/uploadiny-account-race-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700, true);
        touch($directory.'/database.sqlite');
        $original = [
            'database.default' => config('database.default'),
            'database.connections.sqlite.database' => config('database.connections.sqlite.database'),
            'filesystems.disks.local.root' => config('filesystems.disks.local.root'),
            'cache.default' => config('cache.default'),
            'cache.stores.file.path' => config('cache.stores.file.path'),
            'cache.stores.file.lock_path' => config('cache.stores.file.lock_path'),
        ];
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $directory.'/database.sqlite',
            'filesystems.disks.local.root' => $directory.'/private',
            'cache.default' => 'file',
            'cache.stores.file.path' => $directory.'/cache',
            'cache.stores.file.lock_path' => $directory.'/cache',
        ]);
        DB::purge();
        Storage::forgetDisk('local');

        $firstEmail = 'account-race-a@example.test';
        $secondEmail = 'account-race-b@example.test';
        $first = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-account-create.php'), $directory, $firstEmail, 'a']);
        $second = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-account-create.php'), $directory, $secondEmail, 'b']);

        try {
            Artisan::call('migrate', ['--force' => true]);
            $first->start();
            $deadline = microtime(true) + 4;
            while (! is_file($directory.'/ready') && $first->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($directory.'/ready', $first->getErrorOutput());
            $second->start();
            usleep(250000);
            touch($directory.'/resume');
            $first->wait();
            $second->wait();

            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(1, $second->getExitCode(), $second->getErrorOutput());
            $this->assertSame(1, User::query()->count());
            $this->assertSame($firstEmail, User::query()->sole()->email);
            $credentials = (string) file_get_contents($directory.'/private/initial-login.txt');
            $this->assertStringContainsString('Email: '.$firstEmail, $credentials);
            $this->assertStringNotContainsString($secondEmail, $credentials);
        } finally {
            $first->stop();
            $second->stop();
            DB::disconnect();
            File::deleteDirectory($directory);
            config($original);
            DB::purge();
            Storage::forgetDisk('local');
        }
    }
}
