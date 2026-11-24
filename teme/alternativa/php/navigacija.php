<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<nav class="navbar navbar-expand-md navbar-dark fixed-top bg-dark text-uppercase" aria-label="<?php echo Theme::escape($L->get('Main navigation')); ?>">
	<div class="container">
		<a class="navbar-brand" href="<?php echo Theme::escape(Theme::siteUrl()); ?>">
			<i class="bi bi-stars me-2" aria-hidden="true"></i><span class="text-white"><?php echo Theme::escape($site->title()); ?></span>
		</a>
		<button class="navbar-toggler" type="button" data-brendy-collapse-target="#navbarResponsive" aria-controls="navbarResponsive" aria-expanded="false" aria-label="<?php echo Theme::escape($L->get('Toggle navigation')); ?>">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="navbarResponsive">

			<ul class="navbar-nav ms-auto">

				<!-- Blog link (when homepage is set to a static page) -->
				<?php if ($site->homepage() !== false): ?>
				<li class="nav-item<?php echo ($WHERE_AM_I === 'blog') ? ' active' : ''; ?>">
					<a class="nav-link"<?php echo ($WHERE_AM_I === 'blog') ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape(DOMAIN_BASE . ltrim($url->filters('blog'), '/')) ?>">
						<i class="bi bi-journal-text me-1" aria-hidden="true"></i><?php echo Theme::escape($L->get('Blog')) ?>
					</a>
				</li>
				<?php endif; ?>

				<!-- Static pages -->
				<?php foreach ($staticContent as $staticPage): ?>
				<?php $isCurrentStaticPage = Theme::isCurrentPage($staticPage); ?>
				<li class="nav-item<?php echo $isCurrentStaticPage ? ' active' : ''; ?>">
					<a class="nav-link"<?php echo $isCurrentStaticPage ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape($staticPage->permalink()); ?>">
						<?php echo Theme::escape($staticPage->title()); ?>
						<?php if ($isCurrentStaticPage): ?>
							<span class="visually-hidden">(<?php echo Theme::escape($L->get('current')); ?>)</span>
						<?php endif; ?>
					</a>
				</li>
				<?php endforeach ?>

				<!-- Social Networks -->
				<?php foreach (Theme::socialNetworks() as $key=>$label): ?>
				<li class="nav-item">
					<a class="nav-link" href="<?php echo Theme::escape($site->{$key}()); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo Theme::escape($label); ?>">
						<i class="d-none d-md-inline-block nav-social-icon bi <?php echo Theme::escape(Theme::socialIcon($key)) ?>" aria-hidden="true"></i>
						<span class="d-inline d-md-none"><?php echo Theme::escape($label); ?></span>
						<span class="visually-hidden d-none d-md-inline"><?php echo Theme::escape($label); ?></span>
					</a>
				</li>
				<?php endforeach; ?>


			</ul>

		</div>
	</div>
</nav>
