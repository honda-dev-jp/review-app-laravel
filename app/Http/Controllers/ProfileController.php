<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * アカウント画面を表示する。
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * ログインユーザーのアカウント情報を更新する。
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldAvatarPath = $user->avatar_path;
        $newAvatarPath = null;

        // アップロードファイルは一括代入せず、保存後の相対パスだけを avatar_path へ設定する
        $user->fill(
            $request->safe()->except('avatar_image')
        );

        if ($request->hasFile('avatar_image')) {
            $avatarImage = $request->file('avatar_image');

            $newAvatarPath = $avatarImage->store('avatars', 'public');

            if ($newAvatarPath === false) {
                throw new \RuntimeException('ユーザーアイコンの保存に失敗しました。');
            }

            $user->avatar_path = $newAvatarPath;
        }

        // メールアドレスが変更されたか確認
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        // DB更新に失敗した場合は、新たに保存した画像を削除し、DBとストレージの整合性を維持する
        try {
            if (! $user->save()) {
                throw new \RuntimeException('アカウント情報の更新に失敗しました。');
            }
        } catch (\Throwable $e) {
            if ($newAvatarPath !== null) {
                try {
                    $deleted = Storage::disk('public')->delete($newAvatarPath);

                    if (! $deleted) {
                        report(new \RuntimeException(
                            'DB更新失敗後の新ユーザーアイコン削除に失敗しました。'
                        ));
                    }
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $e;
        }

        // DB更新後に旧画像を削除し、削除失敗では更新全体を失敗扱いにしない
        if (
            $newAvatarPath !== null
            && is_string($oldAvatarPath)
            && str_starts_with($oldAvatarPath, 'avatars/')
            && $oldAvatarPath !== $newAvatarPath
        ) {
            try {
                $deleted = Storage::disk('public')->delete($oldAvatarPath);

                if (! $deleted) {
                    report(new \RuntimeException(
                        '旧ユーザーアイコンの削除に失敗しました。'
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return Redirect::route('profile.edit')
            ->withFragment('profile-information')
            ->with('status', 'profile-updated');
    }

    /**
     * ログインユーザーのアカウントを削除する。
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $avatarPath = $user->avatar_path;

        if (! $user->delete()) {
            throw new \RuntimeException('退会処理に失敗しました。');
        }

        // logout時に削除済みUserのremember_tokenが更新され、再保存されることを防ぐ
        $user->setRememberToken(null);

        Auth::logout();

        // DB整合性を優先し、退会完了後にユーザー固有画像の削除を試みる
        if (
            is_string($avatarPath)
            && str_starts_with($avatarPath, 'avatars/')
        ) {
            try {
                $deleted = Storage::disk('public')->delete($avatarPath);

                if (! $deleted) {
                    report(new \RuntimeException(
                        '退会時のユーザーアイコン削除に失敗しました。'
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::route('home')
            ->with('status', __('Your account has been deleted.'));
    }
}
