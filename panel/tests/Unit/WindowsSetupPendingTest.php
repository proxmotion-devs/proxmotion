<?php

namespace Tests\Unit;

use App\Domain\Servers\GuestScripts;
use PHPUnit\Framework\TestCase;

/**
 * What a new Windows server still waits for, read from the output of
 * GuestScripts::WINDOWS_SETUP_COMMAND (the wait itself: WindowsSetupWaitTest).
 */
class WindowsSetupPendingTest extends TestCase
{
    /**
     * @param  array<string, string>  $values
     */
    private function pending(array $values): ?string
    {
        $values += ['image' => 'IMAGE_STATE_COMPLETE', 'name' => 'WEB1', 'active_name' => 'WEB1'];

        return GuestScripts::windowsSetupPending(implode("\r\n", array_map(fn (string $key, string $value) => "{$key}={$value}", array_keys($values), $values))."\r\n");
    }

    public function test_every_plugin_of_the_configuration_must_be_recorded_for_this_instance()
    {
        $plugins = ['service' => 'Stopped', 'plugins' => 'SetUserPasswordPlugin,NetworkConfigPlugin'];

        $this->assertNull($this->pending([...$plugins, 'done' => 'NetworkConfigPlugin,SetUserPasswordPlugin', 'later' => '']));
        $this->assertNull($this->pending([...$plugins, 'done' => 'setuserpasswordplugin,NETWORKCONFIGPLUGIN', 'later' => '']), 'Registry names ignore case.');
        // A plugin that failed records nothing.
        $this->assertSame('Cloudbase-Init has not finished NetworkConfigPlugin', $this->pending([...$plugins, 'done' => 'SetUserPasswordPlugin', 'later' => '']));
        // No config drive or no key for its instance ID: nothing ran for this server yet.
        $this->assertSame('Cloudbase-Init has not finished SetUserPasswordPlugin, NetworkConfigPlugin', $this->pending($plugins));
    }

    public function test_a_plugin_whose_failure_the_log_shows_since_the_last_boot_counts_as_run()
    {
        // UserDataPlugin on user data that is not valid YAML: logged, never recorded, tried again at the next boot.
        $plugins = ['service' => 'Stopped', 'plugins' => 'SetUserPasswordPlugin,UserDataPlugin', 'done' => 'SetUserPasswordPlugin', 'later' => ''];

        $this->assertNull($this->pending([...$plugins, 'failed' => 'UserDataPlugin']));
        $this->assertSame('Cloudbase-Init has not finished UserDataPlugin', $this->pending([...$plugins, 'failed' => '']));
        $this->assertSame('the cloudbase-init service is Running', $this->pending([...$plugins, 'service' => 'Running', 'failed' => 'UserDataPlugin']));
        // Without a plugins line a failure proves the service has run.
        $this->assertNull($this->pending(['service' => 'Stopped', 'failed' => 'UserDataPlugin']));

        $this->assertSame(['UserDataPlugin', 'MTUPlugin'], GuestScripts::windowsSetupFailures("service=Stopped\r\nfailed=UserDataPlugin,MTUPlugin\r\n"));
        $this->assertSame([], GuestScripts::windowsSetupFailures("service=Stopped\r\nfailed=\r\n"));
    }

    public function test_plugins_that_run_at_every_boot_count_as_run()
    {
        // The guide's configuration: ExtendVolumesPlugin and LocalScriptsPlugin record 2 (run again at the next boot), never 1.
        $this->assertNull($this->pending([
            'service' => 'Stopped',
            'plugins' => 'SetUserPasswordPlugin,NetworkConfigPlugin,ExtendVolumesPlugin,SetUserSSHPublicKeysPlugin,LocalScriptsPlugin,UserDataPlugin',
            'done' => 'SetUserPasswordPlugin,NetworkConfigPlugin,SetUserSSHPublicKeysPlugin,UserDataPlugin',
            'later' => 'ExtendVolumesPlugin,LocalScriptsPlugin',
        ]));
    }

    public function test_plugins_of_the_specialize_pass_do_not_count_for_the_service()
    {
        // Way A: the unattend run recorded its own plugins under the same instance ID before the service ran.
        $this->assertSame('Cloudbase-Init has not finished SetUserPasswordPlugin', $this->pending([
            'service' => 'Stopped',
            'plugins' => 'SetUserPasswordPlugin',
            'done' => 'SetHostNamePlugin,ExtendVolumesPlugin,UserDataPlugin',
        ]));
    }

    public function test_without_a_plugins_line_any_plugin_the_service_recorded_is_enough()
    {
        $this->assertNull($this->pending(['service' => 'Stopped', 'done' => 'SetUserPasswordPlugin', 'later' => '']));
        $this->assertNull($this->pending(['service' => 'Stopped', 'done' => 'SetUserPasswordPlugin', 'later' => 'ExtendVolumesPlugin,MTUPlugin']));
        $this->assertSame('Cloudbase-Init has not run for this server yet', $this->pending(['service' => 'Stopped']));
    }

    public function test_without_a_plugins_line_the_plugins_of_the_specialize_pass_do_not_count()
    {
        // Way A: before the service starts it looks stopped, and only the unattend run has recorded its plugins.
        $specialize = ['service' => 'Stopped', 'unattend' => 'SetHostNamePlugin,ExtendVolumesPlugin,UserDataPlugin'];

        $this->assertSame('Cloudbase-Init has not run for this server yet', $this->pending([...$specialize, 'done' => 'SetHostNamePlugin,UserDataPlugin', 'later' => 'ExtendVolumesPlugin']));
        $this->assertNull($this->pending([...$specialize, 'done' => 'SetHostNamePlugin,UserDataPlugin,SetUserPasswordPlugin', 'later' => 'ExtendVolumesPlugin']));
        $this->assertSame('Cloudbase-Init has not run for this server yet', $this->pending([...$specialize, 'done' => 'sethostnameplugin', 'later' => '']), 'Registry names ignore case.');
    }

    public function test_a_restart_cloudbase_init_asked_for_since_the_last_boot_is_waited_for()
    {
        // A local script exiting 1003 records LocalScriptsPlugin as 2 and has Cloudbase-Init restart Windows.
        $finished = ['service' => 'Stopped', 'plugins' => 'SetUserPasswordPlugin,LocalScriptsPlugin', 'done' => 'SetUserPasswordPlugin', 'later' => 'LocalScriptsPlugin'];

        $this->assertSame('Cloudbase-Init has asked for a restart', $this->pending([...$finished, 'restart' => '1']));
        $this->assertNull($this->pending([...$finished, 'restart' => '0']));
        $this->assertNull($this->pending($finished), 'An older script prints no restart line.');
    }

    public function test_a_running_service_a_pending_computer_name_and_an_unfinished_setup_are_waited_for()
    {
        $finished = ['service' => 'Stopped', 'plugins' => 'UserDataPlugin', 'done' => 'UserDataPlugin'];

        $this->assertSame('the cloudbase-init service is Running', $this->pending([...$finished, 'service' => 'Running']));
        $this->assertSame('the computer name WEB1 takes effect at the next restart', $this->pending([...$finished, 'active_name' => 'WIN-KC2E3BTDPBV']));
        $this->assertNull($this->pending([...$finished, 'active_name' => 'web1']));
        $this->assertSame('Windows setup reports IMAGE_STATE_UNDEPLOYABLE', $this->pending([...$finished, 'image' => 'IMAGE_STATE_UNDEPLOYABLE']));
    }

    public function test_a_template_that_was_never_generalized_and_has_no_cloudbase_init_is_ready()
    {
        $this->assertNull($this->pending(['image' => '']));
        $this->assertNull(GuestScripts::windowsSetupPending(''));
    }
}
