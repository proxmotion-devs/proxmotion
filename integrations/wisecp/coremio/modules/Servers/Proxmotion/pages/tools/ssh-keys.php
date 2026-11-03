<?php

declare(strict_types=1);

/*
 * The client area's SSH keys tool (Proxmotion::tool_data('ssh-keys'),
 * docs/adr/0049): the keys Proxmotion manages on the server with delete, and
 * a form to add one. WISECP renders it with the variables of
 * ServerModule::render_tool_page(); actions go through request_tool_action()
 * of WISECP's service page.
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

/** @var list<array<string, mixed>> $keys */
$keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
$full = ($data['full'] ?? false) === true;
?>
<div id="pmx-ssh-keys">
<div class="card bg-gradient border-0 mb-2">
    <div class="card-header">
        <div class="card-title mb-0 fw-semibold"><i class="bi bi-key me-2"></i><?= $e($title) ?></div>
    </div>
    <div class="card-body py-2">
        <p class="text-muted small mb-0"><?= $t('ssh_keys_intro') ?></p>
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
                <tbody>
                    <?php foreach ($keys as $key): ?>
                        <tr>
                            <td><?= ($key['comment'] ?? '') !== '' ? $e($key['comment']) : '<span class="text-muted">' . $t('ssh_key_no_comment') . '</span>' ?></td>
                            <td><code><?= $e($key['type'] ?? '') ?></code> <code class="text-break"><?= $e($key['fingerprint'] ?? '') ?></code></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="pmxSshKeyDelete(this)" data-fingerprint="<?= $e($key['fingerprint'] ?? '') ?>" data-confirm="<?= $t('ssh_key_delete_confirm') ?>" title="<?= $t('ssh_key_delete') ?>"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($keys === []): ?>
                        <tr><td colspan="3" class="text-muted small"><?= $t('ssh_keys_none') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (! $full): ?>
            <div class="card-body">
                <form onsubmit="return pmxSshKeyAdd(this, event)">
                    <label class="form-label small" for="pmx-ssh-key"><?= $t('ssh_key_add_label') ?></label>
                    <textarea id="pmx-ssh-key" class="form-control form-control-sm font-monospace mb-2" name="public_key" rows="3" required placeholder="ssh-ed25519 AAAA... user@host"></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i><?= $t('ssh_key_add') ?></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<script>
(function () {
    var TOOL = 'ssh-keys';
    var after = { successToast: true, afterDone: function () { reload_module_content(TOOL); } };

    function send(action, data, button) {
        request_tool_action(TOOL, action, data, Object.assign({ button: button }, after));
    }

    window.pmxSshKeyDelete = function (button) {
        if (!window.confirm(button.getAttribute('data-confirm'))) {
            return;
        }

        send('delete', { fingerprint: button.getAttribute('data-fingerprint') }, button);
    };

    window.pmxSshKeyAdd = function (form, event) {
        event.preventDefault();
        send('add', { public_key: form.elements.public_key.value }, form.querySelector('button[type="submit"]'));

        return false;
    };
})();
</script>
