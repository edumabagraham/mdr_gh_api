<?php

namespace App\Models;

use App\Notifications\VerifyEmailWithCode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
