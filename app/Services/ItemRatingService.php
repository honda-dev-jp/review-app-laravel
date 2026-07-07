<?php

namespace App\Services;

use App\Models\Item;

class ItemRatingService
{
    /**
     * 指定された作品に紐づくレビューを集計し、
     * 平均評価と評価件数のキャッシュを更新する。
     *
     * @param  Item  $item  対象作品
     */
    public function refresh(Item $item): void
    {
        // 対象作品のレビュー評価を集計する
        $reviews = $item->reviews();
        $ratingCount = $reviews->count();
        $rating = $ratingCount > 0
            ? round((float) $reviews->avg('rating'), 1)
            : null;

        // 評価キャッシュを更新する
        $item->update([
            'rating' => $rating,
            'rating_count' => $ratingCount,
        ]);
    }
}
