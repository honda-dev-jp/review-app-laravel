import { spawnSync } from 'node:child_process';

import { test } from '@playwright/test';

test('E2E専用データベースをresetする', () => {
    const result = spawnSync(
        'docker',
        ['compose', 'exec', '-T', 'laravel.e2e', 'php', 'artisan', 'e2e:reset'],
        { stdio: 'ignore' },
    );

    if (result.error !== undefined) {
        throw new Error(
            'E2E DB resetコマンドを起動できませんでした。Dockerとlaravel.e2e serviceの稼働状態を確認してください。',
        );
    }

    if (result.status !== 0) {
        throw new Error(
            'E2E DB resetに失敗したため、ブラウザテストを中止します。laravel.e2e serviceとE2E専用DB設定を確認してください。',
        );
    }
});
