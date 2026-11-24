<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<?php echo Theme::archiveHeading(); ?>
<?php if (empty($content)) echo Theme::emptyState('No pages found', 'bi-inbox', 'mt-4'); ?>

<?php foreach ($content as $item) : ?>
  <!-- Post -->
	  <article class="card card-modern brendy-card my-5">

    <!-- Load Brendy Plugins: Page Begin -->
    <?php Theme::pageHook('pageBegin', $item); ?>

    <!-- Cover image with gradient overlay -->
    <?php if ($item->coverImage()) : ?>
      <div class="cover-image-wrapper">
        <img class="card-img-top" alt="<?php echo Theme::escape($item->title()); ?>" src="<?php echo Theme::escape($item->coverImage()); ?>" loading="lazy" decoding="async" />
      </div>
    <?php endif ?>

    <div class="card-body">
      <!-- Title -->
      <a href="<?php echo Theme::escape($item->permalink()); ?>">
        <h2 class="title"><?php echo Theme::escape($item->title()); ?></h2>
      </a>

      <!-- Creation date and reading time -->
	      <div class="metadata brendy-meta mb-4">
        <span><i class="bi bi-calendar" aria-hidden="true"></i><time datetime="<?php echo Theme::escape($item->date('c')); ?>"><?php echo Theme::escape($item->date()); ?></time></span>
        <span><i class="bi bi-clock-history" aria-hidden="true"></i><?php echo Theme::escape($L->get('Reading time') . ': ' . $item->readingTime()); ?></span>
	  </div>

      <!-- Breaked content -->
      <?php echo $item->contentBreak(); ?>

      <!-- "Read more" button -->
      <?php if ($item->readMore()) : ?>
        <a class="btn-primary-gradient mt-3" href="<?php echo Theme::escape($item->permalink()); ?>">
          <?php echo Theme::escape($L->get('Read more')); ?>
          <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
      <?php endif ?>

      <!-- Tags and Category -->
      <?php echo Theme::taxonomy($item, array('wrapperClass'=>'post-taxonomy mt-3', 'categoryClass'=>'taxonomy-badge taxonomy-category', 'tagClass'=>'taxonomy-badge taxonomy-tag', 'categoryIcon'=>'bi-folder', 'tagIcon'=>'bi-tag')); ?>

    </div>

    <!-- Load Brendy Plugins: Page End -->
    <?php Theme::pageHook('pageEnd', $item); ?>

  </article>
<?php endforeach ?>

<!-- Pagination -->
<?php echo Theme::pagination('mt-5'); ?>
