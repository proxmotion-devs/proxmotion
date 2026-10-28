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

        {if $server.suspension}
            <div class="alert alert-danger">
                {$server.suspension.message|escape}
                {if $server.suspension.reason}<br><small>{$server.suspension.reason|escape}</small>{/if}
            </div>
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
                    {if $server.traffic}
                        <tr>
                            <th>{$pmx.lang.traffic|escape}</th>
                            <td>
                                {$server.traffic.summary|escape}
                                {if $server.traffic.quota}
                                    <div class="progress" style="margin: 5px 0;">
                                        <div class="progress-bar {if $server.traffic.over}progress-bar-danger{elseif $server.traffic.percent >= 80}progress-bar-warning{else}progress-bar-success{/if}" role="progressbar" aria-valuenow="{$server.traffic.percent}" aria-valuemin="0" aria-valuemax="100" style="width: {$server.traffic.percent}%;"></div>
                                    </div>
                                {/if}
                                {if $server.traffic.over}<div class="text-danger">{$pmx.lang.traffic_over|escape}</div>{/if}
                                <small class="text-muted">{$server.traffic.details|escape}</small>
                            </td>
                        </tr>
                    {/if}
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

        {if $pmx.usage}
            {* Usage history (docs/adr/0050); the charts are SVG built by the module from numbers. *}
            {assign var=usage value=$pmx.usage}
            <h4>{$pmx.lang.usage_history|escape}</h4>
            <div class="btn-group btn-group-sm" role="group" style="margin-bottom: 10px;">
                {foreach from=$usage.ranges item=usageRange}
                    <a href="clientarea.php?action=productdetails&amp;id={$serviceid}&amp;pmxrange={$usageRange.value|escape:'url'}" class="btn {if $usageRange.active}btn-primary{else}btn-default{/if}">{$usageRange.label|escape}</a>
                {/foreach}
            </div>
            {if $usage.empty}
                <p class="text-muted">{$pmx.lang.usage_none|escape}</p>
            {else}
                <div class="row">
                    <div class="col-sm-6">
                        <h5>{$pmx.lang.usage_cpu|escape}</h5>
                        {$usage.cpu}
                    </div>
                    <div class="col-sm-6">
                        <h5>
                            {$pmx.lang.usage_network|escape}
                            <small><span style="color: #2a78d6;">&#9632;</span> {$pmx.lang.usage_in|escape} <span style="color: #eb6834;">&#9632;</span> {$pmx.lang.usage_out|escape}</small>
                        </h5>
                        {$usage.network}
                    </div>
                </div>
                <h5>
                    {$usage.trafficTitle|escape}
                    <small><span style="color: #2a78d6;">&#9632;</span> {$pmx.lang.usage_in|escape} <span style="color: #eb6834;">&#9632;</span> {$pmx.lang.usage_out|escape}</small>
                </h5>
                {$usage.traffic}
                <p class="text-muted"><small>{$usage.trafficTotal|escape}</small></p>
            {/if}
            <hr>
        {/if}

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

            {if $pmx.firewall}
                {assign var=firewall value=$pmx.firewall}
                <hr>
                <h4>
                    {$pmx.lang.firewall|escape}
                    {if $firewall.blocks}
                        <span class="label label-success">{$pmx.lang.firewall_blocks|escape}</span>
                    {else}
                        <span class="label label-default">{$pmx.lang.firewall_allows|escape}</span>
                    {/if}
                </h4>
                <p class="text-muted">{$pmx.lang.firewall_intro|escape}</p>
                <form method="post" action="{$pmxAction}Firewall" class="form-inline" style="margin-bottom: 10px;"{if !$firewall.blocks} onsubmit="return confirm('{$pmx.lang.firewall_block_confirm|escape:'javascript'}');"{/if}>
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" name="op" value="policy">
                    <input type="hidden" name="default_policy" value="{if $firewall.blocks}accept{else}drop{/if}">
                    <button type="submit" class="btn btn-{if $firewall.blocks}default{else}primary{/if} btn-sm">{if $firewall.blocks}{$pmx.lang.firewall_allow_rest|escape}{else}{$pmx.lang.firewall_block_rest|escape}{/if}</button>
                </form>

                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>{$pmx.lang.firewall_action|escape}</th>
                            <th>{$pmx.lang.firewall_protocol|escape}</th>
                            <th>{$pmx.lang.firewall_port|escape}</th>
                            <th>{$pmx.lang.firewall_source|escape}</th>
                            <th>{$pmx.lang.firewall_comment|escape}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$firewall.rules item=rule}
                            <tr>
                                <td><span class="label label-{if $rule.allows}success{else}danger{/if}">{$rule.action|escape}</span></td>
                                <td>{$rule.protocol|escape}</td>
                                <td><code>{$rule.port|escape}</code></td>
                                <td><code>{$rule.source|escape}</code></td>
                                <td>{$rule.comment|escape}</td>
                                <td class="text-right">
                                    {if !$rule.first}
                                        <form method="post" action="{$pmxAction}Firewall" style="display: inline;">
                                            <input type="hidden" name="token" value="{$token}">
                                            <input type="hidden" name="op" value="up">
                                            <input type="hidden" name="index" value="{$rule.index|escape}">
                                            <button type="submit" class="btn btn-default btn-xs">{$pmx.lang.firewall_up|escape}</button>
                                        </form>
                                    {/if}
                                    {if !$rule.last}
                                        <form method="post" action="{$pmxAction}Firewall" style="display: inline;">
                                            <input type="hidden" name="token" value="{$token}">
                                            <input type="hidden" name="op" value="down">
                                            <input type="hidden" name="index" value="{$rule.index|escape}">
                                            <button type="submit" class="btn btn-default btn-xs">{$pmx.lang.firewall_down|escape}</button>
                                        </form>
                                    {/if}
                                    <form method="post" action="{$pmxAction}Firewall" style="display: inline;" onsubmit="return confirm('{$pmx.lang.firewall_delete_confirm|escape:'javascript'}');">
                                        <input type="hidden" name="token" value="{$token}">
                                        <input type="hidden" name="op" value="delete">
                                        <input type="hidden" name="index" value="{$rule.index|escape}">
                                        <button type="submit" class="btn btn-danger btn-xs">{$pmx.lang.firewall_delete|escape}</button>
                                    </form>
                                </td>
                            </tr>
                        {foreachelse}
                            <tr><td colspan="6" class="text-muted">{$pmx.lang.firewall_no_rules|escape}</td></tr>
                        {/foreach}
                    </tbody>
                </table>

                <form method="post" action="{$pmxAction}Firewall" class="form-inline" style="margin-bottom: 10px;">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" name="op" value="add">
                    <select name="rule_action" class="form-control input-sm" aria-label="{$pmx.lang.firewall_action|escape}">
                        <option value="accept">{$pmx.lang.firewall_allow|escape}</option>
                        <option value="drop">{$pmx.lang.firewall_block|escape}</option>
                    </select>
                    <select name="protocol" class="form-control input-sm" aria-label="{$pmx.lang.firewall_protocol|escape}">
                        <option value="tcp">TCP</option>
                        <option value="udp">UDP</option>
                        <option value="icmp">ICMP</option>
                        <option value="any">{$pmx.lang.firewall_any|escape}</option>
                    </select>
                    <input type="text" name="port" class="form-control input-sm" placeholder="{$pmx.lang.firewall_port|escape}" title="{$pmx.lang.firewall_port_hint|escape}" size="11" maxlength="11">
                    <input type="text" name="source" class="form-control input-sm" placeholder="{$pmx.lang.firewall_anywhere|escape}" title="{$pmx.lang.firewall_source_hint|escape}" maxlength="64">
                    <input type="text" name="comment" class="form-control input-sm" placeholder="{$pmx.lang.firewall_comment|escape}" maxlength="60">
                    <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.firewall_add|escape}</button>
                </form>

                {if $firewall.rulesets}
                    <form method="post" action="{$pmxAction}Firewall" class="form-inline" style="margin-bottom: 10px;" onsubmit="return confirm('{$pmx.lang.firewall_ruleset_confirm|escape:'javascript'}');">
                        <input type="hidden" name="token" value="{$token}">
                        <input type="hidden" name="op" value="ruleset">
                        <label for="pmx-ruleset">{$pmx.lang.firewall_ruleset|escape}</label>
                        <select id="pmx-ruleset" name="ruleset" class="form-control input-sm">
                            {foreach from=$firewall.rulesets item=ruleset}
                                <option value="{$ruleset.id|escape}" title="{$ruleset.description|escape}">{$ruleset.name|escape}</option>
                            {/foreach}
                        </select>
                        <select name="mode" class="form-control input-sm">
                            <option value="replace">{$pmx.lang.firewall_ruleset_replace|escape}</option>
                            <option value="append">{$pmx.lang.firewall_ruleset_append|escape}</option>
                        </select>
                        <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.firewall_ruleset_apply|escape}</button>
                    </form>
                {/if}

                {if $firewall.appRules}
                    <p class="text-muted">
                        {$pmx.lang.firewall_apps|escape}
                        {foreach from=$firewall.appRules item=appRule}
                            <code>{$appRule.port|escape}/{$appRule.protocol|escape}</code> ({$appRule.app|escape})
                        {/foreach}
                    </p>
                {/if}
            {/if}

            {if $pmx.sshKeys}
                <hr>
                <h4>{$pmx.lang.ssh_keys|escape}</h4>
                <p class="text-muted">{$pmx.lang.ssh_keys_intro|escape}</p>
                <table class="table table-condensed">
                    <tbody>
                        {foreach from=$pmx.sshKeys.keys item=sshKey}
                            <tr>
                                <td>{if $sshKey.comment}{$sshKey.comment|escape}{else}<span class="text-muted">{$pmx.lang.ssh_key_no_comment|escape}</span>{/if}</td>
                                <td><code>{$sshKey.type|escape}</code> <code>{$sshKey.fingerprint|escape}</code></td>
                                <td class="text-right">
                                    <form method="post" action="{$pmxAction}SshKeys" style="display: inline;" onsubmit="return confirm('{$pmx.lang.ssh_key_delete_confirm|escape:'javascript'}');">
                                        <input type="hidden" name="token" value="{$token}">
                                        <input type="hidden" name="op" value="delete">
                                        <input type="hidden" name="fingerprint" value="{$sshKey.fingerprint|escape}">
                                        <button type="submit" class="btn btn-danger btn-xs">{$pmx.lang.ssh_key_delete|escape}</button>
                                    </form>
                                </td>
                            </tr>
                        {foreachelse}
                            <tr><td class="text-muted">{$pmx.lang.ssh_keys_none|escape}</td></tr>
                        {/foreach}
                    </tbody>
                </table>
                {if !$pmx.sshKeys.full}
                    <form method="post" action="{$pmxAction}SshKeys" style="margin-bottom: 10px;">
                        <input type="hidden" name="token" value="{$token}">
                        <input type="hidden" name="op" value="add">
                        <div class="form-group">
                            <label for="pmx-ssh-key">{$pmx.lang.ssh_key_add_label|escape}</label>
                            <textarea id="pmx-ssh-key" name="public_key" class="form-control" rows="3" required placeholder="ssh-ed25519 AAAA... user@host" style="font-family: monospace;"></textarea>
                        </div>
                        <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.ssh_key_add|escape}</button>
                    </form>
                {/if}
            {/if}

            {if $pmx.osTemplates}
                <hr>
                <h4>{$pmx.lang.reinstall_title|escape}</h4>
                {if $pmx.server.reinstalls}
                    <p class="{if $pmx.server.reinstalls.allowed}help-block{else}text-warning{/if}">{$pmx.server.reinstalls.message|escape}</p>
                {/if}
                {if !$pmx.server.reinstalls || $pmx.server.reinstalls.allowed}
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
                                    {if $installation.canCancel}
                                        <form method="post" action="{$pmxAction}CancelApp" class="form-inline" onsubmit="return confirm('{$pmx.lang.app_cancel_confirm|escape:'javascript'|escape}');">
                                            <input type="hidden" name="token" value="{$token}">
                                            <input type="hidden" name="installation" value="{$installation.id|escape}">
                                            <button type="submit" class="btn btn-default btn-sm">{$pmx.lang.app_cancel|escape}</button>
                                        </form>
                                    {/if}
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
                                                    {if $run.canCancel}
                                                        <form method="post" action="{$pmxAction}CancelApp" style="display: inline" onsubmit="return confirm('{$pmx.lang.app_cancel_confirm|escape:'javascript'|escape}');">
                                                            <input type="hidden" name="token" value="{$token}">
                                                            <input type="hidden" name="installation" value="{$installation.id|escape}">
                                                            <input type="hidden" name="run" value="{$run.id|escape}">
                                                            <button type="submit" class="btn btn-link btn-xs">{$pmx.lang.app_cancel|escape}</button>
                                                        </form>
                                                    {/if}
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
