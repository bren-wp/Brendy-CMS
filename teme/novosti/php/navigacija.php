<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<nav class="navbar-news" aria-label="<?php echo Theme::escape($L->get('Main navigation')); ?>">
	<div class="container">
		<button class="navbar-news__toggle" type="button" data-brendy-collapse-target="#sections" aria-expanded="false" aria-controls="sections" aria-label="<?php echo Theme::escape($L->get('Toggle sections')); ?>">
			<i class="bi bi-list" aria-hidden="true"></i>
		</button>
		<ul class="navbar-news__list" id="sections">

			<li class="navbar-news__item">
				<a class="navbar-news__link<?php echo ($WHERE_AM_I === 'home' || $WHERE_AM_I === 'blog') ? ' is-active' : '' ?>"<?php echo ($WHERE_AM_I === 'home' || $WHERE_AM_I === 'blog') ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape(Theme::siteUrl()) ?>">
					<i class="bi bi-house-door" aria-hidden="true"></i> <?php echo Theme::escape($L->get('Home')) ?>
				</a>
			</li>

			<?php
				// Categories are a derived index. Build navigation through the hardened
				// Category model so stale/hidden page references do not create ghost sections.
				global $categories;
				$newsCategories = array();
				$categoryDb = is_object($categories) && method_exists($categories, 'getDB') ? $categories->getDB() : array();
				if (is_array($categoryDb)) {
					foreach (array_keys($categoryDb) as $catKey) {
						if (!is_string($catKey)) continue;
						try {
							$category = new Category($catKey);
							if (!empty($category->pages())) $newsCategories[] = $category;
						} catch (Throwable $e) {
							// Corrupt taxonomy rows are reported by System Health, not public navigation.
						}
					}
				}
				foreach ($newsCategories as $category) :
					$catKey = $category->key();
					$isActive = ($WHERE_AM_I === 'category' && isset($url) && $url->slug() === $catKey);
			?>
				<li class="navbar-news__item">
					<a class="navbar-news__link<?php echo $isActive ? ' is-active' : '' ?>"<?php echo $isActive ? ' aria-current="page"' : '' ?> href="<?php echo Theme::escape($category->permalink()) ?>">
						<?php echo Theme::escape($category->name()) ?>
					</a>
				</li>
			<?php endforeach ?>

		</ul>
	</div>
</nav>
