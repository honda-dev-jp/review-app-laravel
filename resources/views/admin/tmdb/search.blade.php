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
            'selectMovieHint' => __('admin.tmdb.search.ui.select_movie_hint'),
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
            'openRegistrationModalAccessible' => __('admin.tmdb.search.ui.open_registration_modal_accessible'),
        ],
        'categories' => __('admin.tmdb.search.categories'),
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

        {{-- 検索結果が1件以上の場合に操作案内を表示 --}}
        <p
            id="tmdb-select-movie-hint"
            hidden
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

    {{-- 作品登録モーダル --}}
    <dialog
        id="tmdb-registration-dialog"
        aria-labelledby="tmdb-registration-title"
        class="w-full max-w-2xl p-6 rounded-lg border border-neutral-300 bg-neutral-100 text-neutral-800 shadow-xl backdrop:bg-black/50 max-h-[90vh] overflow-y-auto m-auto"
    >
        <div class="flex items-center justify-between gap-4">
            <h2
                id="tmdb-registration-title"
                tabindex="-1"
                class="text-lg font-semibold text-neutral-900"
            >
                {{ __('admin.tmdb.search.ui.registration_modal_title') }}
            </h2>
            <button
                id="tmdb-registration-close"
                type="button"
                aria-label="{{ __('admin.tmdb.search.ui.close_modal') }}"
                class="h-11 w-11 flex items-center justify-center rounded shrink-0 text-2xl text-neutral-700 hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-blue-600 focus-visible:outline-offset-2"
            >
                ×
            </button>
        </div>
        {{-- ポスターとTMDB参照情報：広い画面では横並び、狭い画面では縦並びにする。 --}}
        <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start">
            <img
                id="tmdb-registration-poster"
                alt="{{ __('admin.tmdb.search.ui.no_poster_alt') }}"
                src="{{ asset('images/no-image.png') }}"
                width="112"
                height="176"
                class="h-44 w-28 shrink-0 rounded-md bg-neutral-200 object-cover"
            />
            {{-- 参照専用：淡い背景で区別し、ラベルを太字、値を通常文字で同じ行に表示する。 --}}
            <div class="min-w-0 flex-1 space-y-2 rounded-md border border-neutral-300 bg-neutral-200 p-3">
                <p class="wrap-break-word text-sm leading-6 text-neutral-800">
                    <strong class="font-semibold">{{ __('admin.tmdb.search.ui.tmdb_id') }}：</strong><span id="tmdb-registration-movie-id" class="font-normal"></span>
                </p>
                <p class="wrap-break-word text-sm leading-6 text-neutral-800">
                    <strong class="font-semibold">{{ __('admin.tmdb.search.ui.original_title') }}：</strong><span id="tmdb-registration-original-title" class="font-normal"></span>
                </p>
                <p class="wrap-break-word text-sm leading-6 text-neutral-800">
                    <strong class="font-semibold">{{ __('admin.tmdb.search.ui.release_date') }}：</strong><span id="tmdb-registration-release-date" class="font-normal"></span>
                </p>
                <p class="wrap-break-word text-sm leading-6 text-neutral-800">
                    <strong class="font-semibold">{{ __('admin.tmdb.search.ui.genres') }}：</strong><span id="tmdb-registration-genres" class="font-normal"></span>
                </p>
            </div>
        </div>

        {{-- 編集可能：作品タイトル・概要全文・アプリ独自カテゴリー --}}
        <div class="mt-6 space-y-4">
            <div class="space-y-1">
                <label for="tmdb-registration-editable-title" class="block text-sm font-medium text-neutral-800">
                    {{ __('admin.tmdb.search.ui.editable_title') }}
                </label>
                <input
                    type="text"
                    id="tmdb-registration-editable-title"
                    name="title"
                    class="w-full rounded-md border border-neutral-500 bg-white px-3 py-2 text-neutral-900 focus-visible:outline-2 focus-visible:outline-blue-600 focus-visible:outline-offset-2"
                />
            </div>
            <div class="space-y-1">
                <label for="tmdb-registration-editable-overview" class="block text-sm font-medium text-neutral-800">
                    {{ __('admin.tmdb.search.ui.overview') }}
                </label>
                <textarea
                    id="tmdb-registration-editable-overview"
                    rows="5"
                    class="w-full rounded-md border border-neutral-500 bg-white px-3 py-2 text-neutral-900 focus-visible:outline-2 focus-visible:outline-blue-600 focus-visible:outline-offset-2"
                ></textarea>
            </div>
            <div class="space-y-1">
                <label for="tmdb-registration-category" class="block text-sm font-medium text-neutral-800">
                    {{ __('admin.tmdb.search.ui.category_label') }}
                </label>
                <select
                    id="tmdb-registration-category"
                    name="category"
                    class="w-full rounded-md border border-neutral-500 bg-white px-3 py-2 text-neutral-900 focus-visible:outline-2 focus-visible:outline-blue-600 focus-visible:outline-offset-2"
                >
                    <option value="" selected disabled>
                        {{ __('admin.tmdb.search.ui.category_placeholder') }}
                    </option>
                </select>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-3">
            <button
                id="tmdb-registration-cancel"
                type="button"
                class="h-11 rounded-md border border-neutral-400 px-4 text-neutral-800 hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-blue-600 focus-visible:outline-offset-2"
            >
                {{ __('admin.tmdb.search.ui.cancel') }}
            </button>
            <button
                id="tmdb-registration-submit"
                type="button"
                class="h-11 rounded-md border border-neutral-300 bg-neutral-200 px-4 text-neutral-500"
                disabled
            >
                {{ __('admin.tmdb.search.ui.register') }}
            </button>
        </div>
        <p class="mt-2 text-sm text-neutral-700">{{ __('admin.tmdb.search.ui.registration_unavailable') }}</p>
    </dialog>

    {{-- 検索用JavaScriptへ安全な設定値を渡す。 --}}
    <script>
        window.tmdbSearchConfig = {{ \Illuminate\Support\Js::from($tmdbSearchConfig) }};
    </script>
</x-admin-layout>
