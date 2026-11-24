<?php defined('BRENDY') or die('Brendy CMS.');
$pluginHealth = $plugin->healthStatus();
$healthStatus = isset($pluginHealth['status']) && in_array($pluginHealth['status'], array('ok', 'warning', 'error'), true)
	? $pluginHealth['status'] : 'warning';
$healthIssues = isset($pluginHealth['issues']) && is_array($pluginHealth['issues']) ? $pluginHealth['issues'] : array();
?>

<?php echo BrendyAdminUi::formOpen(array('id' => 'jsform', 'class' => 'plugin-form')); ?>
	<?php echo BrendyAdminUi::formInputHidden(array('name' => 'tokenCSRF', 'value' => $security->getTokenCSRF())); ?>

	<header class="d-flex flex-wrap align-items-start justify-content-between mb-3">
		<div class="d-flex align-items-center">
			<span class="brendy-plugin-icon"><i class="bi bi-<?php echo Theme::escape($plugin->icon()) ?>" aria-hidden="true"></i></span>
			<div><h2 class="mt-0 mb-1"><?php echo Theme::escape($plugin->name()) ?></h2><p class="text-muted mb-0"><?php echo Theme::escape($plugin->description()) ?></p></div>
		</div>
		<?php if ($plugin->formButtons()) : ?>
		<div class="d-flex flex-wrap gap-2 mt-2 mt-md-0">
			<button type="submit" class="btn btn-primary" name="save"><i class="bi bi-check2-circle" aria-hidden="true"></i> <?php $L->p('Save') ?></button>
			<a class="btn btn-outline-secondary" href="<?php echo HTML_PATH_ADMIN_ROOT ?>plugins"><i class="bi bi-x" aria-hidden="true"></i> <?php $L->p('Cancel') ?></a>
		</div>
		<?php endif ?>
	</header>

	<div class="brendy-health-grid mb-4">
		<div class="brendy-health-card brendy-health-card--<?php echo $healthStatus ?>"><span class="brendy-health-card__icon"><i class="bi bi-heart" aria-hidden="true"></i></span><strong><?php $L->p('Health') ?></strong><p class="mb-0 text-muted"><?php echo Theme::escape($L->g($healthStatus)) ?><?php if (!empty($healthIssues)) : ?> · <?php echo Theme::escape(implode(', ', $healthIssues)) ?><?php endif ?></p></div>
		<div class="brendy-health-card brendy-health-card--ok"><span class="brendy-health-card__icon"><i class="bi bi-sliders" aria-hidden="true"></i></span><strong><?php $L->p('Settings fields') ?></strong><p class="mb-0 text-muted"><?php echo (int)$plugin->settingsFieldCount() ?></p></div>
		<div class="brendy-health-card brendy-health-card--ok"><span class="brendy-health-card__icon"><i class="bi bi-hdd-stack" aria-hidden="true"></i></span><strong><?php $L->p('Stored data') ?></strong><p class="mb-0 text-muted"><?php echo Theme::escape(Filesystem::bytesToHumanFileSize($plugin->databaseSize() + $plugin->workspaceSize())) ?></p></div>
		<div class="brendy-health-card brendy-health-card--<?php echo $plugin->isCompatible() ? 'ok' : 'warning' ?>"><span class="brendy-health-card__icon"><i class="bi bi-stars" aria-hidden="true"></i></span><strong><?php $L->p('Compatibility') ?></strong><p class="mb-0 text-muted"><?php echo Theme::escape(BRENDY_NAME) ?> <?php echo Theme::escape($plugin->brendyRelease() ?: BRENDY_VERSION) ?> · <?php echo Theme::escape($L->g('Version')) ?> <?php echo Theme::escape($plugin->version()) ?></p></div>
	</div>

	<?php if (!$plugin->isCompatible()) : ?>
		<div class="alert alert-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?php echo Theme::escape($L->g('This plugin may not be supported by this version of Brendy')) ?></div>
	<?php endif ?>

	<section class="card p-3 p-md-4 mb-4" aria-label="<?php echo Theme::escape($L->g('Plugin settings')) ?>">
		<?php echo $plugin->form(); ?>
	</section>

	<?php if ($plugin->formButtons()) : ?>
	<footer class="d-flex justify-content-end gap-2 mb-4">
		<a class="btn btn-outline-secondary" href="<?php echo HTML_PATH_ADMIN_ROOT ?>plugins"><i class="bi bi-x" aria-hidden="true"></i> <?php $L->p('Cancel') ?></a>
		<button type="submit" class="btn btn-primary" name="save"><i class="bi bi-check2-circle" aria-hidden="true"></i> <?php $L->p('Save changes') ?></button>
	</footer>
	<?php endif ?>
<?php echo BrendyAdminUi::formClose(); ?>
