<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<section class="page mt-4 mb-4" data-brendy-reading-scope>
	<div class="container">
		<div class="row">
			<div class="col-lg-8 mx-auto brendy-card p-4 p-md-5">
				<!-- Load Brendy Plugins: Page Begin -->
				<?php Theme::plugins('pageBegin'); ?>

				<?php if (!$page->isStatic() && !$url->notFound()) : ?>
					<div class="form-text brendy-meta mb-2">
						<!-- Page creation time -->
						<span class="pe-3"><i class="bi bi-calendar" aria-hidden="true"></i><time datetime="<?php echo Theme::escape($page->date('c')) ?>"><?php echo Theme::escape($page->date()) ?></time></span>

						<!-- Page reading time -->
						<span class="pe-3"><i class="bi bi-clock" aria-hidden="true"></i><?php echo Theme::escape($page->readingTime() . ' ' . $L->get('read')) ?></span>

						<!-- Page author -->
						<span><i class="bi bi-person" aria-hidden="true"></i><?php echo Theme::escape($page->user('nickname')) ?></span>
					</div>
				<?php endif ?>

				<!-- Page title -->
				<h1 class="page-title bold"><?php echo Theme::escape($page->title()) ?></h1>

				<!-- Page description -->
				<?php if (Theme::hasText($page->description())) : ?>
					<p class="page-description italic mt-1 color-light"><?php echo Theme::escape($page->description()) ?></p>
				<?php endif ?>

				<!-- Page content -->
				<div class="page-content mt-3">
					<?php echo $page->content(); ?>
				</div>

				<?php echo Theme::brendyArticleTools($page); ?>

				<!-- Load Brendy Plugins: Page End -->
				<?php Theme::plugins('pageEnd'); ?>
			</div>
		</div>
	</div>
</section>
<?php echo Theme::brendyRelatedContent($page); ?>
