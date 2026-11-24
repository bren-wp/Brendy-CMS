<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<nav class="navbar navbar-expand-md navbar-light bg-light sticky-top" aria-label="<?php echo Theme::escape($L->get('Main navigation')); ?>">
	<div class="container">
		<a class="navbar-brand bold" href="<?php echo Theme::escape(Theme::siteUrl()) ?>"><i class="bi bi-stars" aria-hidden="true"></i> <?php echo Theme::escape($site->title()) ?></a>
		<button class="navbar-toggler" type="button" data-brendy-collapse-target="#fokusNavigation" aria-controls="fokusNavigation" aria-expanded="false" aria-label="<?php echo Theme::escape($L->get('Toggle navigation')); ?>">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="fokusNavigation">
			<ul class="nav-links ms-auto mb-0">
				<?php if ($site->homepage() !== false): ?>
					<li><a class="<?php echo ($WHERE_AM_I === 'blog') ? 'is-active' : '' ?>"<?php echo ($WHERE_AM_I === 'blog') ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape(DOMAIN_BASE . ltrim($url->filters('blog'), '/')) ?>"><i class="bi bi-journal-text" aria-hidden="true"></i> <?php echo Theme::escape($L->get('Blog')) ?></a></li>
				<?php endif; ?>
				<?php foreach ($staticContent as $tmp) : ?>
					<?php $isCurrentStaticPage = Theme::isCurrentPage($tmp); ?>
					<li><a class="<?php echo $isCurrentStaticPage ? 'is-active' : '' ?>"<?php echo $isCurrentStaticPage ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape($tmp->permalink()) ?>"><?php echo Theme::escape($tmp->title()) ?></a></li>
				<?php endforeach ?>
			</ul>
		</div>
	</div>
</nav>
