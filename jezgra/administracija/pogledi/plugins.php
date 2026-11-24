<?php defined('BRENDY') or die('Brendy CMS.');

$availablePlugins = array();
$catalogPlugins = array();
$categories = array();
foreach ($plugins['all'] as $className => $candidate) {
	if ($candidate->type() === 'theme') continue;
	$availablePlugins[$className] = $candidate;
	$categories[$candidate->category()] = true;
}
foreach (isset($pluginsCatalog) && is_array($pluginsCatalog) ? $pluginsCatalog : array() as $catalogKey => $entry) {
	if (!is_array($entry) || isset($availablePlugins[$catalogKey])) continue;
	$metadata = isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : array();
	if (($metadata['type'] ?? '') === 'theme') continue;
	$category = isset($metadata['category']) && is_string($metadata['category']) ? $metadata['category'] : 'system';
	if (!in_array($category, array('analytics', 'content', 'editor', 'integration', 'seo', 'system', 'theme'), true)) $category = 'system';
	$entry['category'] = $category;
	$catalogPlugins[$catalogKey] = $entry;
	$categories[$category] = true;
}
ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
$activePluginCount = count(array_intersect_key($availablePlugins, $pluginsInstalled));
$availablePluginCount = count($availablePlugins) + count($catalogPlugins);

$renderPluginCard = static function ($plugin, $runtimeActive) use ($L, $security) {
	$health = $plugin->healthStatus();
	$healthStatus = isset($health['status']) && in_array($health['status'], array('ok', 'warning', 'error'), true)
		? $health['status'] : 'warning';
	$issues = isset($health['issues']) && is_array($health['issues']) ? implode(', ', $health['issues']) : '';
	$hasInstalledState = $plugin->installed();
	$state = $runtimeActive ? 'active' : ($hasInstalledState ? 'blocked' : 'inactive');
	$stateClass = $state === 'blocked' ? 'error' : $state;
	$stateLabel = $state === 'blocked' ? $L->g('Blocked') : $L->g($state);
	$databaseSize = $plugin->databaseSize();
	$workspaceSize = $plugin->workspaceSize();
	$storedSize = $databaseSize + $workspaceSize;
	$lastModified = $plugin->lastModified();
	$issueCount = isset($health['issues']) && is_array($health['issues']) ? count($health['issues']) : 0;
	$website = $plugin->website();
	$searchText = implode(' ', array($plugin->name(), $plugin->description(), $plugin->author(), $plugin->category()));
	?>
	<article id="<?php echo Theme::escape($plugin->className()) ?>" class="brendy-component-card" data-brendy-filter-item data-search="<?php echo Theme::escape($searchText) ?>" data-status="<?php echo $state ?>" data-category="<?php echo Theme::escape($plugin->category()) ?>">
		<header class="brendy-component-card__header">
			<span class="brendy-component-card__icon"><i class="bi bi-<?php echo Theme::escape($plugin->icon()) ?>" aria-hidden="true"></i></span>
			<div class="brendy-component-card__heading">
				<h3><?php echo Theme::escape($plugin->name()) ?></h3>
				<p><?php echo Theme::escape($L->g($plugin->category())) ?> · <?php echo Theme::escape($L->g('Version')) ?> <?php echo Theme::escape($plugin->version()) ?></p>
			</div>
			<span class="brendy-status brendy-status--<?php echo $stateClass ?>"><i class="bi <?php echo $runtimeActive ? 'bi-check-circle' : ($hasInstalledState ? 'bi-shield-x' : 'bi-circle') ?>" aria-hidden="true"></i><?php echo Theme::escape($stateLabel) ?></span>
		</header>
		<div class="brendy-component-card__body">
			<p><?php echo Theme::escape($plugin->description()) ?></p>
			<div class="brendy-component-card__meta">
				<span title="<?php echo Theme::escape($issues) ?>"><i class="bi bi-heart" aria-hidden="true"></i><?php echo Theme::escape($L->g('Health')) ?>: <strong class="brendy-status--<?php echo $healthStatus ?>"><?php echo Theme::escape($L->g($healthStatus)) ?></strong></span>
				<span><i class="bi bi-sliders" aria-hidden="true"></i><?php echo (int)$plugin->settingsFieldCount() ?> <?php echo Theme::escape($L->g('Settings fields')) ?></span>
				<?php if ($storedSize > 0) : ?><span><i class="bi bi-hdd-stack" aria-hidden="true"></i><?php echo Theme::escape(Filesystem::bytesToHumanFileSize($storedSize)) ?></span><?php endif ?>
				<?php if ($lastModified > 0) : ?><span><i class="bi bi-clock-history" aria-hidden="true"></i><?php echo Theme::escape(date('d.m.Y.', $lastModified)) ?></span><?php endif ?>
				<?php if ($issueCount > 0) : ?><span class="brendy-status--warning"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?php echo (int)$issueCount ?> <?php echo Theme::escape($L->g('health issues')) ?></span><?php endif ?>
				<?php if ($plugin->brendyRelease() !== '') : ?><span><i class="bi bi-stars" aria-hidden="true"></i><?php echo Theme::escape(BRENDY_NAME) ?> <?php echo Theme::escape($plugin->brendyRelease()) ?></span><?php endif ?>
			</div>
		</div>
		<footer class="brendy-component-card__actions">
			<?php if ($runtimeActive && method_exists($plugin, 'form')) : ?>
				<a class="btn btn-sm btn-primary" href="<?php echo HTML_PATH_ADMIN_ROOT ?>configure-plugin/<?php echo rawurlencode($plugin->className()) ?>"><i class="bi bi-sliders" aria-hidden="true"></i><?php $L->p('Settings') ?></a>
			<?php endif ?>
			<?php if ($hasInstalledState) : ?>
				<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>uninstall-plugin/<?php echo rawurlencode($plugin->className()) ?>">
					<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
					<button type="submit" class="btn btn-sm <?php echo $runtimeActive ? 'btn-outline-secondary' : 'btn-outline-danger' ?>" data-brendy-confirm="<?php echo Theme::escape($L->g('Are you sure you want to deactivate this plugin?')) ?>"><i class="bi <?php echo $runtimeActive ? 'bi-pause-circle' : 'bi-shield-x' ?>" aria-hidden="true"></i><?php echo Theme::escape($L->g($runtimeActive ? 'Deactivate' : 'Remove blocked state')) ?></button>
				</form>
			<?php else : ?>
				<?php if ($healthStatus === 'error') : ?>
				<button type="button" class="btn btn-sm btn-outline-danger" disabled title="<?php echo Theme::escape($issues) ?>"><i class="bi bi-heart-pulse" aria-hidden="true"></i><?php echo Theme::escape($L->g('Health')) ?>: <?php echo Theme::escape($L->g('error')) ?></button>
				<?php elseif ($plugin->isCompatible()) : ?>
				<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>install-plugin/<?php echo rawurlencode($plugin->className()) ?>">
					<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
					<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-play-circle" aria-hidden="true"></i><?php $L->p('Activate') ?></button>
				</form>
				<?php else : ?>
				<button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-shield-exclamation" aria-hidden="true"></i><?php $L->p('Incompatible') ?></button>
				<?php endif ?>
			<?php endif ?>
			<?php if (!$hasInstalledState) : ?>
				<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>delete-plugin">
					<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
					<input type="hidden" name="directory" value="<?php echo Theme::escape($plugin->directoryName()) ?>">
					<button type="submit" class="btn btn-sm btn-outline-danger" data-brendy-confirm="<?php echo Theme::escape($L->g('Delete plugin files permanently?')) ?>"><i class="bi bi-trash" aria-hidden="true"></i><?php $L->p('Delete files') ?></button>
				</form>
			<?php endif ?>
			<?php if ($website !== '') : ?><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer" href="<?php echo Theme::escape($website) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><?php echo Theme::escape($plugin->author()) ?></a><?php endif ?>
			<?php if (!$plugin->isCompatible()) : ?><span class="brendy-status brendy-status--warning" title="<?php echo Theme::escape($L->g('This plugin may not be supported by this version of Brendy')) ?>"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><?php $L->p('Compatibility') ?></span><?php endif ?>
		</footer>
	</article>
	<?php
};

$renderCatalogCard = static function ($entry, $catalogKey) use ($L, $security, $site) {
	$directory = isset($entry['directory']) && is_string($entry['directory']) ? $entry['directory'] : '';
	$className = isset($entry['className']) && is_string($entry['className']) ? $entry['className'] : '';
	$metadata = isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : array();
	$category = isset($entry['category']) && is_string($entry['category']) ? $entry['category'] : 'system';
	$installed = !empty($entry['installed']);
	$metadataValid = !empty($entry['metadataValid']);
	$compatible = !empty($entry['compatible']);
	$databaseOk = !empty($entry['databaseOk']);
	$sourceSafe = array_key_exists('sourceSafe', $entry) ? $entry['sourceSafe'] : null;
	$issues = array();
	if (!$metadataValid) $issues[] = 'metadata-format';
	if (!$compatible) $issues[] = 'compatibility';
	if ($installed && !$databaseOk) $issues[] = 'database-integrity';
	if ($sourceSafe === false) $issues[] = 'source-security';
	if ($className === '') $issues[] = 'class-contract';
	$healthStatus = in_array('metadata-format', $issues, true) || in_array('database-integrity', $issues, true) ||
		in_array('source-security', $issues, true) || in_array('class-contract', $issues, true) ? 'error' : (!empty($issues) ? 'warning' : 'ok');
	$state = $installed ? 'blocked' : 'inactive';
	$stateClass = $installed ? 'error' : 'inactive';
	$stateLabel = $installed ? $L->g('Blocked') : $L->g('inactive');

	$name = $directory !== '' ? $directory : 'plugin';
	$description = '';
	$languageDirectory = PATH_PLUGINS . $directory . DS . 'jezici' . DS;
	$languageFile = $languageDirectory . $site->language() . '.json';
	if (!is_file($languageFile) || is_link($languageFile)) $languageFile = $languageDirectory . DEFAULT_LANGUAGE_FILE;
	$raw = Filesystem::readFileBounded($languageFile, 2097152, 2);
	if (is_string($raw)) {
		$data = json_decode($raw, true, 64);
		if (is_array($data) && isset($data['plugin-data']) && is_array($data['plugin-data'])) {
			if (isset($data['plugin-data']['name']) && is_scalar($data['plugin-data']['name'])) $name = (string)$data['plugin-data']['name'];
			if (isset($data['plugin-data']['description']) && is_scalar($data['plugin-data']['description'])) $description = (string)$data['plugin-data']['description'];
		}
	}
	$name = mb_substr($name, 0, 500, CHARSET);
	$description = mb_substr($description, 0, 10000, CHARSET);
	$version = isset($metadata['version']) && is_string($metadata['version']) ? $metadata['version'] : '';
	$icon = isset($metadata['icon']) && is_string($metadata['icon']) && preg_match('/^[a-z0-9-]{1,50}$/D', $metadata['icon']) === 1 ? $metadata['icon'] : 'puzzle';
	$author = isset($metadata['author']) && is_string($metadata['author']) ? $metadata['author'] : '';
	$release = isset($metadata['brendyRelease']) && is_string($metadata['brendyRelease']) ? $metadata['brendyRelease'] : '';
	$website = isset($metadata['website']) && brendyIsSafeHttpUrl($metadata['website'], 2048) ? (string)$metadata['website'] : '';
	$searchText = implode(' ', array($name, $description, $author, $category, $directory));
	$anchor = $className !== '' ? $className : 'plugin-' . $directory;
	?>
	<article id="<?php echo Theme::escape($anchor) ?>" class="brendy-component-card" data-brendy-filter-item data-search="<?php echo Theme::escape($searchText) ?>" data-status="<?php echo Theme::escape($state) ?>" data-category="<?php echo Theme::escape($category) ?>">
		<header class="brendy-component-card__header">
			<span class="brendy-component-card__icon"><i class="bi bi-<?php echo Theme::escape($icon) ?>" aria-hidden="true"></i></span>
			<div class="brendy-component-card__heading"><h3><?php echo Theme::escape($name) ?></h3><p><?php echo Theme::escape($L->g($category)) ?><?php if ($version !== '') : ?> · <?php echo Theme::escape($L->g('Version')) ?> <?php echo Theme::escape($version) ?><?php endif ?></p></div>
			<span class="brendy-status brendy-status--<?php echo $stateClass ?>"><i class="bi <?php echo $installed ? 'bi-shield-x' : 'bi-circle' ?>" aria-hidden="true"></i><?php echo Theme::escape($stateLabel) ?></span>
		</header>
		<div class="brendy-component-card__body">
			<p><?php echo Theme::escape($description) ?></p>
			<div class="brendy-component-card__meta">
				<span title="<?php echo Theme::escape(implode(', ', $issues)) ?>"><i class="bi bi-heart" aria-hidden="true"></i><?php echo Theme::escape($L->g('Health')) ?>: <strong class="brendy-status--<?php echo $healthStatus ?>"><?php echo Theme::escape($L->g($healthStatus)) ?></strong></span>
				<?php if ($release !== '') : ?><span><i class="bi bi-stars" aria-hidden="true"></i><?php echo Theme::escape(BRENDY_NAME) ?> <?php echo Theme::escape($release) ?></span><?php endif ?>
			</div>
		</div>
		<footer class="brendy-component-card__actions">
			<?php if ($installed) : ?>
			<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>delete-plugin">
				<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>"><input type="hidden" name="directory" value="<?php echo Theme::escape($directory) ?>"><input type="hidden" name="removeState" value="1">
				<button type="submit" class="btn btn-sm btn-outline-danger" data-brendy-confirm="<?php echo Theme::escape($L->g('Are you sure you want to deactivate this plugin?')) ?>"><i class="bi bi-shield-x" aria-hidden="true"></i><?php echo Theme::escape($L->g('Remove blocked state')) ?></button>
			</form>
			<?php elseif ($healthStatus !== 'error' && $compatible && $className !== '') : ?>
			<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>install-plugin/<?php echo rawurlencode($className) ?>"><input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>"><button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-play-circle" aria-hidden="true"></i><?php $L->p('Activate') ?></button></form>
			<?php elseif (!$compatible) : ?><button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-shield-exclamation" aria-hidden="true"></i><?php $L->p('Incompatible') ?></button>
			<?php else : ?><button type="button" class="btn btn-sm btn-outline-danger" disabled title="<?php echo Theme::escape(implode(', ', $issues)) ?>"><i class="bi bi-heart-pulse" aria-hidden="true"></i><?php echo Theme::escape($L->g('Health')) ?>: <?php echo Theme::escape($L->g('error')) ?></button><?php endif ?>
			<?php if (!$installed) : ?><form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>delete-plugin"><input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>"><input type="hidden" name="directory" value="<?php echo Theme::escape($directory) ?>"><button type="submit" class="btn btn-sm btn-outline-danger" data-brendy-confirm="<?php echo Theme::escape($L->g('Delete plugin files permanently?')) ?>"><i class="bi bi-trash" aria-hidden="true"></i><?php $L->p('Delete files') ?></button></form><?php endif ?>
			<?php if ($website !== '') : ?><a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer" href="<?php echo Theme::escape($website) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><?php echo Theme::escape($author) ?></a><?php endif ?>
		</footer>
	</article>
	<?php
};

echo BrendyAdminUi::pageTitle(array('title' => $L->g('Plugins'), 'icon' => 'puzzle-piece'));
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
	<p class="text-muted mb-0"><i class="bi bi-puzzle" aria-hidden="true"></i> <?php echo $activePluginCount ?> <?php $L->p('active') ?> · <?php echo $availablePluginCount ?> <?php $L->p('available') ?></p>
	<a class="btn btn-sm btn-outline-primary brendy-icon-label" href="<?php echo HTML_PATH_ADMIN_ROOT ?>plugins-position"><i class="bi bi-arrows-move" aria-hidden="true"></i><?php $L->p('Change the position of the plugins') ?></a>
</div>

<section class="brendy-upload-panel" aria-labelledby="brendy-plugin-upload-title">
	<div><i class="bi bi-file-earmark-zip" aria-hidden="true"></i><div><strong id="brendy-plugin-upload-title"><?php $L->p('Install plugin ZIP') ?></strong><small><?php $L->p('Upload a Brendy-compatible plugin archive. Existing plugin folders are never overwritten.') ?></small></div></div>
	<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>upload-plugin" enctype="multipart/form-data">
		<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
		<label class="visually-hidden" for="brendy-plugin-zip"><?php echo Theme::escape($L->g('Install plugin ZIP')) ?></label>
		<input id="brendy-plugin-zip" type="file" class="form-control" name="extensionZip" accept=".zip,application/zip" required>
		<button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><?php $L->p('Upload and install') ?></button>
	</form>
</section>

<div class="brendy-filter-bar" role="search" aria-label="<?php echo Theme::escape($L->g('Filter plugins')) ?>">
	<div class="brendy-filter-control"><i class="bi bi-search" aria-hidden="true"></i><input type="search" dir="auto" class="form-control" data-brendy-filter-query placeholder="<?php echo Theme::escape($L->g('Search plugins')) ?>" aria-label="<?php echo Theme::escape($L->g('Search plugins')) ?>"></div>
	<select class="form-select" data-brendy-filter-status aria-label="<?php echo Theme::escape($L->g('Filter by status')) ?>">
		<option value=""><?php $L->p('All statuses') ?></option>
		<option value="active"><?php $L->p('Active') ?></option>
		<option value="blocked"><?php $L->p('Blocked') ?></option>
		<option value="inactive"><?php $L->p('Inactive') ?></option>
	</select>
	<select class="form-select" data-brendy-filter-category aria-label="<?php echo Theme::escape($L->g('Filter by category')) ?>">
		<option value=""><?php $L->p('All categories') ?></option>
		<?php foreach (array_keys($categories) as $category) : ?><option value="<?php echo Theme::escape($category) ?>"><?php echo Theme::escape($L->g($category)) ?></option><?php endforeach ?>
	</select>
</div>

<div class="brendy-component-grid">
	<?php foreach ($availablePlugins as $className => $plugin) $renderPluginCard($plugin, isset($pluginsInstalled[$className])); ?>
	<?php foreach ($catalogPlugins as $catalogKey => $entry) $renderCatalogCard($entry, $catalogKey); ?>
	<div class="brendy-empty-filter" data-brendy-filter-empty hidden><i class="bi bi-search" aria-hidden="true"></i><?php $L->p('No matching plugins found') ?></div>
</div>
