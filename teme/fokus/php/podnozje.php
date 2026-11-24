<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<footer class="footer p-3 p-md-5 mt-5 text-center">
	<div class="container">
		<ul class="footer-links ps-0 mb-1">
			<?php foreach (Theme::socialNetworks() as $key => $name) {
				echo '<li class="d-inline-block pe-4"><a class="color-blue brendy-icon-label" target="_blank" rel="noopener noreferrer" href="' . Theme::escape($site->{$key}()) . '"><i class="bi ' . Theme::escape(Theme::socialIcon($key)) . '" aria-hidden="true"></i>' . Theme::escape($name) . '</a></li>';
			}
			?>
		</ul>
		<?php echo Theme::brandSignature('p', 'm-0 mt-2', 'color-blue'); ?>
	</div>
</footer>
