<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<nav class="navbar navbar-expand-md navbar-dark fixed-top navbar-modern text-uppercase" aria-label="<?php echo Theme::escape($L->get('Main navigation')); ?>">
	<div class="container">
		<a class="navbar-brand" href="<?php echo Theme::escape(Theme::siteUrl()) ?>">
			<i class="bi bi-sun me-2" aria-hidden="true"></i><span class="text-white"><?php echo Theme::escape($site->title()) ?></span>
		</a>
		<button class="navbar-toggler" type="button" data-brendy-collapse-target="#navbarResponsive" aria-controls="navbarResponsive" aria-expanded="false" aria-label="<?php echo Theme::escape($L->get('Toggle navigation')); ?>">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="navbarResponsive">
			<ul class="navbar-nav ms-auto">

				<!-- Blog link (when homepage is set to a static page) -->
				<?php if ($site->homepage() !== false): ?>
					<li class="nav-item">
						<a class="nav-link<?php echo ($WHERE_AM_I === 'blog') ? ' active' : '' ?>"<?php echo ($WHERE_AM_I === 'blog') ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape(DOMAIN_BASE . ltrim($url->filters('blog'), '/')) ?>"><?php echo Theme::escape($L->get('Blog')) ?></a>
					</li>
				<?php endif; ?>

				<!-- Static pages -->
				<?php foreach ($staticContent as $staticPage) : ?>
					<?php $isCurrentStaticPage = Theme::isCurrentPage($staticPage); ?>
					<li class="nav-item">
						<a class="nav-link<?php echo $isCurrentStaticPage ? ' active' : '' ?>"<?php echo $isCurrentStaticPage ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape($staticPage->permalink()) ?>"><?php echo Theme::escape($staticPage->title()) ?></a>
					</li>
				<?php endforeach ?>

				<!-- Social Networks -->
				<?php foreach (Theme::socialNetworks() as $key => $label) : ?>
					<li class="nav-item">
						<a class="nav-link" href="<?php echo Theme::escape($site->{$key}()); ?>" target="_blank" rel="noopener noreferrer">
							<i class="d-none d-sm-inline-block nav-social-icon bi <?php echo Theme::escape(Theme::socialIcon($key)) ?>" aria-hidden="true"></i>
							<span class="visually-hidden d-none d-sm-inline"><?php echo Theme::escape($label); ?></span>
							<span class="d-inline d-sm-none"><?php echo Theme::escape($label); ?></span>
						</a>
					</li>
				<?php endforeach; ?>

			</ul>
		</div>
	</div>
</nav>
