<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

render_header('Guidelines', 'guidelines');
?>
<section class="panel fade" id="submission-form">
    <div class="panel-head">
        <h1>Submission Guidelines</h1>
        <p>These rules are based on the common Pointercrate-style moderation standard.</p>
    </div>

    <div class="info-yellow" style="text-align: left; margin-bottom: 16px;">
        <b>Important:</b> Every submission is manually reviewed. Missing proof or suspicious footage will be rejected.
    </div>

    <h3>1. Account & Ownership</h3>
    <p>You must be logged in with your own player account when submitting.</p>
    <p>Do not submit records on behalf of other players unless a moderator explicitly asks for it.</p>

    <h3>2. Video Proof Requirements</h3>
    <p>Your proof must be clear, unedited, and continuous from attempt start to death/completion.</p>
    <p>The video should keep original audio and must not hide critical gameplay information.</p>
    <p>No cuts, speed edits, or visual tampering.</p>
    <p>No overlays that hide transitions, inputs, or attempt context.</p>
    <p>Video quality must be high enough to verify gameplay legitimacy.</p>

    <h3>3. Legitimacy Rules</h3>
    <p>Any hacked, tool-assisted, botted, macro-assisted, noclip, or modified run is prohibited.</p>
    <p>Runs using gameplay-altering mods, replay tools, or external automation will be denied.</p>

    <h3>4. Submission Data Accuracy</h3>
    <p>Choose the exact demon name and submit the real progress you achieved.</p>
    <p>Progress below the demon requirement is automatically invalid for list records.</p>
    <p>Your platform and refresh rate must match the run shown in the proof.</p>

    <h3>5. Raw Footage & Extra Evidence</h3>
    <p>Raw footage is strongly recommended for difficult records and may be required by moderators.</p>
    <p>If requested evidence is not provided in time, the submission can be rejected.</p>

    <h3>6. Review Outcomes</h3>
    <p><b>Approved:</b> record is added to the public list.</p>
    <p><b>Rejected:</b> record stays out of the list; you can resubmit with better proof.</p>
    <p><b>Flagged:</b> moderators may request extra clips, raw files, or clarification.</p>

    <h3>7. Abuse & Penalties</h3>
    <p>Fake submissions, impersonation, repeated spam, or intentionally misleading evidence may result in restrictions.</p>

    <div class="info-green" style="text-align: left; margin-top: 14px;">
        <b>Tip:</b> Keep the video public/unlisted and include useful run notes. Cleaner evidence means faster approval.
    </div>

    <p style="margin-top: 18px;">
        <a class="button blue hover" href="<?= e(base_url('submit.php')) ?>">Go to Submit a Record</a>
    </p>
</section>
<?php render_footer(); ?>
