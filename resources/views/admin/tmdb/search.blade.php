{{-- TMDB検索JavaScriptへ、必要なURLと翻訳済み文言だけを安全に渡す。 --}}
@php
    $tmdbSearchConfig = [
        'url' => route('admin.tmdb.search.results'),
        'loginUrl' => route('login'),
        'messages' => [
            'searchButton' => __('admin.tmdb.search.ui.search_button'),
            'searching' => __('admin.tmdb.search.ui.searching'),
            'noResults' => __('admin.tmdb.search.ui.no_results'),
            'resultsCount' => __('admin.tmdb.search.ui.results_count'),
            'partialSuccess' => __('admin.tmdb.search.ui.partial_success'),
            'invalidQuery' => __('admin.tmdb.search.errors.invalid_query'),
            'unauthenticated' => __('admin.tmdb.search.errors.unauthenticated'),
            'forbidden' => __('admin.tmdb.search.errors.forbidden'),
            'fetchFailed' => __('admin.tmdb.search.errors.fetch_failed'),
            'rateLimited' => __('admin.tmdb.search.errors.rate_limited'),
            'unavailable' => __('admin.tmdb.search.errors.unavailable'),
            'networkFailed' => __('admin.tmdb.search.errors.network_failed'),
            'requestTimeout' => __('admin.tmdb.search.errors.request_timeout'),
            'loginLink' => __('admin.tmdb.search.ui.login_link'),
            'notAvailable' => __('admin.tmdb.search.ui.not_available'),
            'tmdbId' => __('admin.tmdb.search.ui.tmdb_id'),
            'originalTitle' => __('admin.tmdb.search.ui.original_title'),
            'releaseDate' => __('admin.tmdb.search.ui.release_date'),
            'genres' => __('admin.tmdb.search.ui.genres'),
            'overview' => __('admin.tmdb.search.ui.overview'),
            'posterAlt' => __('admin.tmdb.search.ui.poster_alt'),
            'noPosterAlt' => __('admin.tmdb.search.ui.no_poster_alt'),
        ],
    ];
@endphp

<x-admin-layout>
    <x-slot name="title">
        {{ __('admin.tmdb.search.ui.page_title') }}
    </x-slot>

    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">
            {{ __('admin.tmdb.search.ui.page_title') }}
        </h1>
    </x-slot>

    {{-- TMDB映画検索フォーム --}}
    <section class="rounded-lg border border-gray-200 bg-white p-6">
        <form
            id="tmdb-search-form"
            action="{{ route('admin.tmdb.search.results') }}"
            method="GET"
            novalidate
        >
            <label
                for="tmdb-query"
                class="block text-sm font-medium text-gray-700"
            >
                {{ __('admin.tmdb.search.ui.query_label') }}
            </label>

            <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                <input
                    type="search"
                    id="tmdb-query"
                    name="query"
                    required
                    aria-describedby="tmdb-query-error"
                    placeholder="{{ __('admin.tmdb.search.ui.query_placeholder') }}"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 sm:flex-1"
                >

                <button
                    type="submit"
                    id="tmdb-search-button"
                    class="rounded-md bg-gray-800 px-5 py-2 font-medium text-white hover:bg-gray-700"
                >
                    {{ __('admin.tmdb.search.ui.search_button') }}
                </button>
            </div>

            {{-- 入力エラーを検索欄の近くに表示する --}}
            <p
                id="tmdb-query-error"
                role="alert"
                class="mt-2 text-sm text-red-600"
                hidden
            ></p>
        </form>

        {{-- 検索処理や結果件数の状態通知 --}}
        <p
            id="tmdb-search-status"
            role="status"
            aria-live="polite"
            class="mt-4 text-sm text-gray-600"
        ></p>

        {{-- 通信障害や認証エラーの通知 --}}
        <div
            id="tmdb-search-error"
            role="alert"
            class="mt-4 text-sm text-red-600"
            hidden
        ></div>
    </section>

    {{-- 映画検索結果 --}}
    <section
        id="tmdb-search-results"
        aria-busy="false"
        class="mt-6 space-y-4"
    ></section>
    {{-- 検索用JavaScriptへ安全な設定値を渡す。 --}}
    <script>
        window.tmdbSearchConfig = {{ \Illuminate\Support\Js::from($tmdbSearchConfig) }};
    </script>
</x-admin-layout>
