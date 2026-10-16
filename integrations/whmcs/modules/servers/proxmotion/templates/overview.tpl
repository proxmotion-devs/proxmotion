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

        <div class="row">
            <div class="col-sm-6">
                <table class="table table-condensed">
                    <tr>
                        <th>{$pmx.lang.hostname|escape}</th>
                        <td><code>{$server.hostname|escape}</code></td>
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
                    <div class="checkbox">
                        <label><input type="checkbox" name="confirm" value="1" required> {$pmx.lang.reinstall_confirm|escape}</label>
                    </div>
                    <button type="submit" class="btn btn-danger">{$pmx.lang.reinstall_submit|escape}</button>
                </form>
            {/if}
        {/if}
    {/if}
</div>
