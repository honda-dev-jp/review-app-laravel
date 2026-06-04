<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-bold text-slate-900">
                作品一覧
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                気になる映画を見つけて、感想をシェアしよう。
            </p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div class="mb-3 inline-flex items-center gap-2 rounded-2xl bg-blue-50 px-3 py-2 text-sm font-bold text-blue-700">
                        <span class="grid h-7 w-7 place-items-center rounded-xl bg-blue-600 text-white">▦</span>
                        作品一覧
                    </div>

                    <h2 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                        気になる映画を見つけて、感想をシェアしよう。
                    </h2>

                    <p class="mt-3 text-sm leading-7 text-slate-500">
                        作品カードを選択すると、作品詳細・あらすじを確認できます。
                        レビュー表示や投稿機能は後続フェーズで実装予定です。
                    </p>
                </div>

                <div class="rounded-2xl bg-slate-50 px-4 py-3 text-right">
                    <p class="text-xs font-medium text-slate-500">表示作品</p>

                    @if ($items->total() > 0)
                        <p class="mt-1 text-xl font-bold text-slate-900">
                            {{ number_format($items->firstItem()) }}〜{{ number_format($items->lastItem()) }}件目
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            全{{ number_format($items->total()) }}件
                        </p>
                    @else
                        <p class="mt-1 text-xl font-bold text-slate-900">
                            0件
                        </p>
                    @endif
                </div>
            </div>

            @if ($items->isEmpty())
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center">
                    <p class="text-base font-semibold text-slate-700">
                        作品はまだ登録されていません。
                    </p>
                    <p class="mt-2 text-sm text-slate-500">
                        作品データが追加されると、ここに一覧表示されます。
                    </p>
                </div>
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    @foreach ($items as $item)
                        <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-lg">
                            <a href="{{ route('items.show', $item) }}" class="block">
                                <div class="aspect-[4/5] overflow-hidden bg-gradient-to-br from-indigo-200 via-sky-100 to-blue-50">
                                    <div class="flex h-full items-center justify-center bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.9),transparent_28%),radial-gradient(circle_at_70%_20%,rgba(59,130,246,0.35),transparent_30%),linear-gradient(135deg,#312e81,#38bdf8)]">
                                        <span class="text-5xl drop-shadow-md">🎬</span>
                                    </div>
                                </div>

                                <div class="p-3">
                                    <div class="mb-2 inline-flex rounded-full bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-500">
                                        {{ $item->category?->name ?? '未分類' }}
                                    </div>

                                    <h3 class="line-clamp-1 text-sm font-bold text-slate-900 group-hover:text-blue-600">
                                        {{ $item->title }}
                                    </h3>

                                    <div class="mt-2 flex items-center gap-2">
                                        <p class="text-sm text-amber-400">
                                            ★★★★★
                                        </p>

                                        <p class="text-xs font-semibold text-slate-700">
                                            @if (is_null($item->rating))
                                                -
                                            @else
                                                {{ number_format((float) $item->rating, 1) }}
                                            @endif
                                        </p>
                                    </div>

                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ number_format($item->rating_count) }}件
                                    </p>
                                </div>
                            </a>
                        </article>
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $items->links('vendor.pagination.movie') }}
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
