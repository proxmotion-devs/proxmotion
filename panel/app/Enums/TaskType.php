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
    case ChangeSshKeys = 'change_ssh_keys';
    case InstallApp = 'install_app';
    case AppRun = 'app_run';
    case Verify = 'verify';
    case EnableAntiSpoofing = 'enable_anti_spoofing';
    case ApplyFirewall = 'apply_firewall';
    case Move = 'move';
    case CreateSnapshot = 'create_snapshot';
    case RollbackSnapshot = 'rollback_snapshot';
    case DeleteSnapshot = 'delete_snapshot';
    case CreateBackup = 'create_backup';
    case RestoreBackup = 'restore_backup';
    case DeleteBackup = 'delete_backup';

    /**
     * Whether the task installs the server's OS template (it records the
     * template, whose earlier runs TaskEstimates times).
     */
    public function installsSystem(): bool
    {
        return $this === self::Create || $this === self::Reinstall;
    }

    /**
     * Whether a failure leaves the VM half-built or half-deleted, so the
     * server goes into error (TaskFailer, webhook server.failed).
     */
    public function failureBreaksServer(): bool
    {
        return $this->installsSystem() || $this === self::Terminate;
    }
}
