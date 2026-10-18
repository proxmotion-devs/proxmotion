{* Proxmotion: server overview in the WHMCS client area. Texts: lang/<language>.php ({$pmx.lang}). *}
{assign var="pmxAction" value="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a="}

<div class="proxmotion">
    {if $pmx.error}
        <div class="alert alert-warning">{$pmx.error|escape}</div>
    {else}
        {assign var="server" value=$pmx.server}

        {if $server.busy}
            <div class="alert alert-info">{$pmx.lang.busy|escape}</div>
        {/if}

        {if $server.rebootRequired}
            <div class="alert alert-warning">{$pmx.lang.reboot_required|escape}</div>
        {/if}

        <div class="row">
            <div class="col-sm-6">
                <table class="table table-condensed">
                    <tr>
                        <th>{$pmx.lang.hostname|escape}</th>
                        <td>
                            {if $server.active}
                                <form method="post" action="{$pmxAction}ChangeHostname" class="form-inline">
                                    <input type="hidden" name="token" value="{$token}">
                                    <input type="text" name="hostname" class="form-control input-sm" value="{$server.hostname|escape}" required>
                                    <label class="checkbox-inline" title="{$pmx.lang.hostname_reboot_hint|escape}"><input type="checkbox" name="reboot" value="1"> {$pmx.lang.hostname_reboot|escape}</label>
                                    <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.change_hostname|escape}</button>
                                </form>
                            {else}
                                <code>{$server.hostname|escape}</code>
                            {/if}
                        </td>
                    </tr>
                    <tr>
                        <th>{$pmx.lang.status|escape}</th>
                        <td>{$server.statusLabel|escape}</td>
                    </tr>
                    {if $server.active}
                        <tr>
                            <th>{$pmx.lang.power|escape}</th>
                            <td>
                                <span class="label {if $server.power == 'running'}label-success{else}label-default{/if}">{$server.powerLabel|escape}</span>
                            </td>
                        </tr>
                    {/if}
                    {if $server.os}
                        <tr>
                            <th>{$pmx.lang.os|escape}</th>
                            <td>{$server.os|escape}</td>
                        </tr>
                    {/if}
                    {if $server.location}
                        <tr>
                            <th>{$pmx.lang.location|escape}</th>
                            <td>{$server.location|escape}</td>
                        </tr>
                    {/if}
                    <tr>
                        <th>{$pmx.lang.resources|escape}</th>
                        <td>{$server.cpu|escape} · {$server.memory|escape} · {$server.disk|escape}</td>
                    </tr>
                </table>
            </div>

            <div class="col-sm-6">
                <h4>{$pmx.lang.addresses|escape}</h4>
                <table class="table table-condensed">
                    {foreach from=$server.addresses item=address}
                        <tr>
                            <td><code>{$address.address|escape}</code></td>
                            <td>
                                {if $server.active}
                                    <form method="post" action="{$pmxAction}SetRdns" class="form-inline">
                                        <input type="hidden" name="token" value="{$token}">
                                        <input type="hidden" name="address" value="{$address.address|escape}">
                                        <input type="text" name="hostname" class="form-control input-sm" value="{$address.ptr|escape}" placeholder="{$pmx.lang.reverse_dns|escape}" title="{$pmx.lang.reverse_dns_hint|escape}">
                                        <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.set_reverse_dns|escape}</button>
                                    </form>
                                {elseif $address.ptr}
                                    {$address.ptr|escape}
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </table>
            </div>
        </div>

        {if $server.active}
            <div class="btn-toolbar" role="toolbar">
                <div class="btn-group" role="group">
                    {if $server.power != 'running'}
                        <a href="{$pmxAction}Start" class="btn btn-success">{$pmx.lang.start|escape}</a>
                    {else}
                        <a href="{$pmxAction}Reboot" class="btn btn-default">{$pmx.lang.reboot|escape}</a>
                        <a href="{$pmxAction}Shutdown" class="btn btn-default">{$pmx.lang.shutdown|escape}</a>
                        <a href="{$pmxAction}Stop" class="btn btn-danger" onclick="return confirm('{$pmx.lang.stop_confirm|escape:'javascript'}');">{$pmx.lang.stop|escape}</a>
                    {/if}
                </div>
                <div class="btn-group" role="group">
                    <a href="{$pmxAction}Console" class="btn btn-primary" target="_blank" rel="noopener">{$pmx.lang.console|escape}</a>
                    <a href="{$pmxAction}ResetPassword" class="btn btn-default" onclick="return confirm('{$pmx.lang.reset_password_confirm|escape:'javascript'}');">{$pmx.lang.reset_password|escape}</a>
                </div>
            </div>

            {if $pmx.osTemplates}
                <hr>
                <h4>{$pmx.lang.reinstall_title|escape}</h4>
                <p class="text-danger">{$pmx.lang.reinstall_warning|escape}</p>
                <form method="post" action="{$pmxAction}Reinstall">
                    <input type="hidden" name="token" value="{$token}">
                    <div class="form-group">
                        <label for="pmx-os">{$pmx.lang.reinstall_os|escape}</label>
                        <select id="pmx-os" name="os_template" class="form-control">
                            {foreach from=$pmx.osTemplates item=template}
                                <option value="{$template.id|escape}">{$template.name|escape}</option>
                            {/foreach}
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pmx-ssh-key">{$pmx.lang.reinstall_ssh_key|escape}</label>
                        <textarea id="pmx-ssh-key" name="ssh_key" rows="2" class="form-control" placeholder="ssh-ed25519 AAAA…"></textarea>
                    </div>
                    {if $pmx.reinstallApps}
                        <div class="form-group">
                            <label for="pmx-reinstall-app">{$pmx.lang.reinstall_app|escape}</label>
                            <select id="pmx-reinstall-app" name="app" class="form-control">
                                <option value="">{$pmx.lang.reinstall_no_app|escape}</option>
                                {foreach from=$pmx.reinstallApps item=app}
                                    <option value="{$app.id|escape}">{$app.name|escape}</option>
                                {/foreach}
                            </select>
                            <p class="help-block">{$pmx.lang.reinstall_app_hint|escape}</p>
                        </div>
                    {/if}
                    <div class="checkbox">
                        <label><input type="checkbox" name="confirm" value="1" required> {$pmx.lang.reinstall_confirm|escape}</label>
                    </div>
                    <button type="submit" class="btn btn-danger">{$pmx.lang.reinstall_submit|escape}</button>
                </form>
            {/if}

            {if $pmx.installations || $pmx.apps}
                <hr>
                <h4>{$pmx.lang.apps|escape}</h4>
            {/if}

            {if $pmx.installations}
                <table class="table table-condensed">
                    <tbody>
                        {foreach from=$pmx.installations item=installation}
                            <tr>
                                <td><strong>{$installation.name|escape}</strong></td>
                                <td>
                                    <span class="label {if $installation.status == 'installed'}label-success{elseif $installation.danger}label-danger{elseif $installation.busy}label-warning{else}label-default{/if}">{$installation.statusLabel|escape}</span>
                                    {if $installation.busy} {$installation.progress|escape}%{/if}
                                    {if $installation.error}<div class="text-danger small">{$installation.error|escape}</div>{/if}
                                </td>
                                <td>
                                    {foreach from=$installation.outputs item=output}
                                        <div>
                                            <span class="text-muted">{$output.label|escape}:</span>
                                            {if $output.file}
                                                <a href="{$pmxAction}DownloadAppFile&amp;installation={$installation.id|escape:'url'}&amp;output={$output.key|escape:'url'}">{$pmx.lang.app_download|escape} ({$output.filename|escape})</a>
                                            {elseif $output.url}
                                                <a href="{$output.value|escape}" target="_blank" rel="noopener noreferrer">{$output.value|escape}</a>
                                            {else}
                                                <code>{$output.value|escape}</code>
                                            {/if}
                                        </div>
                                    {/foreach}
                                    {if $installation.failed}
                                        <form method="post" action="{$pmxAction}RetryApp" class="form-inline">
                                            <input type="hidden" name="token" value="{$token}">
                                            <input type="hidden" name="installation" value="{$installation.id|escape}">
                                            <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.app_retry|escape}</button>
                                        </form>
                                    {/if}
                                    {if !$installation.runBusy}
                                        {foreach from=$installation.actions item=appAction}
                                            <form method="post" action="{$pmxAction}RunAppAction" class="well well-sm" style="margin-top: 8px">
                                                <input type="hidden" name="token" value="{$token}">
                                                <input type="hidden" name="installation" value="{$installation.id|escape}">
                                                <input type="hidden" name="app_action" value="{$appAction.key|escape}">
                                                <strong>{$appAction.label|escape}</strong>
                                                {foreach from=$appAction.inputs item=input}
                                                    <div class="form-group">
                                                        {if $input.type == 'boolean'}
                                                            <input type="hidden" name="inputs[{$input.key|escape}]" value="0">
                                                            <label><input type="checkbox" name="inputs[{$input.key|escape}]" value="1"{if $input.default == '1'} checked{/if}> {$input.label|escape}</label>
                                                        {else}
                                                            <label for="pmx-action-{$installation.id|escape}-{$appAction.key|escape}-{$input.key|escape}">{$input.label|escape}</label>
                                                            {if $input.type == 'enum'}
                                                                <select id="pmx-action-{$installation.id|escape}-{$appAction.key|escape}-{$input.key|escape}" name="inputs[{$input.key|escape}]" class="form-control">
                                                                    {foreach from=$input.options item=option}
                                                                        <option value="{$option|escape}"{if $option == $input.default} selected{/if}>{$option|escape}</option>
                                                                    {/foreach}
                                                                </select>
                                                            {else}
                                                                <input id="pmx-action-{$installation.id|escape}-{$appAction.key|escape}-{$input.key|escape}" name="inputs[{$input.key|escape}]" class="form-control" value="{$input.default|escape}" autocomplete="off"
                                                                    type="{if $input.type == 'password'}password{elseif $input.type == 'integer' || $input.type == 'port'}number{elseif $input.type == 'email'}email{elseif $input.type == 'url'}url{else}text{/if}"
                                                                    {if $input.min !== null} min="{$input.min|escape}"{/if}{if $input.max !== null} max="{$input.max|escape}"{/if}{if $input.required} required{/if}>
                                                            {/if}
                                                        {/if}
                                                    </div>
                                                {/foreach}
                                                <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.app_run_submit|escape}</button>
                                            </form>
                                        {/foreach}
                                        {if $installation.upgrade}
                                            <form method="post" action="{$pmxAction}UpgradeApp" class="well well-sm" style="margin-top: 8px" onsubmit="return confirm('{$pmx.lang.app_upgrade_confirm|escape:'javascript'|escape}');">
                                                <input type="hidden" name="token" value="{$token}">
                                                <input type="hidden" name="installation" value="{$installation.id|escape}">
                                                {foreach from=$installation.upgrade.inputs item=input}
                                                    <div class="form-group">
                                                        {if $input.type == 'boolean'}
                                                            <input type="hidden" name="inputs[{$input.key|escape}]" value="0">
                                                            <label><input type="checkbox" name="inputs[{$input.key|escape}]" value="1"{if $input.default == '1'} checked{/if}> {$input.label|escape}</label>
                                                        {else}
                                                            <label for="pmx-upgrade-{$installation.id|escape}-{$input.key|escape}">{$input.label|escape}</label>
                                                            {if $input.type == 'enum'}
                                                                <select id="pmx-upgrade-{$installation.id|escape}-{$input.key|escape}" name="inputs[{$input.key|escape}]" class="form-control">
                                                                    {foreach from=$input.options item=option}
                                                                        <option value="{$option|escape}"{if $option == $input.default} selected{/if}>{$option|escape}</option>
                                                                    {/foreach}
                                                                </select>
                                                            {else}
                                                                <input id="pmx-upgrade-{$installation.id|escape}-{$input.key|escape}" name="inputs[{$input.key|escape}]" class="form-control" value="{$input.default|escape}" autocomplete="off"
                                                                    type="{if $input.type == 'password'}password{elseif $input.type == 'integer' || $input.type == 'port'}number{elseif $input.type == 'email'}email{elseif $input.type == 'url'}url{else}text{/if}"
                                                                    {if $input.min !== null} min="{$input.min|escape}"{/if}{if $input.max !== null} max="{$input.max|escape}"{/if}{if $input.required} required{/if}>
                                                            {/if}
                                                        {/if}
                                                    </div>
                                                {/foreach}
                                                <button type="submit" class="btn btn-primary btn-sm">{$installation.upgrade.label|escape}</button>
                                            </form>
                                        {/if}
                                        {if $installation.canUninstall}
                                            <form method="post" action="{$pmxAction}UninstallApp" class="form-inline" onsubmit="return confirm('{$pmx.lang.app_uninstall_confirm|escape:'javascript'|escape}');">
                                                <input type="hidden" name="token" value="{$token}">
                                                <input type="hidden" name="installation" value="{$installation.id|escape}">
                                                <button type="submit" class="btn btn-danger btn-sm">{$pmx.lang.app_uninstall|escape}</button>
                                            </form>
                                        {/if}
                                    {else}
                                        <p class="text-muted small">{$pmx.lang.app_run_busy|escape}</p>
                                    {/if}
                                    {if $installation.runs}
                                        <div class="small" style="margin-top: 8px">
                                            <span class="text-muted">{$pmx.lang.app_runs|escape}</span>
                                            {foreach from=$installation.runs item=run}
                                                <div>
                                                    {$run.label|escape}:
                                                    <span class="label {if $run.status == 'succeeded'}label-success{elseif $run.failed}label-danger{elseif $run.busy}label-warning{else}label-default{/if}">{$run.statusLabel|escape}</span>
                                                    {if $run.error}<span class="text-danger">{$run.error|escape}</span>{/if}
                                                    {foreach from=$run.outputs item=output}
                                                        <span class="text-muted">{$output.label|escape}:</span>
                                                        {if $output.file}
                                                            <a href="{$pmxAction}DownloadAppFile&amp;installation={$installation.id|escape:'url'}&amp;run={$run.id|escape:'url'}&amp;output={$output.key|escape:'url'}">{$pmx.lang.app_download|escape} ({$output.filename|escape})</a>
                                                        {elseif $output.url}
                                                            <a href="{$output.value|escape}" target="_blank" rel="noopener noreferrer">{$output.value|escape}</a>
                                                        {else}
                                                            <code>{$output.value|escape}</code>
                                                        {/if}
                                                    {/foreach}
                                                </div>
                                            {/foreach}
                                        </div>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {/if}

            {if $pmx.appsBusy}
                <p class="text-muted">{$pmx.lang.app_busy|escape}</p>
            {elseif $pmx.apps}
                <h5>{$pmx.lang.app_install|escape}</h5>
                <p class="text-muted">{$pmx.lang.app_install_hint|escape}</p>
                {foreach from=$pmx.apps item=app}
                    {if $app.buy}
                        <div class="well well-sm">
                            <p><strong>{$app.name|escape}</strong> <span class="text-muted">{$app.description|escape}</span></p>
                            <p class="text-muted">{$pmx.lang.app_buy_hint|escape}</p>
                            <a href="{$pmx.addonsUrl|escape}" class="btn btn-default btn-sm">{$pmx.lang.app_buy|escape}</a>
                        </div>
                    {else}
                    <form method="post" action="{$pmxAction}InstallApp" class="well well-sm">
                        {if $app.licence_required}
                            <p class="text-warning">{$pmx.lang.app_licence_required|escape}</p>
                        {/if}
                        <input type="hidden" name="token" value="{$token}">
                        <input type="hidden" name="app" value="{$app.id|escape}">
                        <p><strong>{$app.name|escape}</strong> <span class="text-muted">{$app.description|escape}</span></p>
                        {foreach from=$app.inputs item=input}
                            <div class="form-group">
                                {if $input.type == 'boolean'}
                                    <input type="hidden" name="inputs[{$input.key|escape}]" value="0">
                                    <label><input type="checkbox" name="inputs[{$input.key|escape}]" value="1"{if $input.default == '1'} checked{/if}> {$input.label|escape}</label>
                                {else}
                                    <label for="pmx-app-{$app.id|escape}-{$input.key|escape}">{$input.label|escape}</label>
                                    {if $input.type == 'enum'}
                                        <select id="pmx-app-{$app.id|escape}-{$input.key|escape}" name="inputs[{$input.key|escape}]" class="form-control">
                                            {foreach from=$input.options item=option}
                                                <option value="{$option|escape}"{if $option == $input.default} selected{/if}>{$option|escape}</option>
                                            {/foreach}
                                        </select>
                                    {else}
                                        <input id="pmx-app-{$app.id|escape}-{$input.key|escape}" name="inputs[{$input.key|escape}]" class="form-control" value="{$input.default|escape}" autocomplete="off"
                                            type="{if $input.type == 'password'}password{elseif $input.type == 'integer' || $input.type == 'port'}number{elseif $input.type == 'email'}email{elseif $input.type == 'url'}url{else}text{/if}"
                                            {if $input.min !== null} min="{$input.min|escape}"{/if}{if $input.max !== null} max="{$input.max|escape}"{/if}{if $input.required} required{/if}>
                                    {/if}
                                {/if}
                            </div>
                        {/foreach}
                        <button type="submit" class="btn btn-primary btn-sm">{$pmx.lang.app_install_submit|escape}</button>
                    </form>
                    {/if}
                {/foreach}
            {/if}
        {/if}
    {/if}
</div>
