<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\GuestScriptEvent;
use App\Models\CustomScript;

/**
 * What GuestScripts needs to know about one script besides its content:
 * the fixed script of an event (docs/adr/0010) or a custom script
 * (docs/adr/0043), with the step it records, its file name in the guest,
 * its time limit and the notes it leaves when it fails.
 */
final readonly class ScriptSlot
{
    /**
     * @param  GuestScriptEvent|null  $event  the event it runs at (or whose fixed script it is); null for a manual run or trial of a custom script
     * @param  string  $trigger  what a custom script is told in its trigger input: an event, manual or trial
     */
    private function __construct(
        public string $step,
        public string $file,
        public ?GuestScriptEvent $event,
        public ?CustomScript $custom,
        public string $trigger,
    ) {}

    public static function event(GuestScriptEvent $event): self
    {
        return new self($event->step(), $event->name(), $event, null, $event->value);
    }

    public static function custom(CustomScript $script, ?GuestScriptEvent $event, string $trigger): self
    {
        return new self($script->step(), 'script-'.$script->id, $event, $script, $trigger);
    }

    /** Whether a failure fails the task instead of leaving a note. */
    public function isCritical(): bool
    {
        return $this->custom === null && $this->event?->isCritical() === true;
    }

    public function timeoutSeconds(): int
    {
        return $this->custom === null && $this->event !== null ? $this->event->timeoutSeconds() : GuestScriptEvent::Provisioned->timeoutSeconds();
    }

    /** Whether its output may be kept (never a password script's). */
    public function keepsOutput(): bool
    {
        return $this->custom !== null || $this->event !== GuestScriptEvent::PasswordReset;
    }

    public function unreachableNote(): string
    {
        if ($this->custom !== null) {
            return 'The guest agent did not answer: the script did not run.';
        }

        return match ($this->event) {
            GuestScriptEvent::AddressesChanged => 'The guest agent did not answer: configure the additional addresses inside the server.',
            GuestScriptEvent::DiskResized => 'The guest agent did not answer: grow the file system inside the server (Linux templates with cloud-init grow it at the next boot).',
            GuestScriptEvent::HostnameChanged => 'The guest agent did not answer: change the hostname inside the server.',
            default => 'The guest agent did not answer: the template\'s script did not run.',
        };
    }

    public function failedNote(): string
    {
        if ($this->custom !== null) {
            return 'The script '.$this->custom->name.' failed: ';
        }

        return match ($this->event) {
            GuestScriptEvent::AddressesChanged => 'The additional addresses could not be configured inside the server: ',
            GuestScriptEvent::DiskResized => 'The file system could not be grown inside the server: ',
            GuestScriptEvent::PasswordReset => 'The password reset script failed: ',
            GuestScriptEvent::HostnameChanged => 'The hostname could not be changed inside the server: ',
            default => 'The template\'s script failed: ',
        };
    }
}
