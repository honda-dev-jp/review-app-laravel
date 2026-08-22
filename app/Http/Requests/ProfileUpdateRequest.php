<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array{
     *     name: list<string>,
     *     email: list<string|Unique>,
     *     profile: list<string>,
     *     avatar_image: list<string>
     * }
     */
    public function rules(): array
    {
        $user = $this->user();

        assert($user instanceof User);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'profile' => ['nullable', 'string', 'max:1000'],
            'avatar_image' => ['bail', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
