<?php defined('BRENDY') or die('Brendy CMS.');
	// Hero = first item; everything else as compact rows with excerpt
	$hero      = !empty($content) ? $content[0] : null;
	$restItems = !empty($content) ? array_slice($content, 1) : array();
?>

<?php echo Theme::archiveHeading(); ?>

<?php if (empty($content)) : ?>
		<div class="empty-state brendy-empty-state">
			<i class="bi bi-newspaper" aria-hidden="true"></i><?php $language->p('No pages found') ?>
	</div>
<?php else : ?>

	<!-- Hero story -->
	<?php Theme::pageHook('pageBegin', $hero); ?>
		<article class="hero-story brendy-card <?php echo $hero->coverImage() ? 'hero-story--has-image' : 'hero-story--no-image' ?>">
		<?php if ($hero->coverImage()) : ?>
			<a class="hero-story__media" href="<?php echo Theme::escape($hero->permalink()) ?>" aria-label="<?php echo Theme::escape($hero->title()) ?>">
				<img src="<?php echo Theme::escape($hero->coverImage()) ?>" alt="<?php echo Theme::escape($hero->title()) ?>" loading="eager" fetchpriority="high" decoding="async" data-brendy-eager>
			</a>
		<?php endif ?>
		<div class="hero-story__body">
			<?php if ($hero->categoryKey() !== false) : ?>
				<a class="eyebrow" href="<?php echo Theme::escape($hero->categoryPermalink()) ?>">
					<?php echo Theme::escape($hero->category()) ?>
				</a>
			<?php endif ?>
			<h2 class="hero-story__title">
				<a href="<?php echo Theme::escape($hero->permalink()) ?>"><?php echo Theme::escape($hero->title()) ?></a>
			</h2>
			<?php if ($hero->contentBreak()) : ?>
				<div class="hero-story__lede"><?php echo $hero->contentBreak() ?></div>
			<?php elseif (Theme::hasText($hero->description())) : ?>
				<p class="hero-story__lede"><?php echo Theme::escape($hero->description()) ?></p>
			<?php endif ?>
			<div class="meta brendy-meta">
				<time datetime="<?php echo Theme::escape($hero->date('c')) ?>"><?php echo Theme::escape($hero->date($site->dateFormat() . ' · ' . $site->timeFormat())) ?></time>
			</div>
		</div>
	</article>
	<?php Theme::pageHook('pageEnd', $hero); ?>

	<!-- Compact list with excerpt -->
	<?php if (!empty($restItems)) : ?>
		<div class="card-list">
			<h2 class="section-heading"><i class="bi bi-lightning-charge" aria-hidden="true"></i> <?php echo Theme::escape($L->get('Latest news')) ?></h2>
			<?php foreach ($restItems as $item) : ?>
				<?php Theme::pageHook('pageBegin', $item); ?>
					<article class="news-row brendy-card">
					<?php if ($item->coverImage()) : ?>
						<a class="news-row__media" href="<?php echo Theme::escape($item->permalink()) ?>" aria-label="<?php echo Theme::escape($item->title()) ?>">
							<img src="<?php echo Theme::escape($item->coverImage()) ?>" alt="<?php echo Theme::escape($item->title()) ?>" loading="lazy" decoding="async">
						</a>
					<?php endif ?>
					<div class="news-row__body">
						<?php if ($item->categoryKey() !== false) : ?>
							<a class="eyebrow eyebrow--small" href="<?php echo Theme::escape($item->categoryPermalink()) ?>">
								<?php echo Theme::escape($item->category()) ?>
							</a>
						<?php endif ?>
						<h2 class="news-row__title">
							<a href="<?php echo Theme::escape($item->permalink()) ?>"><?php echo Theme::escape($item->title()) ?></a>
						</h2>
						<div class="news-row__excerpt">
							<?php echo $item->contentBreak() ?>
						</div>
						<div class="meta meta--small brendy-meta">
							<time datetime="<?php echo Theme::escape($item->date('c')) ?>"><?php echo Theme::escape($item->date($site->dateFormat() . ' · ' . $site->timeFormat())) ?></time>
							<?php if ($item->readMore()) : ?>
								<span class="meta__dot"></span>
								<a class="news-row__more" href="<?php echo Theme::escape($item->permalink()) ?>">
									<?php echo Theme::escape($L->get('Read more')) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
								</a>
							<?php endif ?>
						</div>
					</div>
				</article>
				<?php Theme::pageHook('pageEnd', $item); ?>
			<?php endforeach ?>
		</div>
	<?php endif ?>

<?php endif ?>

<!-- Pagination -->
<?php $pager = Theme::pagerData(); ?>
<?php if ($pager['visible']) : ?>
	<nav class="paginator" aria-label="<?php echo Theme::escape($L->get('Pagination')); ?>">
		<?php if ($pager['hasPrevious']) : ?>
			<a class="paginator__btn" href="<?php echo Theme::escape($pager['previousUrl']) ?>" rel="prev" aria-label="<?php echo Theme::escape($L->get('Previous')) ?>">
				<i class="bi bi-chevron-left" aria-hidden="true"></i> <?php echo Theme::escape($L->get('Previous')) ?>
			</a>
		<?php endif ?>
		<span class="paginator__pos"><?php echo (int)$pager['current'] ?> / <?php echo (int)$pager['total'] ?></span>
		<?php if ($pager['hasNext']) : ?>
			<a class="paginator__btn" href="<?php echo Theme::escape($pager['nextUrl']) ?>" rel="next" aria-label="<?php echo Theme::escape($L->get('Next')) ?>">
				<?php echo Theme::escape($L->get('Next')) ?> <i class="bi bi-chevron-right" aria-hidden="true"></i>
			</a>
		<?php endif ?>
	</nav>
<?php endif ?>
