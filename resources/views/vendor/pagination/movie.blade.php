@if ($paginator->hasPages())
    <nav class="mt-8 flex flex-col items-center gap-4" role="navigation" aria-label="ページネーション">
        <p class="text-sm text-slate-600">
            表示中：
            <span class="font-semibold">{{ number_format($paginator->firstItem()) }}</span>
            から
            <span class="font-semibold">{{ number_format($paginator->lastItem()) }}</span>
            件目、全：
            <span class="font-semibold">{{ number_format($paginator->total()) }}</span>
            件
        </p>

        <div class="max-w-full overflow-x-auto px-2">
            <div class="inline-flex overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                @if ($paginator->onFirstPage())
                    <span class="grid h-10 min-w-10 place-items-center border-r border-slate-200 px-3 text-sm font-semibold text-slate-300">
                        ‹
                    </span>
                @else
                    <a
                        href="{{ $paginator->previousPageUrl() }}"
                        rel="prev"
                        class="grid h-10 min-w-10 place-items-center border-r border-slate-200 px-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-blue-600"
                        aria-label="前のページ"
                    >
                        ‹
                    </a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="grid h-10 min-w-10 place-items-center border-r border-slate-200 px-3 text-sm font-semibold text-slate-400">
                            {{ $element }}
                        </span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span
                                    class="grid h-10 min-w-10 place-items-center border-r border-slate-200 bg-blue-600 px-3 text-sm font-bold text-white"
                                    aria-current="page"
                                >
                                    {{ $page }}
                                </span>
                            @else
                                <a
                                    href="{{ $url }}"
                                    class="grid h-10 min-w-10 place-items-center border-r border-slate-200 px-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-blue-600"
                                >
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a
                        href="{{ $paginator->nextPageUrl() }}"
                        rel="next"
                        class="grid h-10 min-w-10 place-items-center px-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-blue-600"
                        aria-label="次のページ"
                    >
                        ›
                    </a>
                @else
                    <span class="grid h-10 min-w-10 place-items-center px-3 text-sm font-semibold text-slate-300">
                        ›
                    </span>
                @endif
            </div>
        </div>
    </nav>
@endif
