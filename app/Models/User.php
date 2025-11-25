<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\ApiKey;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Ticketing\Models\Ticket;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The guard name for Spatie Permission
     */
    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'is_super_admin',
        'bot_state',
        'telegram_chat_id',
        'balance',
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
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Super admins and admins can access the admin panel
        // Resellers and users can access their respective panels if implemented
        if ($panel->getId() === 'admin') {
            return $this->hasAnyRole(['super-admin', 'admin']);
        }
        
        return false;
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['super-admin', 'admin']) || (bool) $this->is_admin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'referrer_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function reseller()
    {
        return $this->hasOne(Reseller::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isReseller(): bool
    {
        return $this->reseller()->exists();
    }
}
