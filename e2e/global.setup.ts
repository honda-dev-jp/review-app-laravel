import { spawnSync } from 'node:child_process';

import { test } from '@playwright/test';

test('E2E専用データベースをresetする', () => {
    const isCI = !!process.env.CI;
    const command = isCI ? 'php' : 'docker';
    const args = isCI
        ? ['artisan', 'e2e:reset']
        : ['compose', 'exec', '-T', 'laravel.e2e', 'php', 'artisan', 'e2e:reset'];
    const result = spawnSync(command, args, {
        stdio: isCI ? 'inherit' : 'ignore',
    });

    if (result.error !== undefined) {
        throw new Error(
            isCI
                ? 'E2E DB resetコマンドを起動できませんでした。CIのPHP実行環境を確認してください。'
                : 'E2E DB resetコマンドを起動できませんでした。Dockerとlaravel.e2e serviceの稼働状態を確認してください。',
        );
    }

    if (result.status !== 0) {
        throw new Error(
            isCI
                ? 'E2E DB resetに失敗したため、ブラウザテストを中止します。CIのE2E専用DB設定を確認してください。'
                : 'E2E DB resetに失敗したため、ブラウザテストを中止します。laravel.e2e serviceとE2E専用DB設定を確認してください。',
        );
    }
});
