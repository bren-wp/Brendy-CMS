<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php echo BrendyAdminUi::formOpen(array('id' => 'jsform', 'class' => 'tab-content')); ?>
<?php $editUserCancelUrl = checkRole(array('admin'), false) ? HTML_PATH_ADMIN_ROOT . 'users' : HTML_PATH_ADMIN_ROOT . 'dashboard'; ?>

<div class="align-middle brendy-form-header">
	<div class="float-end mt-1">
		<button type="submit" class="btn btn-primary btn-sm" name="save"><?php $L->p('Save') ?></button>
		<a class="btn btn-secondary btn-sm" href="<?php echo Theme::escape($editUserCancelUrl) ?>" role="button"><?php $L->p('Cancel') ?></a>
	</div>
	<?php echo BrendyAdminUi::pageTitle(array('title' => $L->g('Edit user'), 'icon' => 'user')); ?>
</div>

<!-- TABS -->
<nav class="mb-3">
	<div class="nav nav-tabs" id="nav-tab" role="tablist">
		<a class="nav-item nav-link active" id="nav-profile-tab" data-bs-toggle="tab" href="#profile" role="tab" aria-controls="profile" aria-selected="true"><?php $L->p('Profile') ?></a>
		<a class="nav-item nav-link" id="nav-picture-tab" data-bs-toggle="tab" href="#picture" role="tab" aria-controls="picture" aria-selected="false"><?php $L->p('Profile picture') ?></a>
		<a class="nav-item nav-link" id="nav-security-tab" data-bs-toggle="tab" href="#security" role="tab" aria-controls="security" aria-selected="false"><?php $L->p('Security') ?></a>
		<a class="nav-item nav-link" id="nav-social-tab" data-bs-toggle="tab" href="#social" role="tab" aria-controls="social" aria-selected="false"><?php $L->p('Social Networks') ?></a>
	</div>
</nav>

<?php
// Token CSRF
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'tokenCSRF',
	'value' => $security->getTokenCSRF()
));

// Username
echo BrendyAdminUi::formInputHidden(array(
	'name' => 'username',
	'value' => $user->username()
));
?>

<div class="tab-content" id="nav-tabContent">
	<!-- Profile tab -->
	<div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="nav-profile-tab">
		<?php
		// Display username but disable the field
		echo BrendyAdminUi::formInputText(array(
			'name' => 'usernameDisabled',
			'label' => $L->g('Username'),
			'value' => $user->username(),
			'class' => '',
			'placeholder' => '',
			'disabled' => true,
			'tip' => ''
		));

		if ($login->role() === 'admin') {
			echo BrendyAdminUi::formSelect(array(
				'name' => 'role',
				'label' => $L->g('Role'),
				'options' => array('author' => $L->g('Author'), 'editor' => $L->g('Editor'), 'admin' => $L->g('Administrator')),
				'selected' => $user->role(),
				'class' => '',
				'tip' => $L->g('author-can-write-and-edit-their-own-content')
			));
		}

		echo BrendyAdminUi::formInputText(array(
			'name' => 'email',
			'label' => $L->g('Email'),
			'value' => $user->email(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'nickname',
			'label' => $L->g('Nickname'),
			'value' => $user->nickname(),
			'class' => '',
			'placeholder' => '',
			'tip' => $L->g('The nickname is almost used in the themes to display the author of the content')
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'firstName',
			'label' => $L->g('First Name'),
			'value' => $user->firstName(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'lastName',
			'label' => $L->g('Last Name'),
			'value' => $user->lastName(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));
		?>
	</div>

	<!-- Profile picture tab -->
	<div class="tab-pane fade" id="picture" role="tabpanel" aria-labelledby="nav-picture-tab">
		<div class="container">
			<div class="row">
				<div class="col-lg-4 col-sm-12 p-0 pe-2">
					<div class="mb-3">
						<label class="form-label" for="jsprofilePictureInputFile"><?php $L->p('Upload image'); ?></label>
						<input type="file" class="form-control" id="jsprofilePictureInputFile" name="profilePictureInputFile">
					</div>
				</div>
				<div class="col-lg-8 col-sm-12 p-0 text-center">
					<img id="jsprofilePicturePreview" class="img-fluid img-thumbnail" alt="<?php echo Theme::escape($L->g('Profile picture preview')) ?>" src="<?php echo Sanitize::html($user->profilePicture() ?: HTML_PATH_CORE_IMG . 'default.svg') ?>" />
				</div>
			</div>
		</div>
		<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
			$("#jsprofilePictureInputFile").on("change", function() {
				var formData = new FormData();
				formData.append('tokenCSRF', tokenCSRF);
				formData.append('profilePictureInputFile', $(this)[0].files[0]);
				formData.append('username', $("#jsusername").val());
				$.ajax({
					url: HTML_PATH_ADMIN_ROOT + "ajax/profile-picture-upload",
					type: "POST",
					data: formData,
					cache: false,
					contentType: false,
					processData: false
				}).done(function(data) {
					if (data.status === 0) {
						$("#jsprofilePicturePreview").attr('src', data.absoluteURL);
					} else {
						showAlert(data.message);
					}
				});
			});
		</script>
	</div>

	<!-- Security tab -->
	<div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="nav-security-tab">
		<?php
		echo BrendyAdminUi::formTitle(array('title' => $L->g('Password')));

		echo '
		<div class="form-group">
			<a href="' . HTML_PATH_ADMIN_ROOT . 'user-password/' . rawurlencode($user->username()) . '" class="btn btn-primary me-2">' . $L->g('Change password') . '</a>
		</div>
		';

		$passwordChangedAt = $user->passwordChangedAt();
		$lastLoginAt = $user->lastLoginAt();
		$lastLoginMethod = $user->lastLoginMethod();
		$loginMethodLabels = array(
			'password' => $L->g('Password login'),
			'two-factor' => $L->g('Two-factor login'),
			'remember' => $L->g('Remember me login')
		);
		$passwordChangedLabel = $passwordChangedAt > 0 ? date(ADMIN_PANEL_DATE_FORMAT, $passwordChangedAt) : $L->g('Not recorded');
		$lastLoginLabel = $lastLoginAt > 0 ? date(ADMIN_PANEL_DATE_FORMAT, $lastLoginAt) : $L->g('Not recorded');
		$lastLoginMethodLabel = isset($loginMethodLabels[$lastLoginMethod]) ? $loginMethodLabels[$lastLoginMethod] : $L->g('Not recorded');
		echo BrendyAdminUi::formTitle(array('title' => $L->g('Account security history')));
		echo '<div class="row mb-3">' .
			'<div class="col-lg-4 mb-2"><div class="border rounded p-3 h-100"><small class="text-muted d-block">' .
			Theme::escape($L->g('Password last changed')) . '</small><strong>' . Theme::escape($passwordChangedLabel) . '</strong></div></div>' .
			'<div class="col-lg-4 mb-2"><div class="border rounded p-3 h-100"><small class="text-muted d-block">' .
			Theme::escape($L->g('Password reuse protection')) . '</small><strong>' .
			Theme::escape(sprintf($L->g('Current plus last %d passwords'), PASSWORD_HISTORY_LIMIT)) . '</strong></div></div>' .
			'<div class="col-lg-4 mb-2"><div class="border rounded p-3 h-100"><small class="text-muted d-block">' .
			Theme::escape($L->g('Last successful login')) . '</small><strong>' . Theme::escape($lastLoginLabel) . '</strong><br><small>' .
			Theme::escape($lastLoginMethodLabel) . '</small></div></div>' .
			'</div>';

		echo BrendyAdminUi::formTitle(array('title' => $L->g('Two-factor authentication')));
		echo '<div class="alert alert-light border" role="status"><strong>' .
			Theme::escape($user->twoFactorEnabled() ? $L->g('Enabled') : $L->g('Disabled')) . '</strong> · ' .
			Theme::escape($user->twoFactorEnabled() ? $L->g('This account requires an authenticator or recovery code after the password') : $L->g('Add a second factor to protect this account if the password is stolen')) . '</div>';
		if ($user->username() === $login->username()) {
			echo '<div class="form-group"><a href="' . HTML_PATH_ADMIN_ROOT . 'two-factor" class="btn btn-outline-primary">' .
				'<i class="bi bi-shield-lock me-1" aria-hidden="true"></i>' . $L->g('Manage two-factor authentication') . '</a></div>';
		}

		echo BrendyAdminUi::formTitle(array('title' => $L->g('Session security')));
		echo '<div class="alert alert-light border" role="note">' .
			Theme::escape($L->g('The internal session revocation token is never displayed and is not an API credential')) .
			'</div>';
		echo '<div class="form-group row"><div class="col-sm-2"></div><div class="col-sm-10">' .
			'<button type="submit" class="btn btn-warning" id="jsregenerateAuthToken" name="regenerateAuthToken">' .
			$L->g('Revoke other sessions') . '</button></div></div>';

		if (checkRole(array('admin'), false)) {
			echo BrendyAdminUi::formTitle(array('title' => $L->g('Status')));

			echo BrendyAdminUi::formInputText(array(
				'name' => 'status',
				'label' => $L->g('Current status'),
				'value' => $user->enabled() ? $L->g('Enabled') : $L->g('Disabled'),
				'class' => '',
				'disabled' => true,
				'tip' => $user->enabled() ? '' : ($user->hasUsablePassword() ? $L->g('The account is disabled but its password is preserved') : $L->g('Set a new password before enabling this legacy-disabled account'))
			));

			if ($user->enabled()) {
				echo '
				<div class="form-group row">
				<div class="col-sm-2"></div>
				<div class="col-sm-10">
					<button type="submit" class="btn btn-warning me-2" id="jsdisableUser" name="disableUser">' . $L->g('Disable user') . '</button>
					<button type="submit" class="btn btn-danger me-2" id="jsdeleteUserAndKeepContent" name="deleteUserAndKeepContent">' . $L->g('Delete user and keep content') . '</button>
					<button type="submit" class="btn btn-danger me-2" id="jsdeleteUserAndDeleteContent" name="deleteUserAndDeleteContent">' . $L->g('Delete user and delete content') . '</button>
				</div>
				</div>
				';
			} else {
				$canEnable = $user->hasUsablePassword();
				echo '<div class="form-group row"><div class="col-sm-2"></div><div class="col-sm-10">' .
					'<button type="submit" class="btn btn-success me-2" id="jsenableUser" name="enableUser"' .
					($canEnable ? '' : ' disabled aria-disabled="true"') . '>' . $L->g('Enable user') . '</button>' .
					(!$canEnable ? '<small class="text-muted d-block mt-2">' . Theme::escape($L->g('Set a new password before enabling this legacy-disabled account')) . '</small>' : '') .
					'</div></div>';
			}

		}
		?>
	</div>

	<!-- Social Networks tab -->
	<div class="tab-pane fade" id="social" role="tabpanel" aria-labelledby="nav-social-tab">
		<?php
		echo BrendyAdminUi::formInputText(array(
			'name' => 'twitter',
			'label' => 'Twitter',
			'value' => $user->twitter(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'facebook',
			'label' => 'Facebook',
			'value' => $user->facebook(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'codepen',
			'label' => 'CodePen',
			'value' => $user->codepen(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'instagram',
			'label' => 'Instagram',
			'value' => $user->instagram(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'gitlab',
			'label' => 'GitLab',
			'value' => $user->gitlab(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'github',
			'label' => 'GitHub',
			'value' => $user->github(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'linkedin',
			'label' => 'LinkedIn',
			'value' => $user->linkedin(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'xing',
			'label' => 'Xing',
			'value' => $user->xing(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'telegram',
			'label' => 'Telegram',
			'value' => $user->telegram(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'mastodon',
			'label' => 'Mastodon',
			'value' => $user->mastodon(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'vk',
			'label' => 'VK',
			'value' => $user->vk(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'youtube',
			'label' => 'Youtube',
			'value' => $user->youtube(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));

		echo BrendyAdminUi::formInputText(array(
			'name' => 'bluesky',
			'label' => 'Bluesky',
			'value' => $user->bluesky(),
			'class' => '',
			'placeholder' => '',
			'tip' => ''
		));
		?>
	</div>
</div>

<?php echo BrendyAdminUi::formClose(); ?>

<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>">
	// Open current tab after refresh page
	$(function() {
		$('#jsregenerateAuthToken').on('click', function(event) {
			if (!window.confirm(<?php echo json_encode($L->g('Regenerating the token revokes other admin sessions and remembered logins. Continue'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)) {
				event.preventDefault();
			}
		});
		const tabKey = 'brendy:activeTab:edit-user;
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
