<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<header class="p-3">
	<div class="container text-center">

		<!-- Site logo -->
		<div class="site-logo">
			<img class="img-thumbnail rounded-circle mx-auto d-block" height="140" width="140" src="<?php echo Theme::escape($site->logo() ? $site->logo() : HTML_PATH_THEME_IMG . 'fokus.png') ?>" alt="<?php echo Theme::escape($site->title()) ?>" loading="eager" fetchpriority="high" decoding="async" data-brendy-eager>
		</div>
		<!-- End Site logo -->

		<!-- Site title -->
		<?php $pageHeading = $WHERE_AM_I === 'home' ? $site->title() : Theme::seoTitleText(); ?>
		<h1 class="site-title mt-3 mb-1 bold"><?php echo Theme::escape($pageHeading) ?></h1>
		<!-- End Site title -->

		<!-- Site description -->
		<?php if (Theme::hasText($site->description())) : ?>
			<div class="site-description mt-2">
				<p><?php echo Theme::escape($site->description()) ?></p>
			</div>
		<?php endif ?>
		<!-- End Site description -->

	</div>
</header>

<!-- Print all the content -->
<section class="mt-4 mb-4">
	<div class="container">
		<div class="row">
			<div class="col-lg-8 mx-auto">

				<!-- Search input -->
				<?php $searchContext = Theme::searchContext(); ?>
				<?php if ($searchContext['active']) : ?>
					<form class="d-flex mb-4" id="search-form" role="search" action="<?php echo Theme::escape($searchContext['action']) ?>" method="get" data-brendy-search-form data-search-base="<?php echo Theme::escape($searchContext['action']) ?>" data-min-chars="<?php echo (int)$searchContext['minChars'] ?>">
						<label class="visually-hidden" for="search-input"><?php echo Theme::escape($L->get('Search')) ?></label>
						<input id="search-input" class="form-control me-2" name="q" type="search" maxlength="200" minlength="<?php echo (int)$searchContext['minChars'] ?>" autocomplete="off" enterkeyhint="search" placeholder="<?php echo Theme::escape($L->get('Search')) ?>" aria-label="<?php echo Theme::escape($L->get('Search')) ?>" value="<?php echo Theme::escape($WHERE_AM_I === 'search' ? $searchContext['term'] : '') ?>">
						<button class="btn btn-outline-primary brendy-icon-label" type="submit"><i class="bi bi-search" aria-hidden="true"></i><?php echo Theme::escape($L->get('Search')) ?></button>
					</form>
				<?php endif ?>
				<!-- End Search input -->

				<!-- Content not available -->
				<?php if (empty($content)) : ?>
					<div class="brendy-empty-state">
						<i class="bi bi-inbox" aria-hidden="true"></i><h3><?php $language->p('No pages found') ?></h3>
					</div>
				<?php endif ?>
				<!-- End Content not available -->

				<!-- Pages -->
				<div class="list-group list-group-flush">
					<?php foreach ($content as $pageTmp) : ?>
						<?php Theme::pageHook('pageBegin', $pageTmp); ?>
						<article class="list-group-item brendy-card p-4 mb-3">
							<div class="d-flex w-100 justify-content-between">

								<!-- Page title -->
				<a href="<?php echo Theme::escape($pageTmp->permalink()) ?>">
					<h2 class="h5 mb-1"><?php echo Theme::escape($pageTmp->title()) ?></h2>
								</a>
								<!-- End Page title -->

								<!-- Page date -->
								<!-- This block is not visible on small devices -->
							<div class="d-none d-sm-block brendy-meta">
									<?php if ($site->fokusDateFormat() === 'relative') : ?>
										<small class="color-blue"><time datetime="<?php echo Theme::escape($pageTmp->date('c')) ?>"><?php echo Theme::escape($pageTmp->relativeTime()) ?></time></small>
									<?php else : ?>
										<small class="color-blue"><time datetime="<?php echo Theme::escape($pageTmp->date('c')) ?>"><?php echo Theme::escape($pageTmp->date()) ?></time></small>
									<?php endif ?>
								</div>
								<!-- End Page date -->

							</div>

							<!-- Page date -->
							<!-- This block is only visible on small devices -->
							<div class="d-block d-sm-none brendy-meta">
								<?php if ($site->fokusDateFormat() === 'relative') : ?>
									<small class="color-blue"><time datetime="<?php echo Theme::escape($pageTmp->date('c')) ?>"><?php echo Theme::escape($pageTmp->relativeTime()) ?></time></small>
								<?php else : ?>
									<small class="color-blue"><time datetime="<?php echo Theme::escape($pageTmp->date('c')) ?>"><?php echo Theme::escape($pageTmp->date()) ?></time></small>
								<?php endif ?>
							</div>
							<!-- End Page date -->

							<!-- Page description -->
							<?php if (Theme::hasText($pageTmp->description())) : ?>
								<p class="mb-1 form-text"><?php echo Theme::escape($pageTmp->description()) ?></p>
							<?php endif ?>
							<!-- End Page description -->

							<!-- Page tags -->
							<?php
							if ($site->fokusShowTags()) {
								$tagsList = $pageTmp->tags(true);
								if (!empty($tagsList)) {
									echo '<small>';
									foreach ($tagsList as $tagKey => $tagName) {
										echo '<a class="badge bg-gray text-dark text-decoration-none me-2" href="' . Theme::escape(DOMAIN_TAGS . rawurlencode($tagKey)) . '">' . Theme::escape($tagName) . '</a>';
									}
									echo '</small>';
								}
							}
							?>
							<!-- End Page tags -->

						</article>
						<?php Theme::pageHook('pageEnd', $pageTmp); ?>
					<?php endforeach ?>
				</div>
				<!-- End Pages -->

				<!-- Pagination -->
				<?php $pager = Theme::pagerData(); ?>
				<?php if ($pager['visible']) : ?>
					<nav class="mt-4" aria-label="<?php echo Theme::escape($L->get('Page navigation')); ?>">
						<ul class="pagination pagination-sm">

							<!-- Older pages -->
							<?php if ($pager['hasPrevious']) : ?>
								<li class="page-item">
							<a class="page-link" href="<?php echo Theme::escape($pager['previousUrl']) ?>" rel="prev" aria-label="<?php echo Theme::escape($L->get('Previous')); ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i> <?php echo Theme::escape($L->get('Previous')) ?></a>
								</li>
							<?php endif; ?>
							<!-- End Older pages -->

							<!-- Newer pages -->
							<?php if ($pager['hasNext']) : ?>
								<li class="page-item ms-auto">
							<a class="page-link" href="<?php echo Theme::escape($pager['nextUrl']) ?>" rel="next" aria-label="<?php echo Theme::escape($L->get('Next')); ?>"><?php echo Theme::escape($L->get('Next')) ?> <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
								</li>
							<?php endif; ?>
							<!-- End Newer pages -->

						</ul>
					</nav>
				<?php endif ?>
				<!-- End Pagination -->

			</div>
		</div>
	</div>
</section>
<!-- End Print all the content -->
