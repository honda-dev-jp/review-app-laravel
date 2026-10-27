<?php

namespace Tests\Feature\Services;

use App\Exceptions\TmdbTokenNotConfiguredException;
use App\Services\TmdbMovieService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TmdbMovieServiceTest extends TestCase
{
    private const TMDB_SEARCH_URL = 'https://api.themoviedb.org/3/search/movie';

    private const TMDB_CONFIGURATION_URL = 'https://api.themoviedb.org/3/configuration';

    /** 実環境の.envを読まず、空の環境ファイルでアプリを起動する。 */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        assert($app instanceof Application);
        $app->beforeBootstrapping(LoadEnvironmentVariables::class, function (Application $app): void {
            $app->useEnvironmentPath('/dev')->loadEnvironmentFrom('null');
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tmdb.read_access_token' => 'dummy-test-token', 'app.locale' => 'ja']);
        // Fakeに登録していない通信は失敗させ、実TMDBへのアクセス漏れを防ぐ。
        Http::preventStrayRequests();
    }

    /**
     * TMDBの映画検索結果が正常に0件だった場合、
     * 空のresultsと件数0のsummaryを返すことを保証する。
     */
    public function test_search_returns_empty_results_when_tmdb_has_no_results(): void
    {
        // Arrange：テスト用のTMDBトークンと、検索結果0件の模擬HTTP応答を準備する。
        config(['services.tmdb.read_access_token' => 'dummy-test-token']);

        Http::preventStrayRequests();

        // 空配列を返すFakeで、上流障害と正常な0件を区別する。
        $fakeResponse = Http::response(['results' => []], 200);
        Http::fake([self::TMDB_SEARCH_URL.'?*' => $fakeResponse]);

        $service = new TmdbMovieService;

        // Act：TmdbMovieServiceのsearch()を実行する。
        $result = $service->search('テスト映画');

        // Assert：resultsが空配列であることを確認する。
        $this->assertSame([], $result['results']);

        // Assert：summary.checkedが0であることを確認する。
        $this->assertSame(0, $result['summary']['checked']);

        // Assert：summary.displayedが0であることを確認する。
        $this->assertSame(0, $result['summary']['displayed']);

        // Assert：想定したTMDB検索リクエストだけが送信されたことを確認する。
        Http::assertSent(function (Request $request): bool {
            // 送信したURLから「?」以降のクエリ文字列を取り出す。
            $queryString = parse_url($request->url(), PHP_URL_QUERY);

            // クエリ文字列が取得できなければ、期待するリクエストではない。
            if (! is_string($queryString)) {
                return false;
            }

            // クエリ文字列を連想配列に変換する。
            // 例：query=movie&page=1
            // → ['query' => 'movie', 'page' => '1']
            parse_str($queryString, $params);

            // HTTPメソッド・送信先・認証・検索条件をすべて検証する。
            // &&で結んだ条件がすべて成立した場合だけtrueを返す。
            return $request->method() === 'GET'
                // TMDBの映画検索エンドポイントであること。
                && str_starts_with($request->url(), self::TMDB_SEARCH_URL.'?')

                // テスト用Bearerトークンで認証していること。
                && $request->hasHeader('Authorization', 'Bearer dummy-test-token')

                // 検索キーワードが正しいこと。
                && ($params['query'] ?? null) === 'テスト映画'

                // 日本語が指定されていること。
                && ($params['language'] ?? null) === 'ja-JP'

                // 成人向け作品が除外されていること。
                && ($params['include_adult'] ?? null) === '0'

                // 1ページ目を検索していること。
                && ($params['page'] ?? null) === '1';
        });

        // Assert：余計なHTTPリクエストがなく、送信回数が1回であることを確認する。
        Http::assertSentCount(1);
    }

    /**
     * 正常な1・5・6件と部分成功を再現し、先頭5件だけを元の順序で返すことを保証する。
     * 件数境界と6件目の不正・正常を組み合わせ、除外後の補充や範囲外の検証を防ぐ。
     *
     * @param  list<int|null>  $ids
     * @param  list<int>  $expectedIds
     */
    #[DataProvider('candidateCases')]
    public function test_search_limits_candidates_and_preserves_order(array $ids, array $expectedIds, int $checked): void
    {
        // Arrange：余分な上流フィールドも加え、公開するキーが限定されるか確認する。
        $movies = array_map(fn (?int $id): array => ['id' => $id, 'title' => '映画', 'vote_count' => 999], $ids);
        $this->fakeSearch(json_encode(['results' => $movies], JSON_THROW_ON_ERROR));

        // Act：検索結果の検証・正規化を実行する。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：assertSameで値・型・順序・公開キーをまとめて保証する。
        $expected = array_map(fn (int $id): array => self::movie($id, '映画'), $expectedIds);
        $this->assertSame(['results' => $expected, 'summary' => ['checked' => $checked, 'displayed' => count($expected)]], $result);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{list<int|null>, list<int>, int}> */
    public static function candidateCases(): array
    {
        return [
            '生JSONの空配列' => [[], [], 0],
            '1件' => [[1], [1], 1],
            '5件' => [[1, 2, 3, 4, 5], [1, 2, 3, 4, 5], 5],
            '6件' => [[1, 2, 3, 4, 5, 6], [1, 2, 3, 4, 5], 5],
            '部分成功は補充しない' => [[1, null, 3, 4, 5, 6], [1, 3, 4, 5], 5],
            '6件目は検証しない' => [[1, 2, 3, 4, 5, null], [1, 2, 3, 4, 5], 5],
        ];
    }

    /**
     * ID・任意項目の型不正や作品以外の要素を混ぜ、不正作品のみ除外されることを保証する。
     * 不正作品だけにポスターがある場合も、Configurationを取得しない。
     */
    #[DataProvider('invalidMovieCases')]
    public function test_search_excludes_invalid_movie_without_fetching_configuration(string $candidate): void
    {
        // Arrange：生JSONで作品の{}と[]、整数と小数を保持し、型変換による救済を検出する。
        $this->fakeSearch('{"results":[{"id":1},'.$candidate.',{"id":3}]}');

        // Act：不正作品を含む検索を実行する。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：値と型の厳密比較により、除外対象・元の順序・件数を保証する。
        $this->assertSame(['results' => [self::movie(1), self::movie(3)], 'summary' => ['checked' => 3, 'displayed' => 2]], $result);
        Http::assertSentCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMovieCases(): iterable
    {
        foreach (['[]', 'null', 'true', '1', '"movie"', '{}'] as $value) {
            yield '作品 '.$value => [$value];
        }
        // 1が最小の有効ID。0・負数・整数風の文字列や小数を許可しない境界を確認する。
        foreach (['null', '0', '-1', '1.5', '1.0', '"1"', 'true', 'false', '[]', '{}'] as $value) {
            yield 'id '.$value => ['{"id":'.$value.',"poster_path":"/invalid-movie.jpg"}'];
        }
        foreach (['title', 'original_title', 'overview', 'release_date', 'poster_path'] as $field) {
            foreach (['123', 'true', 'false', '[]', '{}'] as $value) {
                yield $field.' '.$value => ['{"id":2,'.($field === 'original_title' ? '' : '"original_title":"原題",').'"'.$field.'":'.$value.'}'];
            }
        }
    }

    /**
     * 欠損・Unicode空白・日付の境界を再現し、作品を残したまま設計どおり正規化する。
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $expectedFields
     */
    #[DataProvider('normalizationCases')]
    public function test_search_normalizes_optional_fields(array $fields, array $expectedFields): void
    {
        // Arrange：指定した欠損値・日付をFakeで返し、正常作品の正規化だけを検証する。
        $this->fakeSearch(json_encode(['results' => [array_replace(['id' => 1], $fields)]], JSON_THROW_ON_ERROR));

        // Act：検索して表示用データへ変換する。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：nullと空文字を混同せず、内部空白・改行も厳密に比較する。
        $this->assertSame(['results' => [array_replace(self::movie(1), $expectedFields)], 'summary' => ['checked' => 1, 'displayed' => 1]], $result);
        Http::assertSentCount(1);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function normalizationCases(): iterable
    {
        yield 'idのみ' => [[], []];
        $space = " \t\n\r　\u{00A0}";
        yield '前後だけ除去' => [
            ['title' => $space."映画　A\nB".$space, 'original_title' => $space."Original A\nB".$space, 'overview' => $space."説明　A\nB".$space],
            ['title' => "映画　A\nB", 'original_title' => "Original A\nB", 'overview' => "説明　A\nB"],
        ];
        foreach ([null, '', $space] as $index => $missing) {
            yield '欠損 '.$index => [['title' => $missing, 'original_title' => $missing, 'overview' => $missing], []];
            yield '原題補完 '.$index => [['title' => $missing, 'original_title' => $space.'原題'.$space], ['title' => '原題', 'original_title' => '原題']];
        }
        yield 'title欠落を原題で補完' => [['original_title' => '原題'], ['title' => '原題', 'original_title' => '原題']];
        yield '原題をtitleで補完しない' => [['title' => '映画'], ['title' => '映画']];
        // うるう年・未来を許容し、形式が似ていても実在しない日付はnullにする。
        foreach (['2026-01-01', '2024-02-29', '2099-12-31'] as $date) {
            yield '正常日付 '.$date => [['release_date' => $date], ['release_date' => $date]];
        }
        foreach ([null, '', '2025-02-29', '2026-04-31', '0000-01-01', '2026-00-01', '2026-13-01', '2026-1-01', '2026-01-1', ' 2026-01-01', "2026-01-01\n", '2026-01-01T00:00:00Z', 'invalid'] as $index => $date) {
            yield '欠損不正日付 '.$index => [['release_date' => $date], []];
        }
    }

    /**
     * 全件不正・JSON形状不正・JSON解析失敗を再現し、正常0件として返さないことを保証する。
     * HTTPエラーへの変換はControllerの責務なので、ここでは伝播する例外を検証する。
     *
     * @param  class-string<\Throwable>  $exception
     */
    #[DataProvider('invalidSearchCases')]
    public function test_search_rejects_invalid_response(string $body, string $exception): void
    {
        // Arrange：{}と[]はPHP連想配列経由だと区別が消えるため、生JSONをFakeで返す。
        $this->fakeSearch($body);

        // Act：不正応答を検索処理に渡し、例外を捕捉する。
        $caught = null;
        try {
            (new TmdbMovieService)->search('テスト映画');
        } catch (\Throwable $error) {
            $caught = $error;
        }

        // Assert：例外の分類と検索1回だけを保証し、Configurationへ進まないことを確認する。
        $this->assertInstanceOf($exception, $caught);
        Http::assertSentCount(1);
    }

    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function invalidSearchCases(): iterable
    {
        foreach (['[]', '1', 'null', 'true', '"text"', '{}', '{"results":null}', '{"results":"text"}', '{"results":1}', '{"results":false}', '{"results":{}}', '{"results":{"0":{"id":1}}}', '{"results":[{}]}', '{"results":[{},{},{},{},{}]}', '{"results":[{},{},{},{},{},{"id":6,"poster_path":"/six.jpg"}]}'] as $index => $body) {
            yield '形状または全件不正 '.$index => [$body, \UnexpectedValueException::class];
        }
        yield 'JSON破損' => ['{"results":', \JsonException::class];
        yield '深さ超過' => [str_repeat('[', 513).str_repeat(']', 513), \JsonException::class];
        yield '不正UTF8' => ["{\"results\":[{\"id\":1,\"title\":\"\xFF\"}]}", \JsonException::class];
    }

    /**
     * ポスターパスの許可形式と末尾制御文字等を再現し、URL生成と取得条件を保証する。
     * 不正な文字列でも作品とsummaryは維持し、有効パスの場合だけConfigurationを取得する。
     */
    #[DataProvider('posterPathCases')]
    public function test_search_validates_poster_path(?string $path, bool $valid): void
    {
        // Arrange：有効パスにだけ画像設定を用意し、不要なConfiguration取得は失敗させる。
        $this->fakeSearch(json_encode(['results' => [['id' => 1, 'poster_path' => $path]]], JSON_THROW_ON_ERROR));
        if ($valid) {
            $this->fakeConfiguration(self::configuration(['w342']));
        }

        // Act：パスの検証とURL生成を実行する。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：URLの完全一致とnullを比較し、内部のposter_pathが公開されないことも保証する。
        $movie = self::movie(1);
        $movie['poster_url'] = $valid ? 'https://image.tmdb.org/t/p/w342'.$path : null;
        $this->assertSame(['results' => [$movie], 'summary' => ['checked' => 1, 'displayed' => 1]], $result);
        Http::assertSentCount($valid ? 2 : 1);
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function posterPathCases(): iterable
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            yield $extension => ['/Abc_123-xyz.'.$extension, true];
        }
        // LF・CRLF・NULは末尾までの完全一致を検証するため、実際の制御文字を渡す。
        foreach ([null, '', 'abc.jpg', '/../abc.jpg', '//abc.jpg', '/dir/abc.jpg', '/abc.jpg?x=1', '/abc.jpg#x', 'https://image.tmdb.org/t/p/w342/abc.jpg', '/abc.gif', '/abc.JPG', '/日本語.jpg', "/abc.jpg\n", "/abc.jpg\r\n", "/abc.jpg\0"] as $index => $path) {
            yield '欠損不正パス '.$index => [$path, false];
        }
    }

    /**
     * サイズ選択・Configuration不正を再現し、全ポスターへ同じサイズを適用することを保証する。
     * 部分成功でも画像設定失敗によって映画情報やsummaryが減らないことを確認する。
     */
    #[DataProvider('configurationCases')]
    public function test_search_uses_configuration_or_keeps_movies_without_images(string $body, ?string $size): void
    {
        // Arrange：2作品にポスター、1作品に欠損、1作品に不正IDを用意する。
        $this->fakeSearch('{"results":[{"id":1,"poster_path":"/one.jpg"},{"id":0},{"id":3,"poster_path":"/three.png"},{"id":4}]}');
        $this->fakeConfiguration($body);

        // Act：1回の検索で複数のポスターを生成する。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：映画情報・summaryを厳密比較し、Configurationは作品数によらず1回とする。
        $one = self::movie(1);
        $three = self::movie(3);
        $one['poster_url'] = $size === null ? null : 'https://image.tmdb.org/t/p/'.$size.'/one.jpg';
        $three['poster_url'] = $size === null ? null : 'https://image.tmdb.org/t/p/'.$size.'/three.png';
        $this->assertSame(['results' => [$one, $three, self::movie(4)], 'summary' => ['checked' => 4, 'displayed' => 3]], $result);
        // クロージャで送信先・メソッド・Bearerを同時に照合し、検索APIとの取り違えを防ぐ。
        Http::assertSent(fn (Request $request): bool => $request->url() === self::TMDB_CONFIGURATION_URL
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer dummy-test-token'));
        Http::assertSentCount(2);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function configurationCases(): iterable
    {
        yield '342優先' => [self::configuration(['w500', 'w185', 'w342']), 'w342'];
        yield '342以上の最小' => [self::configuration(['w780', 'w185', 'w500']), 'w500'];
        yield '342未満の最大' => [self::configuration(['w92', 'w185', 'w154']), 'w185'];
        yield '幅の下限' => [self::configuration(['w1']), 'w1'];
        yield '幅の上限' => [self::configuration(['w9999']), 'w9999'];
        yield 'originalのみ' => [self::configuration(['original']), null];
        yield '空配列' => [self::configuration([]), null];
        $invalid = ['w0', 'w-1', 'w0342', 'w10000', 'w342?x=1', "w342\n", "w342\r\n", "w342\0", 'original', 342, null, false, [], new \stdClass];
        yield '不正サイズのみ' => [self::configuration($invalid), null];
        yield '不正サイズは無視' => [self::configuration([...$invalid, 'w185']), 'w185'];
        foreach (['[]', 'null', '1', '{}', '{"images":null}', '{"images":[]}', '{"images":1}', '{"images":{}}', '{"images":{"secure_base_url":null,"poster_sizes":["w342"]}}', '{"images":{"secure_base_url":123,"poster_sizes":["w342"]}}', '{"images":{"poster_sizes":["w342"]}}', '{"images":{"secure_base_url":"https://image.tmdb.org/t/p/"}}'] as $index => $body) {
            yield 'Configuration構造不正 '.$index => [$body, null];
        }
        foreach (['{}', '{"0":"w342"}', 'null', '"w342"', '342', 'true'] as $sizes) {
            // オブジェクトを配列として救済しないことを生JSONで検証する。
            yield 'サイズ型 '.$sizes => ['{"images":{"secure_base_url":"https://image.tmdb.org/t/p/","poster_sizes":'.$sizes.'}}', null];
        }
        foreach (['http://image.tmdb.org/t/p/', 'https://example.com/t/p/', 'https://user@image.tmdb.org/t/p/', 'https://image.tmdb.org:443/t/p/', 'https://image.tmdb.org/t/p/extra/', 'https://image.tmdb.org/t/p/?x=1', 'https://image.tmdb.org/t/p/#x', 'https://image.tmdb.org/t/p'] as $base) {
            yield 'ベースURL '.$base => [json_encode(['images' => ['secure_base_url' => $base, 'base_url' => 'https://image.tmdb.org/t/p/', 'poster_sizes' => ['w342']]], JSON_THROW_ON_ERROR), null];
        }
        yield 'base_urlへ切替不可' => ['{"images":{"base_url":"https://image.tmdb.org/t/p/","poster_sizes":["w342"]}}', null];
        yield 'JSON破損' => ['{', null];
        yield '深さ超過' => [str_repeat('[', 513).str_repeat(']', 513), null];
        yield '不正UTF8' => ["{\"images\":\"\xFF\"}", null];
    }

    /**
     * 上流401・429・その他4xx/5xxを再現し、ステータスを保持した例外とretryなしを保証する。
     */
    #[DataProvider('httpFailureCases')]
    public function test_search_propagates_http_failure_without_retry(int $status): void
    {
        // Arrange：成功JSONを返してもHTTP障害を優先して扱うことをFakeで確認する。
        $this->fakeSearch('{"results":[]}', $status);

        // Act：上流のHTTP失敗を捕捉する。
        $caught = null;
        try {
            (new TmdbMovieService)->search('テスト映画');
        } catch (\Throwable $error) {
            $caught = $error;
        }

        // Assert：Controllerが分類できるステータスを保ち、再送・画像設定取得を行わない。
        $this->assertInstanceOf(RequestException::class, $caught);
        $this->assertInstanceOf(Response::class, $caught->response);
        $this->assertSame($status, $caught->response->status());
        Http::assertSentCount(1);
    }

    /** @return iterable<string, array{int}> */
    public static function httpFailureCases(): iterable
    {
        foreach ([401, 403, 404, 429, 500, 502, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    /**
     * ConfigurationのHTTP障害を再現し、部分成功した映画情報とsummaryを維持することを保証する。
     */
    #[DataProvider('httpFailureCases')]
    public function test_configuration_http_failure_preserves_movies(int $status): void
    {
        // Arrange：検索は成功させ、画像設定だけを障害応答に置き換える。
        $this->fakeSearch('{"results":[{"id":1,"poster_path":"/one.jpg"},{"id":0},{"id":3}]}');
        $this->fakeConfiguration(self::configuration(['w342']), $status);

        // Act：画像設定の失敗を含む検索を実行する。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：画像URL以外の値と型を維持し、retryせず2回の通信で終了する。
        $this->assertSame(['results' => [self::movie(1), self::movie(3)], 'summary' => ['checked' => 3, 'displayed' => 2]], $result);
        Http::assertSentCount(2);
    }

    /**
     * 検索とConfigurationの接続失敗・timeoutを再現し、検索例外の伝播と画像のみの縮退を保証する。
     * 実時間を待たずにHTTP Clientと同じConnectionExceptionを発生させる。
     */
    #[DataProvider('connectionFailureCases')]
    public function test_connection_failures(bool $configuration, string $message): void
    {
        // Arrange：失敗した通信もretry回数を数えるため、Fakeクロージャ内で呼出回数を記録する。
        $attempts = 0;
        $url = $configuration ? self::TMDB_CONFIGURATION_URL : self::TMDB_SEARCH_URL.'?*';
        if ($configuration) {
            $this->fakeSearch('{"results":[{"id":1,"poster_path":"/one.jpg"},{"id":0},{"id":3}]}');
        }
        Http::fake([$url => function (Request $request, array $options) use (&$attempts, $message) {
            $attempts++;
            // 通信を待たず、5秒のtimeout設定そのものも確認する。
            $this->assertSame(5, $options['timeout']);

            return Http::failedConnection($message);
        }]);

        // Act：接続障害を起こし、検索結果または伝播した例外を取得する。
        $result = null;
        $caught = null;
        try {
            $result = (new TmdbMovieService)->search('テスト映画');
        } catch (\Throwable $error) {
            $caught = $error;
        }

        // Assert：検索失敗は例外、画像設定失敗は映画情報維持となり、どちらもretryしない。
        $this->assertSame(1, $attempts);
        if ($configuration) {
            $this->assertNull($caught);
            $this->assertSame(['results' => [self::movie(1), self::movie(3)], 'summary' => ['checked' => 3, 'displayed' => 2]], $result);
        } else {
            $this->assertInstanceOf(ConnectionException::class, $caught);
            $this->assertNull($result);
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function connectionFailureCases(): iterable
    {
        foreach ([false, true] as $configuration) {
            yield ($configuration ? '画像設定' : '検索').'接続失敗' => [$configuration, 'Synthetic connection failure'];
            yield ($configuration ? '画像設定' : '検索').'timeout' => [$configuration, 'cURL error 28: Synthetic timeout'];
        }
    }

    /**
     * トークン未設定・空白・型不正を再現し、通信前に専用例外となることを保証する。
     */
    #[DataProvider('missingTokenCases')]
    public function test_search_rejects_missing_token(mixed $token): void
    {
        // Arrange：実トークンに触れず、設定を欠損値に置き換える。Fakeは送信有無の記録用。
        config(['services.tmdb.read_access_token' => $token]);
        Http::fake();

        // Act：設定不足での検索を実行する。
        $caught = null;
        try {
            (new TmdbMovieService)->search('テスト映画');
        } catch (TmdbTokenNotConfiguredException $error) {
            $caught = $error;
        }

        // Assert：専用例外が発生し、外部通信は一切行われない。
        $this->assertInstanceOf(TmdbTokenNotConfiguredException::class, $caught);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{mixed}> */
    public static function missingTokenCases(): iterable
    {
        foreach ([null, '', " \t\n", 123, false, []] as $index => $token) {
            yield '未設定または型不正 '.$index => [$token];
        }
    }

    /**
     * 同一Serviceで2回検索し、Configurationを検索間でキャッシュしないことを保証する。
     */
    public function test_configuration_is_fetched_for_each_search(): void
    {
        // Arrange：2回目の画像設定を変え、前回の設定の流用を検出する。
        $this->fakeSearch('{"results":[{"id":1,"poster_path":"/one.jpg"}]}');
        Http::fake([self::TMDB_CONFIGURATION_URL => Http::sequence()
            ->push(self::configuration(['w342']))
            ->push(self::configuration(['w500']))]);
        $service = new TmdbMovieService;

        // Act：同じインスタンスで2回検索する。
        $first = $service->search('テスト映画');
        $second = $service->search('テスト映画');

        // Assert：各応答のURLがその回の設定と完全一致し、検索と画像設定が各2回となる。
        $movie = self::movie(1);
        $movie['poster_url'] = 'https://image.tmdb.org/t/p/w342/one.jpg';
        $this->assertSame(['results' => [$movie], 'summary' => ['checked' => 1, 'displayed' => 1]], $first);
        $movie['poster_url'] = 'https://image.tmdb.org/t/p/w500/one.jpg';
        $this->assertSame(['results' => [$movie], 'summary' => ['checked' => 1, 'displayed' => 1]], $second);
        Http::assertSentCount(4);
    }

    /**
     * 6件目だけに有効なポスターがある応答を再現し、表示対象外の作品でConfigurationを取得しない。
     * 先頭5件の映画情報とsummaryだけが返ることを保証する。
     */
    public function test_poster_outside_first_five_does_not_trigger_configuration(): void
    {
        // Arrange：画像設定のFakeは登録せず、範囲外のポスターで通信したら失敗させる。
        $this->fakeSearch('{"results":[{"id":1},{"id":2},{"id":3},{"id":4},{"id":5},{"id":6,"poster_path":"/six.jpg"}]}');

        // Act：6件の候補を検索する。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：先頭5件の値・型・順序を厳密比較し、通信が検索1回だけであることを保証する。
        $this->assertSame([
            'results' => [self::movie(1), self::movie(2), self::movie(3), self::movie(4), self::movie(5)],
            'summary' => ['checked' => 5, 'displayed' => 5],
        ], $result);
        Http::assertSentCount(1);
    }

    /**
     * 全項目を持つ正常作品に対して画像設定の500・JSON破損・接続失敗を再現する。
     * 欠損値中心の既存テストを補い、正規化済み映画情報と件数を保ち、画像URLだけnullになることを保証する。
     */
    #[DataProvider('configurationDetailFailureCases')]
    public function test_configuration_failure_preserves_all_normalized_movie_fields(string $failure): void
    {
        // Arrange：前後のUnicode空白を含む全項目と有効パスを返し、障害時にも正規化結果が残るか確認する。
        $this->fakeSearch(json_encode(['results' => [[
            'id' => 123,
            'title' => '　テスト映画　',
            'original_title' => "\tTest Movie\u{00A0}",
            'release_date' => '2024-02-29',
            'overview' => "\n　映画の概要。\n内部の改行は維持する。　\n",
            'poster_path' => '/detail.jpg',
        ]]], JSON_THROW_ON_ERROR));
        $configurationAttempts = 0;
        // 接続失敗も確実に数えるため、応答の記録ではなくConfiguration専用Fakeの呼出回数を数える。
        Http::fake([self::TMDB_CONFIGURATION_URL => function () use ($failure, &$configurationAttempts): PromiseInterface|\Closure {
            $configurationAttempts++;

            return match ($failure) {
                'http500' => Http::response(self::configuration(['w342']), 500),
                // 200でもJSON解析に失敗するケースを、破損した生の本文で再現する。
                'invalidJson' => Http::response('{', 200),
                // failedConnectionは例外を発生させるClosureを返し、HTTP Clientがそれを実行する。
                'connection' => Http::failedConnection('Synthetic configuration connection failure'),
                default => throw new \LogicException('未定義のテスト条件です。'),
            };
        }]);

        // Act：映画検索は成功させ、Configurationだけを失敗させる。
        $result = (new TmdbMovieService)->search('テスト映画');

        // Assert：配列全体の厳密比較で、画像以外の値・型・キーとsummaryの維持を保証する。
        $this->assertSame([
            'results' => [[
                'id' => 123,
                'title' => 'テスト映画',
                'original_title' => 'Test Movie',
                'release_date' => '2024-02-29',
                'overview' => "映画の概要。\n内部の改行は維持する。",
                'poster_url' => null,
            ]],
            'summary' => ['checked' => 1, 'displayed' => 1],
        ], $result);
        $this->assertSame(1, $configurationAttempts);
        // 検索URLだけをクロージャで選び、画像設定失敗による検索の再送も検出する。
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), self::TMDB_SEARCH_URL.'?')));
    }

    /** @return array<string, array{string}> */
    public static function configurationDetailFailureCases(): array
    {
        return [
            'HTTP 500でも映画の全項目を維持' => ['http500'],
            'JSON破損でも映画の全項目を維持' => ['invalidJson'],
            '接続失敗でも映画の全項目を維持' => ['connection'],
        ];
    }

    /**
     * タイトルと原題が欠損した作品に、テスト専用の翻訳値が返ることを保証する。
     * 固定の「タイトル不明」では通らない文言に差し替え、翻訳キーの参照を確認する。
     */
    public function test_unknown_title_uses_translation_key(): void
    {
        // Arrange：実際の翻訳コンポーネントのメモリ内だけを書き換え、言語ファイルは変更しない。
        $translator = app('translator');
        $this->assertInstanceOf(Translator::class, $translator);
        $key = 'admin.tmdb.search.ui.unknown_title';
        $locale = $translator->getLocale();
        // 先に元の翻訳を読み込み、復元に使う値を保存する。
        $original = $translator->get($key, [], $locale);
        $this->assertIsString($original);
        $replacement = 'テスト専用：翻訳キーから取得した未設定タイトル';
        $this->fakeSearch('{"results":[{"id":1}]}');

        try {
            $translator->addLines([$key => $replacement], $locale);

            // Act：タイトルと原題が欠損した作品を検索する。
            $result = (new TmdbMovieService)->search('テスト映画');

            // Assert：専用文言との厳密比較により、固定文字列による補完を検出する。
            $this->assertSame([
                'results' => [self::movie(1, $replacement)],
                'summary' => ['checked' => 1, 'displayed' => 1],
            ], $result);
            Http::assertSentCount(1);
        } finally {
            // 検索やassertionが失敗しても必ず復元し、後続テストへ翻訳状態を漏らさない。
            $translator->addLines([$key => $original], $locale);
        }
        $this->assertSame($original, $translator->get($key, [], $locale));
    }

    /** 検索先だけをFakeに登録し、未登録のConfiguration等への意図しない通信を検出する。 */
    private function fakeSearch(string $body, int $status = 200): void
    {
        Http::fake([self::TMDB_SEARCH_URL.'?*' => Http::response($body, $status, ['Content-Type' => 'application/json'])]);
    }

    /** 画像設定だけをFakeに追加し、実APIを呼ばず取得条件・障害を再現する。 */
    private function fakeConfiguration(string $body, int $status = 200): void
    {
        Http::fake([self::TMDB_CONFIGURATION_URL => Http::response($body, $status, ['Content-Type' => 'application/json'])]);
    }

    /**
     * 設計書の公開キーだけを持つ期待値。Serviceの正規化処理を複製せず固定値で比較する。
     *
     * @return array{id: int, title: string, original_title: null, release_date: null, overview: null, poster_url: ?string}
     */
    private static function movie(int $id, string $title = 'タイトル不明'): array
    {
        return ['id' => $id, 'title' => $title, 'original_title' => null, 'release_date' => null, 'overview' => null, 'poster_url' => null];
    }

    /** @param list<mixed> $sizes */
    private static function configuration(array $sizes): string
    {
        return json_encode(['images' => ['secure_base_url' => 'https://image.tmdb.org/t/p/', 'poster_sizes' => $sizes]], JSON_THROW_ON_ERROR);
    }
}
