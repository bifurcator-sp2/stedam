<?php

namespace Tests\Unit\Support;

use App\Support\Testing\EnsureTestingDatabase;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Гейт тестовой БД: `artisan test --env=...` не должен прогоняться по
 * рабочей базе — RefreshDatabase необратимо сотрёт её (26–27.11.2026 так
 * исчезли данные laravel).
 */
class EnsureTestingDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Гейт читает config(): фейковый контейнер с repository подменяет
        // рабочий без загрузки приложения (и без подмены error-хендлера).
        $container = new Container;
        $container->instance('config', new Repository([
            'database' => ['default' => 'pgsql', 'connections' => []],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }
    public function test_allows_plain_artisan_test(): void
    {
        $this->assertGatePasses('pgsql', 'laravel', null);
    }

    public function test_allows_testing_database_with_env_option(): void
    {
        $this->assertGatePasses('pgsql', 'testing', 'testing');
    }

    public function test_allows_testing_database_with_suffix(): void
    {
        $this->assertGatePasses('pgsql', 'testing_parallel_1', 'testing');
    }

    public function test_blocks_production_database_with_env_option(): void
    {
        $this->assertGateBlocks('pgsql', 'laravel', 'production');
    }

    public function test_blocks_unknown_database_name(): void
    {
        $this->assertGateBlocks('pgsql', 'laravel_backup', 'staging');
    }

    public function test_ignores_other_commands(): void
    {
        $this->assertGatePasses('pgsql', 'laravel', 'production', 'migrate');
    }

    public function test_ignores_non_database_drivers(): void
    {
        $this->assertGatePasses('sqlite', 'laravel', 'production');
    }

    private function assertGatePasses(string $driver, string $database, ?string $env, string $command = 'test'): void
    {
        $this->gateWith($driver, $database)->handle($this->event($env, $command));

        $this->addToAssertionCount(1);
    }

    private function assertGateBlocks(string $driver, string $database, ?string $env, string $command = 'test'): void
    {
        try {
            $this->gateWith($driver, $database)->handle($this->event($env, $command));
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($database, $e->getMessage());

            return;
        }

        $this->fail('Гейт должен был заблокировать базу '.$database);
    }

    private function event(?string $env, string $command): CommandStarting
    {
        $parameters = ['command' => $command];

        if ($env !== null) {
            // ArrayInput ищет по полному имени «--env», без дефисов ключ
            // молча игнорируется.
            $parameters['--env'] = $env;
        }

        return new CommandStarting($command, new ArrayInput($parameters), new NullOutput);
    }

    private function gateWith(string $driver, string $database): EnsureTestingDatabase
    {
        Container::getInstance()->instance('config', new Repository([
            'database' => [
                'default' => 'gate_probe',
                'connections' => [
                    'gate_probe' => ['driver' => $driver, 'database' => $database],
                ],
            ],
        ]));

        return new EnsureTestingDatabase;
    }
}
