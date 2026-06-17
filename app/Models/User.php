<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\FighterCharacter;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'slack_user_id',
        'slack_handle',
        'display_name',
        'avatar_url',
        'hook_token',
        'last_event_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_event_at' => 'datetime',
        ];
    }

    public function displayHandle(): string
    {
        return $this->slack_handle ?: ($this->display_name ?: ($this->name ?: ('#'.$this->id)));
    }

    public function characterForBoss(?int $bossId): string
    {
        return FighterCharacter::forUserAndBoss($this->id, $bossId)->value;
    }
}
