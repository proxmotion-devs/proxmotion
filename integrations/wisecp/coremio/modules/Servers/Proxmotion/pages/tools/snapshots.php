<?php

declare(strict_types=1);

/*
 * The client area's Snapshots tool (Proxmotion::tool_data('snapshots'),
 * docs/adr/0051): the server's snapshots with roll back and delete, and a
 * form to take one. WISECP renders it with the variables of
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

/** @var list<array<string, mixed>> $snapshots */
$snapshots = is_array($data['snapshots'] ?? null) ? $data['snapshots'] : [];
$slots = is_int($data['slots'] ?? null) ? $data['slots'] : 0;
$unavailable = is_string($data['unavailable'] ?? null) ? $data['unavailable'] : null;
$canTake = ($data['can_take'] ?? false) === true;
?>
<div id="pmx-snapshots">
<div class="card bg-gradient border-0 mb-2">
    <div class="card-header">
        <div class="card-title mb-0 fw-semibold"><i class="bi bi-camera me-2"></i><?= $e($title) ?> <span class="badge text-bg-secondary ms-1"><?= $e(count($snapshots)) ?> / <?= $e($slots) ?></span></div>
    </div>
    <div class="card-body py-2">
        <p class="text-muted small mb-0"><?= $t('snapshots_intro') ?></p>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="bi bi-exclamation-circle-fill flex-shrink-0 me-3 fs-4"></i>
        <div><?= $e($error) ?></div>
    </div>
<?php else: ?>
    <?php if ($unavailable !== null): ?>
        <div class="alert alert-secondary small"><?= $e($unavailable) ?></div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <?php foreach ($snapshots as $snapshot): ?>
                        <tr>
                            <td><?= ($snapshot['label'] ?? '') !== '' ? $e($snapshot['label']) : '<span class="text-muted">' . $t('snapshot_no_label') . '</span>' ?></td>
                            <td class="text-muted small"><?= $e($snapshot['time'] ?? '') ?></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="pmxSnapshot(this, 'rollback')" data-snapshot="<?= $e($snapshot['id'] ?? '') ?>" data-confirm="<?= $t('snapshot_rollback_confirm') ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><?= $t('snapshot_rollback') ?></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="pmxSnapshot(this, 'delete')" data-snapshot="<?= $e($snapshot['id'] ?? '') ?>" data-confirm="<?= $t('snapshot_delete_confirm') ?>" title="<?= $t('snapshot_delete') ?>"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($snapshots === []): ?>
                        <tr><td colspan="3" class="text-muted small"><?= $t('snapshots_none') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canTake): ?>
            <div class="card-body">
                <form class="d-flex gap-2" onsubmit="return pmxSnapshotTake(this, event)">
                    <input type="text" class="form-control form-control-sm" name="label" maxlength="64" placeholder="<?= $t('snapshot_label') ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-camera me-1"></i><?= $t('snapshot_take') ?></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<script>
(function () {
    var TOOL = 'snapshots';
    var after = { successToast: true, afterDone: function () { reload_module_content(TOOL); } };

    function send(action, data, button) {
        request_tool_action(TOOL, action, data, Object.assign({ button: button }, after));
    }

    window.pmxSnapshot = function (button, action) {
        if (!window.confirm(button.getAttribute('data-confirm'))) {
            return;
        }

        send(action, { snapshot: button.getAttribute('data-snapshot') }, button);
    };

    window.pmxSnapshotTake = function (form, event) {
        event.preventDefault();
        send('take', { label: form.elements.label.value }, form.querySelector('button[type="submit"]'));

        return false;
    };
})();
</script>
