<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<header class="masthead">
	<div class="container masthead__inner">
		<div class="masthead__date">
			<?php echo Theme::escape(Date::current('l, F j, Y')); ?>
		</div>
		<a class="masthead__brand" href="<?php echo Theme::escape(Theme::siteUrl()) ?>">
			<?php if ($site->logo()) : ?>
				<img class="masthead__logo" src="<?php echo Theme::escape($site->logo()) ?>" alt="<?php echo Theme::escape($site->title()) ?>" loading="eager" fetchpriority="high" decoding="async" data-brendy-eager>
			<?php else : ?>
				<span class="masthead__title"><?php echo Theme::escape($site->title()) ?></span>
			<?php endif ?>
		</a>
		<div class="masthead__social">
			<?php foreach (Theme::socialNetworks() as $key => $label) : ?>
				<a class="masthead__social-link" href="<?php echo Theme::escape($site->{$key}()) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo Theme::escape($label) ?>">
					<i class="bi <?php echo Theme::escape(Theme::socialIcon($key)) ?>" aria-hidden="true"></i>
				</a>
			<?php endforeach ?>
		</div>
	</div>
</header>
