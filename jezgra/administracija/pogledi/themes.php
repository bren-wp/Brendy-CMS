<?php defined('BRENDY') or die('Brendy CMS.');

echo BrendyAdminUi::pageTitle(array('title' => $L->g('Themes'), 'icon' => 'desktop'));
?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
	<p class="text-muted mb-0"><i class="bi bi-palette2" aria-hidden="true"></i> <?php echo count($themes) ?> <?php $L->p('Bundled themes') ?></p>
</div>

<section class="brendy-upload-panel" aria-labelledby="brendy-theme-upload-title">
	<div><i class="bi bi-file-earmark-zip" aria-hidden="true"></i><div><strong id="brendy-theme-upload-title"><?php $L->p('Install theme ZIP') ?></strong><small><?php $L->p('Upload a Brendy-compatible theme archive. Existing theme folders are never overwritten.') ?></small></div></div>
	<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>upload-theme" enctype="multipart/form-data">
		<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
		<label class="visually-hidden" for="brendy-theme-zip"><?php echo Theme::escape($L->g('Install theme ZIP')) ?></label>
		<input id="brendy-theme-zip" type="file" class="form-control" name="extensionZip" accept=".zip,application/zip" required>
		<button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><?php $L->p('Upload and install') ?></button>
	</form>
</section>

<div class="brendy-filter-bar brendy-filter-bar--themes" role="search" aria-label="<?php echo Theme::escape($L->g('Filter themes')) ?>">
	<div class="brendy-filter-control"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" data-brendy-filter-query placeholder="<?php echo Theme::escape($L->g('Search themes')) ?>" aria-label="<?php echo Theme::escape($L->g('Search themes')) ?>"></div>
	<select class="form-select" data-brendy-filter-status aria-label="<?php echo Theme::escape($L->g('Filter by status')) ?>"><option value=""><?php $L->p('All statuses') ?></option><option value="active"><?php $L->p('Active') ?></option><option value="inactive"><?php $L->p('Inactive') ?></option></select>
</div>

<div class="brendy-component-grid">
	<?php foreach ($themes as $index => $theme) :
		$dirname = isset($theme['dirname']) && is_string($theme['dirname']) ? $theme['dirname'] : '';
		$isActive = hash_equals((string)$site->theme(), $dirname);
		$state = $isActive ? 'active' : 'inactive';
		$health = isset($theme['health']) && is_array($theme['health']) ? $theme['health'] : array('status' => 'warning', 'issues' => array());
		$healthStatus = isset($health['status']) && in_array($health['status'], array('ok', 'warning', 'error'), true) ? $health['status'] : 'warning';
		$healthIssues = isset($health['issues']) && is_array($health['issues']) ? implode(', ', $health['issues']) : '';
		$website = isset($theme['website']) && brendyIsSafeHttpUrl($theme['website'], 2048) ? $theme['website'] : '';
		$icon = isset($theme['icon']) ? $theme['icon'] : 'palette2';
	?>
	<article class="brendy-component-card<?php echo $isActive ? ' brendy-component-card--active' : '' ?>" data-brendy-filter-item data-search="<?php echo Theme::escape(($theme['name'] ?? '') . ' ' . ($theme['description'] ?? '') . ' ' . ($theme['author'] ?? '') . ' ' . $dirname) ?>" data-status="<?php echo $state ?>">
		<div class="brendy-theme-preview brendy-theme-preview--<?php echo ($index % 5) + 1 ?>" aria-hidden="true"><i class="bi bi-<?php echo Theme::escape($icon) ?>"></i></div>
		<header class="brendy-component-card__header">
			<span class="brendy-component-card__icon"><i class="bi bi-<?php echo Theme::escape($icon) ?>" aria-hidden="true"></i></span>
			<div class="brendy-component-card__heading"><h3><?php echo Theme::escape($theme['name'] ?? $dirname) ?></h3><p><?php echo Theme::escape($dirname) ?> · <?php echo Theme::escape($L->g('Version')) ?> <?php echo Theme::escape($theme['version'] ?? '') ?></p></div>
			<span class="brendy-status brendy-status--<?php echo $state ?>"><i class="bi <?php echo $isActive ? 'bi-check-circle' : 'bi-circle' ?>" aria-hidden="true"></i><?php echo Theme::escape($L->g($state)) ?></span>
		</header>
		<div class="brendy-component-card__body">
			<p><?php echo Theme::escape($theme['description'] ?? '') ?></p>
			<div class="brendy-component-card__meta">
				<span title="<?php echo Theme::escape($healthIssues) ?>"><i class="bi bi-heart" aria-hidden="true"></i><?php $L->p('Health') ?>: <strong class="brendy-status--<?php echo $healthStatus ?>"><?php echo Theme::escape($L->g($healthStatus)) ?></strong></span>
				<?php if (!empty($theme['brendyRelease'])) : ?><span><i class="bi bi-stars" aria-hidden="true"></i><?php echo Theme::escape(BRENDY_NAME) ?> <?php echo Theme::escape($theme['brendyRelease']) ?></span><?php endif ?>
				<?php if (!empty($theme['releaseDate'])) : ?><span><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo Theme::escape($theme['releaseDate']) ?></span><?php endif ?>
				<?php if (!empty($theme['lastModified'])) : ?><span><i class="bi bi-clock-history" aria-hidden="true"></i><?php echo Theme::escape(date('d.m.Y.', (int)$theme['lastModified'])) ?></span><?php endif ?>
			</div>
		</div>
		<footer class="brendy-component-card__actions">
			<?php if (!$isActive && $healthStatus === 'error') : ?>
			<button type="button" class="btn btn-sm btn-outline-danger" disabled title="<?php echo Theme::escape($healthIssues) ?>"><i class="bi bi-heart-pulse" aria-hidden="true"></i><?php echo Theme::escape($L->g('Health')) ?>: <?php echo Theme::escape($L->g('error')) ?></button>
			<?php elseif (!$isActive && !empty($theme['compatible'])) : ?>
			<form method="post" action="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'install-theme/' . rawurlencode($dirname)) ?>"><input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>"><button type="submit" class="btn btn-sm btn-primary" data-brendy-confirm="<?php echo Theme::escape($L->g('Activate this theme?')) ?>"><i class="bi bi-check-circle" aria-hidden="true"></i><?php $L->p('Activate') ?></button></form>
			<?php elseif (!$isActive) : ?>
			<button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-shield-exclamation" aria-hidden="true"></i><?php $L->p('Incompatible') ?></button>
			<?php elseif (isset($theme['plugin']) && is_string($theme['plugin']) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $theme['plugin']) === 1) : ?>
			<a class="btn btn-sm btn-primary" href="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'configure-plugin/' . rawurlencode($theme['plugin'])) ?>"><i class="bi bi-sliders" aria-hidden="true"></i><?php $L->p('Settings') ?></a>
			<?php endif ?>
			<?php if (!$isActive) : ?>
			<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>delete-theme"><input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>"><input type="hidden" name="directory" value="<?php echo Theme::escape($dirname) ?>"><button type="submit" class="btn btn-sm btn-outline-danger" data-brendy-confirm="<?php echo Theme::escape($L->g('Delete theme files permanently?')) ?>"><i class="bi bi-trash" aria-hidden="true"></i><?php $L->p('Delete files') ?></button></form>
			<?php endif ?>
			<?php if ($website !== '') : ?><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer" href="<?php echo Theme::escape($website) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><?php echo Theme::escape($theme['author'] ?? '') ?></a><?php endif ?>
			<?php if (empty($theme['compatible'])) : ?><span class="brendy-status brendy-status--warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><?php $L->p('Compatibility') ?></span><?php endif ?>
		</footer>
	</article>
	<?php endforeach ?>
	<div class="brendy-empty-filter" data-brendy-filter-empty hidden><i class="bi bi-search" aria-hidden="true"></i><?php $L->p('No matching themes found') ?></div>
</div>
