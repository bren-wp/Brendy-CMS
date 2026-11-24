<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<header class="border-b border-gray-200 dark:border-gray-800">
	<div class="max-w-2xl mx-auto px-4 py-6 flex items-center justify-between okus-nav-shell">
		<a href="<?php echo Theme::escape(Theme::siteUrl()) ?>" class="text-xl font-bold text-gray-900 dark:text-white hover:text-accent-600 dark:hover:text-accent-400 transition-colors no-underline okus-brand">
			<i class="bi bi-gem" aria-hidden="true"></i> <?php echo Theme::escape($site->title()) ?>
		</a>
		<button class="okus-nav-toggle" type="button" data-brendy-collapse-target="#okusNavigation" aria-controls="okusNavigation" aria-expanded="false" aria-label="<?php echo Theme::escape($L->get('Toggle navigation')); ?>">
			<i class="bi bi-list" aria-hidden="true"></i>
		</button>
		<nav id="okusNavigation" class="okus-nav-panel flex items-center gap-6 text-sm" aria-label="<?php echo Theme::escape($L->get('Main navigation')); ?>">
			<?php if ($site->homepage() !== false): ?>
				<a class="text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 transition-colors no-underline <?php echo ($WHERE_AM_I === 'blog') ? 'font-semibold text-accent-600 dark:text-accent-400' : '' ?>"<?php echo ($WHERE_AM_I === 'blog') ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape(DOMAIN_BASE . ltrim($url->filters('blog'), '/')) ?>">
					<?php echo Theme::escape($L->get('Blog')) ?>
				</a>
			<?php endif; ?>
			<?php foreach ($staticContent as $staticPage) : ?>
				<?php $isCurrentStaticPage = Theme::isCurrentPage($staticPage); ?>
				<a class="text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 transition-colors no-underline <?php echo $isCurrentStaticPage ? 'font-semibold text-accent-600 dark:text-accent-400' : '' ?>"<?php echo $isCurrentStaticPage ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape($staticPage->permalink()) ?>">
					<?php echo Theme::escape($staticPage->title()) ?>
				</a>
			<?php endforeach ?>
			<?php $socialNetworks = Theme::socialNetworks(); ?>
			<?php if (!empty($socialNetworks)): ?>
				<span class="okus-social-links" role="group" aria-label="<?php echo Theme::escape($L->get('Social networks')); ?>">
					<?php foreach ($socialNetworks as $key => $label) : ?>
						<a class="text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 transition-colors no-underline okus-social-link" href="<?php echo Theme::escape($site->{$key}()); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo Theme::escape($label) ?>">
							<i class="bi <?php echo Theme::escape(Theme::socialIcon($key)) ?>" aria-hidden="true"></i><span class="sr-only"><?php echo Theme::escape($label) ?></span>
						</a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</nav>
	</div>
</header>
