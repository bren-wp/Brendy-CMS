<?php defined('BRENDY') or die('Brendy CMS.');

echo BrendyAdminUi::pageTitle(array('title'=>$L->g('About'), 'icon'=>'info-circle'));

echo '
<div class="table-responsive brendy-table-shell mt-3"><table class="table table-striped mb-0">
	<tbody>
';

echo '<tr>';
echo '<td>'.Theme::escape($L->g('Edition')).'</td>';
echo '<td>'.htmlspecialchars(BRENDY_RELEASE_NAME, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET).'</td>';
echo '</tr>';

echo '<tr>';
echo '<td>'.Theme::escape($L->g('Version')).'</td>';
echo '<td>'.htmlspecialchars(BRENDY_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET).'</td>';
echo '</tr>';


echo '<tr>';
echo '<td>'.Theme::escape($L->g('Build number')).'</td>';
echo '<td>'.htmlspecialchars(BRENDY_BUILD, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET).'</td>';
echo '</tr>';

echo '<tr>';
echo '<td>'.Theme::escape($L->g('Release date')).'</td>';
echo '<td>'.htmlspecialchars(BRENDY_RELEASE_DATE, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET).'</td>';
echo '</tr>';

echo '<tr>';
echo '<td>'.Theme::escape($L->g('Primary editor')).'</td>';
echo '<td>'.htmlspecialchars(BRENDY_EDITOR_NAME.' '.BRENDY_EDITOR_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET).' · '.Theme::escape($L->g('Local')).'</td>';
echo '</tr>';

echo '<tr>';
$diskUsage = Filesystem::getSize(PATH_ROOT);
echo '<td>'.Theme::escape($L->g('Disk usage')).'</td>';
echo '<td>'.($diskUsage === false ? '&mdash;' : Theme::escape(Filesystem::bytesToHumanFileSize($diskUsage))).'</td>';
echo '</tr>';

echo '<tr>';
echo '<td>'.Theme::escape($L->g('Product author')).'</td>';
echo '<td><a target="_blank" rel="noopener noreferrer" href="'.Theme::escape(BRENDY_WEBSITE).'">'.Theme::escape(BRENDY_AUTHOR).' · brendigo.com</a></td>';
echo '</tr>';

echo '
	</tbody>
</table></div>
';
