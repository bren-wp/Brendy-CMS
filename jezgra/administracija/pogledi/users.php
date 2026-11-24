<?php defined('BRENDY') or die('Brendy CMS.'); ?>

<?php

echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Users'), 'icon'=>'users'));

echo BrendyAdminUi::link(array(
	'title'=>$L->g('add-a-new-user'),
	'href'=>HTML_PATH_ADMIN_ROOT.'new-user',
	'icon'=>'plus'
));

$roleLabels = array(
	'admin' => $L->g('Administrator'),
	'editor' => $L->g('Editor'),
	'author' => $L->g('Author')
);

// Client-side filtering keeps this flat-file view fast and avoids adding request state.
echo '<div class="card mt-3 mb-3"><div class="card-body py-3"><div class="form-row align-items-end">';
echo '<div class="col-12 col-lg-6 mb-2 mb-lg-0"><label for="brendy-user-search" class="small fw-bold">'.$L->g('Search users').'</label><input id="brendy-user-search" type="search" class="form-control" data-brendy-filter-query autocomplete="off" maxlength="200" placeholder="'.$L->g('Search users').'" /></div>';
echo '<div class="col-6 col-lg-3"><label for="brendy-user-status" class="small fw-bold">'.$L->g('Status').'</label><select id="brendy-user-status" class="form-control" data-brendy-filter-status><option value="">'.$L->g('All statuses').'</option><option value="enabled">'.$L->g('Enabled').'</option><option value="disabled">'.$L->g('Disabled').'</option></select></div>';
echo '<div class="col-6 col-lg-3"><label for="brendy-user-role" class="small fw-bold">'.$L->g('Role').'</label><select id="brendy-user-role" class="form-control" data-brendy-filter-category><option value="">'.$L->g('All roles').'</option>';
foreach ($roleLabels as $roleKey => $roleLabel) {
	echo '<option value="'.htmlspecialchars($roleKey, ENT_QUOTES, CHARSET).'">'.htmlspecialchars($roleLabel, ENT_QUOTES, CHARSET).'</option>';
}
echo '</select></div></div></div></div>';

echo '
<div class="table-responsive brendy-table-shell"><table id="brendy-users-table" class="table table-striped mb-0">
	<thead>
		<tr>
			<th class="border-bottom-0" scope="col">'.$L->g('Username').'</th>
			<th class="border-bottom-0 d-none d-lg-table-cell" scope="col">'.$L->g('Nickname').'</th>
			<th class="border-bottom-0" scope="col">'.$L->g('Email').'</th>
			<th class="border-bottom-0" scope="col">'.$L->g('Status').'</th>
			<th class="border-bottom-0" scope="col">'.$L->g('Role').'</th>
			<th class="border-bottom-0 d-none d-md-table-cell" scope="col">2FA</th>
			<th class="border-bottom-0 d-none d-xl-table-cell" scope="col">'.$L->g('Last login').'</th>
			<th class="border-bottom-0 d-none d-lg-table-cell" scope="col">'.$L->g('Registered').'</th>
		</tr>
	</thead>
	<tbody>
';

$list = $users->keys();
foreach ($list as $username) {
	try {
		$user = new User($username);
		$safeUsername = htmlspecialchars($user->username(), ENT_QUOTES, CHARSET);
		$encodedUsername = rawurlencode($user->username());
		$pictureUrl = $user->profilePicture();
		if (!$pictureUrl) $pictureUrl = HTML_PATH_CORE_IMG . 'default.svg';
		$enabled = $user->enabled();
		$role = isset($roleLabels[$user->role()]) ? $user->role() : 'author';
		$searchText = Text::lowercase(trim($user->username().' '.$user->nickname().' '.$user->email().' '.$roleLabels[$role]));
		$lastLoginAt = $user->lastLoginAt();
		$lastLogin = $lastLoginAt > 0 ? date(ADMIN_PANEL_DATE_FORMAT, $lastLoginAt) : $L->g('Never');
		echo '<tr class="brendy-user-row" data-search="'.htmlspecialchars($searchText, ENT_QUOTES, CHARSET).'" data-status="'.($enabled?'enabled':'disabled').'" data-category="'.htmlspecialchars($role, ENT_QUOTES, CHARSET).'" data-brendy-filter-item>';
		echo '<td><img class="profilePicture me-1" alt="" src="' . htmlspecialchars($pictureUrl, ENT_QUOTES, CHARSET) . '" /><a href="' . HTML_PATH_ADMIN_ROOT . 'edit-user/' . $encodedUsername . '">' . $safeUsername . '</a></td>';
		echo '<td class="d-none d-lg-table-cell">' . htmlspecialchars($user->nickname(), ENT_QUOTES, CHARSET) . '</td>';
		echo '<td>' . htmlspecialchars($user->email(), ENT_QUOTES, CHARSET) . '</td>';
		echo '<td>'.($enabled?'<span class="badge text-bg-success">'.$L->g('Enabled').'</span>':'<span class="badge text-bg-secondary">'.$L->g('Disabled').'</span>').'</td>';
		echo '<td>'.htmlspecialchars($roleLabels[$role], ENT_QUOTES, CHARSET).'</td>';
		echo '<td class="d-none d-md-table-cell">' . ($user->twoFactorEnabled()
			? '<span class="badge text-bg-success"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>' . $L->g('Enabled') . '</span>'
			: '<span class="badge text-bg-light border">' . $L->g('Disabled') . '</span>') . '</td>';
		echo '<td class="d-none d-xl-table-cell">'.htmlspecialchars($lastLogin, ENT_QUOTES, CHARSET).'</td>';
		echo '<td class="d-none d-lg-table-cell">'.Date::format($user->registered(), DB_DATE_FORMAT, ADMIN_PANEL_DATE_FORMAT).'</td>';
		echo '</tr>';
	} catch (Throwable $e) {
		// Corrupt/incomplete legacy records are skipped; System Health reports database issues.
	}
}

echo '
	</tbody>
</table></div>
<div id="brendy-users-empty" class="alert alert-light border" data-brendy-filter-empty hidden role="status">'.$L->g('No users match the selected filters').'</div>
';
?>

