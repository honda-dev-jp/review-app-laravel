<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ReviewComment;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\Expectation;
use RuntimeException;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * アカウント画面を統一された表示名称で表示できることを確認する。
     */
    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $xpath = $this->createXPath($response->getContent());
        $accountHeadings = $xpath->query('//h2[normalize-space(.)="アカウント"]');
        $accountNavigationLinks = $xpath->query(sprintf(
            '//a[@href="%s" and normalize-space(.)="アカウント"]',
            route('profile.edit')
        ));

        $this->assertNotFalse($accountHeadings);
        $this->assertCount(1, $accountHeadings);
        $this->assertNotFalse($accountNavigationLinks);
        $this->assertCount(3, $accountNavigationLinks);

        $response
            ->assertOk()
            ->assertDontSeeText('プロフィール編集');
    }

    /**
     * プロフィール情報を更新できることを確認する。
     */
    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * アカウント画面から自己紹介を更新できることを確認する。
     */
    public function test_profile_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => '映画の感想を書くのが好きです。',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('映画の感想を書くのが好きです。', $user->profile);
    }

    /**
     * 自己紹介を1000文字まで保存できることを確認する。
     */
    public function test_profile_can_be_updated_with_1000_characters(): void
    {
        $user = User::factory()->create();
        $profile = str_repeat('a', 1000);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => $profile,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame($profile, $user->refresh()->profile);
    }

    /**
     * 自己紹介が1001文字の場合にバリデーションエラーになることを確認する。
     */
    public function test_profile_cannot_be_updated_with_more_than_1000_characters(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => str_repeat('a', 1001),
            ]);

        $response->assertSessionHasErrors('profile');
    }

    /**
     * JPEGのユーザーアイコンを安全な保存名で登録できることを確認する。
     */
    public function test_jpeg_avatar_can_be_uploaded(): void
    {
        $this->assertAvatarCanBeUploaded(
            UploadedFile::fake()->image('original-avatar.jpg')
        );
    }

    /**
     * PNGのユーザーアイコンを安全な保存名で登録できることを確認する。
     */
    public function test_png_avatar_can_be_uploaded(): void
    {
        $this->assertAvatarCanBeUploaded(
            $this->fakeImageWithContent('original-avatar.png', 'png')
        );
    }

    /**
     * WebPのユーザーアイコンを安全な保存名で登録できることを確認する。
     */
    public function test_webp_avatar_can_be_uploaded(): void
    {
        $this->assertAvatarCanBeUploaded(
            $this->fakeImageWithContent('original-avatar.webp', 'webp')
        );
    }

    /**
     * GIFのユーザーアイコンが拒否され、既存状態を維持することを確認する。
     */
    public function test_gif_avatar_is_rejected(): void
    {
        $this->assertAvatarIsRejected(
            UploadedFile::fake()->createWithContent(
                'avatar.gif',
                (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true)
            ),
            'アップロードできる画像の形式はJPEG、PNG、WebPです。'
        );
    }

    /**
     * SVGのユーザーアイコンが拒否され、既存状態を維持することを確認する。
     */
    public function test_svg_avatar_is_rejected(): void
    {
        $this->assertAvatarIsRejected(
            UploadedFile::fake()->createWithContent(
                'avatar.svg',
                '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>'
            )
        );
    }

    /**
     * 画像以外のファイルが拒否され、既存状態を維持することを確認する。
     */
    public function test_non_image_avatar_is_rejected(): void
    {
        $this->assertAvatarIsRejected(
            UploadedFile::fake()->createWithContent('avatar.txt', 'not an image')
        );
    }

    /**
     * 拡張子や申告MIMEだけを信用すると不正な内容を画像として受け入れるため、
     * 実ファイル内容に基づく形式検証が維持されることを保証する。
     */
    public function test_avatar_with_spoofed_allowed_extension_is_rejected(): void
    {
        $gifImage = UploadedFile::fake()->image('actual-content.gif');
        // fakeファイルは名前由来のMIME判定へ寄るため、
        // 実内容に基づく形式検証には通常のUploadedFileを使用する。
        $spoofedImage = new UploadedFile(
            $gifImage->getPathname(),
            'spoofed-avatar.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->assertAvatarIsRejected($spoofedImage);
    }

    /**
     * 2MBを超える画像が拒否され、既存状態を維持することを確認する。
     */
    public function test_avatar_larger_than_two_megabytes_is_rejected(): void
    {
        $this->assertAvatarIsRejected(
            UploadedFile::fake()->image('oversized-avatar.jpg')->size(2049),
            'アップロードする画像の容量は2MB以下にしてください。'
        );
    }

    /**
     * 2049KBの拒否だけでは上限値の誤変更を検出できないため、
     * 最大2MBという許可境界が維持されることを保証する。
     */
    public function test_avatar_at_two_megabytes_can_be_uploaded(): void
    {
        $this->assertAvatarCanBeUploaded(
            UploadedFile::fake()->image('two-megabyte-avatar.jpg')->size(2048)
        );
    }

    /**
     * 新画像を選択せずにアカウント情報を更新した場合、既存画像を維持することを確認する。
     */
    public function test_existing_avatar_is_preserved_when_no_new_image_is_selected(): void
    {
        Storage::fake('public');

        $oldAvatarPath = 'avatars/existing-avatar.jpg';
        Storage::disk('public')->put($oldAvatarPath, 'existing image');

        $user = User::factory()->create();
        $user->avatar_path = $oldAvatarPath;
        $user->save();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => '更新後ユーザー',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame($oldAvatarPath, $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($oldAvatarPath);
        $this->assertSame([$oldAvatarPath], Storage::disk('public')->allFiles('avatars'));
    }

    /**
     * 差し替え時にDBと新画像を更新し、DB更新後に旧画像を削除することを確認する。
     */
    public function test_avatar_can_be_replaced_and_old_avatar_is_deleted(): void
    {
        Storage::fake('public');

        $oldAvatarPath = 'avatars/old-avatar.jpg';
        Storage::disk('public')->put($oldAvatarPath, 'old image');

        $user = User::factory()->create();
        $user->avatar_path = $oldAvatarPath;
        $user->save();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_image' => $this->fakeImageWithContent('new-avatar.png', 'png'),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $newAvatarPath = $user->refresh()->avatar_path;
        $this->assertIsString($newAvatarPath);
        $this->assertNotSame($oldAvatarPath, $newAvatarPath);
        Storage::disk('public')->assertExists($newAvatarPath);
        Storage::disk('public')->assertMissing($oldAvatarPath);
    }

    /**
     * DB値が改変されavatars配下以外を指していても共有資産を誤削除しないため、
     * 差し替え時の削除対象がユーザー固有画像だけに限定されることを保証する。
     */
    public function test_avatar_outside_avatar_directory_is_not_deleted_during_replacement(): void
    {
        Storage::fake('public');

        $sharedImagePath = 'other/shared-image.png';
        Storage::disk('public')->put($sharedImagePath, 'shared image');

        $user = User::factory()->create(['avatar_path' => $sharedImagePath]);

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_image' => UploadedFile::fake()->image('new-avatar.jpg'),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $newAvatarPath = $user->refresh()->avatar_path;
        $this->assertIsString($newAvatarPath);
        $this->assertMatchesRegularExpression('#^avatars/[^/]+$#', $newAvatarPath);
        Storage::disk('public')->assertExists($newAvatarPath);
        Storage::disk('public')->assertExists($sharedImagePath);
    }

    /**
     * 新画像を保存できていない状態でDBや旧画像を変更すると表示不能になるため、
     * 保存失敗時は既存状態を維持し、失敗を呼び出し元へ通知することを保証する。
     */
    public function test_avatar_storage_failure_preserves_existing_state_and_rethrows_exception(): void
    {
        Storage::fake('public');

        $oldAvatarPath = 'avatars/existing-avatar.jpg';
        $fakeDisk = Storage::disk('public');
        $fakeDisk->put($oldAvatarPath, 'existing image');

        $originalName = '変更前ユーザー名';
        $originalProfile = '変更前の自己紹介です。';
        $user = User::factory()->create([
            'name' => $originalName,
            'profile' => $originalProfile,
        ]);
        $user->avatar_path = $oldAvatarPath;
        $user->save();

        $failingDisk = \Mockery::mock($fakeDisk)->makePartial();
        /** @var Expectation $putFileAsExpectation */
        $putFileAsExpectation = $failingDisk->shouldReceive('putFileAs');
        $putFileAsExpectation->once()->andReturnFalse();
        Storage::set('public', $failingDisk);

        $this->withoutExceptionHandling();

        $exception = null;

        try {
            $this
                ->actingAs($user)
                ->patch(route('profile.update'), [
                    'name' => '保存されないユーザー名',
                    'email' => $user->email,
                    'profile' => '保存されない自己紹介です。',
                    'avatar_image' => UploadedFile::fake()->image('new-avatar.jpg'),
                ]);
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('ユーザーアイコンの保存に失敗しました。', $exception->getMessage());
        $user->refresh();
        $this->assertSame($originalName, $user->name);
        $this->assertSame($originalProfile, $user->profile);
        $this->assertSame($oldAvatarPath, $user->avatar_path);
        $fakeDisk->assertExists($oldAvatarPath);
        $this->assertSame([$oldAvatarPath], $fakeDisk->allFiles('avatars'));
    }

    /**
     * 新画像保存後にDB更新だけ失敗すると未参照ファイルが残るため、
     * 新画像を補償削除してDBとStorageの整合性を維持することを保証する。
     */
    public function test_database_update_failure_removes_new_avatar_and_preserves_existing_state(): void
    {
        Storage::fake('public');

        $oldAvatarPath = 'avatars/existing-avatar.jpg';
        Storage::disk('public')->put($oldAvatarPath, 'existing image');

        $originalName = '変更前ユーザー名';
        $originalProfile = '変更前の自己紹介です。';
        $user = User::factory()->create([
            'name' => $originalName,
            'profile' => $originalProfile,
            'avatar_path' => $oldAvatarPath,
        ]);

        $databaseException = new RuntimeException('DB更新処理で発生した元例外です。');

        User::saving(function (User $savingUser) use ($databaseException, $user): void {
            if ($savingUser->is($user)) {
                throw $databaseException;
            }
        });

        $this->withoutExceptionHandling();

        $exception = null;

        try {
            $this
                ->actingAs($user)
                ->patch(route('profile.update'), [
                    'name' => '保存されないユーザー名',
                    'email' => $user->email,
                    'profile' => '保存されない自己紹介です。',
                    'avatar_image' => UploadedFile::fake()->image('new-avatar.jpg'),
                ]);
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame($databaseException, $exception);
        $user->refresh();
        $this->assertSame($originalName, $user->name);
        $this->assertSame($originalProfile, $user->profile);
        $this->assertSame($oldAvatarPath, $user->avatar_path);
        Storage::disk('public')->assertExists($oldAvatarPath);
        $this->assertSame([$oldAvatarPath], Storage::disk('public')->allFiles('avatars'));
    }

    /**
     * save()の例外経路とは別にfalseが返る分岐を守るため、Controllerが生成する
     * 失敗例外と新画像の補償削除によって既存状態が維持されることを保証する。
     */
    public function test_database_save_returning_false_removes_new_avatar_and_preserves_existing_state(): void
    {
        Storage::fake('public');

        $oldAvatarPath = 'avatars/existing-avatar.jpg';
        Storage::disk('public')->put($oldAvatarPath, 'existing image');

        $originalName = '変更前ユーザー名';
        $originalProfile = '変更前の自己紹介です。';
        $user = User::factory()->create([
            'name' => $originalName,
            'profile' => $originalProfile,
            'avatar_path' => $oldAvatarPath,
        ]);

        User::saving(function (User $savingUser) use ($user): ?bool {
            return $savingUser->is($user) ? false : null;
        });

        $this->withoutExceptionHandling();

        $exception = null;

        try {
            $this
                ->actingAs($user)
                ->patch(route('profile.update'), [
                    'name' => '保存されないユーザー名',
                    'email' => $user->email,
                    'profile' => '保存されない自己紹介です。',
                    'avatar_image' => UploadedFile::fake()->image('new-avatar.jpg'),
                ]);
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('アカウント情報の更新に失敗しました。', $exception->getMessage());
        $user->refresh();
        $this->assertSame($originalName, $user->name);
        $this->assertSame($originalProfile, $user->profile);
        $this->assertSame($oldAvatarPath, $user->avatar_path);
        Storage::disk('public')->assertExists($oldAvatarPath);
        $this->assertSame([$oldAvatarPath], Storage::disk('public')->allFiles('avatars'));
    }

    /**
     * DB更新後の旧画像削除失敗で更新全体を失敗扱いにしないため、
     * 正しく保存された新画像とDB更新結果が維持されることを保証する。
     */
    public function test_old_avatar_deletion_failure_keeps_successful_update_and_new_avatar(): void
    {
        Storage::fake('public');

        $oldAvatarPath = 'avatars/undeletable-avatar.jpg';
        $fakeDisk = Storage::disk('public');
        $fakeDisk->put($oldAvatarPath, 'old image');

        $user = User::factory()->create();
        $user->avatar_path = $oldAvatarPath;
        $user->save();

        $failingDisk = \Mockery::mock($fakeDisk)->makePartial();
        /** @var Expectation $deleteExpectation */
        $deleteExpectation = $failingDisk->shouldReceive('delete');
        $deleteExpectation
            ->once()
            ->with($oldAvatarPath)
            ->andReturnFalse();
        Storage::set('public', $failingDisk);

        $this->mock(ExceptionHandler::class, function ($mock): void {
            $mock->expects('report');
        });

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_image' => UploadedFile::fake()->image('new-avatar.jpg'),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $newAvatarPath = $user->refresh()->avatar_path;
        $this->assertIsString($newAvatarPath);
        $this->assertNotSame($oldAvatarPath, $newAvatarPath);
        $fakeDisk->assertExists($newAvatarPath);
        $fakeDisk->assertExists($oldAvatarPath);
    }

    /**
     * アバター未設定時はアカウント画面でNo Image画像へフォールバックすることを確認する。
     */
    public function test_null_avatar_path_displays_no_image_on_profile_page(): void
    {
        Storage::fake('public');

        $this->assertProfilePageDisplaysAvatar(
            User::factory()->create(['avatar_path' => null]),
            asset('images/no-image.png')
        );
    }

    /**
     * DBとStorageの一時的な不整合で画面を壊さないため、
     * 記録された実ファイルが存在しない場合もNo Imageへフォールバックすることを保証する。
     */
    public function test_missing_avatar_file_displays_no_image_on_profile_page(): void
    {
        Storage::fake('public');

        $this->assertProfilePageDisplaysAvatar(
            User::factory()->create(['avatar_path' => 'avatars/missing-avatar.jpg']),
            asset('images/no-image.png')
        );
    }

    /**
     * DB値が改変されても任意のStorageパスを表示対象として信用しないため、
     * 許可したavatars配下以外はNo Imageへフォールバックする安全境界を保証する。
     */
    public function test_avatar_path_outside_avatar_directory_displays_no_image_on_profile_page(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('other/avatar.jpg', 'other image');

        $this->assertProfilePageDisplaysAvatar(
            User::factory()->create(['avatar_path' => 'other/avatar.jpg']),
            asset('images/no-image.png')
        );
    }

    /**
     * PC用とモバイル用のナビゲーションは同じBlade内の別要素であるため、
     * 片方だけ表示対応が欠落する回帰を防ぎ、アカウント画面への表示も保証する。
     */
    public function test_profile_page_and_both_navigation_variants_display_avatar(): void
    {
        Storage::fake('public');

        $avatarPath = 'avatars/navigation-avatar.jpg';
        Storage::disk('public')->put($avatarPath, 'avatar image');

        $user = User::factory()->create(['avatar_path' => $avatarPath]);
        $avatarUrl = Storage::disk('public')->url($avatarPath);

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());
        $navigationAvatars = $xpath->query(sprintf('//nav//img[@src="%s"]', $avatarUrl));
        $profileAvatars = $xpath->query(sprintf(
            '//img[@src="%s" and @alt="現在のユーザーアイコン"]',
            $avatarUrl
        ));

        $this->assertNotFalse($navigationAvatars);
        $this->assertCount(2, $navigationAvatars);
        $this->assertNotFalse($profileAvatars);
        $this->assertCount(1, $profileAvatars);
    }

    /**
     * 支援技術がエラー対象とメッセージの関係を識別できるよう、
     * エラー時だけ適切なARIA属性で関連付けられることを保証する。
     */
    public function test_avatar_validation_error_has_accessible_aria_attributes(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $normalResponse = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $normalXPath = $this->createXPath($normalResponse->getContent());
        $normalInputs = $normalXPath->query('//*[@id="avatar_image"]');

        $this->assertNotFalse($normalInputs);
        $this->assertCount(1, $normalInputs);

        $normalInput = $normalInputs->item(0);
        $this->assertInstanceOf(DOMElement::class, $normalInput);
        $this->assertFalse($normalInput->hasAttribute('aria-invalid'));
        $this->assertFalse($normalInput->hasAttribute('aria-describedby'));

        $response = $this
            ->actingAs($user)
            ->followingRedirects()
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_image' => UploadedFile::fake()->createWithContent('avatar.txt', 'not an image'),
            ]);

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());
        $inputs = $xpath->query('//*[@id="avatar_image"]');
        $errors = $xpath->query('//*[@id="avatar-image-error"]');

        $this->assertNotFalse($inputs);
        $this->assertCount(1, $inputs);
        $this->assertNotFalse($errors);
        $this->assertCount(1, $errors);

        $input = $inputs->item(0);
        $this->assertInstanceOf(DOMElement::class, $input);
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertSame('avatar-image-error', $input->getAttribute('aria-describedby'));
    }

    /**
     * 他ユーザーIDやavatar_pathをリクエストへ混入されても権限逸脱させないため、
     * 更新対象が認証ユーザー本人に限定されることを保証する。
     */
    public function test_authenticated_user_cannot_change_another_users_avatar(): void
    {
        Storage::fake('public');

        $otherAvatarPath = 'avatars/other-user-avatar.jpg';
        Storage::disk('public')->put($otherAvatarPath, 'other user avatar');

        $user = User::factory()->create();
        $otherUser = User::factory()->create(['avatar_path' => $otherAvatarPath]);

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'user_id' => $otherUser->id,
                'avatar_path' => $otherAvatarPath,
                'avatar_image' => UploadedFile::fake()->image('own-avatar.jpg'),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->avatar_path);
        $this->assertNotSame($otherAvatarPath, $user->avatar_path);
        $this->assertSame($otherAvatarPath, $otherUser->refresh()->avatar_path);
        Storage::disk('public')->assertExists($otherAvatarPath);
    }

    /**
     * プロフィール更新リクエストに権限や画像パスを含めても更新されないことを確認する。
     */
    public function test_role_and_avatar_path_cannot_be_updated_from_profile_information(): void
    {
        $user = User::factory()->create();
        $user->avatar_path = 'avatars/current.png';
        $user->save();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'profile' => 'プロフィール本文',
                'role' => 'admin',
                'avatar_path' => 'avatars/changed.png',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('user', $user->role);
        $this->assertSame('avatars/current.png', $user->avatar_path);
    }

    /**
     * アカウント画面に退会フォームが表示されることを確認する。
     */
    public function test_delete_account_form_is_displayed_on_profile_page(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $xpath = $this->createXPath($response->getContent());
        $forms = $xpath->query(sprintf(
            '//form[@action="%s" and .//input[@type="hidden" and @name="_method" and translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="DELETE"]]',
            route('profile.destroy')
        ));

        $this->assertNotFalse($forms);
        $this->assertCount(1, $forms);

        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('post', strtolower($form->getAttribute('method')));

        $methodInputs = $xpath->query(
            './/input[@type="hidden" and @name="_method" and translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="DELETE"]',
            $form
        );
        $csrfInputs = $xpath->query(
            './/input[@type="hidden" and @name="_token" and string-length(@value) > 0]',
            $form
        );
        $passwordInputs = $xpath->query(
            './/input[@type="password" and @name="password"]',
            $form
        );

        $this->assertNotFalse($methodInputs);
        $this->assertCount(1, $methodInputs);
        $this->assertNotFalse($csrfInputs);
        $this->assertCount(1, $csrfInputs);
        $this->assertNotFalse($passwordInputs);
        $this->assertCount(1, $passwordInputs);

        $response
            ->assertOk()
            ->assertSeeText(__('Delete Account'))
            ->assertSeeText(__(
                'Once your account is deleted, it cannot be restored. Your reviews and replies will remain and be displayed anonymously. Please enter your current password to confirm account deletion.'
            ));
    }

    /**
     * メールアドレスを変更しない場合は、メール認証済み状態が維持されることを確認する。
     */
    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * 正しいパスワードでユーザーを物理削除し、セッションを無効化してCSRFトークンを再生成し、
     * ログアウト後のhome画面に完了メッセージが表示されることを確認する。
     */
    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();
        $this->withSession([
            'account-deletion-marker' => 'value-to-be-invalidated',
        ]);
        $csrfTokenBeforeDeletion = csrf_token();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'アカウントを削除しました。')
            ->assertSessionMissing('account-deletion-marker')
            ->assertRedirect(route('home'));

        $this->assertNotSame($csrfTokenBeforeDeletion, csrf_token());
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText('アカウントを削除しました。');
    }

    /**
     * 退会時にユーザー固有のアバター画像が削除されることを確認する。
     */
    public function test_user_avatar_is_deleted_after_account_deletion(): void
    {
        Storage::fake('public');

        $avatarPath = 'avatars/deleting-user-avatar.jpg';
        Storage::disk('public')->put($avatarPath, 'avatar image');

        $user = User::factory()->create(['avatar_path' => $avatarPath]);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('public')->assertMissing($avatarPath);
    }

    /**
     * Storage上のファイル不存在を理由に退会済みユーザーをDBへ残さないため、
     * 画像がなくても退会のDB結果が維持されることを保証する。
     */
    public function test_account_deletion_succeeds_when_avatar_file_is_missing(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_path' => 'avatars/missing-avatar.jpg',
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * 画像削除失敗より会員情報のDB整合性を優先する設計を守るため、
     * Storage障害時も退会のDB結果が維持されることを保証する。
     */
    public function test_avatar_deletion_failure_does_not_rollback_account_deletion(): void
    {
        Storage::fake('public');

        $avatarPath = 'avatars/undeletable-user-avatar.jpg';
        $fakeDisk = Storage::disk('public');
        $fakeDisk->put($avatarPath, 'avatar image');

        $user = User::factory()->create(['avatar_path' => $avatarPath]);

        $failingDisk = \Mockery::mock($fakeDisk)->makePartial();
        /** @var Expectation $deleteExpectation */
        $deleteExpectation = $failingDisk->shouldReceive('delete');
        $deleteExpectation
            ->once()
            ->with($avatarPath)
            ->andReturnFalse();
        Storage::set('public', $failingDisk);

        $this->mock(ExceptionHandler::class, function ($mock): void {
            $mock->expects('report');
        });

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $fakeDisk->assertExists($avatarPath);
    }

    /**
     * No Imageは全ユーザーが共有するアプリ管理資産であるため、
     * 個別ユーザーの退会処理で誤削除されないことを保証する。
     */
    public function test_no_image_asset_is_not_deleted_after_account_deletion(): void
    {
        Storage::fake('public');

        $fakeDisk = Storage::disk('public');
        $diskMock = \Mockery::mock($fakeDisk)->makePartial();
        $diskMock->shouldNotReceive('delete');
        Storage::set('public', $diskMock);

        $user = User::factory()->create([
            'avatar_path' => 'images/no-image.png',
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertFileExists(public_path('images/no-image.png'));
    }

    /**
     * 削除済みUserに対するlogout時のremember_token更新でusersレコードが
     * 再INSERTされる回帰を防ぐため、remember_token設定済みでも退会結果が維持されることを保証する。
     */
    public function test_user_with_remember_token_is_not_reinserted_after_account_deletion(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'remember_token' => 'remember-token-for-deletion-test',
        ]);
        $userId = $user->id;
        $email = $user->email;

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertSame(0, User::query()->whereKey($userId)->count());

        $this
            ->post(route('login'), [
                'email' => $email,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * 退会後も投稿済みのレビューと返信コメントが残り、
     * 投稿者のuser_idだけがnullになることを確認する。
     */
    public function test_reviews_and_comments_remain_and_their_user_ids_become_null_after_account_deletion(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create([
            'body' => '退会後も残るレビューです。',
        ]);
        $comment = ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'parent_id' => null,
            'body' => '退会後も残る返信コメントです。',
        ]);

        $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => null,
            'item_id' => $review->item_id,
            'body' => '退会後も残るレビューです。',
        ]);
        $this->assertDatabaseHas('review_comments', [
            'id' => $comment->id,
            'review_id' => $review->id,
            'user_id' => null,
            'body' => '退会後も残る返信コメントです。',
        ]);
    }

    /**
     * 退会後も残す投稿から削除済みユーザーの情報を表示しないため、
     * レビューと返信が匿名名および共通のNo Image画像で表示されることを保証する。
     */
    public function test_reviews_and_comments_without_user_are_displayed_as_anonymous(): void
    {
        $review = Review::factory()->create([
            'user_id' => null,
            'body' => '匿名ユーザーのレビューです。',
        ]);
        ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => null,
            'parent_id' => null,
            'body' => '匿名ユーザーの返信コメントです。',
        ]);

        $response = $this->get(route('items.show', $review->item_id));

        $response
            ->assertOk()
            ->assertSeeTextInOrder([
                '匿名',
                '匿名ユーザーのレビューです。',
                '匿名',
                '匿名ユーザーの返信コメントです。',
            ]);

        $noImageUrl = asset('images/no-image.png');
        $xpath = $this->createXPath($response->getContent());
        $avatars = $xpath->query(sprintf('//img[@src="%s"]', $noImageUrl));

        $this->assertNotFalse($avatars);
        $this->assertCount(2, $avatars);
    }

    /**
     * パスワードが誤っている場合は退会できず、
     * ログイン状態とユーザー・関連データが変更されないことを確認する。
     */
    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create([
            'body' => '削除されないレビューです。',
        ]);
        $comment = ReviewComment::query()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'parent_id' => null,
            'body' => '削除されない返信コメントです。',
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $user->id,
            'item_id' => $review->item_id,
            'body' => '削除されないレビューです。',
        ]);
        $this->assertDatabaseHas('review_comments', [
            'id' => $comment->id,
            'review_id' => $review->id,
            'user_id' => $user->id,
            'body' => '削除されない返信コメントです。',
        ]);
    }

    private function createXPath(string $html): DOMXPath
    {
        // HTML5解析時の警告をテスト出力へ出さないよう、libxmlのエラー処理を一時的に内部化する。
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html, LIBXML_NONET));

            return new DOMXPath($document);
        } finally {
            // 後続テストへ影響させないよう、解析エラーを消去して元の設定へ戻す。
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function assertAvatarCanBeUploaded(UploadedFile $avatarImage): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $originalName = $avatarImage->getClientOriginalName();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_image' => $avatarImage,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $avatarPath = $user->refresh()->avatar_path;
        $this->assertIsString($avatarPath);
        $this->assertMatchesRegularExpression('#^avatars/[^/]+$#', $avatarPath);
        $this->assertNotSame($originalName, basename($avatarPath));
        Storage::disk('public')->assertExists($avatarPath);

        $this
            ->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($avatarPath), false);
    }

    private function assertAvatarIsRejected(
        UploadedFile $avatarImage,
        ?string $expectedMessage = null,
    ): void {
        Storage::fake('public');

        $oldAvatarPath = 'avatars/existing-avatar.jpg';
        Storage::disk('public')->put($oldAvatarPath, 'existing image');

        $user = User::factory()->create(['avatar_path' => $oldAvatarPath]);

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_image' => $avatarImage,
            ]);

        if ($expectedMessage === null) {
            $response->assertSessionHasErrors('avatar_image');
        } else {
            $response->assertSessionHasErrors(['avatar_image' => $expectedMessage]);
        }
        $this->assertSame($oldAvatarPath, $user->refresh()->avatar_path);
        Storage::disk('public')->assertExists($oldAvatarPath);
        $this->assertSame([$oldAvatarPath], Storage::disk('public')->allFiles('avatars'));
    }

    private function assertProfilePageDisplaysAvatar(User $user, string $avatarUrl): void
    {
        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();

        $xpath = $this->createXPath($response->getContent());
        $avatars = $xpath->query(sprintf(
            '//img[@src="%s" and @alt="現在のユーザーアイコン"]',
            $avatarUrl
        ));

        $this->assertNotFalse($avatars);
        $this->assertCount(1, $avatars);
    }

    /**
     * GDのWebP対応有無にテスト結果を依存させないため、
     * PNG・WebPの実バイナリを使ったアップロードファイルを生成する。
     */
    private function fakeImageWithContent(string $name, string $format): UploadedFile
    {
        $contents = match ($format) {
            'png' => base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true
            ),
            'webp' => base64_decode(
                'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
                true
            ),
            default => false,
        };

        $this->assertIsString($contents);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
