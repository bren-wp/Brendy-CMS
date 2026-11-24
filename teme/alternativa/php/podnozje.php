<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<footer class="footer bg-dark" role="contentinfo">
	<div class="container">
		<div class="d-flex flex-column flex-md-row justify-content-center align-items-center text-center text-white text-uppercase">
			<span class="mb-2 mb-md-0"><?php echo Theme::escape($site->footer()); ?></span>
			<span class="ms-md-4 text-warning">
				<img class="mini-logo" src="<?php echo Theme::escape(Theme::coreImage('favicon.png')); ?>" alt="<?php echo Theme::escape(BRENDY_NAME . ' logo'); ?>" loading="lazy" decoding="async" />
				<?php echo Theme::brandSignature('span', '', 'text-white'); ?>
			</span>
		</div>
	</div>
</footer>
