<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<?php echo Theme::archiveHeading(); ?>
<?php if (empty($content)) echo Theme::emptyState('No pages found', 'bi-inbox', 'mt-8'); ?>

<div class="divide-y divide-gray-200 dark:divide-gray-800">
	<?php foreach ($content as $item) : ?>
		<article class="py-8 brendy-card px-4 my-4">

		<!-- Load Brendy Plugins: Page Begin -->
		<?php Theme::pageHook('pageBegin', $item); ?>

		<!-- Cover image -->
		<?php if ($item->coverImage()) : ?>
		<div class="mb-4 rounded-lg overflow-hidden">
			<img class="w-full h-auto" alt="<?php echo Theme::escape($item->title()); ?>" src="<?php echo Theme::escape($item->coverImage()); ?>" loading="lazy" decoding="async" />
		</div>
		<?php endif ?>

		<!-- Date -->
		<time class="brendy-meta block text-sm text-gray-500 dark:text-gray-400 mb-2" datetime="<?php echo Theme::escape($item->date('c')); ?>">
			<i class="bi bi-calendar3" aria-hidden="true"></i> <?php echo Theme::escape($item->date()); ?>
		</time>

		<!-- Title -->
		<h2 class="text-2xl font-bold mb-2">
			<a href="<?php echo Theme::escape($item->permalink()); ?>" class="text-gray-900 dark:text-white hover:text-accent-600 dark:hover:text-accent-400 transition-colors no-underline">
				<?php echo Theme::escape($item->title()); ?>
			</a>
		</h2>

		<!-- Reading time -->
			<p class="brendy-meta text-sm text-gray-500 dark:text-gray-400 mb-3">
			<i class="bi bi-clock" aria-hidden="true"></i> <?php echo Theme::escape($item->readingTime() . ' ' . $L->get('read')); ?>
		</p>

		<!-- Content excerpt -->
		<div class="prose-content text-gray-600 dark:text-gray-300 leading-relaxed">
			<?php echo $item->contentBreak(); ?>
		</div>

		<!-- Read more -->
		<?php if ($item->readMore()) : ?>
		<a href="<?php echo Theme::escape($item->permalink()); ?>" class="inline-flex items-center mt-4 text-accent-600 dark:text-accent-400 hover:text-accent-700 dark:hover:text-accent-300 font-medium text-sm no-underline transition-colors">
				<?php echo Theme::escape($L->get('Read more')); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
		</a>
		<?php endif ?>

		<!-- Tags and Category -->
		<?php echo Theme::taxonomy($item, array(
			'wrapperClass' => 'flex flex-wrap gap-2 mt-4',
			'categoryClass' => 'inline-block text-xs font-medium px-2.5 py-1 rounded-full bg-accent-100 text-accent-700 dark:bg-accent-900 dark:text-accent-300 hover:bg-accent-200 dark:hover:bg-accent-800 transition-colors no-underline',
			'tagClass' => 'inline-block text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors no-underline',
			'categoryIcon' => 'bi-folder', 'tagIcon' => 'bi-tag'
		)); ?>

		<!-- Load Brendy Plugins: Page End -->
		<?php Theme::pageHook('pageEnd', $item); ?>

	</article>
	<?php endforeach ?>
</div>

<!-- Pagination -->
<?php $pager = Theme::pagerData(); ?>
<?php if ($pager['visible']) : ?>
<nav class="flex justify-between items-center py-8 border-t border-gray-200 dark:border-gray-800" aria-label="<?php echo Theme::escape($L->get('Page navigation')); ?>">
	<div>
		<?php if ($pager['hasPrevious']) : ?>
		<a href="<?php echo Theme::escape($pager['previousUrl']) ?>" rel="prev" class="inline-flex items-center text-sm font-medium text-accent-600 dark:text-accent-400 hover:text-accent-700 dark:hover:text-accent-300 no-underline transition-colors">
				<i class="bi bi-arrow-left" aria-hidden="true"></i> <?php echo Theme::escape($L->get('Previous')); ?>
		</a>
		<?php endif; ?>
	</div>
	<div>
		<?php if ($pager['hasNext']) : ?>
		<a href="<?php echo Theme::escape($pager['nextUrl']) ?>" rel="next" class="inline-flex items-center text-sm font-medium text-accent-600 dark:text-accent-400 hover:text-accent-700 dark:hover:text-accent-300 no-underline transition-colors">
				<?php echo Theme::escape($L->get('Next')); ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
		</a>
		<?php endif; ?>
	</div>
</nav>
<?php endif ?>
