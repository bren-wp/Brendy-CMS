<?php defined('BRENDY') or die('Brendy CMS.');

// ============================================================================
// Check role
// ============================================================================

checkRole(array('admin'));

// ============================================================================
// Functions
// ============================================================================

// This function is used on the VIEW to show the tables
function printTable($title, $array) {
	if (!is_array($array)) $array = array();
	echo '<h2 class="mb-2 mt-4">' . htmlspecialchars((string)$title, ENT_QUOTES, CHARSET) . '</h2>';
	echo '<div class="table-responsive"><table class="table table-striped mt-3">
		<tbody>
	';

	foreach ($array as $key=>$value) {
		if($value===false) { $value = 'false'; }
		elseif($value===true) { $value = 'true'; }
		echo '<tr>';
		echo '<td>' . htmlspecialchars((string)$key, ENT_QUOTES, CHARSET) . '</td>';
		if (is_array($value)) {
			$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			echo '<td>' . htmlspecialchars(is_string($encoded) ? $encoded : '', ENT_QUOTES, CHARSET) . '</td>';
		} else {
			echo '<td>' . htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, CHARSET) . '</td>';
		}
		echo '</tr>';
	}

	echo '
		</tbody>
		</table></div>
	';
}

// ============================================================================
// Main before POST
// ============================================================================

// ============================================================================
// POST Method
// ============================================================================

// ============================================================================
// Main after POST
// ============================================================================

$layout['title'] .= ' - '.$L->g('Developers');
