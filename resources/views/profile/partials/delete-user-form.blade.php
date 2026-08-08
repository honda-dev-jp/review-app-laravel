<section class="space-y-6">
    {{-- ==================== 会員退会セクション：開始 ==================== --}}

    {{-- 退会機能の見出し・説明 --}}
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 whitespace-pre-line text-sm text-gray-600">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
    </header>

    {{-- 退会確認モーダルを開くボタン --}}
    <x-danger-button
        id="delete-account-trigger"
        type="button"
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >
        {{ __('Delete Account') }}
    </x-danger-button>

    {{-- ==================== 退会確認モーダル：開始 ==================== --}}
    <x-modal
        name="confirm-user-deletion"
        :show="$errors->userDeletion->isNotEmpty()"
        labelled-by="confirm-user-deletion-title"
        restore-focus-to="delete-account-trigger"
        focusable
    >
        {{-- 退会処理送信フォーム --}}
        {{-- すべての閉じ方を共通モーダルのshowへ集約し、退会フォーム固有の状態だけをリセットする。 --}}
        <form
            method="post"
            action="{{ route('profile.destroy') }}"
            class="p-6"
            x-data="{
                showDeletionError: @js($errors->userDeletion->has('password')),
                password: '',
                resetDeletionUi() {
                    this.showDeletionError = false
                    this.password = ''
                },
            }"
            x-init="$watch('show', value => {
                if (! value) {
                    resetDeletionUi()
                }
            })"
        >
            @csrf
            @method('delete')

            {{-- モーダルの見出し --}}
            <h2
                id="confirm-user-deletion-title"
                class="text-lg font-medium text-gray-900"
            >
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            {{-- 退会後のデータと確認方法の説明 --}}
            <p class="mt-1 whitespace-pre-line text-sm text-gray-600">
                {{ __('Once your account is deleted, it cannot be restored. Your reviews and replies will remain and be displayed anonymously. Please enter your current password to confirm account deletion.') }}
            </p>

            {{-- 現在のパスワード入力欄 --}}
            <div class="mt-6">
                <x-input-label
                    for="password"
                    value="{{ __('Current Password') }}"
                    class="sr-only"
                />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    :aria-invalid="$errors->userDeletion->has('password') ? 'true' : null"
                    :aria-describedby="$errors->userDeletion->has('password') ? 'user-deletion-password-error' : null"
                    x-model="password"
                    x-bind:aria-invalid="showDeletionError ? 'true' : null"
                    x-bind:aria-describedby="showDeletionError ? 'user-deletion-password-error' : null"
                    autocomplete="current-password"
                    placeholder="{{ __('Current Password') }}"
                />

                {{-- userDeletionエラーバッグのパスワードエラーを表示 --}}
                <x-input-error
                    id="user-deletion-password-error"
                    :messages="$errors->userDeletion->get('password')"
                    class="mt-2"
                    x-show="showDeletionError"
                />
            </div>

            {{-- キャンセル・退会実行ボタン --}}
            <div class="mt-6 flex justify-end">
                {{-- モーダルを閉じる --}}
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                {{-- 退会処理を送信する --}}
                <x-danger-button class="ms-3">
                    {{ __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
    {{-- ==================== 退会確認モーダル：終了 ==================== --}}

    {{-- ==================== 会員退会セクション：終了 ==================== --}}
</section>
