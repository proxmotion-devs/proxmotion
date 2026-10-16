<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskType: string
{
    case Create = 'create';
    case Suspend = 'suspend';
    case Unsuspend = 'unsuspend';
    case Terminate = 'terminate';
    case Adopt = 'adopt';
    case Start = 'start';
    case Shutdown = 'shutdown';
    case Stop = 'stop';
    case Reboot = 'reboot';
    case ResetPassword = 'reset_password';
    case Reinstall = 'reinstall';
    case ChangeResources = 'change_resources';
    case GuestScript = 'guest_script';
}
