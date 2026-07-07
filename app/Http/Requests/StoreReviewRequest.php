<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    /**
     * このリクエストを実行できるか判定する。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * レビュー投稿に適用するバリデーションルールを取得する。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * レビュー投稿に適用するバリデーションメッセージを取得する。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => '評価を選択してください。',
            'rating.integer' => '評価は整数で選択してください。',
            'rating.min' => '評価は1以上で選択してください。',
            'rating.max' => '評価は5以下で選択してください。',
            'body.required' => 'レビュー本文を入力してください。',
            'body.string' => 'レビュー本文は文字列で入力してください。',
            'body.max' => 'レビュー本文は1000文字以内で入力してください。',
        ];
    }
}
