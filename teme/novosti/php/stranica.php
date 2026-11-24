<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<article class="article brendy-card" data-brendy-reading-scope>

	<?php Theme::plugins('pageBegin'); ?>

	<header class="article__header">
		<?php if ($page->categoryKey() !== false) : ?>
			<a class="eyebrow" href="<?php echo Theme::escape($page->categoryPermalink()) ?>">
				<?php echo Theme::escape($page->category()) ?>
			</a>
		<?php endif ?>

		<h1 class="article__title"><?php echo Theme::escape($page->title()) ?></h1>

		<?php if (Theme::hasText($page->description())) : ?>
			<p class="article__lede"><?php echo Theme::escape($page->description()) ?></p>
		<?php endif ?>

		<?php if (!$page->isStatic() && !$url->notFound()) : ?>
			<div class="article__meta brendy-meta">
				<span class="article__byline"><i class="bi bi-person" aria-hidden="true"></i> <?php echo Theme::escape($page->user('nickname') ?: $page->user('username')) ?></span>
				<span class="meta__dot"></span>
				<time datetime="<?php echo Theme::escape($page->date('c')) ?>"><?php echo Theme::escape($page->date($site->dateFormat() . ' · ' . $site->timeFormat())) ?></time>
				<span class="meta__dot"></span>
				<span><?php echo Theme::escape($L->get('Reading time') . ' ' . $page->readingTime()) ?></span>
			</div>
		<?php endif ?>
	</header>

	<?php if ($page->coverImage()) : ?>
		<figure class="article__cover">
			<img src="<?php echo Theme::escape($page->coverImage()) ?>" alt="<?php echo Theme::escape($page->title()) ?>" loading="eager" fetchpriority="high" decoding="async" data-brendy-eager>
		</figure>
	<?php endif ?>

	<div class="article__body">
		<?php echo $page->content() ?>
	</div>

	<?php $tagsList = $page->tags(true); ?>
	<?php if (!empty($tagsList)) : ?>
		<footer class="article__footer">
			<div class="article__tags">
				<span class="article__tags-label"><?php echo Theme::escape($L->get('Tags')) ?></span>
				<?php foreach ($tagsList as $tagKey => $tagName) : ?>
					<a class="tag-chip" href="<?php echo Theme::escape(DOMAIN_TAGS . rawurlencode($tagKey)) ?>">#<?php echo Theme::escape($tagName) ?></a>
				<?php endforeach ?>
			</div>
		</footer>
	<?php endif ?>

	<?php echo Theme::brendyArticleTools($page); ?>

	<?php Theme::plugins('pageEnd'); ?>

</article>
<?php echo Theme::brendyRelatedContent($page); ?>
