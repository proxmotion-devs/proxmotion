<?php

namespace App\Models;

use App\Enums\NotificationEvent;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * A staff account of the panel. There are no customer accounts: customers
 * live in the billing system (docs/adr/0007), so every user is an administrator.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $locale
 * @property list<string>|null $notification_events NotificationEvent values to get email about; null: NotificationEvent::defaults() (docs/adr/0044)
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicUuid, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'two_factor_confirmed_at' => 'datetime',
            'notification_events' => 'array',
        ];
    }

    /**
     * Mail and other notifications are written in the administrator's language.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * The events this administrator gets email about (docs/adr/0044).
     *
     * @return list<NotificationEvent>
     */
    public function notificationEvents(): array
    {
        if ($this->notification_events === null) {
            return NotificationEvent::defaults();
        }

        return array_values(array_filter(array_map(NotificationEvent::tryFrom(...), $this->notification_events)));
    }

    public function wantsNotification(NotificationEvent $event): bool
    {
        return in_array($event, $this->notificationEvents(), true);
    }
}
