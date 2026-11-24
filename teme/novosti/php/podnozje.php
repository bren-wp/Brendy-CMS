<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<footer class="site-footer">
	<div class="container site-footer__inner">
		<div class="site-footer__left">
			<p class="site-footer__copy"><?php echo Theme::escape($site->footer()) ?></p>
		</div>
		<div class="site-footer__right">
			<?php echo Theme::brandSignature('p'); ?>
		</div>
	</div>
</footer>
