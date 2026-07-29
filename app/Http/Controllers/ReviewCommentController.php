<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReviewCommentRequest;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;

class ReviewCommentController extends Controller
{
    /**
     * レビューに返信コメントを投稿する。
     *
     * @param  StoreReviewCommentRequest  $request  バリデーション済みリクエスト
     * @param  Review  $review  返信対象レビュー
     */
    public function store(
        StoreReviewCommentRequest $request,
        Review $review
    ): RedirectResponse {
        // 投稿者はフォームから受け取らず、ログイン中のユーザーから取得する。
        $user = $request->user();

        // ルートはauthミドルウェアで保護するが、型安全のため未認証時も明示的に拒否する。
        if ($user === null) {
            abort(403);
        }

        // Form Requestのルールを通過した入力値だけを取得する。
        $validated = $request->validated();

        // review_idはリクエスト値を使わず、対象レビューのリレーション経由で設定する。
        $review->comments()->create([
            'user_id' => $user->id,
            // 初期移植フェーズでは多階層コメントを扱わないため常にnullとする。
            'parent_id' => null,
            'body' => $validated['body'],
        ]);

        return redirect()
            ->route('items.show', $review->item_id)
            ->with('status', '返信コメントを投稿しました。');
    }
}
