<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-semibold text-blue-600">
                本人レビュー一覧
            </p>
            <h1 class="mt-1 text-xl font-bold text-slate-900">
                投稿したレビュー
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
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-[1.5rem] border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">

                {{-- 左側 --}}
                <div class="flex items-center gap-4">
                    <x-user-avatar
                        :user="Auth::user()"
                        alt=""
                        class="h-12 w-12"
                    />
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">
                            レビュー履歴
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            自分が投稿したレビューだけを新しい順に表示しています。
                        </p>
                    </div>
                </div>

                {{-- 右側 --}}
                <p class="text-sm font-semibold text-slate-500">
                    {{ number_format($reviews->total()) }}件
                </p>
            </div>

            <div class="mt-6 grid gap-4">
                @forelse ($reviews as $review)
                    <article
                        x-data="{ confirmDeleteOpen: false }"
                        class="rounded-2xl border border-slate-200 bg-slate-50 p-5"
                        @keydown.escape.window="confirmDeleteOpen = false"
                    >
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex min-w-0 gap-4">
                                <div class="h-20 w-20 flex-shrink-0 overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-200 via-sky-100 to-blue-50 sm:h-24 sm:w-24">
                                    <div class="flex h-full items-center justify-center bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.9),transparent_28%),radial-gradient(circle_at_70%_20%,rgba(59,130,246,0.35),transparent_30%),linear-gradient(135deg,#312e81,#38bdf8)]">
                                        <span class="text-3xl drop-shadow-md">🎬</span>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-slate-500">
                                        作品
                                    </p>

                                    @if ($review->item)
                                        <a
                                            href="{{ route('items.show', $review->item) }}"
                                            class="mt-1 inline-block break-words text-lg font-bold text-slate-900 transition hover:text-blue-600"
                                        >
                                            {{ $review->item->title }}
                                        </a>
                                    @else
                                        <p class="mt-1 break-words text-lg font-bold text-slate-900">
                                            作品情報が見つかりません
                                        </p>
                                    @endif

                                    <div class="mt-3 flex items-center gap-2 text-sm">
                                        <span class="text-amber-400">
                                            @for ($star = 1; $star <= 5; $star++)
                                                @if ($star <= $review->rating)
                                                    ★
                                                @else
                                                    ☆
                                                @endif
                                            @endfor
                                        </span>

                                        <span class="font-semibold text-slate-700">
                                            {{ number_format($review->rating, 1) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <p class="text-sm text-slate-500 sm:text-right">
                                投稿日: {{ $review->created_at->format('Y/m/d') }}
                            </p>
                        </div>

                        <p class="mt-6 whitespace-pre-line break-words text-left text-sm leading-7 text-slate-700">
                            {{ $review->body }}
                        </p>

                        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            @if ($review->item)
                                <a
                                    href="{{ route('items.show', $review->item) }}"
                                    class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-blue-600"
                                >
                                    作品詳細を見る
                                </a>
                            @else
                                <span></span>
                            @endif

                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 sm:w-auto"
                                @click="confirmDeleteOpen = true"
                            >
                                レビューを削除する
                            </button>
                        </div>

                        <div
                            x-show="confirmDeleteOpen"
                            x-transition.opacity
                            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4"
                            style="display: none;"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="delete-review-title-{{ $review->id }}"
                            @click.self="confirmDeleteOpen = false"
                        >
                            <div class="relative w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
                                <button
                                    type="button"
                                    class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-full text-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                    aria-label="閉じる"
                                    @click="confirmDeleteOpen = false"
                                >
                                    <span aria-hidden="true">×</span>
                                </button>

                                <h2
                                    id="delete-review-title-{{ $review->id }}"
                                    class="pr-10 text-lg font-bold text-slate-900"
                                >
                                    レビューを削除しますか？
                                </h2>
                                <p class="mt-2 text-sm text-slate-600">
                                    この操作は取り消せません。
                                </p>

                                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-slate-900"
                                        @click="confirmDeleteOpen = false"
                                    >
                                        キャンセル
                                    </button>

                                    <form
                                        method="POST"
                                        action="{{ route('reviews.destroy', $review) }}"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="redirect_to" value="reviews.mine">

                                        <button
                                            type="submit"
                                            class="inline-flex w-full items-center justify-center rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 sm:w-auto"
                                        >
                                            削除する
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl bg-slate-50 px-4 py-8 text-center">
                        <p class="text-sm font-semibold text-slate-700">
                            まだレビューを投稿していません。
                        </p>
                        <p class="mt-2 text-sm text-slate-500">
                            作品詳細画面からレビューを投稿すると、ここに表示されます。
                        </p>

                        <a
                            href="{{ route('items.index') }}"
                            class="mt-5 inline-flex items-center rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                        >
                            作品一覧を見る
                        </a>
                    </div>
                @endforelse
            </div>

            @if ($reviews->hasPages())
                <div class="mt-6">
                    {{ $reviews->links() }}
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
