<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<footer class="border-t border-gray-200 dark:border-gray-800 mt-8">
	<div class="max-w-2xl mx-auto px-4 py-8">
		<div class="flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-gray-500 dark:text-gray-400">
			<p class="m-0"><?php echo Theme::escape($site->footer()); ?></p>
			<?php echo Theme::brandSignature('p', 'm-0', 'text-accent-600 dark:text-accent-400 hover:text-accent-700 dark:hover:text-accent-300 no-underline font-medium'); ?>
		</div>
	</div>
</footer>
