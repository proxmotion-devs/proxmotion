<?php

declare(strict_types=1);

/*
 * The client area's Apps tool (Proxmotion::tool_data('apps')): installed apps
 * with status, progress, outputs and retry, and a form per installable app.
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

/** @var list<array<string, mixed>> $installations */
$installations = is_array($data['installations'] ?? null) ? $data['installations'] : [];
/** @var list<array<string, mixed>> $apps */
$apps = is_array($data['apps'] ?? null) ? $data['apps'] : [];
$busy = ! empty($data['installing']);
$inputTypes = ['password' => 'password', 'integer' => 'number', 'port' => 'number', 'email' => 'email'];
?>
<div id="pmx-apps">
<div class="card bg-gradient border-0 mb-2">
    <div class="card-header">
        <div class="card-title mb-0 fw-semibold"><i class="bi bi-grid me-2"></i><?= $e($title) ?></div>
    </div>
    <div class="card-body py-2">
        <p class="text-muted small mb-0"><?= $t('apps_desc') ?></p>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="bi bi-exclamation-circle-fill flex-shrink-0 me-3 fs-4"></i>
        <div><?= $e($error) ?></div>
    </div>
<?php else: ?>

    <?php if ($installations !== []): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header fw-semibold"><?= $t('apps_installed') ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($installations as $installation): ?>
                    <?php
                    $status = (string) ($installation['status'] ?? '');
                    $badge = match (true) {
                        $status === 'installed' => 'bg-success',
                        ! empty($installation['failed']) => 'bg-danger',
                        ! empty($installation['busy']) => 'bg-warning text-dark',
                        default => 'bg-secondary',
                    };
                    /** @var list<array<string, mixed>> $outputs */
                    $outputs = is_array($installation['outputs'] ?? null) ? $installation['outputs'] : [];
                    ?>
                    <li class="list-group-item">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <strong><?= $e($installation['name'] ?? '') ?></strong>
                                <span class="badge <?= $badge ?> ms-2"><?= $e($installation['status_label'] ?? $status) ?></span>
                                <?php if (! empty($installation['busy'])): ?>
                                    <span class="text-muted small ms-2"><?= $e($installation['progress'] ?? 0) ?>%<?= isset($installation['step']) ? ' · ' . $e($installation['step']) : '' ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($installation['failed'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pmxAppRetry(this)" data-installation="<?= $e($installation['id'] ?? '') ?>">
                                    <i class="bi bi-arrow-repeat me-1"></i><?= $t('app_retry') ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if (! empty($installation['busy'])): ?>
                            <div class="progress mt-2" style="height: 6px">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: <?= (int) ($installation['progress'] ?? 0) ?>%"></div>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($installation['error'])): ?>
                            <div class="text-danger small mt-1"><?= $e($installation['error']) ?></div>
                        <?php endif; ?>
                        <?php foreach ($outputs as $output): ?>
                            <div class="small mt-1">
                                <span class="text-muted"><?= $e($output['label'] ?? '') ?>:</span>
                                <?php if (($output['type'] ?? '') === 'file'): ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="pmxAppDownload(this)" data-installation="<?= $e($installation['id'] ?? '') ?>" data-output="<?= $e($output['key'] ?? '') ?>">
                                        <i class="bi bi-download me-1"></i><?= $t('app_download') ?> (<?= $e($output['filename'] ?? '') ?>)
                                    </button>
                                <?php elseif (($output['type'] ?? '') === 'url' && preg_match('#^https?://#i', (string) ($output['value'] ?? '')) === 1): ?>
                                    <a href="<?= $e($output['value']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($output['value']) ?></a>
                                <?php else: ?>
                                    <code class="user-select-all"><?= $e($output['value'] ?? '') ?></code>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($busy): ?>
        <div class="alert alert-info small"><i class="bi bi-hourglass-split me-1"></i><?= $t('app_busy') ?></div>
    <?php elseif ($apps !== []): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold"><?= $t('app_install') ?></div>
            <div class="card-body">
                <p class="text-muted small"><?= $t('app_install_hint') ?></p>
                <?php foreach ($apps as $app): ?>
                    <?php
                    $appId = (string) ($app['id'] ?? '');
                    /** @var list<array<string, mixed>> $inputs */
                    $inputs = is_array($app['inputs'] ?? null) ? $app['inputs'] : [];
                    ?>
                    <form class="border rounded p-3 mb-3" onsubmit="return pmxAppInstall(this, event)" data-app="<?= $e($appId) ?>" novalidate>
                        <p class="mb-2"><strong><?= $e($app['name'] ?? '') ?></strong> <span class="text-muted small"><?= $e($app['description'] ?? '') ?></span></p>
                        <?php foreach ($inputs as $input): ?>
                            <?php
                            $key = (string) ($input['key'] ?? '');
                            $type = (string) ($input['type'] ?? 'string');
                            $field = 'pmx-app-' . $appId . '-' . $key;
                            $default = (string) ($input['default'] ?? '');
                            ?>
                            <div class="mb-2">
                                <?php if ($type === 'boolean'): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="<?= $e($field) ?>" data-input="<?= $e($key) ?>" data-boolean="1"<?= $default === '1' ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="<?= $e($field) ?>"><?= $e($input['label'] ?? $key) ?></label>
                                    </div>
                                <?php else: ?>
                                    <label class="form-label small fw-semibold" for="<?= $e($field) ?>"><?= $e($input['label'] ?? $key) ?></label>
                                    <?php if ($type === 'enum'): ?>
                                        <select class="form-select form-select-sm" id="<?= $e($field) ?>" data-input="<?= $e($key) ?>">
                                            <?php foreach (is_array($input['options'] ?? null) ? $input['options'] : [] as $option): ?>
                                                <option value="<?= $e($option) ?>"<?= (string) $option === $default ? ' selected' : '' ?>><?= $e($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input class="form-control form-control-sm" id="<?= $e($field) ?>" data-input="<?= $e($key) ?>" autocomplete="off"
                                            type="<?= $inputTypes[$type] ?? 'text' ?>" value="<?= $type === 'password' ? '' : $e($default) ?>"
                                            <?= isset($input['min']) && $type !== 'password' ? ' min="' . $e($input['min']) . '"' : '' ?><?= isset($input['max']) && $type !== 'password' ? ' max="' . $e($input['max']) . '"' : '' ?><?= ! empty($input['required']) ? ' required' : '' ?>>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-download me-1"></i><?= $t('app_install_submit') ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif ($installations === []): ?>
        <div class="alert alert-secondary small"><?= $t('apps_none') ?></div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
(function () {
    var TOOL = 'apps';

    window.pmxAppInstall = function (form, event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return false;
        }

        var inputs = {};
        form.querySelectorAll('[data-input]').forEach(function (field) {
            inputs[field.getAttribute('data-input')] = field.getAttribute('data-boolean') ? (field.checked ? '1' : '0') : field.value;
        });

        request_tool_action(TOOL, 'install', {
            app: form.getAttribute('data-app'),
            secret_inputs: JSON.stringify(inputs),
        }, {
            button: form.querySelector('button[type="submit"]'),
            successToast: true,
            afterDone: function () { reload_module_content(TOOL); },
        });

        return false;
    };

    window.pmxAppRetry = function (button) {
        request_tool_action(TOOL, 'retry', { installation: button.getAttribute('data-installation') }, {
            button: button,
            successToast: true,
            afterDone: function () { reload_module_content(TOOL); },
        });
    };

    // The file comes from Proxmotion now, as base64, and is saved by the browser.
    window.pmxAppDownload = function (button) {
        request_tool_action(TOOL, 'download', {
            installation: button.getAttribute('data-installation'),
            output: button.getAttribute('data-output'),
        }, {
            button: button,
            done: function (response) {
                if (!response || response.status !== 'successful' || !response.file) {
                    alert_error((response && response.message) || 'Error');
                    return;
                }

                var binary = atob(response.file.content_base64);
                var bytes = new Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }

                var link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([bytes], { type: 'application/octet-stream' }));
                link.download = response.file.name || 'download';
                document.body.appendChild(link);
                link.click();
                setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
            },
        });
    };

    <?php if ($busy): ?>
    // Proxmotion reads the installer's progress about once a minute.
    setTimeout(function () {
        if (document.getElementById('pmx-apps') !== null && typeof reload_module_content === 'function') {
            reload_module_content(TOOL);
        }
    }, 30000);
    <?php endif; ?>
})();
</script>
