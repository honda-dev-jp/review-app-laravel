@php
    $navigationItems = [
        [
            'label' => 'ダッシュボード',
            'href' => route('admin.dashboard'),
            'active' => request()->routeIs('admin.dashboard'),
        ],
        [
            'label' => '登録済み作品一覧',
            'href' => null,
            'active' => false,
        ],
        [
            'label' => 'TMDB検索',
            'href' => null,
            'active' => false,
        ],
    ];
@endphp

<div
    x-data="{ mobileMenuOpen: false }"
    @keydown.escape.window="mobileMenuOpen = false"
>
    <header class="sticky top-0 z-40 flex h-14 items-center justify-between bg-gray-900 px-4 text-white shadow-sm sm:px-6">
        <a
            href="{{ route('admin.dashboard') }}"
            class="rounded text-lg font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-gray-900"
        >
            映画レビューアプリ　管理
        </a>

        <p class="hidden text-sm text-gray-300 md:block">
            管理者：{{ auth()->user()?->name }}
        </p>

        <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-md text-xl text-gray-100 transition hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-white md:hidden"
            aria-label="管理メニューを開閉する"
            aria-controls="admin-mobile-menu"
            :aria-expanded="mobileMenuOpen.toString()"
            @click="mobileMenuOpen = ! mobileMenuOpen"
        >
            <span x-show="! mobileMenuOpen" aria-hidden="true">☰</span>
            <span x-show="mobileMenuOpen" aria-hidden="true" style="display: none;">×</span>
        </button>
    </header>

    <aside class="fixed bottom-0 left-0 top-14 hidden w-48 bg-gray-700 px-3 py-5 text-gray-100 md:block">
        <nav aria-label="管理画面ナビゲーション">
            <ul class="space-y-1">
                @foreach ($navigationItems as $item)
                    <li>
                        @if ($item['href'])
                            <a
                                href="{{ $item['href'] }}"
                                @if ($item['active']) aria-current="page" @endif
                                @class([
                                    'block rounded-md border-l-2 px-3 py-2 text-sm transition hover:bg-gray-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white',
                                    'border-gray-200 font-semibold text-white' => $item['active'],
                                    'border-transparent font-medium text-gray-300' => ! $item['active'],
                                ])
                            >
                                {{ $item['label'] }}
                            </a>
                        @else
                            <span class="block cursor-default rounded-md border-l-2 border-transparent px-3 py-2 text-sm font-medium text-gray-300 transition hover:bg-gray-600 hover:text-white">
                                {{ $item['label'] }}
                            </span>
                        @endif
                    </li>
                @endforeach

                <li aria-hidden="true" class="my-4 border-t border-gray-500"></li>

                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button
                            type="submit"
                            class="block w-full rounded-md border-l-2 border-transparent px-3 py-2 text-left text-sm font-medium text-gray-300 transition hover:bg-gray-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                        >
                            ログアウト
                        </button>
                    </form>
                </li>

            </ul>
        </nav>
    </aside>

    <div
        x-show="mobileMenuOpen"
        x-transition.opacity
        class="fixed inset-0 top-14 z-40 bg-gray-900/60 md:hidden"
        style="display: none;"
        aria-hidden="true"
        @click="mobileMenuOpen = false"
    ></div>

    <aside
        id="admin-mobile-menu"
        x-show="mobileMenuOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="-translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="-translate-x-full"
        class="fixed bottom-0 left-0 top-14 z-50 w-64 max-w-[85vw] bg-gray-700 px-3 py-5 text-gray-100 shadow-2xl md:hidden"
        style="display: none;"
        @click.outside="mobileMenuOpen = false"
    >
        <nav aria-label="モバイル管理画面ナビゲーション">
            <ul class="space-y-1">
                @foreach ($navigationItems as $item)
                    <li>
                        @if ($item['href'])
                            <a
                                href="{{ $item['href'] }}"
                                @if ($item['active']) aria-current="page" @endif
                                @class([
                                    'block rounded-md border-l-2 px-3 py-3 text-sm transition hover:bg-gray-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white',
                                    'border-gray-200 font-semibold text-white' => $item['active'],
                                    'border-transparent font-medium text-gray-300' => ! $item['active'],
                                ])
                                @click="mobileMenuOpen = false"
                            >
                                {{ $item['label'] }}
                            </a>
                        @else
                            <span class="block cursor-default rounded-md border-l-2 border-transparent px-3 py-3 text-sm font-medium text-gray-300 transition hover:bg-gray-600 hover:text-white">
                                {{ $item['label'] }}
                            </span>
                        @endif
                    </li>
                @endforeach

                <li aria-hidden="true" class="my-4 border-t border-gray-500"></li>

                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button
                            type="submit"
                            class="block w-full rounded-md border-l-2 border-transparent px-3 py-3 text-left text-sm font-medium text-gray-300 transition hover:bg-gray-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                        >
                            ログアウト
                        </button>
                    </form>
                </li>

            </ul>
        </nav>
    </aside>
</div>
