<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 作品一覧で作品の基本情報と評価キャッシュが表示されることを保証する。
     */
    public function test_item_index_displays_item_category_and_rating_summary(): void
    {
        $category = Category::factory()->create([
            'name' => 'サスペンス',
        ]);
        $item = Item::factory()->create([
            'category_id' => $category->id,
            'title' => '一覧表示確認作品',
            'rating' => 4.5,
            'rating_count' => 2,
        ]);

        $this
            ->get(route('items.index'))
            ->assertOk()
            ->assertSeeText($item->title)
            ->assertSeeText($category->name)
            ->assertSeeText('4.5')
            ->assertSeeText('2件');
    }

    /**
     * 作品一覧が10件ずつ表示され、11件目が2ページ目へ分かれることを保証する。
     */
    public function test_item_index_paginates_items_by_ten(): void
    {
        $category = Category::factory()->create();

        for ($number = 1; $number <= 11; $number++) {
            Item::factory()->create([
                'category_id' => $category->id,
                'title' => sprintf('一覧ページネーション作品%02d', $number),
                'created_at' => now()->subMinutes($number),
            ]);
        }

        $this
            ->get(route('items.index'))
            ->assertOk()
            ->assertSeeText('1〜10件目')
            ->assertSeeText('全11件')
            ->assertSee('一覧ページネーション作品01')
            ->assertSee('一覧ページネーション作品10')
            ->assertDontSee('一覧ページネーション作品11')
            ->assertSee('page=2', false);

        $this
            ->get(route('items.index', ['page' => 2]))
            ->assertOk()
            ->assertSeeText('11〜11件目')
            ->assertSeeText('全11件')
            ->assertSee('一覧ページネーション作品11')
            ->assertDontSee('一覧ページネーション作品01');
    }

    /**
     * 作品一覧画面が正常表示され、通常時に空の通知領域が出力されないことを確認する。
     */
    public function test_item_index_page_does_not_output_empty_status_region(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);

        $statusElements = $this->createXPath($response->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(0, $statusElements);
    }

    private function createXPath(string $html): DOMXPath
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html, LIBXML_NONET));

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }
}
