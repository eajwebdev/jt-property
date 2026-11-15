<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
        'role',
        'appearance',
        'password',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /** Properties this (staff) user is assigned to. */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class)->withTimestamps();
    }

    /** Owners and accountants see every property; staff only their assigned ones. */
    public function canAccessProperty(int|Property $property): bool
    {
        if ($this->role !== Role::Staff) {
            return true;
        }

        return $this->properties()->whereKey($property instanceof Property ? $property->id : $property)->exists();
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    /**
     * True when at least one active owner other than $user exists.
     */
    public static function hasOtherActiveOwner(User $user): bool
    {
        return static::where('role', Role::Owner)->whereNull('deactivated_at')->whereKeyNot($user->id)->exists();
    }
}
