<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::pageTitle(array('title' => $L->g('API access'), 'icon' => 'exchange')); ?>

<div class="alert alert-warning" role="alert">
	<?php echo Sanitize::html($L->g('Generating API credentials invalidates the previous dedicated API credentials and reveals the new secrets only once')) ?>
</div>

<?php echo BrendyAdminUi::formOpen(array('id' => 'js-enable-api')); ?>
<?php echo BrendyAdminUi::formInputHidden(array('name' => 'tokenCSRF', 'value' => $security->getTokenCSRF())); ?>
<?php
echo BrendyAdminUi::formSelect(array(
	'name' => 'expiryDays',
	'label' => $L->g('Credential lifetime'),
	'options' => array(
		'30' => $L->g('30 days'),
		'90' => $L->g('90 days'),
		'180' => $L->g('180 days'),
		'365' => $L->g('365 days'),
		'0' => $L->g('No automatic expiration')
	),
	'selected' => '90',
	'class' => '',
	'tip' => $L->g('Expiring credentials reduce the useful lifetime of a leaked API secret')
));
?>
<button type="submit" class="btn btn-primary"><?php $L->p('Generate new API credentials') ?></button>
<?php echo BrendyAdminUi::formClose(); ?>
