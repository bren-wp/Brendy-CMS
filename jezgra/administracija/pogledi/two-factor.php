<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
	<?php echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Two-factor authentication'), 'icon'=>'shield-lock')); ?>
	<a class="btn btn-sm btn-outline-secondary" href="<?php echo HTML_PATH_ADMIN_ROOT . 'edit-user/' . rawurlencode($user->username()) ?>"><?php $L->p('Back to profile') ?></a>
</div>

<?php if (!empty($recoveryCodesDisplay)) : ?>
<div class="alert alert-warning" role="alert">
	<h2 class="h6"><i class="bi bi-key-fill" aria-hidden="true"></i> <?php $L->p('Save these recovery codes now') ?></h2>
	<p class="mb-2"><?php $L->p('Each recovery code can be used only once. Brendy stores only their hashes and cannot show these codes again.') ?></p>
	<pre class="p-3 border rounded mb-2 brendy-code-block"><?php echo Theme::escape(implode(PHP_EOL, $recoveryCodesDisplay)) ?></pre>
	<p class="small mb-0"><?php $L->p('Store them offline in a secure password manager or another protected location.') ?></p>
</div>
<?php endif; ?>

<div class="card mb-4">
	<div class="card-body">
		<div class="d-flex justify-content-between align-items-start flex-wrap">
			<div>
				<h2 class="h5 mb-1"><?php $L->p('Authenticator app') ?></h2>
				<p class="text-muted mb-0"><?php $L->p('Use any standards-compatible TOTP authenticator. No Brendy cloud service or API key is required.') ?></p>
			</div>
			<span class="badge badge-<?php echo $user->twoFactorEnabled() ? 'success' : 'secondary' ?> mt-1"><?php echo Theme::escape($user->twoFactorEnabled() ? $L->g('Enabled') : $L->g('Disabled')) ?></span>
		</div>
	</div>
</div>

<?php if (!$user->twoFactorEnabled() && $setupSecret === false) : ?>
<div class="card">
	<div class="card-body">
		<h2 class="h5"><?php $L->p('Enable two-factor authentication') ?></h2>
		<p class="text-muted"><?php $L->p('Confirm your current password before Brendy creates a temporary enrollment secret.') ?></p>
		<?php echo BrendyAdminUi::formOpen(array()); ?>
		<?php echo BrendyAdminUi::formInputHidden(array('name'=>'tokenCSRF', 'value'=>$security->getTokenCSRF())); ?>
		<?php echo BrendyAdminUi::formInputText(array('name'=>'currentPassword','label'=>$L->g('Current password'),'type'=>'password','value'=>'','autocomplete'=>'current-password','maxlength'=>4096,'required'=>true,'tip'=>'')); ?>
		<button type="submit" class="btn btn-primary" name="prepareTwoFactor" value="1"><i class="bi bi-shield-plus" aria-hidden="true"></i> <?php $L->p('Start setup') ?></button>
		<?php echo BrendyAdminUi::formClose(); ?>
	</div>
</div>
<?php elseif (!$user->twoFactorEnabled() && is_string($setupSecret)) : ?>
<?php
	$accountLabel = $user->email() !== '' ? Sanitize::htmlDecode($user->email()) : $user->username();
	$provisioningUri = Totp::provisioningUri($setupSecret, $accountLabel, BRENDY_NAME);
?>
<div class="card">
	<div class="card-body">
		<h2 class="h5"><?php $L->p('Finish setup') ?></h2>
		<ol class="ps-3">
			<li class="mb-2"><?php $L->p('Add a new account in your authenticator app and enter this secret') ?>:<br><code class="user-select-all"><?php echo Theme::escape($setupSecret) ?></code></li>
			<li class="mb-2"><?php $L->p('If your authenticator accepts otpauth links, you can copy this local provisioning URI') ?>:<br><code class="small user-select-all brendy-code-break"><?php echo Theme::escape($provisioningUri) ?></code><br><a class="btn btn-sm btn-outline-primary mt-2" href="<?php echo Theme::escape($provisioningUri) ?>"><i class="bi bi-phone me-1" aria-hidden="true"></i><?php $L->p('Open in authenticator app') ?></a></li>
			<li><?php $L->p('Enter the current six-digit code below to verify setup.') ?></li>
		</ol>
		<?php echo BrendyAdminUi::formOpen(array()); ?>
		<?php echo BrendyAdminUi::formInputHidden(array('name'=>'tokenCSRF', 'value'=>$security->getTokenCSRF())); ?>
		<?php echo BrendyAdminUi::formInputText(array('name'=>'currentPassword','label'=>$L->g('Current password'),'type'=>'password','value'=>'','autocomplete'=>'current-password','maxlength'=>4096,'required'=>true,'tip'=>'')); ?>
		<?php echo BrendyAdminUi::formInputText(array('name'=>'twoFactorCode','label'=>$L->g('Six-digit authenticator code'),'type'=>'text','value'=>'','autocomplete'=>'one-time-code','maxlength'=>6,'required'=>true,'tip'=>$L->g('The code changes every 30 seconds'))); ?>
		<button type="submit" class="btn btn-primary me-2" name="enableTwoFactor" value="1"><i class="bi bi-shield-check" aria-hidden="true"></i> <?php $L->p('Enable 2FA') ?></button>
		<button type="submit" class="btn btn-outline-secondary" name="cancelSetup" value="1"><?php $L->p('Cancel') ?></button>
		<?php echo BrendyAdminUi::formClose(); ?>
	</div>
</div>
<?php else : ?>
<div class="row">
	<div class="col-lg-6 mb-4">
		<div class="card h-100"><div class="card-body">
			<h2 class="h5"><?php $L->p('Recovery codes') ?></h2>
			<p class="text-muted"><?php echo Theme::escape(sprintf($L->g('Unused recovery codes: %d'), $user->twoFactorRecoveryCount())) ?></p>
			<p class="small"><?php $L->p('Generate a new set if any recovery code may have been exposed. The previous set becomes invalid immediately.') ?></p>
			<?php echo BrendyAdminUi::formOpen(array()); ?>
			<?php echo BrendyAdminUi::formInputHidden(array('name'=>'tokenCSRF', 'value'=>$security->getTokenCSRF())); ?>
			<?php echo BrendyAdminUi::formInputText(array('name'=>'currentPassword','label'=>$L->g('Current password'),'type'=>'password','value'=>'','autocomplete'=>'current-password','maxlength'=>4096,'required'=>true,'tip'=>'')); ?>
			<?php echo BrendyAdminUi::formInputText(array('name'=>'twoFactorCode','label'=>$L->g('Current authenticator or recovery code'),'type'=>'text','value'=>'','autocomplete'=>'one-time-code','maxlength'=>64,'required'=>true,'tip'=>'')); ?>
			<button type="submit" class="btn btn-warning" name="regenerateRecoveryCodes" value="1"><?php $L->p('Generate new recovery codes') ?></button>
			<?php echo BrendyAdminUi::formClose(); ?>
		</div></div>
	</div>
	<div class="col-lg-6 mb-4">
		<div class="card h-100 border-danger"><div class="card-body">
			<h2 class="h5 text-danger"><?php $L->p('Disable two-factor authentication') ?></h2>
			<p class="text-muted"><?php $L->p('This reduces account security and revokes other active sessions and persistent login tokens.') ?></p>
			<?php echo BrendyAdminUi::formOpen(array()); ?>
			<?php echo BrendyAdminUi::formInputHidden(array('name'=>'tokenCSRF', 'value'=>$security->getTokenCSRF())); ?>
			<?php echo BrendyAdminUi::formInputText(array('name'=>'currentPassword','label'=>$L->g('Current password'),'type'=>'password','value'=>'','autocomplete'=>'current-password','maxlength'=>4096,'required'=>true,'tip'=>'')); ?>
			<?php echo BrendyAdminUi::formInputText(array('name'=>'twoFactorCode','label'=>$L->g('Current authenticator or recovery code'),'type'=>'text','value'=>'','autocomplete'=>'one-time-code','maxlength'=>64,'required'=>true,'tip'=>'')); ?>
			<button type="submit" class="btn btn-danger" name="disableTwoFactor" value="1"><?php $L->p('Disable 2FA') ?></button>
			<?php echo BrendyAdminUi::formClose(); ?>
		</div></div>
	</div>
</div>
<?php endif; ?>
