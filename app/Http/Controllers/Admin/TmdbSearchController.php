<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TmdbTokenNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Services\TmdbMovieService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use UnexpectedValueException;

class TmdbSearchController extends Controller
{
    /**
     * 管理者の映画検索キーワードを検証し、TMDB検索結果をJSON形式で返す。
     *
     * @param  Request  $request  映画検索キーワードを含むHTTPリクエスト
     * @param  TmdbMovieService  $tmdbMovieService  TMDB映画検索を担当するService
     */
    public function search(
        Request $request,
        TmdbMovieService $tmdbMovieService
    ): JsonResponse {
        $query = $request->input('query');

        // 文字列の場合のみ前後のUnicode空白を除去し、不正な型は変換せずバリデーションへ渡す。
        if (is_string($query)) {
            $query = mb_trim($query, null, 'UTF-8');

            $request->merge([
                'query' => $query,
            ]);
        }

        // 入力検証が成功した場合、queryキーには文字列が格納される。
        /** @var array{query: string} $validated */
        $validated = $request->validate(
            [
                'query' => 'required|string|max:100',
            ],
            [
                'query.required' => __('admin.tmdb.search.errors.invalid_query'),
                'query.string' => __('admin.tmdb.search.errors.invalid_query'),
                'query.max' => __('admin.tmdb.search.errors.invalid_query'),
            ]
        );

        // バリデーション済みの検索語をServiceに渡し、正規化済みの検索結果を取得する。
        $validatedQuery = $validated['query'];

        try {
            // 検索が成功した場合は、Serviceが整形した結果と件数を返す。
            $data = $tmdbMovieService->search($validatedQuery);

            // Serviceから受け取った検索結果と件数をJSONで返す。
            return response()->json($data);
        } catch (TmdbTokenNotConfiguredException) {
            // TMDBのアクセストークンが未設定の場合は、利用不可として返す。
            // 内部設定の詳細を利用者へ公開しない。
            return response()->json([
                'message' => __('admin.tmdb.search.errors.unavailable'),
            ], 503);

        } catch (RequestException $exception) {
            // TMDB側の429（レート制限）だけを503として扱う。
            // それ以外の4xx・5xxは502へ変換する。
            if ($exception->response->status() === 429) {
                return response()->json([
                    'message' => __('admin.tmdb.search.errors.rate_limited'),
                ], 503);
            }

            return response()->json([
                'message' => __('admin.tmdb.search.errors.fetch_failed'),
            ], 502);

        } catch (ConnectionException) {
            // 接続失敗・タイムアウトは、一時的な利用不可として503を返す。
            return response()->json([
                'message' => __('admin.tmdb.search.errors.fetch_failed'),
            ], 503);

        } catch (JsonException|UnexpectedValueException) {
            // TMDB検索結果のJSON破損・構造不正は502へ変換する。
            return response()->json([
                'message' => __('admin.tmdb.search.errors.fetch_failed'),
            ], 502);
        }
    }
}
