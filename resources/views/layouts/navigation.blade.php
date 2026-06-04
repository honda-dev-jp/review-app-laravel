<nav
    x-data="{ mobileMenuOpen: false }"
    class="sticky top-0 z-40 border-b border-slate-200/70 bg-white/90 backdrop-blur"
    @keydown.escape.window="mobileMenuOpen = false"
>
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center" aria-label="映画レビュー ホーム">
            <img src="{{ asset('images/logo-header.png') }}" alt="映画レビュー" class="h-10 w-auto sm:h-12">
        </a>

        <div class="flex items-center gap-6">
            <div class="hidden items-center gap-8 whitespace-nowrap text-sm font-semibold text-slate-600 md:flex" aria-label="PCナビゲーション">
                <x-nav-link :href="route('items.index')" :active="request()->routeIs('home') || request()->routeIs('items.*')">
                    作品一覧
                </x-nav-link>

                @auth
                    <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.edit')">
                        プロフィール編集
                    </x-nav-link>
                @endauth
            </div>

            @guest
                <div class="hidden items-center gap-3 whitespace-nowrap md:flex">
                    <a
                        href="{{ route('login') }}"
                        class="rounded-full border border-blue-200 bg-white px-5 py-2 text-sm font-bold text-blue-700 shadow-sm transition hover:border-blue-300 hover:bg-blue-50 hover:shadow-md"
                    >
                        ログイン
                    </a>

                    @if (Route::has('register'))
                        <a
                            href="{{ route('register') }}"
                            class="rounded-full bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-md"
                        >
                            会員登録
                        </a>
                    @endif
                </div>
            @endguest

            @auth
                <div class="relative hidden items-center gap-3 whitespace-nowrap md:flex" x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button
                        type="button"
                        class="group flex items-center gap-3 rounded-full border border-slate-200 bg-white px-3 py-2 shadow-sm transition hover:border-blue-200 hover:bg-blue-50/60"
                        aria-label="ユーザーメニュー"
                        :aria-expanded="open.toString()"
                        @click="open = ! open"
                        @click.outside="open = false"
                    >
                        <span class="grid h-9 w-9 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-blue-100 to-sky-100 text-lg">
                            {{ mb_substr(Auth::user()->name, 0, 1) }}
                        </span>
                        <span class="text-sm font-semibold text-slate-700">{{ Auth::user()->name }}</span>
                        <span class="text-slate-400 transition group-hover:text-blue-500" :class="{ 'rotate-180': open }">⌄</span>
                    </button>

                    <div
                        x-show="open"
                        x-transition.origin.top.right
                        class="absolute right-0 top-14 z-50 w-56 overflow-hidden rounded-3xl border border-slate-200 bg-white p-2 shadow-xl"
                        style="display: none;"
                    >
                        <a
                            href="{{ route('profile.edit') }}"
                            class="block rounded-2xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-blue-50 hover:text-blue-700"
                        >
                            プロフィール編集
                        </a>

                        <div class="my-2 border-t border-slate-100"></div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <button
                                type="submit"
                                class="block w-full rounded-2xl px-4 py-3 text-left text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
                            >
                                ログアウト
                            </button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>

        <button
            type="button"
            class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50 md:hidden"
            aria-label="メニューを開く"
            aria-controls="mobile-menu"
            :aria-expanded="mobileMenuOpen.toString()"
            @click="mobileMenuOpen = ! mobileMenuOpen"
        >
            <span x-show="! mobileMenuOpen" aria-hidden="true">☰</span>
            <span x-show="mobileMenuOpen" aria-hidden="true" style="display: none;">×</span>
        </button>
    </div>

    <div
        x-show="mobileMenuOpen"
        x-transition.opacity
        class="fixed inset-0 top-[65px] z-40 bg-slate-900/30 md:hidden"
        style="display: none;"
        @click="mobileMenuOpen = false"
        aria-hidden="true"
    ></div>

    <aside
        id="mobile-menu"
        x-show="mobileMenuOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="fixed right-0 top-[65px] z-50 h-[calc(100vh-65px)] w-60 max-w-[78vw] border-l border-slate-200 bg-white px-3 py-4 shadow-2xl md:hidden"
        style="display: none;"
        @click.outside="mobileMenuOpen = false"
    >
        <div class="flex flex-col gap-2 text-sm font-semibold" aria-label="スマホナビゲーション">
            <a
                href="{{ route('items.index') }}"
                class="rounded-2xl bg-blue-50 px-4 py-3 text-blue-700 transition hover:bg-blue-100 hover:text-blue-800"
                @click="mobileMenuOpen = false"
            >
                作品一覧
            </a>

            @guest
                <a
                    href="{{ route('login') }}"
                    class="rounded-2xl px-4 py-3 text-slate-600 transition hover:bg-slate-50 hover:text-blue-600"
                    @click="mobileMenuOpen = false"
                >
                    ログイン
                </a>

                @if (Route::has('register'))
                    <a
                        href="{{ route('register') }}"
                        class="rounded-2xl bg-blue-600 px-4 py-3 text-center text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-md"
                        @click="mobileMenuOpen = false"
                    >
                        会員登録
                    </a>
                @endif
            @endguest

            @auth
                <a
                    href="{{ route('profile.edit') }}"
                    class="rounded-2xl px-4 py-3 text-slate-600 transition hover:bg-slate-50 hover:text-blue-600"
                    @click="mobileMenuOpen = false"
                >
                    プロフィール編集
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <button
                        type="submit"
                        class="w-full rounded-2xl px-4 py-3 text-left text-slate-600 transition hover:bg-slate-50 hover:text-blue-600"
                    >
                        ログアウト
                    </button>
                </form>
            @endauth
        </div>
    </aside>
</nav>
