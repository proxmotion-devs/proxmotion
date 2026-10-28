<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email to administrators about an event (docs/adr/0044), sent by
 * AdminNotifier. Texts are English keys with their replacements, translated
 * when the mail is written, in the recipient's language. Mails never carry
 * secrets.
 */
class AdminAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  NotificationEvent|null  $event  null for a test mail
     * @param  array<string, string|int>  $replace  replacements of the subject
     * @param  list<array{0: string, 1?: array<string, string|int>}>  $lines  paragraphs with their replacements
     */
    public function __construct(
        public readonly ?NotificationEvent $event,
        public readonly string $subject,
        public readonly array $replace = [],
        public readonly array $lines = [],
        public readonly ?string $url = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('['.config('app.name').'] '.__($this->subject, self::translated($this->replace)));

        foreach ($this->lines as $line) {
            $mail->line(__($line[0], self::literal(self::translated($line[1] ?? []))));
        }

        if ($this->url !== null) {
            $mail->action(__('Open in the panel'), $this->url);
        }

        if ($this->event !== null) {
            $mail->line(__('You get this email because notifications about ":event" are turned on.', ['event' => __($this->event->label())]));
        }

        return $mail;
    }

    /**
     * Replacements that are English keys (labels like "Traffic quota") are
     * translated too; names and errors without a translation stay as they are.
     *
     * @param  array<string, string|int>  $replace
     * @return array<string, string|int>
     */
    private static function translated(array $replace): array
    {
        return array_map(fn (string|int $value) => is_string($value) ? (string) __($value) : $value, $replace);
    }

    /**
     * Replacements in paragraphs are text, never Markdown: errors come from guests
     * (an app's status.json) and Proxmox, so "[Log in](https://…)" in one must not
     * become a link in the mail. Blade escapes HTML and the mail joins lines, so
     * escaping link brackets (and backslashes, which could undo that) is enough.
     *
     * @param  array<string, string|int>  $replace
     * @return array<string, string|int>
     */
    private static function literal(array $replace): array
    {
        return array_map(fn (string|int $value) => is_string($value) ? addcslashes($value, '\\[]') : $value, $replace);
    }
}
