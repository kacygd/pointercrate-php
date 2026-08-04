<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

render_header(t('guidelines.title'), 'guidelines');
?>
<section class="panel fade" id="submission-form">
    <div class="panel-head">
        <h1><?= e(t('guidelines.heading')) ?></h1>
        <p><?= e(t('guidelines.intro')) ?></p>
    </div>

    <div class="info-yellow" style="text-align: left; margin-bottom: 16px;">
        <b><?= e(t('guidelines.important_label')) ?></b> <?= e(t('guidelines.important_text')) ?>
    </div>

    <h3><?= e(t('guidelines.s1_title')) ?></h3>
    <p><?= e(t('guidelines.s1_p1')) ?></p>
    <p><?= e(t('guidelines.s1_p2')) ?></p>

    <h3><?= e(t('guidelines.s2_title')) ?></h3>
    <p><?= e(t('guidelines.s2_p1')) ?></p>
    <p><?= e(t('guidelines.s2_p2')) ?></p>
    <p><?= e(t('guidelines.s2_p3')) ?></p>
    <p><?= e(t('guidelines.s2_p4')) ?></p>
    <p><?= e(t('guidelines.s2_p5')) ?></p>

    <h3><?= e(t('guidelines.s3_title')) ?></h3>
    <p><?= e(t('guidelines.s3_p1')) ?></p>
    <p><?= e(t('guidelines.s3_p2')) ?></p>

    <h3><?= e(t('guidelines.s4_title')) ?></h3>
    <p><?= e(t('guidelines.s4_p1')) ?></p>
    <p><?= e(t('guidelines.s4_p2')) ?></p>
    <p><?= e(t('guidelines.s4_p3')) ?></p>

    <h3><?= e(t('guidelines.s5_title')) ?></h3>
    <p><?= e(t('guidelines.s5_p1')) ?></p>
    <p><?= e(t('guidelines.s5_p2')) ?></p>

    <h3><?= e(t('guidelines.s6_title')) ?></h3>
    <p><b><?= e(t('guidelines.s6_approved')) ?></b> <?= e(t('guidelines.s6_approved_text')) ?></p>
    <p><b><?= e(t('guidelines.s6_rejected')) ?></b> <?= e(t('guidelines.s6_rejected_text')) ?></p>
    <p><b><?= e(t('guidelines.s6_flagged')) ?></b> <?= e(t('guidelines.s6_flagged_text')) ?></p>

    <h3><?= e(t('guidelines.s7_title')) ?></h3>
    <p><?= e(t('guidelines.s7_p1')) ?></p>

    <div class="info-green" style="text-align: left; margin-top: 14px;">
        <b><?= e(t('guidelines.tip_label')) ?></b> <?= e(t('guidelines.tip_text')) ?>
    </div>

    <p style="margin-top: 18px;">
        <a class="button blue hover" href="<?= e(base_url('submit.php')) ?>"><?= e(t('guidelines.submit_button')) ?></a>
    </p>
</section>
<?php render_footer(); ?>
