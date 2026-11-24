<?php defined('BRENDY') or die('Brendy CMS.'); ?>
<footer class="footer footer-modern">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-left mb-3 mb-md-0">
                <p class="m-0 text-uppercase"><?php echo Theme::escape($site->footer()); ?></p>
            </div>
            <div class="col-md-6 text-center text-md-right">
                <?php echo Theme::brandSignature('p', 'm-0'); ?>
            </div>
        </div>
    </div>
</footer>
