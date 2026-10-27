<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TmdbSearchTest extends TestCase
{
    private const SEARCH_URL = 'https://api.themoviedb.org/3/search/movie';

    private const CONFIGURATION_URL = 'https://api.themoviedb.org/3/configuration';

    private const TEST_TOKEN = 'dummy-controller-test-token';

    private const FETCH_FAILED = '映画情報を取得できませんでした。';

    /**
     * 既存のKernel起動を維持し、環境ファイルだけを空の/dev/nullへ切り替える。
     * parentの起動後では.env読込を防げないため、読込前の標準フックで設定する。
     */
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
        // 各テストでアプリを作り直し、実設定を使わずセッション・設定・認証状態を独立させる。
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'app.debug' => false,
            'app.locale' => 'ja',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'services.tmdb.read_access_token' => self::TEST_TOKEN,
        ]);
        // 登録外の通信は例外にする。空のFakeは記録だけを開始し、全URLへの成功応答を与えない。
        Http::preventStrayRequests();
        Http::fake([]);
    }

    /**
     * 未認証ユーザーがTMDB検索結果APIへアクセスした場合、
     * HTTP 401が返されることを保証する。
     */
    public function test_guest_cannot_access_tmdb_search_results(): void
    {
        // Arrange：入力不正より先に認証が働くことを確認するため、有効な検索語を用意する。
        $url = route('admin.tmdb.search.results', ['query' => '映画']);

        // Act：未認証の状態で検索結果APIへGETリクエストを送る。
        $response = $this->getJson(
            $url
        );

        // Assert：HTTP 401が返されることを確認する。
        $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        Http::assertNothingSent();
    }

    /**
     * 一般ユーザーがTMDB検索結果APIへアクセスした場合、
     * 管理者権限がないためHTTP 403が返されることを保証する。
     */
    public function test_user_cannot_access_tmdb_search_results(): void
    {
        // Arrange：一般ユーザーを作成する。
        $user = User::factory()->make([
            'role' => 'user',
        ]);

        // Act：一般ユーザーとして検索結果APIへアクセスする。
        $response = $this
            ->actingAs($user)
            ->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：DB未保存のUserでも実際の認可middlewareが拒否し、TMDBへ通信しない。
        $this->assertFalse($user->exists);
        $response->assertForbidden()->assertExactJson(['message' => 'This action is unauthorized.']);
        Http::assertNothingSent();
    }

    /**
     * 管理者が有効な検索キーワードを指定した場合、
     * 実Serviceの検索結果1件をHTTP 200で返すことを保証する。
     */
    public function test_admin_can_access_tmdb_search_results(): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        $this->fakeSearch('{"results":[{"id":1,"title":"テスト映画"}]}');

        // Act：管理者として検索キーワードを指定し、検索結果APIへアクセスする。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results', ['query' => 'ガールズ＆パンツァー']));

        // Assert：未保存Userでも実Gateを通過し、仮の0件ではなく正規化済みの映画を返す。
        $this->assertFalse($admin->exists);
        $response->assertOk()->assertExactJson(['results' => [self::movie(1)], 'summary' => ['checked' => 1, 'displayed' => 1]]);
        $this->assertSearchRequest('ガールズ＆パンツァー');
        Http::assertSentCount(1);
    }

    /**
     * 管理者が検索キーワードを指定せずに検索結果APIへアクセスした場合、
     * HTTP 422とqueryのバリデーションエラーが返されることを保証する。
     */
    public function test_admin_cannot_search_without_query(): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        // Act：queryを指定せずに検索結果APIへアクセスする。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results'));

        // Assert：固定の入力エラーと通信なしを確認する。
        $this->assertInvalidQuery($response);
    }

    /**
     * 管理者が全角スペースのみの検索キーワードを指定した場合、
     * 前後のUnicode空白を除去した結果、HTTP 422と
     * queryのバリデーションエラーが返されることを保証する。
     */
    public function test_admin_cannot_search_with_whitespace_only_query(): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        // Act：全角スペースのみのqueryを指定して検索結果APIへアクセスする。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results', ['query' => '　　']));

        // Assert：Unicode空白のみを拒否し、TMDBへ通信しない。
        $this->assertInvalidQuery($response);
    }

    /**
     * 管理者が通常文字・補助平面文字をそれぞれ101文字指定した場合、
     * 最大100文字の制限に違反し、HTTP 422と
     * queryのバリデーションエラーが返されることを保証する。
     */
    #[DataProvider('queryCharacterCases')]
    public function test_admin_cannot_search_with_query_over_100_characters(string $character): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        // Act：UTF-16では2単位になる補助平面文字も、Laravel側の文字数で101文字として検証する。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results', ['query' => str_repeat($character, 101)]));

        // Assert：上限超過を422で拒否し、TMDBへ通信しない。
        $this->assertInvalidQuery($response);
    }

    /**
     * 管理者が通常文字・補助平面文字をそれぞれ上限ちょうどの100文字指定した場合、
     * バリデーションを通過し、実Serviceの映画1件が返されることを保証する。
     */
    #[DataProvider('queryCharacterCases')]
    public function test_admin_can_search_with_query_of_100_characters(string $character): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        $this->fakeSearch('{"results":[{"id":1,"title":"テスト映画"}]}');

        // Act：補助平面文字100文字（HTMLではUTF-16の200単位）も、実Controllerの検証へ渡す。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results', ['query' => str_repeat($character, 100)]));

        // Assert：100文字がそのままServiceへ渡り、正常な映画1件を返す。
        $response->assertOk()->assertExactJson(['results' => [self::movie(1)], 'summary' => ['checked' => 1, 'displayed' => 1]]);
        $this->assertSearchRequest(str_repeat($character, 100));
        Http::assertSentCount(1);
    }

    /**
     * 通常文字の既存検証を維持し、HTMLのUTF-16単位数とLaravelの文字数が異なる条件だけを追加する。
     *
     * @return array<string, array{string}>
     */
    public static function queryCharacterCases(): array
    {
        return ['通常文字' => ['あ'], '補助平面文字' => ["\u{1F600}"]];
    }

    /**
     * 管理者が検索キーワードに配列を指定した場合、
     * stringルールに違反し、HTTP 422と
     * queryのバリデーションエラーが返されることを保証する。
     */
    public function test_admin_cannot_search_with_array_query(): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        // Act：queryに配列を指定して検索結果APIへアクセスする。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results', [
                'query' => ['映画'],
            ]));

        // Assert：配列を文字列へ変換して救済せず、通信前に422で拒否する。
        $this->assertInvalidQuery($response);
    }

    /**
     * 管理者が前後に全角空白を含む100文字の検索キーワードを指定した場合、
     * 空白除去後の文字数でバリデーションされ、
     * HTTP 200が返されることを保証する。
     */
    public function test_admin_can_search_with_trimmed_query_of_100_characters(): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        $this->fakeSearch('{"results":[{"id":1,"title":"テスト映画"}]}');

        // Act：前後に全角空白を付けた100文字のqueryで検索する。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results', [
                'query' => '　'.str_repeat('あ', 100).'　',
            ]));

        // Assert：前後空白を除いた100文字で通信し、映画1件と件数を返す。
        $response->assertOk()->assertExactJson(['results' => [self::movie(1)], 'summary' => ['checked' => 1, 'displayed' => 1]]);
        $this->assertSearchRequest(str_repeat('あ', 100));
        Http::assertSentCount(1);
    }

    /**
     * 管理者が間に全角スペースを含む101文字の検索キーワードを指定した場合、
     * 最大100文字の制限に違反し、HTTP 422と
     * queryのバリデーションエラーが返されることを保証する。
     */
    public function test_admin_cannot_search_with_internal_whitespace_over_100_characters(): void
    {
        // Arrange：管理者ユーザーを作成する。
        $admin = User::factory()->make([
            'name' => '管理テストユーザー',
            'role' => 'admin',
        ]);

        // Act：途中に全角空白を含む101文字のqueryで検索する。
        $response = $this
            ->actingAs($admin)
            ->getJson(route('admin.tmdb.search.results', [
                'query' => str_repeat('あ', 50).'　'.str_repeat('あ', 50),
            ]));

        // Assert：内部空白も文字数に含めて422で拒否し、通信しない。
        $this->assertInvalidQuery($response);
    }

    /**
     * 正常0件・複数件・部分成功を再現し、200でも先頭5件・順序・summaryの契約を維持する。
     *
     * @param  list<int>  $ids
     * @param  list<int>  $expectedIds
     */
    #[DataProvider('resultCases')]
    public function test_search_returns_results_and_summary(array $ids, array $expectedIds, int $checked, int $displayed): void
    {
        // Arrange：境界の入力と期待値は別々に指定し、Serviceの除外処理を期待値へ複製しない。
        $this->actingAsAdmin();
        $this->fakeSearch(json_encode(['results' => array_map(fn (int $id): array => ['id' => $id, 'title' => 'テスト映画'], $ids)], JSON_THROW_ON_ERROR));

        // Act：実際のルート・Controller・Serviceを通す。
        $response = $this->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：assertSameで値・型・順序・公開キーを厳密比較し、正常0件と障害を区別する。
        $response->assertOk();
        $this->assertSame(['results' => array_map(self::movie(...), $expectedIds), 'summary' => ['checked' => $checked, 'displayed' => $displayed]], $response->json());
        Http::assertSentCount(1);
    }

    /** @return array<string, array{list<int>, list<int>, int, int}> */
    public static function resultCases(): array
    {
        return [
            '正常0件' => [[], [], 0, 0],
            '5件正常' => [[1, 2, 3, 4, 5], [1, 2, 3, 4, 5], 5, 5],
            '6件正常' => [[1, 2, 3, 4, 5, 6], [1, 2, 3, 4, 5], 5, 5],
            '部分成功で補充しない' => [[1, 0, 3, 4, 5, 6], [1, 3, 4, 5], 5, 4],
            '6件目は検証しない' => [[1, 2, 3, 4, 5, 0], [1, 2, 3, 4, 5], 5, 5],
        ];
    }

    /**
     * 正規化が必要な複数作品と画像設定の正常・500を再現し、どちらも映画情報を200で返す。
     * 個々のサイズ・パスの境界値はServiceテストへ任せ、HTTP応答への連携を確認する。
     */
    #[DataProvider('configurationCases')]
    public function test_search_returns_normalized_movies(int $configurationStatus, ?string $posterUrl): void
    {
        // Arrange：余分な上流キーを含む応答と画像設定をFakeにし、実API・CDNを使わずURLを生成する。
        $this->actingAsAdmin();
        $this->fakeSearch('{"page":1,"results":[{"id":12,"title":"　映画 A　","original_title":" Original A ","release_date":"2024-02-29","overview":"　説明文　","poster_path":"/sample.jpg","vote_count":99},{"id":34,"title":"　","original_title":" Original B ","release_date":"2025-02-29","overview":"　"}]}');
        Http::fake([self::CONFIGURATION_URL => Http::response(['images' => [
            'secure_base_url' => 'https://image.tmdb.org/t/p/', 'poster_sizes' => ['w342'],
        ]], $configurationStatus)]);

        // Act：正規化とConfiguration取得を伴う検索を行う。
        $response = $this->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：期待値を仕様から明示し、画像設定失敗でも映画情報・件数と200を維持する。
        $response->assertOk();
        $this->assertSame([
            'results' => [
                ['id' => 12, 'title' => '映画 A', 'original_title' => 'Original A', 'release_date' => '2024-02-29', 'overview' => '説明文', 'poster_url' => $posterUrl],
                ['id' => 34, 'title' => 'Original B', 'original_title' => 'Original B', 'release_date' => null, 'overview' => null, 'poster_url' => null],
            ],
            'summary' => ['checked' => 2, 'displayed' => 2],
        ], $response->json());
        $this->assertSearchRequest('映画');
        Http::assertSentCount(2);
    }

    /** @return array<string, array{int, ?string}> */
    public static function configurationCases(): array
    {
        return ['画像あり' => [200, 'https://image.tmdb.org/t/p/w342/sample.jpg'], '画像設定のみ失敗' => [500, null]];
    }

    /** null・空文字・Unicode空白のみを再現し、422の固定文言とTMDB通信なしを保証する。 */
    #[DataProvider('emptyQueryCases')]
    public function test_admin_cannot_search_with_empty_query(?string $query): void
    {
        // Arrange：管理者を準備する。nullはURL生成で省略されるので、JSON入力で明示する。
        $this->actingAsAdmin();

        // Act：GETのJSON入力をControllerの同じ入力検証へ渡す。
        $response = $this->json('GET', route('admin.tmdb.search.results'), ['query' => $query]);

        // Assert：欠損の表現によらずrequired違反として通信前に拒否する。
        $this->assertInvalidQuery($response);
    }

    /** @return array<string, array{?string}> */
    public static function emptyQueryCases(): array
    {
        return ['null' => [null], '空文字' => [''], '半角空白' => ['   '], '混在Unicode空白' => [" \t\n　\u{00A0}"]];
    }

    /** 99文字と混在Unicode空白を再現し、前後だけを除去して内部空白・表記・固定検索条件を維持する。 */
    #[DataProvider('validQueryCases')]
    public function test_search_passes_normalized_query_to_service(string $query, string $expected): void
    {
        // Arrange：ブラウザ由来のpage等が上流条件を上書きしないことも確認する。
        $this->actingAsAdmin();
        $this->fakeSearch('{"results":[]}');

        // Act：追加パラメータを伴う検索を行う。
        $response = $this->getJson(route('admin.tmdb.search.results', [
            'query' => $query, 'page' => 8, 'language' => 'en-US', 'include_adult' => true,
        ]));

        // Assert：正規化した検索語と固定条件で通信し、検索結果を200で返す。
        $response->assertOk()->assertExactJson(['results' => [], 'summary' => ['checked' => 0, 'displayed' => 0]]);
        $this->assertSearchRequest($expected);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string, string}> */
    public static function validQueryCases(): array
    {
        return [
            '99文字' => [str_repeat('あ', 99), str_repeat('あ', 99)],
            '内部空白と表記維持' => [" \t\n　\u{00A0}映画　A B＆Ｃ\u{00A0}　\n", '映画　A B＆Ｃ'],
        ];
    }

    /** 上流HTTP障害を再現し、429のみ503、それ以外は502の固定JSONへ変換して内部情報を隠す。 */
    #[DataProvider('httpErrorCases')]
    public function test_upstream_http_error_is_converted(int $upstreamStatus, int $status, string $message): void
    {
        // Arrange：上流例外に合成トークンと識別用本文を含め、レスポンスへの転用を検出する。
        $this->actingAsAdmin();
        $this->fakeSearch('UPSTREAM_PRIVATE_DETAIL '.self::TEST_TOKEN, $upstreamStatus);

        // Act：実ServiceのHTTP例外をControllerに処理させる。
        $response = $this->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：固定文言以外の本文・summary・トークン・例外詳細を許可せず、retryしない。
        $this->assertFixedError($response, $status, $message);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int, int, string}> */
    public static function httpErrorCases(): array
    {
        return [
            '上流401は未認証401と区別' => [401, 502, self::FETCH_FAILED],
            'その他4xx' => [404, 502, self::FETCH_FAILED],
            '429は503' => [429, 503, 'しばらくしてから再度お試しください。'],
            '500は502' => [500, 502, self::FETCH_FAILED],
            '上流503も502' => [503, 502, self::FETCH_FAILED],
        ];
    }

    /** 接続失敗とtimeoutを再現し、例外詳細を公開せず503を返してretryしないことを保証する。 */
    #[DataProvider('connectionErrorCases')]
    public function test_connection_error_is_converted_to_503(string $detail): void
    {
        // Arrange：実時間を待たず標準の接続例外をFakeで発生させ、応答がない場合も試行回数を数える。
        $this->actingAsAdmin();
        $attempts = 0;
        Http::fake([self::SEARCH_URL.'?*' => function () use ($detail, &$attempts): \Closure {
            $attempts++;

            return Http::failedConnection($detail.' '.self::TEST_TOKEN);
        }]);

        // Act：接続例外をControllerで変換する。
        $response = $this->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：機密情報の代わりに固定メッセージだけを返し、試行は1回である。
        $this->assertFixedError($response, 503, self::FETCH_FAILED);
        $this->assertSame(1, $attempts);
    }

    /** @return array<string, array{string}> */
    public static function connectionErrorCases(): array
    {
        return ['接続失敗' => ['Synthetic connection failure PRIVATE_DETAIL'], 'タイムアウト' => ['cURL error 28: Synthetic timeout PRIVATE_DETAIL']];
    }

    /** JSON破損・構造不正・全件不正を再現し、正常0件へ変換せず502の固定JSONだけを返す。 */
    #[DataProvider('invalidResponseCases')]
    public function test_invalid_response_is_converted_to_502(string $body): void
    {
        // Arrange：{}と[]の区別を失わないよう生JSONを返し、実Serviceの検証を通す。
        $this->actingAsAdmin();
        $this->fakeSearch($body);

        // Act：JSON解析と作品検証の例外をControllerで変換する。
        $response = $this->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：M1の内部診断例外を利用者向け文言に転用せず、画像設定も取得しない。
        $this->assertFixedError($response, 502, self::FETCH_FAILED);
        Http::assertSentCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponseCases(): iterable
    {
        yield 'JSON破損' => ['{"private":"'.self::TEST_TOKEN.'",'];
        yield '深さ超過' => [str_repeat('[', 513).str_repeat(']', 513)];
        yield '不正UTF8' => ["{\"results\":[{\"id\":1,\"title\":\"\xFF\"}]}"];
        foreach (['[]', '1', 'null', '{"private":"UPSTREAM_PRIVATE_DETAIL"}', '{"results":null}', '{"results":"text"}', '{"results":{}}', '{"results":{"0":{"id":1}}}'] as $index => $body) {
            yield '構造不正 '.$index => [$body];
        }
        yield '1件全件不正' => ['{"results":[{"id":0}]}'];
        yield '5件全件不正' => ['{"results":[{},{},{},{},{}]}'];
        yield '6件目から救済しない' => ['{"results":[{},{},{},{},{},{"id":6}]}'];
    }

    /** トークン未設定・空白を再現し、上流通信を行わず利用不可503だけを返す。 */
    #[DataProvider('missingTokenCases')]
    public function test_missing_token_is_converted_to_503(?string $token): void
    {
        // Arrange：実トークンを参照せず、テスト設定だけを欠損値に置き換える。
        $this->actingAsAdmin();
        config(['services.tmdb.read_access_token' => $token]);

        // Act：正常な検索語で設定不足の経路を通す。
        $response = $this->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：内部設定や例外詳細を返さず、外部通信しない。
        $this->assertFixedError($response, 503, '検索サービスを利用できません。');
        Http::assertNothingSent();
    }

    /** @return array<string, array{?string}> */
    public static function missingTokenCases(): array
    {
        return ['未設定' => [null], '空文字' => [''], '空白のみ' => [" \t\n"]];
    }

    /** メール未認証の管理者を再現し、verifiedを要求せずServiceの検索結果を200で返す。 */
    public function test_unverified_admin_can_search(): void
    {
        // Arrange：メール未認証の管理者と正常な検索応答を準備する。
        $this->actingAs(User::factory()->unverified()->make(['role' => 'admin']));
        $this->fakeSearch('{"results":[{"id":1,"title":"テスト映画"}]}');

        // Act：メール認証middlewareを省略する設計の検索結果APIへアクセスする。
        $response = $this->getJson(route('admin.tmdb.search.results', ['query' => '映画']));

        // Assert：メール認証への誘導ではなく、正常な検索結果を返す。
        $response->assertOk()->assertExactJson(['results' => [self::movie(1)], 'summary' => ['checked' => 1, 'displayed' => 1]]);
        Http::assertSentCount(1);
    }

    /** 検索画面の各権限を再現し、未認証はログインへ、一般は403、管理者はメール認証不要で200とする。 */
    #[DataProvider('pageAccessCases')]
    public function test_search_page_access(?string $role, bool $verified, int $status): void
    {
        // Arrange：画面表示は外部通信不要なので、HTTP応答を登録せず実middlewareを通す。
        if ($role !== null) {
            $this->actingAs(User::factory()->make(['role' => $role, 'email_verified_at' => $verified ? now() : null]));
        }

        // Act：ブラウザと同じHTML要求で検索画面へアクセスする。
        $response = $this->get(route('admin.tmdb.search'));

        // Assert：両ルートに同じ認証認可方針が適用され、画面表示ではTMDBを呼ばない。
        $response->assertStatus($status);
        if ($role === null) {
            $response->assertRedirect(route('login'));
        }
        Http::assertNothingSent();
    }

    /** @return array<string, array{?string, bool, int}> */
    public static function pageAccessCases(): array
    {
        return ['未認証' => [null, false, 302], '一般' => ['user', true, 403], '管理者' => ['admin', true, 200], 'メール未認証管理者' => ['admin', false, 200]];
    }

    /** DB保存が不要な検索なので、RefreshDatabaseによるDB再作成を避けて実GuardへUserを渡す。 */
    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->make(['role' => 'admin']));
    }

    /** 検索先だけをFakeに登録し、未登録のConfiguration等はpreventStrayRequestsで阻止する。 */
    private function fakeSearch(string $body, int $status = 200): void
    {
        Http::fake([self::SEARCH_URL.'?*' => Http::response($body, $status, ['Content-Type' => 'application/json'])]);
    }

    /** エンコード後のURLをデコードして照合し、Unicode検索語・固定条件・Bearer認証を確認する。 */
    private function assertSearchRequest(string $query): void
    {
        Http::assertSent(function (Request $request) use ($query): bool {
            $queryString = parse_url($request->url(), PHP_URL_QUERY);
            if (! is_string($queryString)) {
                return false;
            }
            parse_str($queryString, $params);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), self::SEARCH_URL.'?')
                && $request->hasHeader('Authorization', 'Bearer '.self::TEST_TOKEN)
                && ($params['query'] ?? null) === $query
                && ($params['language'] ?? null) === 'ja-JP'
                && ($params['include_adult'] ?? null) === '0'
                && ($params['page'] ?? null) === '1';
        });
    }

    /**
     * 422の標準message/errors構造を全文比較し、summaryや内部情報の追加を検出する。
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertInvalidQuery(TestResponse $response): void
    {
        $message = '映画タイトルを1〜100文字で入力してください。';
        $response->assertUnprocessable()->assertExactJson(['message' => $message, 'errors' => ['query' => [$message]]]);
        Http::assertNothingSent();
    }

    /**
     * 設計書の固定文言だけを許可し、summary・上流本文・トークン・例外詳細の混入を厳密に拒否する。
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertFixedError(TestResponse $response, int $status, string $message): void
    {
        $response->assertStatus($status)->assertExactJson(['message' => $message]);
    }

    /**
     * 正常作品の期待値は設計書の公開キーと固定値で構成し、Serviceの変換処理から生成しない。
     *
     * @return array{id: int, title: string, original_title: null, release_date: null, overview: null, poster_url: null}
     */
    private static function movie(int $id): array
    {
        return ['id' => $id, 'title' => 'テスト映画', 'original_title' => null, 'release_date' => null, 'overview' => null, 'poster_url' => null];
    }
}
