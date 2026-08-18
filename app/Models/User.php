<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'profile',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * 表示用のユーザーアイコンURLを取得する。
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (
                    is_string($this->avatar_path)
                    && str_starts_with($this->avatar_path, 'avatars/')
                    && Storage::disk('public')->exists($this->avatar_path)
                ) {
                    return Storage::disk('public')->url($this->avatar_path);
                }

                return asset('images/no-image.png');
            },
        );
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasMany<ReviewComment, $this>
     */
    public function reviewComments(): HasMany
    {
        return $this->hasMany(ReviewComment::class);
    }
}
