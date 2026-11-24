<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<script nonce="<?php echo htmlspecialchars(brendyCspNonce(), ENT_QUOTES | ENT_SUBSTITUTE, CHARSET); ?>" charset="utf-8">
	function showAlert(text) {
		$("#alert").text(String(text));
		$("#alert").slideDown().delay(<?php echo ALERT_DISAPPEAR_IN*1000 ?>).slideUp();
	}

	<?php if (Alert::defined()): ?>
	setTimeout(function(){ showAlert(<?php echo json_encode(Alert::get(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>) }, 500);
	<?php endif; ?>

	$(window).on("click", function() {
		$("#alert").hide();
	});
</script>

<div id="alert" class="alert <?php echo (Alert::status() === ALERT_STATUS_FAIL)?'alert-danger':'alert-success' ?>"></div>
