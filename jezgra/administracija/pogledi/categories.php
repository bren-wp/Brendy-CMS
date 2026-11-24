<?php defined('BRENDY') or die('Brendy CMS.');

echo BrendyAdminUi::pageTitle(array('title'=>$L->g('Categories'), 'icon'=>'tags'));

echo BrendyAdminUi::link(array(
	'title'=>$L->g('Add a new category'),
	'href'=>HTML_PATH_ADMIN_ROOT.'new-category',
	'icon'=>'plus'
));

echo '
<div class="table-responsive brendy-table-shell mt-3"><table class="table table-striped mb-0">
	<thead>
		<tr>
			<th class="border-bottom-0" scope="col">'.Theme::escape($L->g('Name')).'</th>
			<th class="border-bottom-0" scope="col">'.Theme::escape($L->g('URL')).'</th>
		</tr>
	</thead>
	<tbody>
';

foreach ($categories->keys() as $key) {
	try {
		$category = new Category($key);
	} catch (Throwable $e) {
		continue;
	}
	$encodedKey = rawurlencode((string)$key);
	$editUrl = HTML_PATH_ADMIN_ROOT . 'edit-category/' . $encodedKey;
	$publicLabel = $url->filters('category', false) . $key;
	echo '<tr>';
	echo '<td><a href="'.Theme::escape($editUrl).'">'.Theme::escape($category->name()).'</a></td>';
	echo '<td><a href="'.Theme::escape($category->permalink()).'">'.Theme::escape($publicLabel).'</a></td>';
	echo '</tr>';
}

echo '
	</tbody>
</table></div>
';
