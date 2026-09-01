<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    /**
     * 管理画面ダッシュボードにページ名付きのtitleが表示されることを保証する。
     */
    public function test_admin_dashboard_displays_page_title(): void
    {
        $this
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<title>ダッシュボード | 映画レビューアプリ 管理</title>', false);
    }

    /**
     * admin layoutでtitle slotを指定しない場合に管理画面のアプリ名へフォールバックすることを保証する。
     */
    public function test_admin_layout_uses_application_name_when_title_slot_is_not_specified(): void
    {
        $this
            ->blade('<x-admin-layout>Content</x-admin-layout>')
            ->assertSee('<title>映画レビューアプリ 管理</title>', false);
    }

    /**
     * admin layoutで空のtitle slotを指定した場合に管理画面のアプリ名へフォールバックすることを保証する。
     */
    public function test_admin_layout_uses_application_name_when_title_slot_is_empty(): void
    {
        $this
            ->blade('<x-admin-layout><x-slot name="title"></x-slot> Content</x-admin-layout>')
            ->assertSee('<title>映画レビューアプリ 管理</title>', false);
    }

    /**
     * admin layoutで空白のみのtitle slotを指定した場合に管理画面のアプリ名へフォールバックすることを保証する。
     */
    public function test_admin_layout_uses_application_name_when_title_slot_contains_only_whitespace(): void
    {
        $this
            ->blade('<x-admin-layout><x-slot name="title">   </x-slot> Content</x-admin-layout>')
            ->assertSee('<title>映画レビューアプリ 管理</title>', false);
    }
}
