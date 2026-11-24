<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<div class="row justify-content-center">
	<div class="col-lg-7 col-xl-6">
		<?php echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Confirm identity'), 'icon'=>'shield-lock')); ?>
		<div class="alert alert-warning" role="status">
			<strong><?php $L->p('Sensitive action') ?></strong><br>
			<?php $L->p('For your protection, Brendy requires your current credentials again before continuing.') ?>
		</div>
		<div class="card">
			<div class="card-body">
				<?php echo BrendyAdminUi::formOpen(array('id'=>'js-reauth-form')); ?>
				<?php echo BrendyAdminUi::formInputHidden(array('name'=>'tokenCSRF', 'value'=>$security->getTokenCSRF())); ?>
				<?php echo BrendyAdminUi::formInputText(array(
					'name'=>'currentPassword',
					'label'=>$L->g('Current password'),
					'type'=>'password',
					'value'=>'',
					'autocomplete'=>'current-password',
					'maxlength'=>4096,
					'required'=>true,
					'tip'=>$L->g('This confirmation is valid only for a short security window')
				)); ?>
				<?php if ($reauthNeedsTwoFactor) : ?>
					<?php echo BrendyAdminUi::formInputText(array(
						'name'=>'twoFactorCode',
						'label'=>$L->g('Current authenticator or recovery code'),
						'type'=>'text',
						'value'=>'',
						'autocomplete'=>'one-time-code',
						'maxlength'=>64,
						'required'=>true,
						'tip'=>$L->g('Two-factor protected accounts must confirm both factors')
					)); ?>
				<?php endif; ?>
				<button type="submit" class="btn btn-primary"><i class="bi bi-shield-check me-1" aria-hidden="true"></i><?php $L->p('Confirm and continue') ?></button>
				<a class="btn btn-outline-secondary ms-2" href="<?php echo HTML_PATH_ADMIN_ROOT ?>dashboard"><?php $L->p('Cancel') ?></a>
				<?php echo BrendyAdminUi::formClose(); ?>
			</div>
		</div>
		<p class="small text-muted mt-3 mb-0"><?php echo Theme::escape(sprintf($L->g('Recent authentication remains valid for %d minutes'), (int)(STEP_UP_AUTH_TTL / 60))) ?></p>
	</div>
</div>
