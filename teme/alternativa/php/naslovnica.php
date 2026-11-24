<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!-- Hero Section -->
<header class="hero" role="banner">
	<div class="container">
		<div class="hero-content">
			<!-- One context-aware page heading for home and archive/search views. -->
			<?php $siteSlogan = $site->slogan();
			$heroHeading = $WHERE_AM_I === 'home' ? ((is_string($siteSlogan) && $siteSlogan !== '') ? $siteSlogan : $site->title()) : Theme::seoTitleText(); ?>
			<h1 class="hero-title"><?php echo Theme::escape($heroHeading); ?></h1>

			<!-- Site description -->
			<?php if (Theme::hasText($site->description())) : ?>
				<p class="hero-subtitle"><?php echo Theme::escape($site->description()); ?></p>
			<?php endif ?>

			<!-- Custom search form if the Search plugin is enabled -->
			<?php
			$searchContext = Theme::searchContext();
			$showSearchBox = $searchContext['active'] && !($WHERE_AM_I === 'search' && $searchContext['resultCount'] === 0);
			if ($showSearchBox) :
				$searchLabel = $language->get('Search');
			?>
				<div class="hero-search">
					<form class="search-form" role="search" action="<?php echo Theme::escape($searchContext['action']); ?>" method="get" data-brendy-search-form data-search-base="<?php echo Theme::escape($searchContext['action']); ?>" data-min-chars="<?php echo (int)$searchContext['minChars']; ?>">
						<label for="search-input" class="visually-hidden"><?php echo Theme::escape($searchLabel); ?></label>
						<div class="search-input-wrapper">
							<i class="bi bi-search search-icon" aria-hidden="true"></i>
							<input id="search-input" class="form-control" name="q" type="search" maxlength="200" minlength="<?php echo (int)$searchContext['minChars']; ?>" autocomplete="off" enterkeyhint="search" placeholder="<?php echo Theme::escape($searchLabel); ?>" aria-label="<?php echo Theme::escape($searchLabel); ?>" value="<?php echo Theme::escape($WHERE_AM_I === 'search' ? $searchContext['term'] : ''); ?>">
						</div>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</div>
</header>

<!-- Main content area -->
<div class="brendy-home-content">
	<?php if (empty($content)) : ?>
		<div class="brendy-empty-state">
			<i class="bi bi-file-earmark-text" aria-hidden="true"></i>
			<p class="text-muted"><?php $language->p('No pages found') ?></p>
		</div>
	<?php endif ?>

	<!-- Print all the content -->
	<?php foreach ($content as $item) : ?>
		<article class="home-page">
			<div class="container">
				<div class="row">
					<div class="col-lg-8 mx-auto brendy-card p-4 p-md-5">
						<!-- Load Brendy Plugins: Page Begin -->
						<?php Theme::pageHook('pageBegin', $item); ?>

						<!-- Page title -->
						<header>
							<h2 class="title">
								<a class="brendy-title-link" href="<?php echo Theme::escape($item->permalink()); ?>"><?php echo Theme::escape($item->title()); ?></a>
							</h2>
						</header>

						<!-- Page description -->
						<?php if (Theme::hasText($item->description())) : ?>
							<p class="page-description"><?php echo Theme::escape($item->description()); ?></p>
						<?php endif ?>

						<!-- Page content until the pagebreak -->
						<div class="page-excerpt">
							<?php echo $item->contentBreak(); ?>
						</div>

						<!-- Shows "read more" button if necessary -->
						<?php if ($item->readMore()) : ?>
							<div class="text-end pt-3">
								<a class="btn btn-primary btn-sm brendy-icon-label" href="<?php echo Theme::escape($item->permalink()); ?>" role="button" aria-label="<?php echo Theme::escape($L->get('Read more') . ' - ' . $item->title()); ?>"><?php echo Theme::escape($L->get('Read more')); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
							</div>
						<?php endif ?>

						<!-- Page date -->
						<footer class="mt-3 article-footer brendy-meta">
							<?php if ($site->alternativaDateFormat() === 'relative') : ?>
								<small class="text-muted"><i class="bi bi-clock-history" aria-hidden="true"></i> <time datetime="<?php echo Theme::escape($item->date('c')); ?>"><?php echo Theme::escape($item->relativeTime()) ?></time></small>
							<?php elseif ($site->alternativaDateFormat() === 'absolute') : ?>
								<small class="text-muted"><i class="bi bi-calendar3" aria-hidden="true"></i> <time datetime="<?php echo Theme::escape($item->date('c')); ?>"><?php echo Theme::escape($item->date()) ?></time></small>
							<?php endif ?>
						</footer>

						<!-- Load Brendy Plugins: Page End -->
						<?php Theme::pageHook('pageEnd', $item); ?>
					</div>
				</div>
			</div>
		</article>
	<?php endforeach ?>
</div>

<!-- Pagination -->
<?php echo Theme::pagination('mt-4'); ?>
