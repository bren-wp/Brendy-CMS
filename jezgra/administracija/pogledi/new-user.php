<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::formOpen(array('id'=>'jsform', 'class'=>'tab-content')); ?>

<div class="align-middle brendy-form-header">
	<div class="float-end mt-1">
		<button type="submit" class="btn btn-primary btn-sm" name="save"><?php $L->p('Save') ?></button>
		<a class="btn btn-secondary btn-sm" href="<?php echo HTML_PATH_ADMIN_ROOT.'users' ?>" role="button"><?php $L->p('Cancel') ?></a>
	</div>
	<?php echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Add a new user'), 'icon'=>'user')); ?>
</div>

<?php
	echo BrendyAdminUi::formInputHidden(array(
		'name'=>'tokenCSRF',
		'value'=>$security->getTokenCSRF()
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'new_username',
		'label'=>$L->g('Username'),
		'value'=>$newUsernameValue,
		'class'=>'',
		'placeholder'=>'',
		'tip'=>''
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'new_password',
		'type'=>'password',
		'label'=>$L->g('Password'),
		'value'=>'',
		'class'=>'',
		'placeholder'=>'',
		'autocomplete'=>'new-password',
		'minlength'=>PASSWORD_LENGTH,
		'maxlength'=>PASSWORD_MAX_LENGTH,
		'required'=>true,
		'tip'=>$L->g('Use a unique long password or passphrase')
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'confirm_password',
		'type'=>'password',
		'label'=>$L->g('Confirm Password'),
		'value'=>'',
		'class'=>'',
		'placeholder'=>'',
		'autocomplete'=>'new-password',
		'minlength'=>PASSWORD_LENGTH,
		'maxlength'=>PASSWORD_MAX_LENGTH,
		'required'=>true,
		'tip'=>$L->g('Repeat the new password exactly')
	));

	echo BrendyAdminUi::formSelect(array(
		'name'=>'role',
		'label'=>$L->g('Role'),
		'options'=>array('author'=>$L->g('Author'), 'editor'=>$L->g('Editor'), 'admin'=>$L->g('Administrator')),
		'selected'=>'author',
		'class'=>'',
		'tip'=>$L->g('author-can-write-and-edit-their-own-content')
	));

	echo BrendyAdminUi::formInputText(array(
		'name'=>'email',
		'label'=>$L->g('Email'),
		'value'=>$newEmailValue,
		'class'=>'',
		'placeholder'=>'',
		'tip'=>''
	));
?>

<?php echo BrendyAdminUi::formClose(); ?>
