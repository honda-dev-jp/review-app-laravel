<?php

namespace App\Services;

use App\Exceptions\TmdbTokenNotConfiguredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * TMDB APIとの通信および映画検索結果の整形を担当するサービス。
 *
 * 映画検索結果を最大5件に絞り込み、必要な項目を抽出する。
 * ポスター画像が必要な場合は、TMDBの画像設定から表示用URLを生成する。
 */
class TmdbMovieService
{
    private const TMDB_SEARCH_URL = 'https://api.themoviedb.org/3/search/movie';

    private const TMDB_CONFIGURATION_URL = 'https://api.themoviedb.org/3/configuration';

    /**
     * 検証済みの映画検索キーワードを使用してTMDBから映画情報を取得する。
     *
     * 検索結果の先頭最大5件を検証し、画面表示に必要な項目へ整形する。
     *
     * @param  string  $query  検証済みの映画検索キーワード
     * @return array{
     *     results: list<array{
     *         id: int,
     *         title: string,
     *         original_title: string|null,
     *         release_date: string|null,
     *         overview: string|null,
     *         poster_url: string|null
     *     }>,
     *     summary: array{
     *         checked: int,
     *         displayed: int
     *     }
     * }
     *
     * @throws TmdbTokenNotConfiguredException TMDB API Read Access Tokenが未設定、または不正な場合
     */
    public function search(string $query): array
    {
        // Laravelの設定ファイルからTMDB API Read Access Tokenを取得する。
        // 実際のトークンはconfig/services.phpを経由して読み込む。
        $token = config('services.tmdb.read_access_token');

        // トークンが文字列でない、または空白を除いて空なら、
        // TMDBへ通信せず、専用の例外を投げる。
        if (! is_string($token) || trim($token) === '') {
            throw new TmdbTokenNotConfiguredException;
        }

        // Bearer認証を設定したHTTPリクエストを準備する。
        // withToken()はAuthorizationヘッダーを設定する。
        $tmdbRequest = Http::withToken($token);

        // TMDBからの応答を最大5秒待つ。
        // 応答がない場合は接続タイムアウトとして例外が発生する。
        $tmdbRequest->timeout(5);

        // TMDB Movie Search APIに渡す検索条件を定義する。
        $params = [
            'query' => $query,          // 検索キーワード
            'language' => 'ja-JP',      // 日本語の検索結果を要求
            'include_adult' => false,   // 成人向け作品を除外
            'page' => 1,                // 検索結果の1ページ目
        ];

        // TMDBの映画検索エンドポイントへGETリクエストを送信する。
        // 取得したHTTPレスポンスにはステータスやJSON本文が含まれる。
        $tmdbResponse = $tmdbRequest->get(self::TMDB_SEARCH_URL, $params);

        // TMDBからのHTTP応答が2xx以外なら異常として扱う。
        // 4xx・5xxではRequestExceptionを送出する。
        // 3xxはthrow()だけでは例外にならないため、別途異常として扱う。
        if (! $tmdbResponse->successful()) {
            $tmdbResponse->throw();

            throw new \UnexpectedValueException('TMDB検索のHTTPステータスが不正です。');
        }

        // TMDBから受信したJSON本文をPHPの値へ変換する。
        // falseを指定してJSONオブジェクトとJSON配列を区別する。
        // JSONの構文不正はJsonExceptionとして呼び出し元へ伝える。
        $payload = json_decode(
            $tmdbResponse->body(),
            false,
            512,
            JSON_THROW_ON_ERROR
        );

        // JSONの最上位がオブジェクトであることを確認する。
        // resultsが存在し、JSON配列であることを確認する。
        // 配列のキーが0から連続するリスト形式であることも確認する。
        // 構造不正を正常な検索結果0件として扱わない。
        if (
            ! ($payload instanceof \stdClass)
            || ! is_array($payload->results ?? null)
            || ! array_is_list($payload->results)
        ) {
            throw new \UnexpectedValueException('TMDB検索結果の構造が不正です。');
        }

        // 正常な検索結果0件の場合は、空のresultsと件数0のsummaryを返す。
        if ($payload->results === []) {
            return [
                'results' => [],
                'summary' => [
                    'checked' => 0,
                    'displayed' => 0,
                ],
            ];
        }

        // TMDB検索結果の先頭最大5件を検証対象として取り出す。
        // 不正な作品があっても、6件目以降から補充しない。
        $candidates = array_slice($payload->results, 0, 5);

        // 検証対象となった作品数を取得する。
        // checkedはTMDB全体の検索ヒット数ではない。
        $checked = count($candidates);

        // 検証を通過した映画情報を格納する配列を準備する。
        $validMovies = [];

        // 先頭最大5件を順番に検証する。
        foreach ($candidates as $candidate) {
            // JSONオブジェクト以外の作品データは除外する。
            if (! ($candidate instanceof \stdClass)) {
                continue;
            }

            // TMDB映画IDを取得する。
            // idが存在しない場合はnullとして扱う。
            $movieId = $candidate->id ?? null;

            // idが1以上の整数でなければ、その作品を除外する。
            if (! is_int($movieId) || $movieId < 1) {
                continue;
            }

            // TMDBからタイトル・原題・あらすじを取得する。
            // 項目が存在しない場合はnullとして扱う。
            $title = $candidate->title ?? null;
            $originalTitle = $candidate->original_title ?? null;
            $overview = $candidate->overview ?? null;

            // nullは正常な欠損値として許可する。
            // null以外で文字列ではない項目があれば、その作品を除外する。
            if (
                ($title !== null && ! is_string($title))
                || ($originalTitle !== null && ! is_string($originalTitle))
                || ($overview !== null && ! is_string($overview))
            ) {
                continue;
            }

            // 文字列の前後にあるUnicode空白を除去する。
            // nullの場合はそのまま維持する
            $title = $title !== null ? mb_trim($title, null, 'UTF-8') : null;
            $originalTitle = $originalTitle !== null
                ? mb_trim($originalTitle, null, 'UTF-8')
                : null;
            $overview = $overview !== null ? mb_trim($overview, null, 'UTF-8') : null;

            // 原題・あらすじの空文字はnullに統一する。
            $originalTitle = $originalTitle === '' ? null : $originalTitle;
            $overview = $overview === '' ? null : $overview;

            // タイトルが欠損している場合は原題で補完する。
            // 原題もなければ言語ファイルの「タイトル不明」を使用する。
            $normalizedTitle = $title !== null && $title !== ''
                ? $title
                : ($originalTitle ?? __('admin.tmdb.search.ui.unknown_title'));

            // TMDBから公開日とポスターパスを取得する。
            // 項目が存在しない場合はnullとして扱う。
            $releaseDate = $candidate->release_date ?? null;
            $posterPath = $candidate->poster_path ?? null;

            // null以外で文字列ではない場合は、作品そのものを除外する。
            // 不正な型を文字列へ強制変換して受け入れない。
            if (
                ($releaseDate !== null && ! is_string($releaseDate))
                || ($posterPath !== null && ! is_string($posterPath))
            ) {
                continue;
            }

            // 公開日がYYYY-MM-DD形式で、実在する日付か確認する。
            // 不正な日付文字列は作品を除外せずnullに正規化する。
            if (
                $releaseDate !== null
                && (
                    preg_match('~\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z~', $releaseDate) !== 1
                    || ! checkdate(
                        (int) substr($releaseDate, 5, 2),
                        (int) substr($releaseDate, 8, 2),
                        (int) substr($releaseDate, 0, 4)
                    )
                )
            ) {
                $releaseDate = null;
            }

            // ポスターパスが本アプリで許可された形式か確認する。
            // 不正なパス文字列は作品を維持し、nullへ正規化する。
            if (
                $posterPath !== null
                && preg_match(
                    '~\A/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)\z~',
                    $posterPath
                ) !== 1
            ) {
                $posterPath = null;
            }

            // 必須項目と型検証を通過した作品だけを配列に追加する。
            // poster_pathは後続のConfiguration処理で使用する内部データ。
            // 最終的なJSONにはposter_pathを含めない。
            $validMovies[] = [
                'id' => $movieId,
                'title' => $normalizedTitle,
                'original_title' => $originalTitle,
                'release_date' => $releaseDate,
                'overview' => $overview,
                'poster_path' => $posterPath,
                'poster_url' => null,
            ];
        }

        // TMDBから検索結果が返されたにもかかわらず、
        // すべての作品が検証で除外された場合は異常として扱う。
        // 正常な検索結果0件とは区別する。
        if ($validMovies === []) {
            throw new \UnexpectedValueException('TMDB検索結果の検証対象がすべて不正です。');
        }

        // 正常な作品に有効なポスターパスがあるか確認する。
        // 1件もなければConfiguration APIを呼び出す必要はない。
        $hasPoster = false;

        foreach ($validMovies as $movie) {
            if ($movie['poster_path'] !== null) {
                $hasPoster = true;
                break;
            }
        }

        // Configurationから取得した画像設定の格納先。
        // 取得できなかった場合はnullを維持し、No Imageへフォールバックする。
        $configImages = null;

        // 有効なポスターパスが存在する場合だけConfigurationを取得する。
        if ($hasPoster) {
            try {
                // Bearer認証を設定し、最大5秒でConfiguration APIへGETする。
                // 検索1回につきConfigurationへの通信は最大1回。
                $configResponse = Http::withToken($token)
                    ->timeout(5)
                    ->get(self::TMDB_CONFIGURATION_URL);

                // HTTP応答が2xxの場合だけJSON本文を解析する。
                // 4xx・5xxは画像設定取得失敗として扱い、映画情報は維持する。
                if ($configResponse->successful()) {
                    $configuration = json_decode(
                        $configResponse->body(),
                        false,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    // JSONのトップレベルがオブジェクトであることを確認する。
                    if ($configuration instanceof \stdClass) {
                        // imagesを取得し、JSONオブジェクトであることを確認する。
                        $images = $configuration->images ?? null;

                        if ($images instanceof \stdClass) {
                            // 画像配信元URLとポスターサイズを取り出す。
                            $baseUrl = $images->secure_base_url ?? null;
                            $sizes = $images->poster_sizes ?? null;

                            // 配信元URLが文字列であり、
                            // ポスターサイズがJSON配列であることを確認する。
                            if (
                                is_string($baseUrl)
                                && is_array($sizes)
                                && array_is_list($sizes)
                            ) {
                                // 型検証を通過した値だけを内部処理用の配列に保存する。
                                $configImages = [
                                    'secure_base_url' => $baseUrl,
                                    'poster_sizes' => $sizes,
                                ];
                            }
                        }
                    }
                }
            } catch (
                ConnectionException|\JsonException
            ) {
                // 通信失敗・タイムアウト・JSON破損は画像なしとして扱う。
                // Configuration障害だけで映画検索全体を失敗させない。
            }
        }

        // 使用するポスターサイズの初期値。
        // 有効なConfigurationがなければ画像URLを生成しない。
        $posterSize = null;

        // 画像の配信元が設計書で許可されたURLと完全一致する場合だけ使用する。
        if (
            $configImages !== null
            && $configImages['secure_base_url'] === 'https://image.tmdb.org/t/p/'
        ) {
            // 有効なポスター幅を格納する。
            $validWidths = [];

            // Configurationに含まれるサイズを1件ずつ検証する。
            foreach ($configImages['poster_sizes'] as $size) {
                // w + 1〜4桁の正の整数以外は採用しない。
                // original、先頭0、不正な文字列も除外する。
                if (
                    ! is_string($size)
                    || preg_match('~\Aw[1-9][0-9]{0,3}\z~', $size) !== 1
                ) {
                    continue;
                }

                // 先頭のwを取り除き、数値の幅として保存する。
                $validWidths[] = (int) substr($size, 1);
            }

            // 有効な幅が1件以上ある場合だけサイズを選択する。
            if ($validWidths !== []) {
                // 幅を数値の昇順に並べ替える。
                sort($validWidths, SORT_NUMERIC);

                // 342px以上のサイズから最小のものを選ぶ。
                foreach ($validWidths as $width) {
                    if ($width >= 342) {
                        $posterSize = 'w'.$width;
                        break;
                    }
                }

                // 342px以上がなければ、利用可能な最大幅を選ぶ。
                if ($posterSize === null) {
                    $posterSize = 'w'.max($validWidths);
                }
            }
        }

        // 最終的にControllerへ返す映画情報を格納する。
        $results = [];

        // 検証済み作品から公開用の映画情報を作成する。
        foreach ($validMovies as $movie) {
            // 画像設定・サイズ・ポスターパスがすべて有効な場合だけURLを生成する。
            if (
                $configImages !== null
                && $posterSize !== null
                && $movie['poster_path'] !== null
            ) {
                $movie['poster_url'] = $configImages['secure_base_url']
                    .$posterSize
                    .$movie['poster_path'];
            }

            // poster_pathはService内部専用なので公開結果から削除する。
            unset($movie['poster_path']);

            // 公開用の映画情報だけを結果配列に追加する。
            $results[] = $movie;
        }

        // 検証対象数と実際の表示件数を含めて検索結果を返す。
        return [
            'results' => $results,
            'summary' => [
                'checked' => $checked,
                'displayed' => count($results),
            ],
        ];
    }
}
