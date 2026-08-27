<?php

namespace Tests\Feature;

use App\Console\Commands\ResetE2eDatabase;
use Database\Seeders\E2eSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Expectation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * E2E DB resetの安全ガードとサブコマンド制御を、実DBへ接続せず検証する。
 *
 * RefreshDatabaseやArtisan facade経由では破壊的コマンドが実行される余地があるため、
 * DB接続はFacade mock、サブコマンドはcall()を上書きしたテストダブルで隔離する。
 */
class ResetE2eDatabaseTest extends TestCase
{
    /**
     * E2E以外の環境ではDBへ接続せず、破壊的処理を開始しないことを保証する。
     */
    public function test_it_fails_before_connecting_when_environment_is_not_e2e(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');

        DB::shouldReceive('connection')->never();

        $command = new TestableResetE2eDatabase;
        $result = $this->executeCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString(
            'E2E環境ではないため、データベースリセットを中止しました。',
            $result['display'],
        );
        $this->assertSame([], $command->calls);
    }

    /**
     * 設定上のDB名が不一致なら実DBへ接続せず、破壊的処理を開始しないことを保証する。
     */
    public function test_it_fails_before_connecting_when_configured_database_is_not_e2e_database(): void
    {
        $this->setE2eEnvironment();
        config()->set('database.connections.e2e_mysql.database', 'unexpected_database');

        DB::shouldReceive('connection')->never();

        $command = new TestableResetE2eDatabase;
        $result = $this->executeCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString(
            '設定上のDB名が e2e_testing ではないため、データベースリセットを中止しました。',
            $result['display'],
        );
        $this->assertSame([], $command->calls);
    }

    /**
     * 実接続先DB名を取得できない場合は、破壊的処理を開始しないことを保証する。
     */
    public function test_it_fails_when_actual_database_cannot_be_determined(): void
    {
        $this->setSuccessfulConfigGuard();
        $this->mockActualDatabase(null);

        $command = new TestableResetE2eDatabase;
        $result = $this->executeCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString(
            '実接続先DB名を取得できないため、データベースリセットを中止しました。',
            $result['display'],
        );
        $this->assertSame([], $command->calls);
    }

    /**
     * 実接続先DB名が不一致なら、破壊的処理を開始しないことを保証する。
     */
    public function test_it_fails_when_actual_database_is_not_e2e_database(): void
    {
        $this->setSuccessfulConfigGuard();
        $this->mockActualDatabase('unexpected_database');

        $command = new TestableResetE2eDatabase;
        $result = $this->executeCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString(
            '実際の接続先が e2e_testing ではないため、データベースリセットを中止しました。',
            $result['display'],
        );
        $this->assertSame([], $command->calls);
    }

    /**
     * wipeに失敗した場合は、migrationとSeederを実行しないことを保証する。
     */
    public function test_it_stops_when_database_wipe_fails(): void
    {
        $this->setSuccessfulGuards();

        $command = new TestableResetE2eDatabase([1]);
        $result = $this->executeCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('E2E DBのwipeに失敗しました。', $result['display']);
        $this->assertSame([
            $this->expectedWipeCall(),
        ], $command->calls);
    }

    /**
     * migrationに失敗した場合は、Seederを実行しないことを保証する。
     */
    public function test_it_stops_when_migration_fails(): void
    {
        $this->setSuccessfulGuards();

        $command = new TestableResetE2eDatabase([0, 1]);
        $result = $this->executeCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('E2E DBのmigrationに失敗しました。', $result['display']);
        $this->assertSame([
            $this->expectedWipeCall(),
            $this->expectedMigrateCall(),
        ], $command->calls);
    }

    /**
     * Seederに失敗した場合は異常終了することを保証する。
     */
    public function test_it_fails_when_seeding_fails(): void
    {
        $this->setSuccessfulGuards();

        $command = new TestableResetE2eDatabase([0, 0, 1]);
        $result = $this->executeCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('E2E fixtureの投入に失敗しました。', $result['display']);
        $this->assertSame([
            $this->expectedWipeCall(),
            $this->expectedMigrateCall(),
            $this->expectedSeedCall(),
        ], $command->calls);
    }

    /**
     * 全ガード通過後、固定connectionでwipe、migration、Seederの順に実行することを保証する。
     */
    public function test_it_resets_e2e_database_with_fixed_arguments(): void
    {
        $this->setSuccessfulGuards();

        $command = new TestableResetE2eDatabase([0, 0, 0]);
        $result = $this->executeCommand($command);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('', $result['display']);
        $this->assertSame([
            $this->expectedWipeCall(),
            $this->expectedMigrateCall(),
            $this->expectedSeedCall(),
        ], $command->calls);
    }

    /**
     * Laravel標準のrun()経路でConsole outputを初期化し、本物のhandle()を実行する。
     *
     * @return array{exit_code: int, display: string}
     */
    private function executeCommand(TestableResetE2eDatabase $command): array
    {
        $command->setLaravel($this->app);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        return [
            'exit_code' => $exitCode,
            'display' => $tester->getDisplay(),
        ];
    }

    private function setSuccessfulGuards(): void
    {
        $this->setSuccessfulConfigGuard();
        $this->mockActualDatabase('e2e_testing');
    }

    private function setSuccessfulConfigGuard(): void
    {
        $this->setE2eEnvironment();
        config()->set('database.connections.e2e_mysql.database', 'e2e_testing');
    }

    private function setE2eEnvironment(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'e2e');
    }

    private function mockActualDatabase(?string $databaseName): void
    {
        $connection = Mockery::mock(Connection::class);
        $selectExpectation = $connection->shouldReceive('selectOne');

        assert($selectExpectation instanceof Expectation);

        $selectExpectation
            ->once()
            ->with('SELECT DATABASE() AS database_name')
            ->andReturn($databaseName === null ? null : (object) [
                'database_name' => $databaseName,
            ]);

        DB::shouldReceive('connection')
            ->once()
            ->with('e2e_mysql')
            ->andReturn($connection);
    }

    /**
     * @return array{
     *     command: string,
     *     arguments: array{'--database': string, '--force': bool}
     * }
     */
    private function expectedWipeCall(): array
    {
        return [
            'command' => 'db:wipe',
            'arguments' => [
                '--database' => 'e2e_mysql',
                '--force' => true,
            ],
        ];
    }

    /**
     * @return array{
     *     command: string,
     *     arguments: array{'--database': string, '--force': bool}
     * }
     */
    private function expectedMigrateCall(): array
    {
        return [
            'command' => 'migrate',
            'arguments' => [
                '--database' => 'e2e_mysql',
                '--force' => true,
            ],
        ];
    }

    /**
     * @return array{
     *     command: string,
     *     arguments: array{
     *         '--class': class-string<E2eSeeder>,
     *         '--database': string,
     *         '--force': bool
     *     }
     * }
     */
    private function expectedSeedCall(): array
    {
        return [
            'command' => 'db:seed',
            'arguments' => [
                '--class' => E2eSeeder::class,
                '--database' => 'e2e_mysql',
                '--force' => true,
            ],
        ];
    }
}

/**
 * 破壊的Artisanサブコマンドを実行せず、呼び出しと終了コードだけをテストする。
 */
final class TestableResetE2eDatabase extends ResetE2eDatabase
{
    /**
     * @var list<array{command: string, arguments: array<string, bool|string>}>
     */
    public array $calls = [];

    /**
     * @param  list<int>  $exitCodes
     */
    public function __construct(private array $exitCodes = [])
    {
        parent::__construct();
    }

    /**
     * Laravel標準Command::call()と同じ入力を受け、実行せずに呼び出し内容だけを記録する。
     *
     * @param  Command|string  $command
     * @param  array<mixed>  $arguments
     */
    #[\Override]
    public function call($command, array $arguments = []): int
    {
        assert(is_string($command));

        foreach ($arguments as $key => $value) {
            assert(is_string($key));
            assert(is_bool($value) || is_string($value));
        }

        /** @var array<string, bool|string> $arguments */
        $this->calls[] = [
            'command' => $command,
            'arguments' => $arguments,
        ];

        return array_shift($this->exitCodes) ?? 0;
    }
}
