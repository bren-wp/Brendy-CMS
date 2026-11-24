<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<article class="py-8 brendy-card px-4" data-brendy-reading-scope>

	<!-- Load Brendy Plugins: Page Begin -->
	<?php Theme::plugins('pageBegin'); ?>

	<?php if (!$page->isStatic() && !$url->notFound()): ?>
	<!-- Breadcrumb navigation -->
	<nav aria-label="<?php echo Theme::escape($L->get('Breadcrumb')); ?>" class="mb-6">
		<ol class="flex items-center text-sm text-gray-500 dark:text-gray-400 gap-1.5">
			<li>
				<a href="<?php echo Theme::escape(Theme::siteUrl()); ?>" class="hover:text-accent-600 dark:hover:text-accent-400 no-underline transition-colors">
					<?php echo Theme::escape($site->title()); ?>
				</a>
			</li>
			<?php if ($page->categoryKey() !== false): ?>
			<li class="flex items-center gap-1.5">
				<span aria-hidden="true">/</span>
				<a href="<?php echo Theme::escape($page->categoryPermalink()); ?>" class="hover:text-accent-600 dark:hover:text-accent-400 no-underline transition-colors">
					<?php echo Theme::escape($page->category()); ?>
				</a>
			</li>
			<?php endif; ?>
			<li class="flex items-center gap-1.5">
				<span aria-hidden="true">/</span>
				<span class="text-gray-700 dark:text-gray-300 font-medium truncate max-w-[200px]" aria-current="page">
					<?php echo Theme::escape($page->title()); ?>
				</span>
			</li>
		</ol>
	</nav>

	<!-- Date and reading time -->
		<div class="brendy-meta mb-4">
		<time class="text-sm text-gray-500 dark:text-gray-400" datetime="<?php echo Theme::escape($page->date('c')); ?>">
			<?php echo Theme::escape($page->date()); ?>
		</time>
		<span class="text-sm text-gray-400 dark:text-gray-500 mx-2">&middot;</span>
		<span class="text-sm text-gray-500 dark:text-gray-400">
			<?php echo Theme::escape($page->readingTime() . ' ' . $L->get('read')); ?>
		</span>
		<?php if ($page->user('nickname')): ?>
		<span class="text-sm text-gray-400 dark:text-gray-500 mx-2">&middot;</span>
		<span class="text-sm text-gray-500 dark:text-gray-400">
			<?php echo Theme::escape($page->user('nickname')); ?>
		</span>
		<?php endif; ?>
	</div>
	<?php endif ?>

	<!-- Title -->
	<h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-6 leading-tight">
		<?php echo Theme::escape($page->title()); ?>
	</h1>

	<!-- Cover image -->
	<?php if ($page->coverImage()): ?>
	<figure class="mb-8 rounded-lg overflow-hidden">
		<img class="w-full h-auto" alt="<?php echo Theme::escape($page->title()); ?>" src="<?php echo Theme::escape($page->coverImage()); ?>" loading="eager" fetchpriority="high" decoding="async" data-brendy-eager />
	</figure>
	<?php endif ?>

	<!-- Full content -->
	<div class="prose-content text-gray-700 dark:text-gray-300 leading-relaxed">
		<?php echo $page->content(); ?>
	</div>

	<!-- Tags and Category -->
	<?php echo Theme::taxonomy($page, array(
		'wrapperTag' => 'footer',
		'wrapperClass' => 'flex flex-wrap gap-2 mt-8 pt-6 border-t border-gray-200 dark:border-gray-800',
		'categoryClass' => 'inline-block text-xs font-medium px-2.5 py-1 rounded-full bg-accent-100 text-accent-700 dark:bg-accent-900 dark:text-accent-300 hover:bg-accent-200 dark:hover:bg-accent-800 transition-colors no-underline',
		'tagClass' => 'inline-block text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors no-underline'
	)); ?>

	<!-- Back to home -->
	<?php if (!$page->isStatic()) : ?>
	<div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-800">
		<a href="<?php echo Theme::escape(Theme::siteUrl()); ?>" class="inline-flex items-center text-sm font-medium text-accent-600 dark:text-accent-400 hover:text-accent-700 dark:hover:text-accent-300 no-underline transition-colors">
				<i class="bi bi-house" aria-hidden="true"></i> <?php echo Theme::escape($L->get('Back to home')); ?>
		</a>
	</div>
	<?php endif ?>

	<?php echo Theme::brendyArticleTools($page); ?>

	<!-- Load Brendy Plugins: Page End -->
	<?php Theme::plugins('pageEnd'); ?>

</article>
<?php echo Theme::brendyRelatedContent($page); ?>
