<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReviewRequest;
use App\Models\Item;
use App\Models\Review;
use App\Services\ItemRatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * 作品にレビュー本文と評価を投稿する。
     *
     * @param  StoreReviewRequest  $request  バリデーション済みリクエスト
     * @param  Item  $item  レビュー投稿対象の作品
     * @param  ItemRatingService  $itemRatingService  評価キャッシュ更新サービス
     */
    public function store(
        StoreReviewRequest $request,
        Item $item,
        ItemRatingService $itemRatingService
    ): RedirectResponse {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        if ($item->reviews()->where('user_id', $user->id)->exists()) {
            return back()
                ->withErrors(['body' => 'この作品にはすでにレビューを投稿しています。'])
                ->withInput();
        }

        $validated = $request->validated();

        DB::transaction(function () use ($item, $user, $validated, $itemRatingService): void {
            $item->reviews()->create([
                'user_id' => $user->id,
                'rating' => $validated['rating'],
                'body' => $validated['body'],
            ]);

            $itemRatingService->refresh($item);
        });

        return redirect()
            ->route('items.show', $item)
            ->with('status', 'レビューを投稿しました。');
    }

    /**
     * 自分が投稿したレビューを削除する。
     *
     * @param  Review  $review  削除対象レビュー
     * @param  ItemRatingService  $itemRatingService  評価キャッシュ更新サービス
     */
    public function destroy(Review $review, ItemRatingService $itemRatingService): RedirectResponse
    {
        $this->authorize('delete', $review);

        $item = $review->item()->firstOrFail();

        DB::transaction(function () use ($review, $item, $itemRatingService): void {
            $review->delete();

            $itemRatingService->refresh($item);
        });

        return redirect()
            ->route('items.show', $item)
            ->with('status', 'レビューを削除しました。');
    }
}
