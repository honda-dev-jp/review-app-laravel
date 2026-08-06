<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    {{-- メール認証リンク再送フォーム --}}
    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    {{-- アカウント情報更新フォーム（ユーザーアイコンを含む） --}}
    <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        {{-- ユーザーアイコン入力欄 --}}
        <div
            x-data="{
                previewUrl: null,
                updatePreview(event) {
                    const file = event.target.files[0];

                    if (! file) {
                        if (this.previewUrl) {
                            URL.revokeObjectURL(this.previewUrl);
                        }

                        this.previewUrl = null;

                        return;
                    }

                    if (this.previewUrl) {
                        URL.revokeObjectURL(this.previewUrl);
                    }

                    this.previewUrl = URL.createObjectURL(file);
                }
            }"
        >
            <x-input-label for="avatar_image" :value="__('User Icon')" />

            <div class="mb-3 mt-2">
                <template x-if="previewUrl">
                    <img
                        :src="previewUrl"
                        alt="選択したユーザーアイコンのプレビュー"
                        class="h-20 w-20 rounded-full object-cover"
                    >
                </template>

                <template x-if="! previewUrl">
                    <x-user-avatar
                        :user="$user"
                        alt="現在のユーザーアイコン"
                        class="h-20 w-20"
                    />
                </template>
            </div>

            <input
                id="avatar_image"
                name="avatar_image"
                type="file"
                accept=".jpg,.jpeg,.png,.webp"
                @change="updatePreview($event)"
                @error('avatar_image')
                    aria-invalid="true"
                    aria-describedby="avatar-image-error"
                @enderror
                class="mt-2 block w-full text-sm text-gray-700
                    file:mr-4 file:rounded-md file:border-0
                    file:bg-gray-800 file:px-4 file:py-2
                    file:text-sm file:font-semibold file:text-white
                    hover:file:bg-gray-700
                    focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >

            <x-input-error
                id="avatar-image-error"
                class="mt-2"
                :messages="$errors->get('avatar_image')"
            />
        </div>

        {{-- ニックネーム入力欄 --}}
        <div>
            <x-input-label for="name" :value="__('Name')" />

            <x-text-input
                id="name"
                name="name"
                type="text"
                class="mt-1 block w-full"
                :value="old('name', $user->name)"
                :aria-invalid="$errors->has('name') ? 'true' : null"
                :aria-describedby="$errors->has('name') ? 'profile-name-error' : null"
                required
                autofocus
                autocomplete="name"
            />

            <x-input-error
                id="profile-name-error"
                class="mt-2"
                :messages="$errors->get('name')"
            />
        </div>

        {{-- メールアドレス入力欄 --}}
        <div>
            <x-input-label for="email" :value="__('Email')" />

            <x-text-input
                id="email"
                name="email"
                type="email"
                class="mt-1 block w-full"
                :value="old('email', $user->email)"
                :aria-invalid="$errors->has('email') ? 'true' : null"
                :aria-describedby="$errors->has('email') ? 'profile-email-error' : null"
                required
                autocomplete="username"
            />

            <x-input-error
                id="profile-email-error"
                class="mt-2"
                :messages="$errors->get('email')"
            />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- 自己紹介入力欄 --}}
        <div>
            <x-input-label for="profile" :value="__('Self Introduction')" />

            <textarea
                id="profile"
                name="profile"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                rows="5"
                maxlength="1000"
                @error('profile')
                    aria-invalid="true"
                    aria-describedby="profile-error"
                @enderror
            >{{ old('profile', $user->profile) }}</textarea>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('Please enter within 1000 characters.') }}
            </p>
            <x-input-error
                id="profile-error"
                class="mt-2"
                :messages="$errors->get('profile')"
            />
        </div>

        {{-- 更新ボタンと保存完了メッセージ --}}
        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
