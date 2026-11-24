<?php defined('BRENDY') or die('Brendy CMS.');

$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($query, CHARSET) > 100) $query = mb_substr($query, 0, 100, CHARSET);
$rows = array_values(array_filter($syslog->getDB(), static function ($row) { return is_array($row); }));
if ($query !== '') {
	$needle = Text::lowercase($query);
	$rows = array_values(array_filter($rows, static function ($row) use ($needle, $L) {
		$haystack = array(
			isset($row['username']) && is_scalar($row['username']) ? (string)$row['username'] : '',
			isset($row['notes']) && is_scalar($row['notes']) ? (string)$row['notes'] : '',
			isset($row['method']) && is_scalar($row['method']) ? (string)$row['method'] : '',
			isset($row['dictionaryKey']) && is_scalar($row['dictionaryKey']) ? $L->g((string)$row['dictionaryKey']) : ''
		);
		return mb_strpos(Text::lowercase(implode(' ', $haystack)), $needle, 0, CHARSET) !== false;
	}));
}
$pageNumber = brendyIntegerParameter($_GET, 'page', 1, 1, 1000000);
if ($pageNumber === null) $pageNumber = 1;
$perPage = 50;
$total = count($rows);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($pageNumber > $totalPages) $pageNumber = $totalPages;
$visibleRows = array_slice($rows, ($pageNumber - 1) * $perPage, $perPage);
?>

<?php
$auditIntegrity = method_exists($syslog, 'verifyChain') ? $syslog->verifyChain() : array('ok'=>false, 'protected'=>0);
$auditIntegrityOk = is_array($auditIntegrity) && isset($auditIntegrity['ok']) && $auditIntegrity['ok'] === true;
$auditProtected = is_array($auditIntegrity) && isset($auditIntegrity['protected']) ? max(0, (int)$auditIntegrity['protected']) : 0;
$auditHeadSequence = is_array($auditIntegrity) && isset($auditIntegrity['headSequence']) ? max(0, (int)$auditIntegrity['headSequence']) : 0;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
	<?php echo BrendyAdminUi::pageTitle(array('title' => $L->g('Activity log'), 'icon' => 'journal-text')); ?>
	<div class="d-flex align-items-center flex-wrap">
		<span class="badge <?php echo $auditIntegrityOk ? 'text-bg-success' : 'text-bg-danger' ?> me-2 p-2">
			<i class="bi <?php echo $auditIntegrityOk ? 'bi-shield-check' : 'bi-shield-exclamation' ?> me-1" aria-hidden="true"></i>
			<?php echo Theme::escape($auditIntegrityOk ? $L->g('Audit chain verified') : $L->g('Audit chain validation failed')) ?>
			<?php if ($auditIntegrityOk) : ?> · <?php echo (int)$auditProtected ?> · <?php echo Theme::escape($L->g('Head sequence')) ?> #<?php echo (int)$auditHeadSequence ?><?php endif ?>
		</span>
		<span class="badge text-bg-light border p-2"><?php echo (int)$total ?> / <?php echo (int)SYSLOG_RETENTION_AMOUNT ?></span>
	</div>
</div>
<?php if (!$auditIntegrityOk) : ?>
<div class="alert alert-danger" role="alert"><strong><?php $L->p('Activity log integrity warning') ?></strong> <?php $L->p('The audit log HMAC chain is invalid or incomplete. Review the installation for manual changes, corruption or a security incident.') ?></div>
<?php endif ?>

<form class="form-inline mb-3" method="get" action="<?php echo HTML_PATH_ADMIN_ROOT ?>audit-log">
	<label class="visually-hidden" for="audit-q"><?php $L->p('Search') ?></label>
	<input id="audit-q" class="form-control me-2 flex-grow-1" type="search" name="q" maxlength="100" value="<?php echo Theme::escape($query) ?>" placeholder="<?php echo Theme::escape($L->g('Search activity')) ?>">
	<button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> <?php $L->p('Search') ?></button>
	<?php if ($query !== '') : ?><a class="btn btn-link" href="<?php echo HTML_PATH_ADMIN_ROOT ?>audit-log"><?php $L->p('Clear') ?></a><?php endif ?>
</form>

<div class="table-responsive brendy-table-shell">
<table class="table table-hover align-middle mb-0">
	<thead><tr><th>#</th><th><?php $L->p('Date') ?></th><th><?php $L->p('User') ?></th><th><?php $L->p('Action') ?></th><th><?php $L->p('Details') ?></th><th>HTTP</th></tr></thead>
	<tbody>
	<?php if (empty($visibleRows)) : ?>
		<tr><td colspan="6" class="text-center text-muted py-5"><?php $L->p('No activity found') ?></td></tr>
	<?php else : foreach ($visibleRows as $row) :
		$date = isset($row['date']) && is_string($row['date']) ? $row['date'] : '';
		$user = isset($row['username']) && is_scalar($row['username']) ? (string)$row['username'] : '';
		$key = isset($row['dictionaryKey']) && is_scalar($row['dictionaryKey']) ? (string)$row['dictionaryKey'] : '';
		$notes = isset($row['notes']) && is_scalar($row['notes']) ? (string)$row['notes'] : '';
		$method = isset($row['method']) && is_scalar($row['method']) ? strtoupper((string)$row['method']) : '';
		$sequence = isset($row['sequence']) && is_int($row['sequence']) ? max(0, $row['sequence']) : 0;
	?>
		<tr><td class="text-nowrap small text-muted"><?php echo $sequence > 0 ? (int)$sequence : '—' ?></td><td class="text-nowrap small"><?php echo Theme::escape($date) ?></td><td><?php echo Theme::escape($user) ?></td><td><?php echo Theme::escape($L->g($key)) ?></td><td class="small text-muted"><?php echo Theme::escape($notes) ?></td><td><span class="badge text-bg-light border"><?php echo Theme::escape($method) ?></span></td></tr>
	<?php endforeach; endif ?>
	</tbody>
</table>
</div>

<?php if ($totalPages > 1) : ?>
<nav class="mt-3" aria-label="<?php echo Theme::escape($L->g('Activity log pagination')) ?>"><ul class="pagination pagination-sm flex-wrap">
<?php $previousPageLink = 0; foreach (Paginator::window($pageNumber, $totalPages, 2) as $i) : ?>
	<?php if ($previousPageLink > 0 && $i > $previousPageLink + 1) : ?><li class="page-item disabled" aria-hidden="true"><span class="page-link">&hellip;</span></li><?php endif ?>
	<li class="page-item<?php echo $i === $pageNumber ? ' active' : '' ?>"><a class="page-link"<?php echo $i === $pageNumber ? ' aria-current="page"' : '' ?> href="<?php echo HTML_PATH_ADMIN_ROOT ?>audit-log?page=<?php echo (int)$i ?><?php echo $query !== '' ? '&amp;q=' . rawurlencode($query) : '' ?>"><?php echo (int)$i ?></a></li>
<?php $previousPageLink = $i; endforeach ?>
</ul></nav>
<?php endif ?>
