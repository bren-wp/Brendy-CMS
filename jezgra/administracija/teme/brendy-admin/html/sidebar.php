<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<aside class="brendy-sidebar-shell" aria-label="<?php echo Theme::escape($L->g('Administration navigation')) ?>">
	<a class="brendy-sidebar-brand" href="<?php echo HTML_PATH_ADMIN_ROOT ?>dashboard" aria-label="<?php echo Theme::escape(BRENDY_NAME) ?>">
		<span class="brendy-sidebar-brand__mark"><img src="<?php echo HTML_PATH_CORE_IMG ?>logo.svg" width="22" height="22" alt=""></span>
		<span><strong><?php echo Theme::escape(BRENDY_NAME) ?></strong></span>
	</a>

	<nav class="brendy-sidebar-nav">
		<?php foreach ($brendyAdminNavigation as $section) : ?>
			<?php if ($section['label'] !== '') : ?>
				<h2><?php echo Theme::escape($section['label']) ?></h2>
			<?php endif ?>
			<ul class="nav flex-column">
				<?php foreach ($section['items'] as $item) : ?>
				<?php
				$currentAdminSlug = isset($layout['slug']) && is_string($layout['slug']) ? $layout['slug'] : '';
				$itemActive = isset($item['activeSlugs']) && is_array($item['activeSlugs']) && in_array($currentAdminSlug, $item['activeSlugs'], true);
				?>
				<li class="nav-item">
					<a class="nav-link<?php echo !empty($item['featured']) ? ' brendy-nav-featured' : '' ?><?php echo $itemActive ? ' active' : '' ?>" href="<?php echo Theme::escape($item['url']) ?>"<?php if ($itemActive) : ?> aria-current="page"<?php endif ?><?php if (!empty($item['target'])) : ?> target="_blank" rel="noopener noreferrer"<?php endif ?>>
						<i class="bi <?php echo Theme::escape($item['icon']) ?>" aria-hidden="true"></i><span><?php echo Theme::escape($item['label']) ?></span>
					</a>
				</li>
				<?php endforeach ?>
			</ul>
		<?php endforeach ?>

		<?php if (!empty($brendyPluginShortcuts)) : ?>
			<div class="brendy-sidebar-plugins">
				<h2><?php $L->p('Plugin shortcuts') ?></h2>
				<ul class="nav flex-column">
					<?php foreach ($brendyPluginShortcuts as $pluginShortcut) : ?>
						<li class="nav-item"><?php echo $pluginShortcut ?></li>
					<?php endforeach ?>
				</ul>
			</div>
		<?php endif ?>
	</nav>

	<div class="brendy-sidebar-footer">
		<form method="post" action="<?php echo HTML_PATH_ADMIN_ROOT ?>logout">
			<input type="hidden" name="tokenCSRF" value="<?php echo Theme::escape($security->getTokenCSRF()) ?>">
			<button type="submit" class="nav-link btn btn-link"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span><?php $L->p('Logout') ?></span></button>
		</form>
		<a href="https://brendigo.com" target="_blank" rel="noopener noreferrer" class="brendy-sidebar-meta">
			<span><?php echo Theme::escape(BRENDY_RELEASE_NAME) ?> <?php echo Theme::escape(BRENDY_VERSION) ?></span><small>Brendigo · brendigo.com</small>
		</a>
	</div>
</aside>
