<?php

namespace App\Console\Commands;

use Database\Seeders\E2eSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E2E専用データベースを安全にリセットするArtisanコマンド。
 *
 * 開発DBなどへの誤操作を防ぐため、E2E環境・固定connection・
 * 設定上のDB名・実接続先DB名を検証してから破壊的処理を実行する。
 */
class ResetE2eDatabase extends Command
{
    protected $signature = 'e2e:reset';

    protected $description = 'E2E専用データベースを安全にリセットする';

    private const CONNECTION = 'e2e_mysql';

    private const DB_DATABASE = 'e2e_testing';

    public function handle(): int
    {
        // 開発DBなどで破壊的resetが実行されないよう、Laravelが認識する実効環境を確認する。
        if (! app()->environment('e2e')) {
            $this->error('E2E環境ではないため、データベースリセットを中止しました。');

            return 1;
        }

        // 環境変数の誤設定だけでreset対象が変わらないよう、固定connectionの実効DB名を照合する。
        $configuredDatabase = config('database.connections.'.self::CONNECTION.'.database');

        if ($configuredDatabase !== self::DB_DATABASE) {
            $this->error('設定上のDB名が e2e_testing ではないため、データベースリセットを中止しました。');

            return 1;
        }

        // 設定値だけを信用せず、実際のMySQL接続先もe2e_testingであることを破壊処理の直前に確認する。
        /** @var object{database_name: string}|null $actualDatabase */
        $actualDatabase = DB::connection(self::CONNECTION)
            ->selectOne('SELECT DATABASE() AS database_name');

        if ($actualDatabase === null) {
            $this->error('実接続先DB名を取得できないため、データベースリセットを中止しました。');

            return 1;
        }

        if ($actualDatabase->database_name !== self::DB_DATABASE) {
            $this->error('実際の接続先が e2e_testing ではないため、データベースリセットを中止しました。');

            return 1;
        }

        // 外部入力で対象DBを変更できないよう、検証済みのE2E専用connectionをコード側で固定してresetする。
        // wipeとmigrationを分離し、それぞれの失敗を確実にe2e:resetの異常終了へ伝播させる。
        $wipeExitCode = $this->call('db:wipe', [
            '--database' => self::CONNECTION,
            '--force' => true,
        ]);

        if ($wipeExitCode !== 0) {
            $this->error('E2E DBのwipeに失敗しました。');

            return 1;
        }

        $migrateExitCode = $this->call('migrate', [
            '--database' => self::CONNECTION,
            '--force' => true,
        ]);

        if ($migrateExitCode !== 0) {
            $this->error('E2E DBのmigrationに失敗しました。');

            return 1;
        }

        // reset後の状態を毎回同じにするため、通常SeederではなくE2E専用Seederだけを固定connectionで実行する。
        $seedExitCode = $this->call('db:seed', [
            '--class' => E2eSeeder::class,
            '--database' => self::CONNECTION,
            '--force' => true,
        ]);

        if ($seedExitCode !== 0) {
            $this->error('E2E fixtureの投入に失敗しました。');

            return 1;
        }

        return 0;
    }
}
