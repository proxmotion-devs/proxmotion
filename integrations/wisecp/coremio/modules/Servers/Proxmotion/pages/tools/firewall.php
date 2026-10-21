<?php

declare(strict_types=1);

/*
 * The client area's Firewall tool (Proxmotion::tool_data('firewall'),
 * docs/adr/0035): what happens to traffic no rule matches, the inbound rules in order with up, down and
 * delete, a form to add a rule, a rule set to apply and the app ports.
 * WISECP renders it with the variables of ServerModule::render_tool_page();
 * actions go through request_tool_action() of WISECP's service page.
 *
 * @var array<string, mixed> $data
 * @var string|null $error
 * @var string $tool_label
 * @var array<string, mixed> $lang
 */

defined('CORE_FOLDER') or exit('You can not get in here!');

$data = isset($data) && is_array($data) ? $data : [];
$error = isset($error) && is_string($error) ? $error : null;
$texts = isset($lang) && is_array($lang) ? $lang : [];
$title = isset($tool_label) && is_string($tool_label) ? $tool_label : '';

$e = static fn (mixed $value): string => htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES);
$t = static fn (string $key): string => htmlspecialchars(is_string($texts[$key] ?? null) ? $texts[$key] : $key, ENT_QUOTES);

$blocks = ($data['default_policy'] ?? '') === 'drop';
/** @var list<array<string, mixed>> $rules */
$rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
/** @var list<array<string, mixed>> $appRules */
$appRules = is_array($data['app_rules'] ?? null) ? $data['app_rules'] : [];
/** @var list<array<string, mixed>> $rulesets */
$rulesets = is_array($data['rulesets'] ?? null) ? $data['rulesets'] : [];
$protocols = ['tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'any' => $t('firewall_any')];
?>
<div id="pmx-firewall">
<div class="card bg-gradient border-0 mb-2">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="card-title mb-0 fw-semibold">
            <i class="bi bi-shield-check me-2"></i><?= $e($title) ?>
            <?php if ($error === null): ?>
                <span class="badge <?= $blocks ? 'bg-success' : 'bg-secondary' ?> ms-2"><?= $blocks ? $t('firewall_blocks') : $t('firewall_allows') ?></span>
            <?php endif; ?>
        </div>
        <?php if ($error === null): ?>
            <button type="button" class="btn btn-sm <?= $blocks ? 'btn-outline-secondary' : 'btn-primary' ?>" onclick="pmxFirewallPolicy(this)" data-policy="<?= $blocks ? 'accept' : 'drop' ?>"<?= $blocks ? '' : ' data-confirm="' . $t('firewall_block_confirm') . '"' ?>>
                <?= $blocks ? $t('firewall_allow_rest') : $t('firewall_block_rest') ?>
            </button>
        <?php endif; ?>
    </div>
    <div class="card-body py-2">
        <p class="text-muted small mb-0"><?= $t('firewall_intro') ?></p>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="bi bi-exclamation-circle-fill flex-shrink-0 me-3 fs-4"></i>
        <div><?= $e($error) ?></div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th><?= $t('firewall_action') ?></th>
                        <th><?= $t('firewall_protocol') ?></th>
                        <th><?= $t('firewall_port') ?></th>
                        <th><?= $t('firewall_source') ?></th>
                        <th><?= $t('firewall_comment') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $index => $rule): ?>
                        <?php $allows = ($rule['action'] ?? '') !== 'drop'; ?>
                        <tr>
                            <td><span class="badge <?= $allows ? 'bg-success' : 'bg-danger' ?>"><?= $allows ? $t('firewall_allow') : $t('firewall_block') ?></span></td>
                            <td><?= $e($protocols[(string) ($rule['protocol'] ?? '')] ?? '') ?></td>
                            <td><code><?= isset($rule['port']) ? $e($rule['port']) : $t('firewall_all_ports') ?></code></td>
                            <td><code><?= isset($rule['source']) ? $e($rule['source']) : $t('firewall_anywhere') ?></code></td>
                            <td><?= $e($rule['comment'] ?? '') ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($index > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pmxFirewallRule(this, 'up')" data-index="<?= $index ?>" title="<?= $t('firewall_up') ?>"><i class="bi bi-arrow-up"></i></button>
                                <?php endif; ?>
                                <?php if ($index < count($rules) - 1): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pmxFirewallRule(this, 'down')" data-index="<?= $index ?>" title="<?= $t('firewall_down') ?>"><i class="bi bi-arrow-down"></i></button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="pmxFirewallRule(this, 'delete')" data-index="<?= $index ?>" data-confirm="<?= $t('firewall_delete_confirm') ?>" title="<?= $t('firewall_delete') ?>"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rules === []): ?>
                        <tr><td colspan="6" class="text-muted small"><?= $t('firewall_no_rules') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body">
            <form class="row g-2 align-items-end" onsubmit="return pmxFirewallAdd(this, event)">
                <div class="col-6 col-md-2">
                    <select class="form-select form-select-sm" name="rule_action" aria-label="<?= $t('firewall_action') ?>">
                        <option value="accept"><?= $t('firewall_allow') ?></option>
                        <option value="drop"><?= $t('firewall_block') ?></option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select form-select-sm" name="protocol" aria-label="<?= $t('firewall_protocol') ?>">
                        <?php foreach ($protocols as $value => $label): ?>
                            <option value="<?= $e($value) ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <input class="form-control form-control-sm" name="port" maxlength="11" placeholder="<?= $t('firewall_port') ?>" title="<?= $t('firewall_port_hint') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <input class="form-control form-control-sm" name="source" maxlength="64" placeholder="<?= $t('firewall_anywhere') ?>" title="<?= $t('firewall_source_hint') ?>">
                </div>
                <div class="col-8 col-md-2">
                    <input class="form-control form-control-sm" name="comment" maxlength="60" placeholder="<?= $t('firewall_comment') ?>">
                </div>
                <div class="col-4 col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus me-1"></i><?= $t('firewall_add') ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($rulesets !== []): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header fw-semibold"><?= $t('firewall_ruleset') ?></div>
            <div class="card-body">
                <form class="row g-2 align-items-end" onsubmit="return pmxFirewallRuleset(this, event)" data-confirm="<?= $t('firewall_ruleset_confirm') ?>">
                    <div class="col-12 col-md-5">
                        <select class="form-select form-select-sm" name="ruleset" aria-label="<?= $t('firewall_ruleset') ?>">
                            <?php foreach ($rulesets as $ruleset): ?>
                                <option value="<?= $e($ruleset['id'] ?? '') ?>" title="<?= $e($ruleset['description'] ?? '') ?>"><?= $e($ruleset['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-8 col-md-4">
                        <select class="form-select form-select-sm" name="mode">
                            <option value="replace"><?= $t('firewall_ruleset_replace') ?></option>
                            <option value="append"><?= $t('firewall_ruleset_append') ?></option>
                        </select>
                    </div>
                    <div class="col-4 col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100"><?= $t('firewall_ruleset_apply') ?></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($appRules !== []): ?>
        <p class="text-muted small">
            <?= $t('firewall_apps') ?>
            <?php foreach ($appRules as $appRule): ?>
                <code><?= $e($appRule['port'] ?? '') ?>/<?= $e(strtoupper((string) ($appRule['protocol'] ?? ''))) ?></code> (<?= $e($appRule['app'] ?? '') ?>)
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
(function () {
    var TOOL = 'firewall';
    var after = { successToast: true, afterDone: function () { reload_module_content(TOOL); } };

    function send(action, data, button) {
        request_tool_action(TOOL, action, data, Object.assign({ button: button }, after));
    }

    window.pmxFirewallPolicy = function (button) {
        var question = button.getAttribute('data-confirm');

        if (question && !window.confirm(question)) {
            return;
        }

        send('policy', { default_policy: button.getAttribute('data-policy') }, button);
    };

    window.pmxFirewallRule = function (button, action) {
        var question = button.getAttribute('data-confirm');

        if (question && !window.confirm(question)) {
            return;
        }

        send(action, { index: button.getAttribute('data-index') }, button);
    };

    window.pmxFirewallAdd = function (form, event) {
        event.preventDefault();
        send('add', {
            rule_action: form.elements.rule_action.value,
            protocol: form.elements.protocol.value,
            port: form.elements.port.value,
            source: form.elements.source.value,
            comment: form.elements.comment.value,
        }, form.querySelector('button[type="submit"]'));

        return false;
    };

    window.pmxFirewallRuleset = function (form, event) {
        event.preventDefault();

        if (!window.confirm(form.getAttribute('data-confirm'))) {
            return false;
        }

        send('ruleset', { ruleset: form.elements.ruleset.value, mode: form.elements.mode.value }, form.querySelector('button[type="submit"]'));

        return false;
    };
})();
</script>
