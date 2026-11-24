<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::formOpen(array('id' => 'jsform', 'class' => 'tab-content')); ?>

<div class="align-middle brendy-form-header">
	<div class="float-end mt-1">
		<button type="submit" class="btn btn-primary btn-sm" name="save"><i class="bi bi-check2" aria-hidden="true"></i> <?php $L->p('Save') ?></button>
		<a class="btn btn-secondary btn-sm" href="<?php echo HTML_PATH_ADMIN_ROOT . 'dashboard' ?>" role="button"><i class="bi bi-x" aria-hidden="true"></i> <?php $L->p('Cancel') ?></a>
	</div>
	<?php echo BrendyAdminUi::pageTitle(array('title' => $L->g('Settings'), 'icon' => 'cog')); ?>
</div>

<!-- TABS -->
<nav class="mb-3">
	<div class="nav nav-tabs" id="nav-tab" role="tablist">
		<a class="nav-item nav-link active" id="nav-general-tab" data-bs-toggle="tab" href="#general" role="tab" aria-controls="general" aria-selected="true"><i class="bi bi-sliders" aria-hidden="true"></i> <?php $L->p('General') ?></a>
		<a class="nav-item nav-link" id="nav-appearance-tab" data-bs-toggle="tab" href="#appearance" role="tab" aria-controls="appearance" aria-selected="false"><i class="bi bi-palette" aria-hidden="true"></i> <?php $L->p('Appearance') ?></a>
		<a class="nav-item nav-link" id="nav-advanced-tab" data-bs-toggle="tab" href="#advanced" role="tab" aria-controls="advanced" aria-selected="false"><i class="bi bi-gear" aria-hidden="true"></i> <?php $L->p('Advanced') ?></a>
		<a class="nav-item nav-link" id="nav-seo-tab" data-bs-toggle="tab" href="#seo" role="tab" aria-controls="seo" aria-selected="false"><i class="bi bi-search" aria-hidden="true"></i> <?php $L->p('SEO') ?></a>
		<a class="nav-item nav-link" id="nav-social-tab" data-bs-toggle="tab" href="#social" role="tab" aria-controls="social" aria-selected="false"><i class="bi bi-share" aria-hidden="true"></i> <?php $L->p('Social Networks') ?></a>
		<a class="nav-item nav-link" id="nav-images-tab" data-bs-toggle="tab" href="#images" role="tab" aria-controls="images" aria-selected="false"><i class="bi bi-images" aria-hidden="true"></i> <?php $L->p('Images') ?></a>
		<a class="nav-item nav-link" id="nav-language-tab" data-bs-toggle="tab" href="#language" role="tab" aria-controls="language" aria-selected="false"><i class="bi bi-globe2" aria-hidden="true"></i> <?php $L->p('Language') ?></a>
		<a class="nav-item nav-link" id="nav-custom-fields-tab" data-bs-toggle="tab" href="#custom-fields" role="tab" aria-controls="custom-fields" aria-selected="false"><i class="bi bi-braces" aria-hidden="true"></i> <?php $L->p('Custom fields') ?></a>
		<a class="nav-item nav-link" id="nav-logo-tab" data-bs-toggle="tab" href="#logo" role="tab" aria-controls="logo" aria-selected="false"><i class="bi bi-badge-ad" aria-hidden="true"></i> <?php $L->p('Logo') ?></a>
	</div>
</nav>

<?php
// Token CSRF
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'tokenCSRF',
	'value' => $security->getTokenCSRF()
));
?>

<!-- General tab -->
<div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="nav-general-tab">

	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Site')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'title',
		'label' => $L->g('Site title'),
		'value' => $site->title(),
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('use-this-field-to-name-your-site')
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'slogan',
		'label' => $L->g('Site slogan'),
		'value' => $site->slogan(),
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('use-this-field-to-add-a-catchy-phrase')
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'description',
		'label' => $L->g('Site description'),
		'value' => $site->description(),
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('you-can-add-a-site-description-to-provide')
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'footer',
		'label' => $L->g('Footer text'),
		'value' => $site->footer(),
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('you-can-add-a-small-text-on-the-bottom')
	));
	?>
</div>

<!-- Appearance tab -->
<div class="tab-pane fade" id="appearance" role="tabpanel" aria-labelledby="nav-appearance-tab">
	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Theme appearance')));
	?>
	<div class="form-group row">
		<label for="jsaccentColor" class="col-sm-2 col-form-label"><?php $L->p('Accent color') ?></label>
		<div class="col-sm-10 d-flex align-items-center">
			<input id="jsaccentColorPicker" type="color" class="form-control p-1 me-2 brendy-accent-color-picker" value="<?php echo Theme::escape($site->accentColor()) ?>" aria-label="<?php echo Theme::escape($L->g('Choose accent color')) ?>">
			<input id="jsaccentColor" name="accentColor" type="text" class="form-control" maxlength="7" pattern="#[0-9A-Fa-f]{6}" value="<?php echo Theme::escape($site->accentColor()) ?>" required>
		</div>
	</div>
	<?php
	echo BrendyAdminUi::formSelect(array(
		'name' => 'colorScheme',
		'label' => $L->g('Color scheme'),
		'options' => array('auto' => $L->g('Follow device setting'), 'light' => $L->g('Light'), 'dark' => $L->g('Dark')),
		'selected' => $site->colorScheme(),
		'tip' => $L->g('Visitors can still switch the theme from the public page.')
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'contentWidth',
		'label' => $L->g('Content width'),
		'options' => array('compact' => $L->g('Compact'), 'wide' => $L->g('Wide'), 'full' => $L->g('Extra wide')),
		'selected' => $site->contentWidth(),
		'tip' => $L->g('Sets the maximum content width for all bundled themes.')
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'typographyScale',
		'label' => $L->g('Typography scale'),
		'options' => array('compact' => $L->g('Compact'), 'comfortable' => $L->g('Comfortable'), 'large' => $L->g('Large')),
		'selected' => $site->typographyScale(),
		'tip' => $L->g('Changes the base text size consistently in every bundled theme.')
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'cardStyle',
		'label' => $L->g('Card style'),
		'options' => array('elevated' => $L->g('Elevated'), 'outlined' => $L->g('Outlined'), 'flat' => $L->g('Flat')),
		'selected' => $site->cardStyle()
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'animationLevel',
		'label' => $L->g('Animation level'),
		'options' => array('full' => $L->g('Full'), 'subtle' => $L->g('Subtle'), 'off' => $L->g('Off')),
		'selected' => $site->animationLevel(),
		'tip' => $L->g('The reduced-motion device preference always takes priority.')
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'roundedCards',
		'label' => $L->g('Rounded cards'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => $site->roundedCards() ? 'true' : 'false'
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Visitor experience')));
	foreach (array(
		'showReadingProgress' => array($L->g('Reading progress'), $site->showReadingProgress()),
		'showBackToTop' => array($L->g('Back to top button'), $site->showBackToTop()),
		'showPostMeta' => array($L->g('Post metadata'), $site->showPostMeta()),
		'showReadingTime' => array($L->g('Reading time'), $site->showReadingTime()),
		'showArticleTools' => array($L->g('Article tools'), $site->showArticleTools()),
		'showRelatedContent' => array($L->g('Related content'), $site->showRelatedContent()),
		'enableImageLazyLoading' => array($L->g('Lazy-load images'), $site->enableImageLazyLoading())
	) as $field => $setting) {
		echo BrendyAdminUi::formSelect(array(
			'name' => $field,
			'label' => $setting[0],
			'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
			'selected' => $setting[1] ? 'true' : 'false'
		));
	}

	echo BrendyAdminUi::formSelect(array(
		'name' => 'readingProgressPosition',
		'label' => $L->g('Reading progress position'),
		'options' => array('top' => $L->g('Top'), 'bottom' => $L->g('Bottom')),
		'selected' => $site->readingProgressPosition(),
		'tip' => $L->g('Controls where the core reading progress bar is displayed.')
	));
	echo BrendyAdminUi::formInputText(array(
		'name' => 'readingProgressHeight',
		'label' => $L->g('Reading progress height'),
		'value' => $site->readingProgressHeight(),
		'type' => 'number',
		'min' => 2,
		'max' => 10,
		'inputmode' => 'numeric',
		'tip' => $L->g('Reading progress height in pixels.')
	));
	echo BrendyAdminUi::formInputText(array(
		'name' => 'readingProgressColor',
		'label' => $L->g('Reading progress color'),
		'value' => $site->readingProgressColor(),
		'type' => 'text',
		'maxlength' => 7,
		'placeholder' => '#2563eb',
		'tip' => $L->g('Use a six-digit hexadecimal color.')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Bundled theme options')));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'alternativaShowPostInformation',
		'label' => $L->g('Alternativa post information'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => $site->alternativaShowPostInformation() ? 'true' : 'false'
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'alternativaDateFormat',
		'label' => $L->g('Alternativa date format'),
		'options' => array('noshow' => $L->g('Do not show'), 'relative' => $L->g('Relative'), 'absolute' => $L->g('Absolute')),
		'selected' => $site->alternativaDateFormat()
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'fokusDarkMode',
		'label' => $L->g('Fokus force dark stylesheet'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => $site->fokusDarkMode() ? 'true' : 'false'
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'fokusDateFormat',
		'label' => $L->g('Fokus date format'),
		'options' => array('relative' => $L->g('Relative'), 'absolute' => $L->g('Absolute')),
		'selected' => $site->fokusDateFormat()
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'fokusShowTags',
		'label' => $L->g('Fokus show tags'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => $site->fokusShowTags() ? 'true' : 'false'
	));
	?>
	<div class="brendy-appearance-preview p-4 mt-4 border" data-brendy-appearance-preview style="--preview-accent:<?php echo Theme::escape($site->accentColor()) ?>">
		<div class="d-flex align-items-center mb-3"><i class="bi bi-stars me-2" aria-hidden="true"></i><strong><?php $L->p('Live style preview') ?></strong></div>
		<div class="brendy-appearance-preview__card">
			<h3 class="h5 mb-2"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i> <?php echo Theme::escape(BRENDY_NAME) ?></h3>
			<p class="mb-3"><?php $L->p('These settings apply consistently to every bundled public theme.') ?></p>
			<button class="btn btn-sm brendy-appearance-preview__button" type="button" tabindex="-1"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i> <?php echo Theme::escape(BRENDY_NAME) ?></button>
		</div>
	</div>
</div>

<!-- Advanced tab -->
<div class="tab-pane fade" id="advanced" role="tabpanel" aria-labelledby="nav-advanced-tab">
	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Content')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'itemsPerPage',
		'label' => $L->g('Items per page'),
		'value' => $site->itemsPerPage(),
		'type' => 'number',
		'min' => 1,
		'max' => 1000,
		'inputmode' => 'numeric',
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('Number of items to show per page')
	));

	echo BrendyAdminUi::formSelect(array(
		'name' => 'orderBy',
		'label' => $L->g('Order content by'),
		'options' => array('date' => $L->g('Date'), 'position' => $L->g('Position')),
		'selected' => $site->orderBy(),
		'class' => '',
		'tip' => $L->g('order-the-content-by-date-to-build-a-blog')
	));

	echo BrendyAdminUi::formSelect(array(
		'name' => 'defaultContentStatus',
		'label' => $L->g('Default content status'),
		'options' => array('published' => $L->g('Published'), 'draft' => $L->g('Draft')),
		'selected' => $site->defaultContentStatus(),
		'class' => '',
		'tip' => $L->g('default-status-for-new-content')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Predefined pages')));

	// Homepage
	try {
		$options = array();
		$homeKey = $site->homepage();
		if (is_string($homeKey) && $homeKey !== '') {
			$home = new Page($homeKey);
			$options = array($homeKey => $home->title());
		}
	} catch (Throwable $e) {
		// continue
	}
	echo BrendyAdminUi::formSelect(array(
		'name' => 'homepage',
		'label' => $L->g('Homepage'),
		'options' => $options,
		'selected' => false,
		'class' => '',
		'tip' => $L->g('Returning page for the main page')
	));
	?>
	<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
		$(function() {
			var homepage = $("#jshomepage").select2({
				placeholder: <?php echo json_encode($L->g('Start typing to see a list of suggestions.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>,
				allowClear: true,
				theme: "brendy5",
				minimumInputLength: 1,
				ajax: {
					url: HTML_PATH_ADMIN_ROOT + "ajax/get-published",
					data: function(params) {
						var query = {
							query: params.term
						}
						return query;
					},
					processResults: function(data) {
						return data;
					}
				}
			});
		});
	</script>

	<?php
	// Page not found 404
	try {
		$options = array();
		$pageNotFoundKey = $site->pageNotFound();
		if (is_string($pageNotFoundKey) && $pageNotFoundKey !== '') {
			$pageNotFound = new Page($pageNotFoundKey);
			$options = array($pageNotFoundKey => $pageNotFound->title());
		}
	} catch (Throwable $e) {
		// continue
	}
	echo BrendyAdminUi::formSelect(array(
		'name' => 'pageNotFound',
		'label' => $L->g('Page not found'),
		'options' => $options,
		'selected' => false,
		'class' => '',
		'tip' => $L->g('Returning page when the page doesnt exist')
	));
	?>

	<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
		$(function() {
			var homepage = $("#jspageNotFound").select2({
				placeholder: <?php echo json_encode($L->g('Start typing to see a list of suggestions.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>,
				allowClear: true,
				theme: "brendy5",
				minimumInputLength: 1,
				ajax: {
					url: HTML_PATH_ADMIN_ROOT + "ajax/get-published",
					data: function(params) {
						var query = {
							query: params.term
						}
						return query;
					},
					processResults: function(data) {
						return data;
					}
				}
			});
		});
	</script>

	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Maintenance mode')));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'maintenanceMode',
		'label' => $L->g('Maintenance mode'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => $site->maintenanceMode() ? 'true' : 'false',
		'tip' => $L->g('Temporarily return HTTP 503 for the public site while keeping the admin area available.')
	));
	echo BrendyAdminUi::formInputText(array(
		'name' => 'maintenanceMessage',
		'label' => $L->g('Message'),
		'value' => $site->maintenanceMessage(),
		'type' => 'text',
		'maxlength' => 1000,
		'tip' => $L->g('Public message displayed while maintenance mode is enabled.')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Email account settings')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'emailFrom',
		'label' => $L->g('Sender email'),
		'value' => $site->emailFrom(),
		'type' => 'email',
		'autocomplete' => 'email',
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('Emails will be sent from this address')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Autosave')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'autosaveInterval',
		'label' => $L->g('Interval'),
		'value' => $site->autosaveInterval(),
		'type' => 'number',
		'min' => 1,
		'max' => 1440,
		'inputmode' => 'numeric',
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('Number in minutes for every execution of autosave')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Site URL')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'url',
		'label' => 'URL',
		'value' => $site->url(),
		'type' => 'url',
		'autocomplete' => 'url',
		'class' => '',
		'tip' => $L->g('full-url-of-your-site'),
		'placeholder' => 'https://'
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Page content')));

	echo BrendyAdminUi::formSelect(array(
		'name' => 'markdownParser',
		'label' => $L->g('Markdown parser'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => ($site->markdownParser() ? 'true' : 'false'),
		'class' => '',
		'tip' => $L->g('Enable the markdown parser for the content of the page.')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('URL Filters')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'uriPage',
		'label' => $L->g('Pages'),
		'value' => $site->uriFilters('page'),
		'class' => '',
		'placeholder' => '',
		'tip' => DOMAIN_PAGES
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'uriTag',
		'label' => $L->g('Tags'),
		'value' => $site->uriFilters('tag'),
		'class' => '',
		'placeholder' => '',
		'tip' => DOMAIN_TAGS
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'uriCategory',
		'label' => $L->g('Category'),
		'value' => $site->uriFilters('category'),
		'class' => '',
		'placeholder' => '',
		'tip' => DOMAIN_CATEGORIES
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'uriBlog',
		'label' => $L->g('Blog'),
		'value' => $site->uriFilters('blog'),
		'class' => '',
		'placeholder' => '',
		'tip' => DOMAIN . $site->uriFilters('blog'),
		'disabled' => Text::isEmpty($site->uriFilters('blog'))
	));
	?>
</div>

<!-- SEO tab -->
<div class="tab-pane fade" id="seo" role="tabpanel" aria-labelledby="nav-seo-tab">
	<?php
	// Count only publicly accessible records, without opening page content or
	// contacting external indexing services. These are local eligibility metrics.
	$seoKeys = $pages->getPublicList(1, -1, true, true, true);
	$seoIndexable = $seoExcluded = $seoMissingDescription = 0;
	foreach (is_array($seoKeys) ? $seoKeys : array() as $seoKey) {
		$seoRow = $pages->getPageDB($seoKey);
		if (!is_array($seoRow)) continue;
		if (!empty($seoRow['noindex'])) { $seoExcluded++; continue; }
		$seoIndexable++;
		if (!Theme::hasText($seoRow['description'] ?? '')) $seoMissingDescription++;
	}
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Local SEO overview')));
	$seoMetrics = array('Indexable public content' => $seoIndexable,
		'Public content with noindex' => $seoExcluded, 'Indexable content without a manual description' => $seoMissingDescription);
	echo '<div class="row mb-3">';
	foreach ($seoMetrics as $seoLabel => $seoCount) {
		echo '<div class="col-md-4 mb-2"><div class="border rounded p-3 h-100"><strong class="d-block h4 mb-1">' . (int)$seoCount . '</strong><span>' . Theme::escape($L->g($seoLabel)) . '</span></div></div>';
	}
	echo '</div><p class="text-muted small">' . Theme::escape($L->g('Local SEO counts are not confirmed search engine index counts.')) . '</p>';
	echo '<p><a href="' . Theme::escape(DOMAIN_BASE . 'robots.txt') . '" target="_blank" rel="noopener noreferrer">robots.txt</a>';
	$seoSitemap = Theme::sitemapUrl();
	if ($seoSitemap !== false) echo ' · <a href="' . Theme::escape($seoSitemap) . '" target="_blank" rel="noopener noreferrer">sitemap.xml</a>';
	else echo ' · ' . Theme::escape($L->g('Sitemap plugin is not enabled.'));
	echo '</p>';

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Extreme friendly URL')));

	echo BrendyAdminUi::formSelect(array(
		'name' => 'extremeFriendly',
		'label' => $L->g('Allow Unicode'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => ($site->extremeFriendly() ? 'true' : 'false'),
		'class' => '',
		'tip' => $L->g('Allow unicode characters in the URL and some part of the system.')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Title formats')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'titleFormatHomepage',
		'label' => $L->g('Homepage'),
		'value' => $site->titleFormatHomepage(),
		'class' => '',
		'tip' => $L->g('Variables allowed'),
		'tipCode' => array('{{site-title}}', '{{site-slogan}}', '{{site-description}}'),
		'placeholder' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'titleFormatPages',
		'label' => $L->g('Pages'),
		'value' => $site->titleFormatPages(),
		'class' => '',
		'tip' => $L->g('Variables allowed'),
		'tipCode' => array('{{page-title}}', '{{page-description}}', '{{site-title}}', '{{site-slogan}}', '{{site-description}}'),
		'placeholder' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'titleFormatCategory',
		'label' => $L->g('Category'),
		'value' => $site->titleFormatCategory(),
		'class' => '',
		'tip' => $L->g('Variables allowed'),
		'tipCode' => array('{{category-name}}', '{{site-title}}', '{{site-slogan}}', '{{site-description}}'),
		'placeholder' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'titleFormatTag',
		'label' => $L->g('Tag'),
		'value' => $site->titleFormatTag(),
		'class' => '',
		'tip' => $L->g('Variables allowed'),
		'tipCode' => array('{{tag-name}}', '{{site-title}}', '{{site-slogan}}', '{{site-description}}'),
		'placeholder' => ''
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Social sharing')));
	echo BrendyAdminUi::formInputText(array(
		'name' => 'seoSocialImage',
		'label' => $L->g('Default social image'),
		'value' => $site->seoSocialImage(),
		'type' => 'text',
		'placeholder' => 'https://example.com/image.jpg',
		'tip' => $L->g('Used as a safe fallback for Open Graph, Twitter Cards and structured data.')
	));
	echo BrendyAdminUi::formInputText(array(
		'name' => 'seoFacebookAppId',
		'label' => $L->g('Facebook App ID'),
		'value' => $site->seoFacebookAppId(),
		'type' => 'text',
		'inputmode' => 'numeric',
		'placeholder' => '',
		'tip' => $L->g('Optional numeric Facebook application identifier.')
	));
	echo BrendyAdminUi::formInputText(array(
		'name' => 'seoTwitterSite',
		'label' => $L->g('Twitter/X site handle'),
		'value' => $site->seoTwitterSite(),
		'type' => 'text',
		'placeholder' => '@username',
		'tip' => $L->g('Optional site account used in Twitter/X card metadata.')
	));
	echo BrendyAdminUi::formSelect(array(
		'name' => 'seoTwitterCard',
		'label' => $L->g('Twitter/X card type'),
		'options' => array('summary_large_image' => $L->g('Summary with large image'), 'summary' => $L->g('Summary')),
		'selected' => $site->seoTwitterCard(),
		'tip' => $L->g('Large-image cards automatically fall back to Summary when no safe image exists.')
	));
	echo BrendyAdminUi::formTextarea(array(
		'name' => 'robotsTxt',
		'label' => $L->g('Robots.txt'),
		'value' => $site->robotsTxt(),
		'rows' => 7,
		'placeholder' => "User-agent: *\nAllow: /",
		'tip' => $L->g('Core serves this content at /robots.txt and adds the sitemap URL automatically when Sitemap is enabled.')
	));

	?>
</div>

<!-- Social Network tab -->
<div class="tab-pane fade" id="social" role="tabpanel" aria-labelledby="nav-social-tab">
	<?php
	echo BrendyAdminUi::formInputText(array(
		'name' => 'twitter',
		'label' => 'Twitter',
		'value' => $site->twitter(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'facebook',
		'label' => 'Facebook',
		'value' => $site->facebook(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'codepen',
		'label' => 'CodePen',
		'value' => $site->codepen(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'instagram',
		'label' => 'Instagram',
		'value' => $site->instagram(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'gitlab',
		'label' => 'GitLab',
		'value' => $site->gitlab(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'github',
		'label' => 'GitHub',
		'value' => $site->github(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'linkedin',
		'label' => 'LinkedIn',
		'value' => $site->linkedin(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'xing',
		'label' => 'Xing',
		'value' => $site->xing(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'telegram',
		'label' => 'Telegram',
		'value' => $site->telegram(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'mastodon',
		'label' => 'Mastodon',
		'value' => $site->mastodon(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'dribbble',
		'label' => 'Dribbble',
		'value' => $site->dribbble(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'vk',
		'label' => 'VK',
		'value' => $site->vk(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'youtube',
		'label' => 'Youtube',
		'value' => $site->youtube(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'bluesky',
		'label' => 'Bluesky',
		'value' => $site->bluesky(),
		'class' => '',
		'placeholder' => '',
		'tip' => ''
	));
	?>
</div>

<!-- Images tab -->
<div class="tab-pane fade" id="images" role="tabpanel" aria-labelledby="nav-images-tab">
	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Thumbnails')));

	echo BrendyAdminUi::formSelect(array(
		'name' => 'thumbnailEnable',
		'label' => $L->g('Thumbnail generation'),
		'options' => array('true' => $L->g('Enabled'), 'false' => $L->g('Disabled')),
		'selected' => ($site->thumbnailEnable() ? 'true' : 'false'),
		'class' => '',
		'tip' => $L->g('Enable or disable automatic thumbnail generation on image upload.')
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'thumbnailWidth',
		'label' => $L->g('Width'),
		'value' => $site->thumbnailWidth(),
		'type' => 'number',
		'min' => 16,
		'max' => 8192,
		'inputmode' => 'numeric',
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('Thumbnail width in pixels')
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'thumbnailHeight',
		'label' => $L->g('Height'),
		'value' => $site->thumbnailHeight(),
		'type' => 'number',
		'min' => 16,
		'max' => 8192,
		'inputmode' => 'numeric',
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('Thumbnail height in pixels')
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'thumbnailQuality',
		'label' => $L->g('Quality'),
		'value' => $site->thumbnailQuality(),
		'type' => 'number',
		'min' => 0,
		'max' => 100,
		'inputmode' => 'numeric',
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('Thumbnail quality in percentage')
	));
	?>
</div>

<!-- Timezone and language tab -->
<div class="tab-pane fade" id="language" role="tabpanel" aria-labelledby="nav-language-tab">
	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Language and timezone')));

	echo BrendyAdminUi::formSelect(array(
		'name' => 'language',
		'label' => $L->g('Language'),
		'options' => $L->getLanguageList(),
		'selected' => $site->language(),
		'class' => '',
		'tip' => $L->g('select-your-sites-language')
	));

	echo BrendyAdminUi::formSelect(array(
		'name' => 'timezone',
		'label' => $L->g('Timezone'),
		'options' => Date::timezoneList(),
		'selected' => $site->timezone(),
		'class' => '',
		'tip' => $L->g('select-a-timezone-for-a-correct')
	));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'locale',
		'label' => $L->g('Locale'),
		'value' => $site->locale(),
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('with-the-locales-you-can-set-the-regional-user-interface')
	));

	echo BrendyAdminUi::formTitle(array('title' => $L->g('Date and time formats')));

	echo BrendyAdminUi::formInputText(array(
		'name' => 'dateFormat',
		'label' => $L->g('Date format'),
		'value' => $site->dateFormat(),
		'class' => '',
		'placeholder' => '',
		'tip' => $L->g('Current format') . ': ' . Date::current($site->dateFormat())
	));
	?>
</div>

<!-- Custom fields -->
<div class="tab-pane fade" id="custom-fields" role="tabpanel" aria-labelledby="nav-custom-fields-tab">
	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Custom fields')));

	echo BrendyAdminUi::formTextarea(array(
		'name' => 'customFields',
		'label' => 'JSON Format',
		'value' => json_encode($site->customFields(), JSON_PRETTY_PRINT),
		'class' => '',
		'placeholder' => '',
		'tip' => Sanitize::removeTags($L->g('define-custom-fields-for-the-content')),
		'rows' => 15
	));
	?>
</div>

<!-- Site logo tab -->
<div class="tab-pane fade" id="logo" role="tabpanel" aria-labelledby="nav-logo-tab">
	<?php
	echo BrendyAdminUi::formTitle(array('title' => $L->g('Site logo')));
	?>

	<div class="container">
		<div class="row">
			<div class="col-lg-4 col-sm-12 p-0 pe-2">
				<div class="mb-3">
					<label for="jssiteLogoInputFile" class="form-label"><?php $L->p('Upload image'); ?></label>
					<input id="jssiteLogoInputFile" class="form-control" type="file" name="inputFile">
				</div>
				<button id="jsbuttonRemoveLogo" type="button" class="btn btn-primary w-100 mt-4 mb-4"><i class="bi bi-trash" aria-hidden="true"></i> <?php $L->p('Remove logo') ?></button>
			</div>
			<div class="col-lg-8 col-sm-12 p-0 text-center">
				<img id="jssiteLogoPreview" class="img-fluid img-thumbnail" alt="<?php echo Theme::escape($L->g('Site logo preview')) ?>" src="<?php echo Sanitize::html($site->logo() ?: HTML_PATH_CORE_IMG . 'default.svg') ?>" />
			</div>
		</div>
	</div>
	<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
		$("#jsbuttonRemoveLogo").on("click", async function() {
			const button = $(this);
			button.prop("disabled", true);
			const result = await brendyAjax.removeLogo();
			button.prop("disabled", false);
			if (result && result.status === 0) {
				$("#jssiteLogoPreview").attr("src", "<?php echo HTML_PATH_CORE_IMG . 'default.svg' ?>");
			} else {
				showAlert((result && result.message) ? result.message : "<?php echo Theme::escape($L->g('Request failed')) ?>");
			}
		});

		$("#jssiteLogoInputFile").on("change", function() {
			var formData = new FormData();
			formData.append('tokenCSRF', tokenCSRF);
			formData.append('inputFile', $(this)[0].files[0]);
			$.ajax({
				url: HTML_PATH_ADMIN_ROOT + "ajax/logo-upload",
				type: "POST",
				data: formData,
				cache: false,
				contentType: false,
				processData: false
			}).done(function(data) {
				if (data.status === 0) {
					$("#jssiteLogoPreview").attr('src', data.absoluteURL);
				} else {
					showAlert(data.message);
				}
			}).fail(function() {
				showAlert("<?php echo Theme::escape($L->g('Request failed')) ?>");
			});
		});
	</script>
</div>

<?php echo BrendyAdminUi::formClose(); ?>

<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
	// Open current tab after refresh page
	$(function() {
		const tabKey = 'brendy:activeTab:settings;
		const tabs = Array.from(document.querySelectorAll('#nav-tab a[data-bs-toggle="tab"]'));
		tabs.forEach(function(tab) {
			tab.addEventListener('shown.bs.tab', function() {
				try { window.localStorage.setItem(tabKey, tab.getAttribute('href')); } catch (_) {}
			});
		});
		try {
			const saved = window.localStorage.getItem(tabKey);
			const selected = tabs.find(function(tab) { return tab.getAttribute('href') === saved; });
			if (selected && window.bootstrap) bootstrap.Tab.getOrCreateInstance(selected).show();
		} catch (_) {}
	});
</script>
