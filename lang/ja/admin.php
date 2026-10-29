<?php

return [
    'tmdb' => [
        'search' => [
            'errors' => [
                'invalid_query' => '映画タイトルを1〜100文字で入力してください。',
                'unauthenticated' => 'ログインが必要です。再度ログインしてください。',
                'forbidden' => 'この機能を利用する権限がありません。',
                'fetch_failed' => '映画情報を取得できませんでした。',
                'rate_limited' => 'しばらくしてから再度お試しください。',
                'unavailable' => '検索サービスを利用できません。',
                'network_failed' => '通信に失敗しました。接続を確認して再度お試しください。',
                'request_timeout' => '検索に時間がかかっています。しばらくしてから再度お試しください。',
            ],
            'ui' => [
                'page_title' => 'TMDB映画検索',
                'query_label' => '映画タイトル',
                'query_placeholder' => '映画タイトルを入力',
                'search_button' => '検索',
                'searching' => '検索中...',
                'no_results' => '該当作品なし',
                'results_count' => ':displayed件の作品を表示しました。',
                'unknown_title' => 'タイトル不明',
                'not_available' => '情報なし',
                'tmdb_id' => 'TMDB ID',
                'original_title' => '原題',
                'release_date' => '公開日',
                'genres' => 'TMDBジャンル（参考）',
                'overview' => '概要',
                'poster_alt' => '作品「:title」のポスター',
                'no_poster_alt' => 'ポスター画像なし',
                'login_link' => 'ログイン画面へ',
                'partial_success' => ':checked件中:displayed件を表示しました。:excluded件はデータ形式が不正だったため除外しました。',
            ],
        ],
    ],
];
