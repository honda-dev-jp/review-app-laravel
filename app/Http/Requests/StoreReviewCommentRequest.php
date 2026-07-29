<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReviewCommentRequest extends FormRequest
{
    /**
     * バリデーションエラーを格納する名前付きエラーバッグ。
     *
     * @var string
     */
    protected $errorBag = 'reviewComment';

    /**
     * このリクエストを実行できるか判定する。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * レビュー返信投稿に適用するバリデーションルールを取得する。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1000'],
            // バリデーションエラーを表示する返信フォームの識別に使用する。
            'form_review_id' => ['required', 'integer'],
        ];
    }

    /**
     * レビュー返信投稿に適用するバリデーションメッセージを取得する。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => '返信本文を入力してください。',
            'body.string' => '返信本文は文字列を入力してください。',
            'body.max' => '返信本文は1000文字以内で入力してください。',
        ];
    }
}
