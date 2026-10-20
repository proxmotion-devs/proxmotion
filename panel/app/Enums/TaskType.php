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
    case ChangePrimaryAddress = 'change_primary_address';
    case ChangeAddresses = 'change_addresses';
    case ChangeHostname = 'change_hostname';
    case InstallApp = 'install_app';
    case AppRun = 'app_run';
    case Verify = 'verify';
    case EnableAntiSpoofing = 'enable_anti_spoofing';
}
