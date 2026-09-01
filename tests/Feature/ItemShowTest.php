<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemShowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 作品詳細で作品名と評価キャッシュが表示されることを保証する。
     */
    public function test_item_show_displays_title_and_rating_summary(): void
    {
        $item = Item::factory()->create([
            'title' => '詳細表示確認作品',
            'rating' => 4.5,
            'rating_count' => 2,
        ]);

        $this
            ->get(route('items.show', $item))
            ->assertOk()
            ->assertSee('<title>詳細表示確認作品 | 映画レビューアプリ</title>', false)
            ->assertSeeText($item->title)
            ->assertSeeText('平均評価')
            ->assertSeeText('4.5')
            ->assertSeeText('評価件数')
            ->assertSeeText('2件');
    }

    /**
     * 作品詳細のブラウザタイトルでHTML特殊文字がエスケープされることを保証する。
     */
    public function test_item_show_escapes_html_special_characters_in_browser_title(): void
    {
        $itemTitle = '記号 & <作品> "二重引用" \'単一引用\'';
        $item = Item::factory()->create([
            'title' => $itemTitle,
        ]);

        $this
            ->get(route('items.show', $item))
            ->assertOk()
            ->assertSee(
                '<title>記号 &amp; &lt;作品&gt; &quot;二重引用&quot; &#039;単一引用&#039; | 映画レビューアプリ</title>',
                false
            )
            ->assertDontSee('<title>'.$itemTitle.' | 映画レビューアプリ</title>', false);
    }

    /**
     * 存在しない作品の詳細URLが404になることを保証する。
     */
    public function test_item_show_returns_not_found_for_missing_item(): void
    {
        $this
            ->get(route('items.show', 999999))
            ->assertNotFound();
    }
}
