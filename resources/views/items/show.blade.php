<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-semibold text-blue-600">
                作品詳細
            </p>
            <h1 class="mt-1 text-xl font-bold text-slate-900">
                {{ $item->title }}
            </h1>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-6">
            <a
                href="{{ route('items.index') }}"
                class="inline-flex items-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-blue-600"
            >
                ← 作品一覧へ戻る
            </a>
        </div>

        @if (session('status'))
            <div
                role="status"
                class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700"
            >
                {{ session('status') }}
            </div>
        @endif

        <section class="grid min-w-0 gap-8 md:grid-cols-[minmax(0,320px)_minmax(0,1fr)] md:grid-rows-[auto_1fr] md:items-start">
            <div class="min-w-0 md:col-start-1 md:row-start-1">
                <div class="mx-auto w-full max-w-[280px] md:mx-0">
                    <div class="aspect-square overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-200 via-sky-100 to-blue-50">
                        <div class="flex h-full items-center justify-center bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.9),transparent_28%),radial-gradient(circle_at_70%_20%,rgba(59,130,246,0.35),transparent_30%),linear-gradient(135deg,#312e81,#38bdf8)]">
                            <span class="text-5xl drop-shadow-md">🎬</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid min-w-0 gap-5 md:col-start-1 md:row-start-2">
                <div class="grid min-w-0 grid-cols-2 gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-slate-900">
                            平均評価
                        </p>

                        <div class="mt-2 flex min-w-0 flex-wrap items-center gap-2">
                            @php
                                $roundedRating = is_null($item->rating)
                                    ? 0
                                    : (int) round((float) $item->rating);
                            @endphp

                            <p class="text-lg">
                                @for ($star = 1; $star <= 5; $star++)
                                    @if ($star <= $roundedRating)
                                        <span class="text-amber-400">★</span>
                                    @else
                                        <span class="text-slate-300">☆</span>
                                    @endif
                                @endfor
                            </p>

                            <p class="break-words text-2xl font-bold text-slate-900">
                                @if (is_null($item->rating))
                                    -
                                @else
                                    {{ number_format((float) $item->rating, 1) }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="min-w-0 text-right">
                        <p class="text-sm font-bold text-slate-900">
                            評価件数
                        </p>

                        <p class="mt-2 break-words text-2xl font-bold text-slate-900">
                            {{ number_format($item->rating_count) }}件
                        </p>
                    </div>
                </div>

                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-900">
                        評価分布
                    </p>

                    <div class="mt-3 grid min-w-0 gap-2 text-xs text-slate-500">
                        @foreach ([5, 4, 3, 2, 1] as $star)
                            <div class="grid min-w-0 grid-cols-[3.5rem_minmax(0,1fr)_3rem] items-center gap-2">
                                <span>{{ $star }}つ星</span>
                                <div class="h-2 min-w-0 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full w-0 rounded-full bg-amber-400"></div>
                                </div>
                                <span class="text-right">-%</span>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-3 break-words text-xs leading-6 text-slate-500">
                        星ごとの評価分布は、後続PRで集計方法を整理して表示予定です。
                    </p>
                </div>
            </div>

            <div class="min-w-0 md:col-start-2 md:row-span-2 md:row-start-1">
                <div class="mb-4 inline-flex max-w-full rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">
                    <span class="min-w-0 break-words">
                        {{ $item->category?->name ?? '未分類' }}
                    </span>
                </div>

                <h2 class="min-w-0 break-words text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                    {{ $item->title }}
                </h2>

                <div class="mt-8">
                    <h3 class="text-base font-bold text-slate-900">
                        あらすじ
                    </h3>

                    @if (filled($item->description))
                        <p class="mt-3 whitespace-pre-line break-words text-sm leading-8 text-slate-600">
                            {{ $item->description }}
                        </p>
                    @else
                        <p class="mt-3 text-sm text-slate-500">
                            この作品の説明文はまだ登録されていません。
                        </p>
                    @endif
                </div>
            </div>
        </section>

        <section class="mt-10 rounded-[1.5rem] border border-slate-200 bg-white p-6 text-left shadow-sm">
            <h3 class="text-lg font-bold text-slate-900">
                レビュー投稿
            </h3>

            @auth
                @php
                    $myReview = $item->reviews->firstWhere('user_id', auth()->id());
                @endphp

                @if ($myReview)
                    <p class="mt-4 rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        この作品にはすでにレビューを投稿しています。
                    </p>
                @else

                    {{-- ==================== レビュー投稿フォーム：開始 ==================== --}}
                    <form method="POST" action="{{ route('reviews.store', $item) }}" class="mt-5 space-y-5">
                        @csrf

                        <div>
                            <label for="rating" class="block text-sm font-bold text-slate-900">
                                評価
                            </label>

                            <select
                                id="rating"
                                name="rating"
                                @error('rating')
                                    aria-invalid="true"
                                    aria-describedby="review-rating-error"
                                @enderror
                                class="mt-2 block w-full rounded-2xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option value="">評価を選択してください</option>
                                @foreach ([5, 4, 3, 2, 1] as $star)
                                    <option value="{{ $star }}" @selected((string) old('rating') === (string) $star)>
                                        {{ $star }}つ星
                                    </option>
                                @endforeach
                            </select>

                            @error('rating')
                                <p
                                    id="review-rating-error"
                                    class="mt-2 text-sm font-semibold text-red-600"
                                >
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label for="body" class="block text-sm font-bold text-slate-900">
                                レビュー本文
                            </label>

                            {{-- 返信投稿の入力値が通常レビュー投稿フォームへ表示されないように分離する。 --}}
                            <textarea
                                id="body"
                                name="body"
                                rows="5"
                                class="mt-2 block w-full rounded-2xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="作品の感想を入力してください"
                                @error('body')
                                    aria-invalid="true"
                                    aria-describedby="review-body-error"
                                @enderror
                            >{{ old('form_review_id') === null ? old('body') : '' }}</textarea>

                            {{-- 通常レビュー投稿のデフォルトエラーバッグだけを表示する。 --}}
                            @error('body')
                                <p
                                    id="review-body-error"
                                    class="mt-2 text-sm font-semibold text-red-600"
                                >
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                            >
                                レビューを投稿する
                            </button>
                        </div>
                    </form>
                    {{-- ==================== レビュー投稿フォーム：終了 ==================== --}}

                @endif
            @else
                <div class="mt-4 rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    レビューを投稿するには
                    <a href="{{ route('login') }}" class="font-semibold text-blue-600 hover:text-blue-700">
                        ログイン
                    </a>
                    が必要です。
                </div>
            @endauth
        </section>

        <section class="mt-10 rounded-[1.5rem] border border-slate-200 bg-white p-6 text-left shadow-sm">
            <h3 class="text-lg font-bold text-slate-900">
                レビュー表示
            </h3>

            {{-- ==================== レビュー一覧：開始 ==================== --}}
            @forelse ($item->reviews as $review)
                {{-- ---------- レビュー1件：開始 ---------- --}}
                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <x-user-avatar
                                :user="$review->user"
                                alt=""
                                class="h-10 w-10"
                            />

                            <div class="font-semibold text-slate-900">
                                {{ $review->user?->name ?? '匿名' }}
                            </div>
                        </div>

                        <div class="text-sm text-slate-500">
                            {{ $review->created_at->format('Y/m/d') }}
                        </div>
                    </div>

                    <div class="mt-1 flex items-center gap-1 text-sm">
                        <span class="text-amber-400">
                            @for ($star = 1; $star <= 5; $star++)
                                @if ($star <= $review->rating)
                                    ★
                                @else
                                    ☆
                                @endif
                            @endfor
                        </span>

                        <span class="font-medium text-slate-700">
                            {{ number_format($review->rating, 1) }}
                        </span>
                    </div>

                    <div class="mt-2 text-sm leading-7 text-slate-700">
                        <span class="font-semibold text-slate-900">レビュー本文：</span>
                        {{ $review->body }}
                    </div>

                    {{-- ---------- 返信コメント一覧：開始 ---------- --}}
                    @if ($review->comments->isNotEmpty())
                        <div class="mt-4 rounded-2xl border-l-4 border-l-sky-300 bg-sky-50/60 p-4">
                            <p class="text-sm font-semibold text-slate-700">
                                返信コメント
                            </p>

                            @foreach ($review->comments as $comment)
                                <div class="mt-3 rounded-xl bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex items-center gap-3">
                                            <x-user-avatar
                                                :user="$comment->user"
                                                alt=""
                                                class="h-8 w-8"
                                            />

                                            <div class="font-semibold text-slate-800">
                                                {{ $comment->user?->name ?? '匿名' }}
                                            </div>
                                        </div>

                                        <div class="text-xs text-slate-500">
                                            {{ $comment->created_at->format('Y/m/d') }}
                                        </div>
                                    </div>

                                    <div class="mt-2 text-sm leading-6 text-slate-700">
                                        {{ $comment->body }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    {{-- ---------- 返信コメント一覧：終了 ---------- --}}

                    @auth
                        {{-- ---------- 返信投稿フォーム：開始 ---------- --}}
                        <form
                            method="POST"
                            action="{{ route('reviews.comments.store', $review) }}"
                            class="mt-4 space-y-3"
                        >
                            @csrf

                            {{-- バリデーション失敗時に、入力値とエラーを元の返信フォームだけへ戻すための識別用ID。 --}}
                            {{-- 投稿先レビューの決定には使用せず、投稿先はルートの{review}から取得する。 --}}
                            <input
                                type="hidden"
                                name="form_review_id"
                                value="{{ $review->id }}"
                            >

                            <div>
                                <label
                                    for="comment-body-{{ $review->id }}"
                                    class="block text-sm font-bold text-slate-900"
                                >
                                    返信コメント
                                </label>

                                {{-- 送信元の返信フォームにだけ、バリデーション失敗前の本文を戻す。 --}}
                                <textarea
                                    id="comment-body-{{ $review->id }}"
                                    name="body"
                                    rows="4"
                                    class="mt-2 block w-full rounded-2xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="レビューへの返信を入力してください"
                                    @if (
                                        (string) old('form_review_id') === (string) $review->id
                                        && $errors->reviewComment->has('body')
                                    )
                                        aria-invalid="true"
                                        aria-describedby="comment-body-error-{{ $review->id }}"
                                    @endif
                                >{{ (string) old('form_review_id') === (string) $review->id ? old('body') : '' }}</textarea>

                                {{-- 返信投稿専用のエラーバッグから、送信元フォームにだけエラーを表示する。 --}}
                                @if (
                                    (string) old('form_review_id') === (string) $review->id
                                    && $errors->reviewComment->has('body')
                                )
                                    <p
                                        id="comment-body-error-{{ $review->id }}"
                                        class="mt-2 text-sm font-semibold text-red-600"
                                    >
                                        {{ $errors->reviewComment->first('body') }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-full bg-sky-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700"
                                >
                                    返信を投稿する
                                </button>
                            </div>
                        </form>
                        {{-- ---------- 返信投稿フォーム：終了 ---------- --}}

                    @else
                        {{-- ---------- 返信投稿：未ログイン案内 ---------- --}}
                        <div class="mt-4 rounded-2xl bg-white px-4 py-3 text-sm text-slate-600">
                            返信するには
                            <a
                                href="{{ route('login') }}"
                                class="font-semibold text-blue-600 hover:text-blue-700"
                            >
                                ログイン
                            </a>
                            が必要です。
                        </div>
                    @endauth

                </div>
                {{-- ---------- レビュー1件：終了 ---------- --}}

            @empty
                <div>
                    まだレビューはありません。
                </div>
            @endforelse
            {{-- ==================== レビュー一覧：終了 ==================== --}}

        </section>
    </div>
</x-app-layout>
