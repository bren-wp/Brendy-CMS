<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<!-- Post -->
<article class="card card-modern brendy-card my-5" data-brendy-reading-scope>

	<!-- Load Brendy Plugins: Page Begin -->
	<?php Theme::plugins('pageBegin'); ?>

	<!-- Cover image with gradient overlay -->
	<?php if ($page->coverImage()): ?>
	<div class="cover-image-wrapper">
		<img class="card-img-top" alt="<?php echo Theme::escape($page->title()); ?>" src="<?php echo Theme::escape($page->coverImage()); ?>" loading="eager" fetchpriority="high" decoding="async" data-brendy-eager />
	</div>
	<?php endif ?>

	<div class="card-body">
		<!-- Title -->
		<h1 class="title"><?php echo Theme::escape($page->title()); ?></h1>

		<?php if (!$page->isStatic() && !$url->notFound()): ?>
		<!-- Creation date and reading time -->
			<div class="metadata brendy-meta mb-4">
			<span><i class="bi bi-calendar" aria-hidden="true"></i><time datetime="<?php echo Theme::escape($page->date('c')); ?>"><?php echo Theme::escape($page->date()); ?></time></span>
			<span><i class="bi bi-clock-history" aria-hidden="true"></i><?php echo Theme::escape($L->get('Reading time') . ': ' . $page->readingTime()) ?></span>
		</div>
		<?php endif ?>

		<!-- Full content -->
		<div class="content">
			<?php echo $page->content(); ?>
		</div>

		<!-- Tags and Category -->
		<?php echo Theme::taxonomy($page, array('wrapperClass'=>'post-taxonomy mt-3', 'categoryClass'=>'taxonomy-badge taxonomy-category', 'tagClass'=>'taxonomy-badge taxonomy-tag', 'categoryIcon'=>'bi-folder', 'tagIcon'=>'bi-tag')); ?>

		<?php echo Theme::brendyArticleTools($page); ?>

	</div>

	<!-- Load Brendy Plugins: Page End -->
	<?php Theme::plugins('pageEnd'); ?>

</article>
<?php echo Theme::brendyRelatedContent($page); ?>
