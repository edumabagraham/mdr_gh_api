<?php

namespace App\Models;

use App\Access\AccountStatus;
use App\Access\Permission;
use App\Access\Role;
use App\Notifications\VerifyEmailWithCode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Titles offered at account setup. Kept short and deliberately incomplete:
     * the list covers who actually works in this service, and anyone it does
     * not fit can leave it blank rather than be mislabelled.
     *
     * @var list<string>
     */
    public const TITLES = ['Dr', 'Prof', 'Mr', 'Mrs', 'Ms', 'Miss', 'Mx'];

    /**
     * The columns carry the same defaults, but a freshly created model would
     * otherwise report null for both until it was read back from the database —
     * and a null status reads as "not active", which locks the user out of the
     * application they just created.
     *
     * Both are deliberately absent from #[Fillable]: neither may ever be set
     * from a registration or profile payload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => Role::CLINICIAN,
        'status' => AccountStatus::ACTIVE,
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
            'mdc_expires_on' => 'date',
            'credential_sighted_on' => 'date',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
            'invited_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /** Name with the title in front of it, where there is one. */
    public function displayName(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->name);
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::ACTIVE;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::ADMIN;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Everything this user's role allows. Handy for handing the client a
     * capability list rather than making it re-implement the matrix.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->isActive() ? Permission::for($this->role) : [];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    /**
     * The verification code currently outstanding for this user, if any.
     */
    public function emailVerificationCode(): HasOne
    {
        return $this->hasOne(EmailVerificationCode::class);
    }

    /**
     * Send the verification email.
     *
     * Laravel's default sends a signed link. This application verifies with a
     * short code typed into the SPA instead, so the notification is swapped
     * here — every caller of the framework's verification flow gets the code.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailWithCode(EmailVerificationCode::issueFor($this)));
    }
}
