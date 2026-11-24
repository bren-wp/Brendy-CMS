<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::formOpen(array('id'=>'jsform', 'class'=>'tab-content')); ?>

<div class="align-middle brendy-form-header">
	<div class="float-end mt-1">
		<button type="submit" class="btn btn-primary btn-sm" name="save"><?php $L->p('Save') ?></button>
		<a class="btn btn-secondary btn-sm" href="<?php echo Theme::escape(HTML_PATH_ADMIN_ROOT . 'edit-user/' . rawurlencode($user->username())) ?>" role="button"><?php $L->p('Cancel') ?></a>
	</div>
	<?php echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Change password'), 'icon'=>'user')); ?>
</div>

<?php
	// Token CSRF
	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'tokenCSRF',
		'value'=>$security->getTokenCSRF()
	));

	// Username
	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'username',
		'value'=>$user->username()
	));

	// Username disabled
	echo BrendyAdminUi::formInputText(array(
		'name'=>'usernameDisabled',
		'label'=>$L->g('Username'),
		'value'=>$user->username(),
		'class'=>'',
		'placeholder'=>'',
		'disabled'=>true,
		'tip'=>''
	));

	// New password
	if ($user->username() === $login->username()) {
		echo BrendyAdminUi::formInputText(array(
			'name'=>'currentPassword',
			'label'=>$L->g('Current password'),
			'type'=>'password',
			'value'=>'',
			'class'=>'',
			'placeholder'=>'',
			'autocomplete'=>'current-password',
			'maxlength'=>4096,
			'required'=>true,
			'tip'=>$L->g('Required when changing your own password')
		));
	}

	// New password
	echo BrendyAdminUi::formInputText(array(
		'name'=>'newPassword',
		'label'=>$L->g('New password'),
		'type'=>'password',
		'value'=>'',
		'class'=>'',
		'placeholder'=>'',
		'autocomplete'=>'new-password',
		'minlength'=>PASSWORD_LENGTH,
		'maxlength'=>PASSWORD_MAX_LENGTH,
		'required'=>true,
		'tip'=>sprintf($L->g('Use a unique long password or passphrase. The current and last %d passwords cannot be reused.'), PASSWORD_HISTORY_LIMIT)
	));

	// Confirm password
	echo BrendyAdminUi::formInputText(array(
		'name'=>'confirmPassword',
		'label'=>$L->g('Confirm new password'),
		'type'=>'password',
		'value'=>'',
		'class'=>'',
		'placeholder'=>'',
		'autocomplete'=>'new-password',
		'minlength'=>PASSWORD_LENGTH,
		'maxlength'=>PASSWORD_MAX_LENGTH,
		'required'=>true,
		'tip'=>$L->g('Repeat the new password exactly')
	));
?>

<?php echo BrendyAdminUi::formClose(); ?>
