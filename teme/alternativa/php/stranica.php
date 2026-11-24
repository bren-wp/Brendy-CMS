<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!-- Breadcrumb Navigation for SEO -->
<nav class="breadcrumb-nav" aria-label="<?php echo Theme::escape($L->get('Breadcrumb')); ?>">
	<div class="container">
		<ol class="breadcrumb bg-transparent p-0 mb-0">
			<li class="breadcrumb-item">
				<a href="<?php echo Theme::escape(Theme::siteUrl()); ?>">
					<span><?php echo Theme::escape($L->get('Home')); ?></span>
				</a>
			</li>
			<li class="breadcrumb-item active" aria-current="page">
				<span><?php echo Theme::escape($page->title()); ?></span>
			</li>
		</ol>
	</div>
</nav>

<article class="page" data-brendy-reading-scope>
	<div class="container">
		<div class="row">
				<div class="col-lg-8 mx-auto brendy-card p-4 p-md-5">
				<!-- Load Brendy Plugins: Page Begin -->
				<?php Theme::plugins('pageBegin'); ?>

				<!-- Page header -->
				<header class="page-header mb-4">
					<!-- Page title -->
					<h1 class="title"><?php echo Theme::escape($page->title()); ?></h1>

					<?php if (!$page->isStatic() && !$url->notFound() && $site->alternativaShowPostInformation()) : ?>
						<div class="page-meta brendy-meta text-muted mb-3">
							<!-- Page creation time -->
							<span class="pe-3">
								<i class="bi bi-calendar" aria-hidden="true"></i>
								<time datetime="<?php echo Theme::escape($page->date('c')); ?>"><?php echo Theme::escape($page->date()) ?></time>
							</span>

							<?php if ($page->dateModified() && $page->dateModified() !== $page->date()): ?>
							<!-- Last modified -->
							<span class="pe-3">
								<i class="bi bi-pencil" aria-hidden="true"></i>
								<time datetime="<?php echo Theme::escape($page->dateModified('c')); ?>"><?php echo Theme::escape($L->get('Updated') . ': ' . $page->dateModified()); ?></time>
							</span>
							<?php endif; ?>

							<!-- Page reading time -->
							<span class="pe-3">
								<i class="bi bi-clock" aria-hidden="true"></i>
								<span><?php echo Theme::escape($page->readingTime() . ' ' . $L->g('read')) ?></span>
							</span>

							<!-- Page author -->
							<span>
								<i class="bi bi-person" aria-hidden="true"></i>
								<span><?php echo Theme::escape($page->user('nickname')) ?></span>
							</span>
						</div>
					<?php endif ?>

					<!-- Page description -->
					<?php if (Theme::hasText($page->description())) : ?>
						<p class="page-description lead"><?php echo Theme::escape($page->description()); ?></p>
					<?php endif ?>
				</header>

				<!-- Page cover image -->
				<?php if ($page->coverImage()) : ?>
					<figure class="page-cover-image-wrapper mb-4">
						<img class="page-cover-image img-fluid rounded" src="<?php echo Theme::escape($page->coverImage()); ?>" alt="<?php echo Theme::escape($page->title()); ?>" loading="eager" fetchpriority="high" decoding="async" data-brendy-eager />
					</figure>
				<?php endif ?>

				<!-- Page content -->
				<div class="page-content">
					<?php echo $page->content(); ?>
				</div>

				<?php echo Theme::brendyArticleTools($page); ?>

				<!-- Load Brendy Plugins: Page End -->
				<?php Theme::plugins('pageEnd'); ?>
			</div>
		</div>
	</div>
</article>
<?php echo Theme::brendyRelatedContent($page); ?>
