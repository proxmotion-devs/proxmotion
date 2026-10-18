<?php

declare(strict_types=1);

/*
 * The client area's Apps tool (Proxmotion::tool_data('apps')): installed apps
 * with status, progress, outputs and retry, their actions, upgrade, uninstall and
 * latest runs (ADR 0016), a form per installable app (paid apps without a
 * right offered to buy, ADR 0019) and a reinstall with an app (ADR 0016 §5).
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
/** @var list<array<string, mixed>> $reinstallTemplates */
$reinstallTemplates = is_array($data['reinstall']['templates'] ?? null) ? $data['reinstall']['templates'] : [];
/** @var list<array<string, mixed>> $reinstallApps */
$reinstallApps = is_array($data['reinstall']['apps'] ?? null) ? $data['reinstall']['apps'] : [];
$runBusy = array_filter($installations, static fn (array $installation): bool => ! empty($installation['run_busy'])) !== [];
$inputTypes = ['password' => 'password', 'integer' => 'number', 'port' => 'number', 'email' => 'email', 'url' => 'url'];

/**
 * Outputs of an installation or of a run ($run non-empty): texts shown, files downloaded on demand.
 *
 * @param array<mixed> $outputs
 */
$outputsHtml = static function (array $outputs, string $installation, string $run) use ($e, $t): string {
    $html = '';

    foreach ($outputs as $output) {
        if (! is_array($output)) {
            continue;
        }

        $html .= '<div class="small mt-1"><span class="text-muted">' . $e($output['label'] ?? '') . ':</span> ';

        if (($output['type'] ?? '') === 'file') {
            $html .= '<button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="pmxAppDownload(this)" data-installation="' . $e($installation) . '" data-run="' . $e($run) . '" data-output="' . $e($output['key'] ?? '') . '">'
                . '<i class="bi bi-download me-1"></i>' . $t('app_download') . ' (' . $e($output['filename'] ?? '') . ')</button>';
        } elseif (($output['type'] ?? '') === 'url' && preg_match('#^https?://#i', (string) ($output['value'] ?? '')) === 1) {
            $html .= '<a href="' . $e($output['value']) . '" target="_blank" rel="noopener noreferrer">' . $e($output['value']) . '</a>';
        } else {
            $html .= '<code class="user-select-all">' . $e($output['value'] ?? '') . '</code>';
        }

        $html .= '</div>';
    }

    return $html;
};

/**
 * Fields of declared inputs; the values go out as JSON (secret_inputs).
 *
 * @param array<mixed> $inputs
 */
$inputsHtml = static function (array $inputs, string $prefix) use ($e, $inputTypes): string {
    $html = '';

    foreach ($inputs as $input) {
        if (! is_array($input)) {
            continue;
        }

        $key = (string) ($input['key'] ?? '');
        $type = (string) ($input['type'] ?? 'string');
        $field = $prefix . '-' . $key;
        $default = (string) ($input['default'] ?? '');
        $html .= '<div class="mb-2">';

        if ($type === 'boolean') {
            $html .= '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . $e($field) . '" data-input="' . $e($key) . '" data-boolean="1"' . ($default === '1' ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $e($field) . '">' . $e($input['label'] ?? $key) . '</label></div>';
        } else {
            $html .= '<label class="form-label small fw-semibold" for="' . $e($field) . '">' . $e($input['label'] ?? $key) . '</label>';

            if ($type === 'enum') {
                $html .= '<select class="form-select form-select-sm" id="' . $e($field) . '" data-input="' . $e($key) . '">';

                foreach (is_array($input['options'] ?? null) ? $input['options'] : [] as $option) {
                    $html .= '<option value="' . $e($option) . '"' . ((string) $option === $default ? ' selected' : '') . '>' . $e($option) . '</option>';
                }

                $html .= '</select>';
            } else {
                $html .= '<input class="form-control form-control-sm" id="' . $e($field) . '" data-input="' . $e($key) . '" autocomplete="off" type="' . ($inputTypes[$type] ?? 'text') . '"'
                    . ' value="' . ($type === 'password' ? '' : $e($default)) . '"' . (! empty($input['required']) ? ' required' : '') . '>';
            }
        }

        $html .= '</div>';
    }

    return $html;
};
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
                        ! empty($installation['danger']) => 'bg-danger',
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
                        <?= $outputsHtml($outputs, (string) ($installation['id'] ?? ''), '') ?>
                        <?php
                        /** @var list<array<string, mixed>> $appActions */
                        $appActions = is_array($installation['actions'] ?? null) ? $installation['actions'] : [];
                        /** @var list<array<string, mixed>> $runs */
                        $runs = is_array($installation['runs'] ?? null) ? $installation['runs'] : [];
                        /** @var array<string, mixed>|null $upgrade */
                        $upgrade = is_array($installation['upgrade'] ?? null) ? $installation['upgrade'] : null;
                        ?>
                        <?php if (! empty($installation['run_busy'])): ?>
                            <div class="alert alert-info small mt-2 mb-0"><i class="bi bi-hourglass-split me-1"></i><?= $t('app_run_busy') ?></div>
                        <?php elseif ($appActions !== [] || ! empty($installation['can_uninstall']) || $upgrade !== null): ?>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <?php if ($upgrade !== null): ?>
                                    <form class="border rounded p-2" onsubmit="return pmxAppUpgrade(this, event)" data-installation="<?= $e($installation['id'] ?? '') ?>" data-confirm="<?= $t('app_upgrade_confirm') ?>" novalidate>
                                        <?= $inputsHtml(is_array($upgrade['inputs'] ?? null) ? $upgrade['inputs'] : [], 'pmx-upgrade-' . ($installation['id'] ?? '')) ?>
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-up-circle me-1"></i><?= $e($upgrade['label'] ?? '') ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php foreach ($appActions as $appAction): ?>
                                    <form class="border rounded p-2" onsubmit="return pmxAppAction(this, event)" data-installation="<?= $e($installation['id'] ?? '') ?>" data-action="<?= $e($appAction['key'] ?? '') ?>" novalidate>
                                        <div class="small fw-semibold mb-1"><?= $e($appAction['label'] ?? '') ?></div>
                                        <?= $inputsHtml(is_array($appAction['inputs'] ?? null) ? $appAction['inputs'] : [], 'pmx-action-' . ($installation['id'] ?? '') . '-' . ($appAction['key'] ?? '')) ?>
                                        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-play me-1"></i><?= $t('app_run_submit') ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if (! empty($installation['can_uninstall'])): ?>
                                    <div class="align-self-end">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="pmxAppUninstall(this)" data-installation="<?= $e($installation['id'] ?? '') ?>" data-confirm="<?= $t('app_uninstall_confirm') ?>">
                                            <i class="bi bi-trash me-1"></i><?= $t('app_uninstall') ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($runs !== []): ?>
                            <div class="small mt-2">
                                <div class="text-muted"><?= $t('app_runs') ?></div>
                                <?php foreach ($runs as $run): ?>
                                    <div class="mt-1">
                                        <?= $e($run['label'] ?? '') ?>
                                        <span class="badge <?= ($run['status'] ?? '') === 'succeeded' ? 'bg-success' : (! empty($run['failed']) ? 'bg-danger' : (! empty($run['busy']) ? 'bg-warning text-dark' : 'bg-secondary')) ?> ms-1"><?= $e($run['status_label'] ?? '') ?></span>
                                        <?php if (isset($run['error'])): ?>
                                            <span class="text-danger ms-1"><?= $e($run['error']) ?></span>
                                        <?php endif; ?>
                                        <?= $outputsHtml(is_array($run['outputs'] ?? null) ? $run['outputs'] : [], (string) ($installation['id'] ?? ''), (string) ($run['id'] ?? '')) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
                    <?php if (! empty($app['buy'])): ?>
                        <div class="border rounded p-3 mb-3">
                            <p class="mb-1"><strong><?= $e($app['name'] ?? '') ?></strong> <span class="text-muted small"><?= $e($app['description'] ?? '') ?></span></p>
                            <p class="text-muted small mb-0"><i class="bi bi-bag me-1"></i><?= $t('app_buy_hint') ?></p>
                        </div>
                        <?php continue; ?>
                    <?php endif; ?>
                    <form class="border rounded p-3 mb-3" onsubmit="return pmxAppInstall(this, event)" data-app="<?= $e($appId) ?>" novalidate>
                        <p class="mb-2"><strong><?= $e($app['name'] ?? '') ?></strong> <span class="text-muted small"><?= $e($app['description'] ?? '') ?></span></p>
                        <?php if (! empty($app['licence_required'])): ?>
                            <p class="text-warning small mb-2"><?= $t('app_licence_required') ?></p>
                        <?php endif; ?>
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

    <?php if ($reinstallApps !== [] && $reinstallTemplates !== []): ?>
        <div class="card border-0 shadow-sm mt-2">
            <div class="card-header fw-semibold"><?= $t('app_reinstall_title') ?></div>
            <div class="card-body">
                <p class="text-danger small"><?= $t('app_reinstall_warning') ?></p>
                <form onsubmit="return pmxAppReinstall(this, event)">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="pmx-reinstall-os"><?= $t('app_reinstall_os') ?></label>
                        <select class="form-select form-select-sm" id="pmx-reinstall-os" name="os_template">
                            <?php foreach ($reinstallTemplates as $template): ?>
                                <option value="<?= $e($template['id'] ?? '') ?>"><?= $e($template['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="pmx-reinstall-app"><?= $t('app_reinstall_app') ?></label>
                        <select class="form-select form-select-sm" id="pmx-reinstall-app" name="app">
                            <?php foreach ($reinstallApps as $app): ?>
                                <option value="<?= $e($app['id'] ?? '') ?>"><?= $e($app['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= $t('app_reinstall_hint') ?></div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="pmx-reinstall-confirm" name="confirm" value="1" required>
                        <label class="form-check-label small" for="pmx-reinstall-confirm"><?= $t('app_reinstall_confirm') ?></label>
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-arrow-repeat me-1"></i><?= $t('app_reinstall_submit') ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
(function () {
    var TOOL = 'apps';

    // The form's inputs as JSON, sent in secret_inputs (masked in WISECP's service history).
    function pmxInputs(form) {
        var inputs = {};
        form.querySelectorAll('[data-input]').forEach(function (field) {
            inputs[field.getAttribute('data-input')] = field.getAttribute('data-boolean') ? (field.checked ? '1' : '0') : field.value;
        });

        return JSON.stringify(inputs);
    }

    window.pmxAppAction = function (form, event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return false;
        }

        request_tool_action(TOOL, 'action', {
            installation: form.getAttribute('data-installation'),
            app_action: form.getAttribute('data-action'),
            secret_inputs: pmxInputs(form),
        }, {
            button: form.querySelector('button[type="submit"]'),
            successToast: true,
            afterDone: function () { reload_module_content(TOOL); },
        });

        return false;
    };

    window.pmxAppUpgrade = function (form, event) {
        event.preventDefault();

        if (!form.reportValidity() || !window.confirm(form.getAttribute('data-confirm'))) {
            return false;
        }

        request_tool_action(TOOL, 'upgrade', {
            installation: form.getAttribute('data-installation'),
            secret_inputs: pmxInputs(form),
        }, {
            button: form.querySelector('button[type="submit"]'),
            successToast: true,
            afterDone: function () { reload_module_content(TOOL); },
        });

        return false;
    };

    window.pmxAppUninstall = function (button) {
        if (!window.confirm(button.getAttribute('data-confirm'))) {
            return;
        }

        request_tool_action(TOOL, 'uninstall', { installation: button.getAttribute('data-installation') }, {
            button: button,
            successToast: true,
            afterDone: function () { reload_module_content(TOOL); },
        });
    };

    window.pmxAppInstall = function (form, event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return false;
        }

        request_tool_action(TOOL, 'install', {
            app: form.getAttribute('data-app'),
            secret_inputs: pmxInputs(form),
        }, {
            button: form.querySelector('button[type="submit"]'),
            successToast: true,
            afterDone: function () { reload_module_content(TOOL); },
        });

        return false;
    };

    // Wipes the server: the checkbox confirms it, Proxmotion installs the app once the new system runs.
    window.pmxAppReinstall = function (form, event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return false;
        }

        request_tool_action(TOOL, 'reinstall', {
            os_template: form.elements.os_template.value,
            app: form.elements.app.value,
            confirm: form.elements.confirm.checked ? '1' : '',
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
            run: button.getAttribute('data-run') || '',
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

    <?php if ($busy || $runBusy): ?>
    // Proxmotion reads the progress of installers and actions about once a minute.
    setTimeout(function () {
        if (document.getElementById('pmx-apps') !== null && typeof reload_module_content === 'function') {
            reload_module_content(TOOL);
        }
    }, 30000);
    <?php endif; ?>
})();
</script>
