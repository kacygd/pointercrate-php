<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function pointercrate_beaten_score(int $position): float
{
    return demonlist_beaten_score($position);
}

function pointercrate_score(int $position, int $requirement, int $progress): float
{
    return demonlist_score($position, $requirement, $progress);
}

function youtube_video_id(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $host = strtolower((string) $parts['host']);
    if (str_contains($host, 'youtube.com') && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        if (!empty($query['v']) && is_string($query['v'])) {
            return trim($query['v']);
        }
    }

    if (str_contains($host, 'youtu.be') && !empty($parts['path'])) {
        return trim((string) $parts['path'], '/');
    }

    return null;
}

function youtube_embed_url(string $url): ?string
{
    $id = youtube_video_id($url);
    return $id !== null && $id !== '' ? 'https://www.youtube.com/embed/' . rawurlencode($id) : null;
}

function card_thumbnail_url(array $demon): string
{
    $configured = trim((string) ($demon['thumbnail_url'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    $videoUrl = trim((string) ($demon['video_url'] ?? ''));
    $youtubeId = youtube_video_id($videoUrl);
    if ($youtubeId !== null && $youtubeId !== '') {
        return 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg';
    }

    return '';
}

function css_background_image(string $url): string
{
    if ($url === '') {
        return 'background-image: linear-gradient(135deg, #1f3048 0%, #101824 100%);';
    }

    $safe = str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", '', ''], $url);
    return "background-image: url('{$safe}');";
}

function level_comment_page_url(int $position, int $page = 1, string $fragment = 'level-comments'): string
{
    $position = max(1, $position);
    $page = max(1, $page);
    $path = (string) $position;
    if ($page > 1) {
        $path .= '/comments/page/' . $page;
    }

    $fragment = ltrim(trim($fragment), '#');
    if ($fragment !== '') {
        $path .= '#' . $fragment;
    }

    return base_url($path);
}

function demon_creator_name(array $demon): string
{
    return demon_primary_creator_name($demon);
}

function render_player_role_link(string $name, ?int $userId = null): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '-';
    }

    $labelText = $userId !== null && $userId > 0
        ? (user_public_name_by_id($userId, $trimmed) ?? $trimmed)
        : $trimmed;
    $label = '<b>' . e($labelText) . '</b>';
    if ($userId !== null && $userId > 0) {
        $url = base_url('players.php?uid=' . $userId);
        return '<a class="player-link" href="' . e($url) . '">' . $label . '</a>';
    }

    return $label;
}

function render_creator_credit(array $demon): string
{
    $creators = demon_creator_names($demon);
    if ($creators === []) {
        return '-';
    }

    $primary = array_shift($creators);
    
    $primaryTrimmed = trim($primary);
    $userStmt = db()->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $userStmt->execute([':username' => $primaryTrimmed]);
    $primaryUserId = (int) ($userStmt->fetchColumn() ?: 0);
    
    $html = render_player_role_link($primary, $primaryUserId > 0 ? $primaryUserId : null);

    if ($creators === []) {
        return $html;
    }
    $tooltipText = implode(', ', array_map(fn($c) => e($c), $creators));
    
    $html .= ' ' . e(t('demon.and')) . ' <span class="tooltip underdotted">';
    $html .= e(t('demon.more'));
    $html .= '<span class="tooltiptext fade">' . $tooltipText . '</span>';
    $html .= '</span>';

    return $html;
}

function video_host_label(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
        return 'YouTube';
    }
    if (str_contains($host, 'twitch.tv')) {
        return 'Twitch';
    }
    if (str_contains($host, 'bilibili.com')) {
        return 'Bilibili';
    }
    return $host !== '' ? $host : 'Video';
}

function render_demon_dropdown(string $id, string $title, string $description, array $demons, int $currentId): void
{
    ?>
    <div>
        <div class="button white hover no-shadow js-toggle" data-toggle-group="0" data-dropdown-id="<?= e($id) ?>">
            <?= e($title) ?>
        </div>

        <div class="see-through fade dropdown" id="<?= e($id) ?>">
            <div class="search js-search seperated" style="margin: 10px;">
                <input placeholder="<?= e(t('list.filter')) ?>" type="text">
            </div>
            <p style="margin: 10px;"><?= e($description) ?></p>
            <ul class="flex wrap space">
                <?php if ($demons === []): ?>
                    <li class="white" style="min-width: 100%; width: 100%;"><?= e(t('list.no_entries')) ?></li>
                <?php endif; ?>

                <?php foreach ($demons as $demon): ?>
                    <li class="hover white" title="#<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>">
                        <a href="<?= e(base_url((string) ((int) $demon['position']))) ?>">
                            #<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>
                            <br>
                            <i><?= e(t('list.published_by')) ?> <?= e((string) $demon['publisher']) ?></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

function render_level_comment_card(
    array $comment,
    array $levelCommentBadges,
    array $levelCommentReplies,
    bool $commentsOpen,
    bool $canModerateLevelComments,
    int $position,
    bool $isReply = false,
    int $commentPage = 1
): void {
    $commentId = (int) ($comment['id'] ?? 0);
    if ($commentId < 1) {
        return;
    }

    $commentCountry = normalize_country_code((string) ($comment['country_code'] ?? ''));
    $commentFlag = country_flag_html($commentCountry, true);
    $commentUsername = trim((string) ($comment['username'] ?? ''));
    $commentDisplayName = user_display_name_from_row($comment);
    $commentUserId = (int) ($comment['user_id'] ?? 0);
    $commentProfileUrl = $commentUserId > 0
        ? base_url('players.php?uid=' . $commentUserId)
        : base_url('players.php?user=' . rawurlencode($commentUsername));
    $commentBadgesHtml = render_user_badges($levelCommentBadges[$commentUserId] ?? [], 'level-comment-badges');
    $commentCreatedAt = strtotime((string) ($comment['created_at'] ?? '')) ?: time();
    $commentBody = (string) ($comment['body'] ?? '');
    $likeCount = (int) ($comment['like_count'] ?? 0);
    $dislikeCount = (int) ($comment['dislike_count'] ?? 0);
    $currentReaction = (int) ($comment['current_reaction'] ?? 0);
    $reportedByCurrentUser = (int) ($comment['reported_by_current_user'] ?? 0) === 1;
    $currentUserId = is_logged_in() ? (int) current_user_id() : 0;
    $canEditOwnComment = $currentUserId > 0 && $currentUserId === $commentUserId && !current_user_comments_disabled();
    $canDeleteComment = $canModerateLevelComments || $canEditOwnComment;
    $isPinned = !$isReply && (int) ($comment['is_pinned'] ?? 0) === 1;
    $commentUpdatedAt = strtotime((string) ($comment['updated_at'] ?? '')) ?: 0;
    $commentClasses = ['level-comment'];
    if ($isReply) {
        $commentClasses[] = 'level-comment-reply';
    }
    if ($isPinned) {
        $commentClasses[] = 'is-pinned';
    }
    $commentPage = max(1, $commentPage);
    $commentActionUrl = level_comment_page_url($position, $commentPage, 'comment-' . $commentId);
    $commentReplies = $levelCommentReplies[$commentId] ?? [];
    ?>
    <article id="comment-<?= $commentId ?>" class="<?= e(implode(' ', $commentClasses)) ?>">
        <div class="level-comment-head">
            <div class="level-comment-author">
                <a class="player-inline player-link" href="<?= e($commentProfileUrl) ?>" title="<?= e($commentUsername) ?>">
                    <?= $commentFlag ?><span><?= e($commentDisplayName !== '' ? $commentDisplayName : $commentUsername) ?></span>
                </a>
                <?= $commentBadgesHtml ?>
                <?php if ($isPinned): ?>
                    <span class="level-comment-pin"><?= e(t('comment.pinned')) ?></span>
                <?php endif; ?>
            </div>
            <time datetime="<?= e(date('c', $commentCreatedAt)) ?>">
                <?= e(date('Y-m-d H:i', $commentCreatedAt)) ?>
            </time>
        </div>
        <p class="level-comment-body"><?php if ($commentUpdatedAt > $commentCreatedAt): ?><span class="muted level-comment-edited"><?= e(t('comment.edited')) ?></span> <?php endif; ?><?= nl2br(e($commentBody)) ?></p>

        <div class="level-comment-actions" aria-label="<?= e(t('comment.actions')) ?>">
            <?php if (is_logged_in()): ?>
                <form method="post" action="<?= e($commentActionUrl) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="react_level_comment">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="reaction" value="like">
                    <button class="level-comment-action<?= $currentReaction === 1 ? ' is-active' : '' ?>" type="submit" title="<?= e(t('comment.like')) ?>">
                        <i class="fa fa-thumbs-up"></i><span><?= $likeCount ?></span>
                    </button>
                </form>
                <form method="post" action="<?= e($commentActionUrl) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="react_level_comment">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="reaction" value="dislike">
                    <button class="level-comment-action<?= $currentReaction === -1 ? ' is-active' : '' ?>" type="submit" title="<?= e(t('comment.dislike')) ?>">
                        <i class="fa fa-thumbs-down"></i><span><?= $dislikeCount ?></span>
                    </button>
                </form>
            <?php else: ?>
                <span class="level-comment-action-static"><i class="fa fa-thumbs-up"></i><span><?= $likeCount ?></span></span>
                <span class="level-comment-action-static"><i class="fa fa-thumbs-down"></i><span><?= $dislikeCount ?></span></span>
            <?php endif; ?>

            <?php if ($canModerateLevelComments && !$isReply): ?>
                <form method="post" action="<?= e($commentActionUrl) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="pin_level_comment">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <input type="hidden" name="pin_state" value="<?= $isPinned ? 'unpin' : 'pin' ?>">
                    <button class="level-comment-action" type="submit" title="<?= e($isPinned ? t('comment.unpin_title') : t('comment.pin_title')) ?>">
                        <i class="fa fa-thumb-tack"></i><span><?= e($isPinned ? t('comment.unpin') : t('comment.pin')) ?></span>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($canDeleteComment): ?>
                <form method="post" action="<?= e($commentActionUrl) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_level_comment">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <button class="level-comment-action is-danger" type="submit" title="<?= e(t('comment.delete_title')) ?>" data-confirm="<?= e(t('comment.delete_confirm')) ?>">
                        <i class="fa fa-trash"></i><span><?= e(t('common.delete')) ?></span>
                    </button>
                </form>
            <?php endif; ?>

            <?php if (is_logged_in()): ?>
                <?php if ($canEditOwnComment): ?>
                    <details class="level-comment-report level-comment-edit-box">
                        <summary><?= e(t('common.edit')) ?></summary>
                        <form class="stack-form level-comment-form level-comment-edit-form" method="post" action="<?= e($commentActionUrl) ?>">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="edit_level_comment">
                            <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                            <label class="field">
                                <span><?= e(t('comment.edit_comment')) ?></span>
                                <textarea name="comment_body" maxlength="<?= (int) level_comment_body_max_length() ?>" required><?= e($commentBody) ?></textarea>
                            </label>
                            <button class="button blue hover small" type="submit"><?= e(t('comment.save_edit')) ?></button>
                        </form>
                    </details>
                <?php elseif ($reportedByCurrentUser): ?>
                    <span class="level-comment-action-static"><?= e(t('comment.reported')) ?></span>
                <?php else: ?>
                    <details class="level-comment-report">
                        <summary><?= e(t('common.report')) ?></summary>
                        <form class="level-comment-mini-form" method="post" action="<?= e($commentActionUrl) ?>">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="report_level_comment">
                            <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                            <input type="text" name="report_reason" maxlength="<?= (int) level_comment_report_reason_max_length() ?>" placeholder="<?= e(t('comment.reason_optional')) ?>">
                            <button class="button danger small" type="submit"><?= e(t('common.submit')) ?></button>
                        </form>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (!$isReply && $commentsOpen && current_user_can_comment()): ?>
            <details class="level-comment-reply-box">
                <summary><?= e(t('comment.reply')) ?></summary>
                <form class="stack-form level-comment-form level-comment-reply-form" method="post" action="<?= e($commentActionUrl) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_level_comment">
                    <input type="hidden" name="parent_comment_id" value="<?= $commentId ?>">
                    <label class="field">
                        <span><?= e(t('comment.add_reply')) ?></span>
                        <textarea name="comment_body" maxlength="<?= (int) level_comment_body_max_length() ?>" placeholder="<?= e(t('comment.reply_placeholder')) ?>" required></textarea>
                    </label>
                    <button class="button blue hover small" type="submit"><?= e(t('comment.post_reply')) ?></button>
                </form>
            </details>
        <?php endif; ?>

        <?php if (!$isReply && $commentReplies !== []): ?>
            <details class="level-comment-replies-toggle">
                <summary>
                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    <span><?= e(t_choice('comment.reply_count_one', 'comment.reply_count_many', count($commentReplies))) ?></span>
                </summary>
                <div class="level-comment-replies">
                    <?php foreach ($commentReplies as $reply): ?>
                        <?php render_level_comment_card($reply, $levelCommentBadges, $levelCommentReplies, $commentsOpen, $canModerateLevelComments, $position, true, $commentPage); ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </article>
    <?php
}

$requestedRank = (int) ($_GET['rank'] ?? 0);
$requestedId = (int) ($_GET['id'] ?? 0);
if ($requestedRank < 1 && $requestedId < 1) {
    redirect('index.php');
}

if ($requestedRank < 1 && $requestedId > 0) {
    $legacyStmt = db()->prepare('SELECT position FROM demons WHERE id = :id LIMIT 1');
    $legacyStmt->execute([':id' => $requestedId]);
    $legacyPosition = (int) ($legacyStmt->fetchColumn() ?: 0);
    if ($legacyPosition < 1) {
        http_response_code(404);
        render_header(t('demon.not_found_title'), 'list');
        ?>
        <section class="panel fade">
            <h1><?= e(t('demon.not_found_title')) ?></h1>
            <p class="muted"><?= e(t('demon.not_found_text')) ?></p>
            <a class="button blue hover" href="<?= e(base_url('index.php')) ?>"><?= e(t('common.back_to_list')) ?></a>
        </section>
        <?php
        render_footer();
        exit;
    }

    redirect((string) $legacyPosition);
}

$rank = $requestedRank;

$hasUserBannedColumn = users_has_is_banned_column();
$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();

$allDemons = db()->query('SELECT id, position, name, publisher, legacy
                           FROM demons
                           ORDER BY position ASC')->fetchAll();

$demonSelectSql = $hasUserBannedColumn
    ? 'SELECT d.*,
              COUNT(CASE WHEN banned_users.id IS NULL THEN c.id END) AS completion_count
       FROM demons d
       LEFT JOIN completions c ON c.demon_id = d.id
       LEFT JOIN users banned_users
         ON LOWER(banned_users.username) = LOWER(c.player)
        AND COALESCE(banned_users.is_banned, 0) = 1
       WHERE d.position = :rank
       GROUP BY d.id'
    : 'SELECT d.*, COUNT(c.id) AS completion_count
       FROM demons d
       LEFT JOIN completions c ON c.demon_id = d.id
       WHERE d.position = :rank
       GROUP BY d.id';
$stmt = db()->prepare($demonSelectSql);
$stmt->execute([':rank' => $rank]);
$demon = $stmt->fetch();

if ($demon !== false) {
    $id = (int) ($demon['id'] ?? 0);
} else {
    $id = 0;
}

if ($demon === false) {
    http_response_code(404);
    render_header(t('demon.not_found_title'), 'list');
    ?>
    <section class="panel fade">
        <h1><?= e(t('demon.not_found_title')) ?></h1>
        <p class="muted"><?= e(t('demon.not_found_text')) ?></p>
        <a class="button blue hover" href="<?= e(base_url('index.php')) ?>"><?= e(t('common.back_to_list')) ?></a>
    </section>
    <?php
    render_footer();
    exit;
}

$levelCommentsDisabledMessage = level_comments_disabled_message($demon);
$demonPosition = (int) ($demon['position'] ?? 0);
$levelCommentActionPage = max(1, (int) ($_POST['comment_page'] ?? $_GET['comment_page'] ?? 1));
$levelCommentRedirect = level_comment_page_url($demonPosition, $levelCommentActionPage);
$levelCommentAnchorRedirect = static function (int $commentId) use ($demonPosition, $levelCommentActionPage): string {
    return level_comment_page_url(
        $demonPosition,
        $levelCommentActionPage,
        $commentId > 0 ? 'comment-' . $commentId : 'level-comments'
    );
};
$fetchLevelCommentForAction = static function (int $commentId) use ($id): ?array {
    if ($commentId < 1) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, demon_id, user_id, parent_comment_id
         FROM level_comments
         WHERE id = :id AND demon_id = :demon_id
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $commentId,
        ':demon_id' => $id,
    ]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
};

if (method_is_post()) {
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['add_level_comment', 'edit_level_comment', 'react_level_comment', 'report_level_comment', 'pin_level_comment', 'delete_level_comment'], true)) {
        $commentId = (int) ($_POST['comment_id'] ?? 0);
        $commentRedirect = $levelCommentAnchorRedirect($commentId);

        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect($commentRedirect);
        }

        if (!is_logged_in()) {
            flash('error', t('comments.login_required'));
            redirect('login.php?next=' . rawurlencode($commentRedirect));
        }

        if (in_array($action, ['add_level_comment', 'edit_level_comment'], true) && current_user_comments_disabled()) {
            flash('error', t('comments.account_disabled'));
            redirect($commentRedirect);
        }

        if ($action === 'add_level_comment') {
            if (!level_comments_enabled_for_demon($demon)) {
                flash('error', $levelCommentsDisabledMessage ?? t('comments.disabled'));
                redirect($levelCommentRedirect);
            }

            $parentCommentId = max(0, (int) ($_POST['parent_comment_id'] ?? 0));
            if ($parentCommentId > 0) {
                try {
                    $parentComment = $fetchLevelCommentForAction($parentCommentId);
                    if ($parentComment === null) {
                        flash('error', t('comments.reply_target_missing'));
                        redirect($levelCommentRedirect);
                    }

                    $parentParentId = (int) ($parentComment['parent_comment_id'] ?? 0);
                    if ($parentParentId > 0) {
                        $parentCommentId = $parentParentId;
                    }
                } catch (Throwable) {
                    flash('error', t('comments.reply_schema_error'));
                    redirect($levelCommentRedirect);
                }
            }

            $commentBody = normalize_level_comment_body((string) ($_POST['comment_body'] ?? ''));
            if ($commentBody === '') {
                flash('error', t('comments.empty'));
                redirect($parentCommentId > 0 ? $levelCommentAnchorRedirect($parentCommentId) : $levelCommentRedirect);
            }

            try {
                $commentStmt = db()->prepare(
                    'INSERT INTO level_comments (demon_id, user_id, parent_comment_id, body)
                     VALUES (:demon_id, :user_id, :parent_comment_id, :body)'
                );
                $commentStmt->execute([
                    ':demon_id' => $id,
                    ':user_id' => current_user_id(),
                    ':parent_comment_id' => $parentCommentId > 0 ? $parentCommentId : null,
                    ':body' => $commentBody,
                ]);
                flash('success', $parentCommentId > 0 ? t('comments.reply_posted') : t('comments.posted'));
            } catch (Throwable) {
                flash('error', t('comments.post_failed'));
            }

            redirect($parentCommentId > 0 ? $levelCommentAnchorRedirect($parentCommentId) : $levelCommentRedirect);
        }

        try {
            $targetComment = $fetchLevelCommentForAction($commentId);
        } catch (Throwable) {
            flash('error', t('comments.actions_schema_error'));
            redirect($levelCommentRedirect);
        }

        if ($targetComment === null) {
            flash('error', t('comments.not_found'));
            redirect($levelCommentRedirect);
        }

        if ($action === 'edit_level_comment') {
            $targetUserId = (int) ($targetComment['user_id'] ?? 0);
            if ($targetUserId < 1 || $targetUserId !== (int) current_user_id()) {
                flash('error', t('comments.edit_own_only'));
                redirect($commentRedirect);
            }

            $commentBody = normalize_level_comment_body((string) ($_POST['comment_body'] ?? ''));
            if ($commentBody === '') {
                flash('error', t('comments.empty'));
                redirect($commentRedirect);
            }

            try {
                $editComment = db()->prepare(
                    'UPDATE level_comments
                     SET body = :body,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :comment_id
                       AND demon_id = :demon_id
                       AND user_id = :user_id'
                );
                $editComment->execute([
                    ':body' => $commentBody,
                    ':comment_id' => $commentId,
                    ':demon_id' => $id,
                    ':user_id' => current_user_id(),
                ]);
                flash('success', t('comments.updated'));
            } catch (Throwable) {
                flash('error', t('comments.update_failed'));
            }

            redirect($commentRedirect);
        }

        if ($action === 'delete_level_comment') {
            $targetUserId = (int) ($targetComment['user_id'] ?? 0);
            $canDeleteTarget = can_moderate_level_comments() || ($targetUserId > 0 && $targetUserId === (int) current_user_id());
            if (!$canDeleteTarget) {
                flash('error', t('comments.delete_own_only'));
                redirect($commentRedirect);
            }

            $parentCommentId = (int) ($targetComment['parent_comment_id'] ?? 0);
            try {
                $deleteComment = db()->prepare('DELETE FROM level_comments WHERE id = :comment_id AND demon_id = :demon_id');
                $deleteComment->execute([
                    ':comment_id' => $commentId,
                    ':demon_id' => $id,
                ]);
                flash('success', t('comments.deleted'));
            } catch (Throwable) {
                flash('error', t('comments.delete_failed'));
            }

            redirect($parentCommentId > 0 ? $levelCommentAnchorRedirect($parentCommentId) : $levelCommentRedirect);
        }

        if ($action === 'react_level_comment') {
            $reactionValue = level_comment_reaction_value((string) ($_POST['reaction'] ?? ''));
            if ($reactionValue === null) {
                flash('error', t('comments.invalid_reaction'));
                redirect($commentRedirect);
            }

            try {
                $currentReactionStmt = db()->prepare(
                    'SELECT reaction
                     FROM level_comment_reactions
                     WHERE comment_id = :comment_id AND user_id = :user_id
                     LIMIT 1'
                );
                $currentReactionStmt->execute([
                    ':comment_id' => $commentId,
                    ':user_id' => current_user_id(),
                ]);
                $currentReaction = (int) ($currentReactionStmt->fetchColumn() ?: 0);

                if ($currentReaction === $reactionValue) {
                    $deleteReaction = db()->prepare(
                        'DELETE FROM level_comment_reactions
                         WHERE comment_id = :comment_id AND user_id = :user_id'
                    );
                    $deleteReaction->execute([
                        ':comment_id' => $commentId,
                        ':user_id' => current_user_id(),
                    ]);
                } else {
                    $reactionStmt = db()->prepare(
                        'INSERT INTO level_comment_reactions (comment_id, user_id, reaction)
                         VALUES (:comment_id, :user_id, :reaction)
                         ON DUPLICATE KEY UPDATE
                            reaction = VALUES(reaction),
                            updated_at = CURRENT_TIMESTAMP'
                    );
                    $reactionStmt->execute([
                        ':comment_id' => $commentId,
                        ':user_id' => current_user_id(),
                        ':reaction' => $reactionValue,
                    ]);
                }
            } catch (Throwable) {
                flash('error', t('comments.reaction_failed'));
            }

            redirect($commentRedirect);
        }

        if ($action === 'report_level_comment') {
            $reason = normalize_level_comment_report_reason((string) ($_POST['report_reason'] ?? ''));
            try {
                $reportStmt = db()->prepare(
                    'INSERT INTO level_comment_reports (comment_id, user_id, reason)
                     VALUES (:comment_id, :user_id, :reason)
                     ON DUPLICATE KEY UPDATE
                        reason = VALUES(reason),
                        created_at = CURRENT_TIMESTAMP'
                );
                $reportStmt->execute([
                    ':comment_id' => $commentId,
                    ':user_id' => current_user_id(),
                    ':reason' => $reason !== '' ? $reason : null,
                ]);
                flash('success', t('comments.report_sent'));
            } catch (Throwable) {
                flash('error', t('comments.report_failed'));
            }

            redirect($commentRedirect);
        }

        if ($action === 'pin_level_comment') {
            if (!can_pin_level_comments()) {
                flash('error', t('comments.pin_permission'));
                redirect($commentRedirect);
            }

            if ((int) ($targetComment['parent_comment_id'] ?? 0) > 0) {
                flash('error', t('comments.pin_top_level_only'));
                redirect($commentRedirect);
            }

            $pinState = strtolower(trim((string) ($_POST['pin_state'] ?? 'pin')));
            try {
                $commentDb = db();
                if ($pinState === 'unpin') {
                    $pinStmt = $commentDb->prepare(
                        'UPDATE level_comments
                         SET is_pinned = 0,
                             pinned_by_user_id = NULL,
                             pinned_at = NULL
                         WHERE id = :comment_id AND demon_id = :demon_id'
                    );
                    $pinStmt->execute([
                        ':comment_id' => $commentId,
                        ':demon_id' => $id,
                    ]);
                    flash('success', t('comments.unpinned'));
                } else {
                    $commentDb->beginTransaction();

                    $levelCommentLocks = $commentDb->prepare(
                        'SELECT id
                         FROM level_comments
                         WHERE demon_id = :demon_id
                           AND parent_comment_id IS NULL
                         ORDER BY id ASC
                         FOR UPDATE'
                    );
                    $levelCommentLocks->execute([
                        ':demon_id' => $id,
                    ]);
                    $targetExists = false;
                    foreach ($levelCommentLocks->fetchAll() as $lockedComment) {
                        if ((int) ($lockedComment['id'] ?? 0) === $commentId) {
                            $targetExists = true;
                            break;
                        }
                    }
                    if (!$targetExists) {
                        throw new RuntimeException(t('comments.not_found'));
                    }

                    $clearPinned = $commentDb->prepare(
                        'UPDATE level_comments
                         SET is_pinned = 0,
                             pinned_by_user_id = NULL,
                             pinned_at = NULL
                         WHERE demon_id = :demon_id
                           AND parent_comment_id IS NULL
                           AND id <> :comment_id
                           AND COALESCE(is_pinned, 0) = 1'
                    );
                    $clearPinned->execute([
                        ':comment_id' => $commentId,
                        ':demon_id' => $id,
                    ]);

                    $pinStmt = $commentDb->prepare(
                        'UPDATE level_comments
                         SET is_pinned = 1,
                             pinned_by_user_id = :user_id,
                             pinned_at = CURRENT_TIMESTAMP
                         WHERE id = :comment_id AND demon_id = :demon_id'
                    );
                    $pinStmt->execute([
                        ':comment_id' => $commentId,
                        ':demon_id' => $id,
                        ':user_id' => current_user_id(),
                    ]);

                    $commentDb->commit();
                    flash('success', t('comments.pinned'));
                }
            } catch (Throwable) {
                if (isset($commentDb) && $commentDb->inTransaction()) {
                    $commentDb->rollBack();
                }
                flash('error', t('comments.pin_failed'));
            }

            redirect($commentRedirect);
        }
    }
}

$main = [];
$extended = [];
$legacy = [];

foreach ($allDemons as $entry) {
    $position = (int) $entry['position'];
    $isLegacy = (int) ($entry['legacy'] ?? 0) === 1;
    $listBucket = demonlist_list_bucket($position, $isLegacy);

    if ($listBucket === 'main') {
        $main[] = $entry;
        continue;
    }

    if ($listBucket === 'extended') {
        $extended[] = $entry;
        continue;
    }

    $legacy[] = $entry;
}

$prevId = null;
$nextId = null;
for ($i = 0, $count = count($allDemons); $i < $count; $i++) {
    if ((int) $allDemons[$i]['id'] === $id) {
        if ($i > 0) {
            $prevId = (int) $allDemons[$i - 1]['position'];
        }
        if ($i < $count - 1) {
            $nextId = (int) $allDemons[$i + 1]['position'];
        }
        break;
    }
}

$completionsSql = $hasUserBannedColumn
    ? 'SELECT c.*, u.country_code, ' . user_select_display_name_expression('u', 'username', 'display_name') . '
       FROM completions c
       LEFT JOIN users u ON LOWER(u.username) = LOWER(c.player)
       WHERE c.demon_id = :id
         AND (u.id IS NULL OR COALESCE(u.is_banned, 0) = 0)
       ORDER BY c.progress DESC, COALESCE(c.placement, 999999), c.created_at ASC'
    : 'SELECT c.*, u.country_code, ' . user_select_display_name_expression('u', 'username', 'display_name') . '
       FROM completions c
       LEFT JOIN users u ON LOWER(u.username) = LOWER(c.player)
       WHERE c.demon_id = :id
       ORDER BY c.progress DESC, COALESCE(c.placement, 999999), c.created_at ASC';
$completionsStmt = db()->prepare($completionsSql);
$completionsStmt->execute([':id' => $id]);
$completions = $completionsStmt->fetchAll();

$historyStmt = db()->prepare('SELECT created_at, old_position, new_position, note
                              FROM demon_position_history
                              WHERE demon_id = :demon_id
                              ORDER BY created_at DESC
                              LIMIT 50');
$historyStmt->execute([':demon_id' => $id]);
$positionHistory = $historyStmt->fetchAll();

$currentCommentUserId = is_logged_in() ? (int) current_user_id() : 0;
$levelCommentsPerPage = 10;
$levelCommentRequestedPage = max(1, (int) ($_GET['comment_page'] ?? 1));
$levelCommentTotal = 0;
$levelCommentTotalPages = 1;
$levelCommentPage = 1;
$levelCommentsRaw = [];
$levelComments = [];
$levelCommentReplies = [];

$commentUserFilterSql = $hasUserBannedColumn ? ' AND COALESCE(u.is_banned, 0) = 0' : '';
$topLevelCommentConditionSql = ' AND COALESCE(lc.parent_comment_id, 0) = 0';
$commentSelectSql = 'SELECT lc.id, lc.parent_comment_id, lc.body, lc.is_pinned, lc.pinned_at, lc.created_at, lc.updated_at,
           u.id AS user_id, u.username, u.country_code, '
    . user_select_display_name_expression('u', 'username', 'display_name') . ',
           COALESCE(lr.like_count, 0) AS like_count,
           COALESCE(lr.dislike_count, 0) AS dislike_count,
           COALESCE(myr.reaction, 0) AS current_reaction,
           CASE WHEN myrep.id IS NULL THEN 0 ELSE 1 END AS reported_by_current_user
    FROM level_comments lc
    INNER JOIN users u ON u.id = lc.user_id
    LEFT JOIN (
        SELECT comment_id,
               SUM(CASE WHEN reaction = 1 THEN 1 ELSE 0 END) AS like_count,
               SUM(CASE WHEN reaction = -1 THEN 1 ELSE 0 END) AS dislike_count
        FROM level_comment_reactions
        GROUP BY comment_id
    ) lr ON lr.comment_id = lc.id
    LEFT JOIN level_comment_reactions myr
      ON myr.comment_id = lc.id AND myr.user_id = :current_reaction_user_id
    LEFT JOIN level_comment_reports myrep
      ON myrep.comment_id = lc.id AND myrep.user_id = :current_report_user_id
    WHERE lc.demon_id = :demon_id';

try {
    $commentCountSql = 'SELECT COUNT(*)
                        FROM level_comments lc
                        INNER JOIN users u ON u.id = lc.user_id
                        WHERE lc.demon_id = :demon_id'
        . $topLevelCommentConditionSql
        . $commentUserFilterSql;
    $commentCountStmt = db()->prepare($commentCountSql);
    $commentCountStmt->execute([':demon_id' => $id]);
    $levelCommentTotal = max(0, (int) $commentCountStmt->fetchColumn());
} catch (Throwable) {
    $levelCommentTotal = 0;
}

if ($levelCommentTotal > 0) {
    $levelCommentTotalPages = max(1, (int) ceil($levelCommentTotal / $levelCommentsPerPage));
    $levelCommentPage = min($levelCommentRequestedPage, $levelCommentTotalPages);
    $levelCommentOffset = ($levelCommentPage - 1) * $levelCommentsPerPage;

    try {
        $commentsSql = $commentSelectSql
            . $topLevelCommentConditionSql
            . $commentUserFilterSql
            . ' ORDER BY COALESCE(lc.is_pinned, 0) DESC, lc.pinned_at DESC, lc.created_at DESC
                LIMIT :limit OFFSET :offset';
        $commentsStmt = db()->prepare($commentsSql);
        $commentsStmt->bindValue(':demon_id', $id, PDO::PARAM_INT);
        $commentsStmt->bindValue(':current_reaction_user_id', $currentCommentUserId, PDO::PARAM_INT);
        $commentsStmt->bindValue(':current_report_user_id', $currentCommentUserId, PDO::PARAM_INT);
        $commentsStmt->bindValue(':limit', $levelCommentsPerPage, PDO::PARAM_INT);
        $commentsStmt->bindValue(':offset', $levelCommentOffset, PDO::PARAM_INT);
        $commentsStmt->execute();
        $levelComments = $commentsStmt->fetchAll();

        $parentCommentIds = array_values(array_filter(array_map(
            static fn(array $comment): int => (int) ($comment['id'] ?? 0),
            $levelComments
        )));

        $levelCommentRepliesRaw = [];
        if ($parentCommentIds !== []) {
            $parentCommentIdList = implode(', ', $parentCommentIds);
            $repliesSql = $commentSelectSql
                . $commentUserFilterSql
                . ' AND lc.parent_comment_id IN (' . $parentCommentIdList . ')
                    ORDER BY lc.created_at ASC';
            $repliesStmt = db()->prepare($repliesSql);
            $repliesStmt->execute([
                ':demon_id' => $id,
                ':current_reaction_user_id' => $currentCommentUserId,
                ':current_report_user_id' => $currentCommentUserId,
            ]);
            $levelCommentRepliesRaw = $repliesStmt->fetchAll();
        }

        foreach ($levelCommentRepliesRaw as $comment) {
            $parentCommentId = (int) ($comment['parent_comment_id'] ?? 0);
            if ($parentCommentId > 0) {
                $levelCommentReplies[$parentCommentId][] = $comment;
            }
        }

        $levelCommentsRaw = array_merge($levelComments, $levelCommentRepliesRaw);
    } catch (Throwable) {
        $levelComments = [];
        $levelCommentReplies = [];
        $levelCommentsRaw = [];
    }
}

$levelCommentVisibleCount = count($levelComments);
$levelCommentPageUrl = static function (int $page) use ($demonPosition): string {
    return level_comment_page_url($demonPosition, $page);
};
$levelCommentBadges = user_badges_by_user_ids(
    db(),
    array_unique(array_map(static fn(array $comment): int => (int) ($comment['user_id'] ?? 0), $levelCommentsRaw))
);
$canModerateLevelComments = can_moderate_level_comments();

$listEditorsSql = 'SELECT username, ' . user_select_display_name_expression() . ', country_code, youtube_channel
                   FROM users
                   WHERE role IN ("owner", "list_editor")';
if ($hasUserBannedColumn) {
    $listEditorsSql .= ' AND COALESCE(is_banned, 0) = 0';
}
$listEditorsSql .= '
                   ORDER BY created_at ASC, username ASC
                   LIMIT 20';
$listEditors = db()->query($listEditorsSql)->fetchAll();

$listHelpersSql = 'SELECT username, ' . user_select_display_name_expression() . ', country_code, youtube_channel
                   FROM users
                   WHERE role = "list_helper"';
if ($hasUserBannedColumn) {
    $listHelpersSql .= ' AND COALESCE(is_banned, 0) = 0';
}
$listHelpersSql .= '
                   ORDER BY created_at ASC, username ASC
                   LIMIT 20';
$listHelpers = db()->query($listHelpersSql)->fetchAll();

$discordWidgetUrl = discord_server_widget_url();

$embed = youtube_embed_url((string) $demon['video_url']);
$thumbUrl = card_thumbnail_url($demon);
$thumbStyle = css_background_image($thumbUrl);

$position = (int) $demon['position'];
$requirement = (int) $demon['requirement'];
$minimumScore = number_format(pointercrate_score($position, $requirement, $requirement), 2);
$fullScore = number_format(pointercrate_score($position, $requirement, 100), 2);
$isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
$showDemonPoints = demonlist_is_ranked_entry($position, $isLegacy);
$currentBucket = demonlist_list_bucket($position, $isLegacy);
$category = match ($currentBucket) {
    'extended' => t('list.extended'),
    'legacy' => t('list.legacy'),
    default => t('list.main'),
};
$creator = demon_creator_name($demon);
$publisher = trim((string) ($demon['publisher'] ?? ''));
$verifier = trim((string) ($demon['verifier'] ?? ''));
$levelDescription = normalize_demon_description((string) ($demon['description'] ?? ''));
$publisherUserId = isset($demon['publisher_user_id']) ? (int) $demon['publisher_user_id'] : 0;
$verifierUserId = isset($demon['verifier_user_id']) ? (int) $demon['verifier_user_id'] : 0;
$verifiedMetaSuffix = $verifier !== '' ? t('demon.meta_verified_by', ['verifier' => $verifier]) : '';
$levelInfoRows = demon_level_info_rows();
$levelInfoCustomValues = demon_level_info_custom_values(db(), $id);

$renderLevelInfoValue = static function (array $row) use (
    $demon,
    $position,
    $category,
    $requirement,
    $creator,
    $publisher,
    $publisherUserId,
    $verifier,
    $verifierUserId,
    $levelInfoCustomValues
): string {
    if ((string) ($row['type'] ?? 'field') === 'custom') {
        $key = demon_level_info_normalize_custom_key((string) ($row['key'] ?? ''));
        $value = $key !== '' ? (string) ($levelInfoCustomValues[$key] ?? '') : '';
        if ($value === '') {
            $value = (string) ($row['default_value'] ?? '');
        }

        return $value !== '' ? e($value) : '-';
    }

    $field = (string) ($row['field'] ?? '');
    return match ($field) {
        'position' => '#' . $position,
        'category' => e($category),
        'difficulty' => e((string) ($demon['difficulty'] ?? '-')),
        'requirement' => $requirement . '%',
        'creator' => render_creator_credit($demon),
        'publisher' => $publisher !== '' ? render_player_role_link($publisher, $publisherUserId > 0 ? $publisherUserId : null) : '-',
        'verifier' => $verifier !== '' ? render_player_role_link($verifier, $verifierUserId > 0 ? $verifierUserId : null) : '-',
        'level_id' => e((string) (($demon['level_id'] ?? '') !== '' ? $demon['level_id'] : '-')),
        'level_length' => e((string) (($demon['level_length'] ?? '') !== '' ? $demon['level_length'] : '-')),
        'song' => e((string) (($demon['song'] ?? '') !== '' ? $demon['song'] : '-')),
        'object_count' => ($demon['object_count'] ?? null) !== null ? e(number_format((int) $demon['object_count'])) : '-',
        default => '-',
    };
};

$metaDescription = t('demon.meta_description', [
    'position' => $position,
    'name' => (string) $demon['name'],
    'creator' => $creator !== '' ? $creator : t('common.unknown'),
    'publisher' => $publisher !== '' ? $publisher : t('common.unknown'),
    'verified' => $verifiedMetaSuffix,
    'requirement' => $requirement,
    'points' => $showDemonPoints ? $fullScore : '0.00',
]);

render_header((string) $demon['name'], 'list', [
    'title' => '#' . $position . ' - ' . (string) $demon['name'],
    'description' => $metaDescription,
    'url' => base_url((string) $position),
    'image' => $thumbUrl,
]);
?>

<nav class="flex wrap m-center fade" id="lists" style="text-align: center;">
    <?php
    $mainListDescription = demonlist_main_list_dropdown_description($showExtendedList, $showLegacyList);
    $extendedListDescription = demonlist_extended_list_dropdown_description(true);
    $legacyListDescription = demonlist_legacy_list_dropdown_description();
    ?>
    <?php render_demon_dropdown('mainlist', t('list.main'), $mainListDescription, $main, $id); ?>
    <?php if ($showExtendedList): ?>
        <?php render_demon_dropdown('extended', t('list.extended'), $extendedListDescription, $extended, $id); ?>
    <?php endif; ?>
    <?php if ($showLegacyList): ?>
        <?php render_demon_dropdown('legacy', t('list.legacy'), $legacyListDescription, $legacy, $id); ?>
    <?php endif; ?>
</nav>

<div class="flex m-center container">
    <main class="left">
        <section class="panel fade demon-hero-panel">
            <div class="flex mobile-col demon-hero">
                <a class="thumb ratio-16-9 demon-hero-thumb" href="<?= e((string) $demon['video_url']) ?>" target="_blank" rel="noreferrer" style="<?= e($thumbStyle) ?>">
                    <?php if ($thumbUrl !== ''): ?>
                        <img src="<?= e($thumbUrl) ?>" alt="<?= e(t('demon.thumbnail_alt', ['name' => (string) $demon['name']])) ?>" loading="lazy">
                    <?php endif; ?>
                </a>
                <div class="demon-hero-content">
                    <h1 class="demon-hero-title">
                        #<?= $position ?> &#8211; <?= e((string) $demon['name']) ?>
                    </h1>
                    <p class="demon-hero-byline">
                        <?= e(t('demon.byline_by')) ?> <?= render_creator_credit($demon) ?>, <?= e(t('list.published_by')) ?> <?= render_player_role_link($publisher, $publisherUserId > 0 ? $publisherUserId : null) ?><?php if ($verifier !== ''): ?>, <?= e(t('list.verified_by')) ?> <?= render_player_role_link($verifier, $verifierUserId > 0 ? $verifierUserId : null) ?><?php endif; ?>
                    </p>
                    <?php if ($showDemonPoints): ?>
                        <p class="demon-hero-score">
                            <?= $minimumScore ?> (<?= $requirement ?>%) &#8212; <?= $fullScore ?> (100%) <?= e(t('list.points')) ?>
                        </p>
                    <?php endif; ?>
                    <div class="demon-hero-actions">
                        <?php if ($prevId !== null): ?>
                            <a class="button white hover small" href="<?= e(base_url((string) $prevId)) ?>"><i class="fa fa-chevron-left"></i> <?= e(t('demon.prev')) ?></a>
                        <?php endif; ?>
                        <?php if ($nextId !== null): ?>
                            <a class="button white hover small" href="<?= e(base_url((string) $nextId)) ?>"><?= e(t('demon.next')) ?> <i class="fa fa-chevron-right"></i></a>
                        <?php endif; ?>
                        <a class="button blue hover small" href="<?= e((string) $demon['video_url']) ?>" target="_blank" rel="noreferrer"><?= e(t('demon.verification_video')) ?></a>
                    </div>
                </div>
            </div>

            <?php if ($levelDescription !== ''): ?>
                <blockquote class="demon-description-quote">
                    &ldquo;<?= nl2br(e($levelDescription)) ?>&rdquo;
                </blockquote>
            <?php endif; ?>

            <div class="detail-grid demon-detail-grid">
                <div class="panel subtle">
                    <h3><?= e(t('demon.level_info')) ?></h3>
                    <?php if ($levelInfoRows === []): ?>
                        <p class="muted"><?= e(t('demon.no_level_info')) ?></p>
                    <?php else: ?>
                        <dl class="key-value compact">
                            <?php foreach ($levelInfoRows as $row): ?>
                                <div><dt><?= e((string) ($row['label'] ?? '')) ?></dt><dd><?= $renderLevelInfoValue($row) ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </div>
                <div class="panel subtle">
                    <h3><?= e(t('demon.scoring')) ?></h3>
                    <dl class="key-value compact">
                        <div><dt><?= e(t('demon.at_requirement')) ?></dt><dd><?= $minimumScore ?> pts</dd></div>
                        <div><dt><?= e(t('demon.at_100')) ?></dt><dd><?= $fullScore ?> pts</dd></div>
                        <div><dt><?= e(t('demon.completions')) ?></dt><dd><?= (int) $demon['completion_count'] ?></dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <?php if ($embed !== null): ?>
            <section class="panel fade">
                <div class="panel-head">
                    <h2><?= e(t('demon.verification_preview')) ?></h2>
                </div>
                <iframe class="ratio-16-9 demon-preview-frame" allowfullscreen src="<?= e($embed) ?>" title="<?= e(t('demon.video_iframe_title', ['name' => (string) $demon['name']])) ?>"></iframe>
            </section>
        <?php endif; ?>

        <section class="records panel fade">
            <div class="underlined pad">
                <h2><?= e(t('demon.records')) ?></h2>
                <h3><?= e(t('demon.qualify', ['requirement' => $requirement])) ?></h3>
                <h4><?= e(t('demon.records_submitted', ['count' => count($completions)])) ?></h4>
            </div>

            <?php if ($completions === []): ?>
                <h3><?= e(t('demon.no_records')) ?></h3>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table records-table">
                        <thead>
                            <tr>
                                <th class="blue"><?= e(t('demon.record_holder')) ?></th>
                                <th class="blue"><?= e(t('common.progress')) ?></th>
                                <th class="blue"><?= e(t('demon.video_proof')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completions as $completion): ?>
                                <?php
                                $progress = (int) ($completion['progress'] ?? 100);
                                $countryCode = normalize_country_code((string) ($completion['country_code'] ?? ''));
                                $playerFlag = country_flag_html($countryCode, true);
                                $playerName = (string) $completion['player'];
                                $playerLabel = trim((string) ($completion['display_name'] ?? $playerName));
                                $playerProfileUrl = base_url('players.php?user=' . rawurlencode($playerName));
                                ?>
                                <tr>
                                    <td>
                                        <span class="player-inline">
                                            <?= $playerFlag ?><a class="player-link" href="<?= e($playerProfileUrl) ?>" title="<?= e($playerName) ?>"><?= e($playerLabel) ?></a>
                                        </span>
                                    </td>
                                    <td><?= $progress ?>%</td>
                                    <td>
                                        <a class="link" target="_blank" rel="noreferrer" href="<?= e((string) $completion['video_url']) ?>">
                                            <?= e(video_host_label((string) $completion['video_url'])) ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel fade">
            <details class="history-toggle">
                <summary><?= e(t('demon.position_history')) ?></summary>
                <div class="history-toggle-content">
                    <div class="history-toggle-inner">
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th><?= e(t('demon.date_change')) ?></th>
                                        <th><?= e(t('demon.new_position')) ?></th>
                                        <th><?= e(t('demon.reason')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($positionHistory === []): ?>
                                        <tr><td colspan="3" class="muted"><?= e(t('demon.no_position_history')) ?></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($positionHistory as $event): ?>
                                        <?php
                                        $reason = trim((string) ($event['note'] ?? ''));
                                        if ($reason === '') {
                                            $reason = $event['old_position'] === null ? t('demon.initial_placement') : t('demon.position_updated');
                                        }
                                        ?>
                                        <tr>
                                            <td><?= e(date('Y-m-d H:i', strtotime((string) $event['created_at']))) ?></td>
                                            <td>#<?= (int) $event['new_position'] ?></td>
                                            <td><?= e($reason) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </details>
        </section>

        <section class="panel fade level-comments-panel" id="level-comments">
            <div class="panel-head">
                <h2><?= e(t('common.comments')) ?></h2>
                <?php if ($levelCommentTotal > 0): ?>
                    <p><?= e(t_choice('demon.comments_count_one', 'demon.comments_count_many', $levelCommentTotal)) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($levelCommentsDisabledMessage !== null): ?>
                <p class="muted level-comments-status"><?= e($levelCommentsDisabledMessage) ?></p>
            <?php elseif (current_user_can_comment()): ?>
                <form class="stack-form level-comment-form" method="post" action="<?= e(base_url((string) $position . '#level-comments')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_level_comment">
                    <label class="field">
                        <span><?= e(t('demon.add_comment')) ?></span>
                        <textarea name="comment_body" maxlength="<?= (int) level_comment_body_max_length() ?>" placeholder="<?= e(t('demon.comment_placeholder')) ?>" required></textarea>
                    </label>
                    <div class="level-comment-form-actions">
                        <span class="muted"><?= e(t('demon.signed_in_as', ['name' => (string) (current_user_display_name() ?? '')])) ?></span>
                        <button class="button blue hover" type="submit"><?= e(t('demon.post_comment')) ?></button>
                    </div>
                </form>
            <?php elseif (is_logged_in()): ?>
                <p class="muted level-comments-status"><?= e(t('comments.account_disabled')) ?></p>
            <?php endif; ?>

            <div class="level-comments-list">
                <?php if ($levelCommentTotal === 0 && $levelCommentsDisabledMessage === null): ?>
                    <p class="muted level-comments-empty"><?= e(t('demon.no_comments')) ?></p>
                <?php endif; ?>
                <?php foreach ($levelComments as $comment): ?>
                    <?php render_level_comment_card(
                        $comment,
                        $levelCommentBadges,
                        $levelCommentReplies,
                        $levelCommentsDisabledMessage === null,
                        $canModerateLevelComments,
                        $position,
                        false,
                        $levelCommentPage
                    ); ?>
                <?php endforeach; ?>
            </div>

            <?php if ($levelCommentTotalPages > 1): ?>
                <nav class="level-comments-pagination" aria-label="<?= e(t('demon.comment_pages')) ?>">
                    <a class="button white hover small<?= $levelCommentPage <= 1 ? ' is-disabled' : '' ?>"
                       href="<?= e($levelCommentPage <= 1 ? $levelCommentPageUrl(1) : $levelCommentPageUrl($levelCommentPage - 1)) ?>"
                       aria-disabled="<?= $levelCommentPage <= 1 ? 'true' : 'false' ?>"><</a>
                    <span class="level-comments-page-status"><?= e(t('demon.page', ['page' => $levelCommentPage])) ?></span>
                    <a class="button white hover small<?= $levelCommentPage >= $levelCommentTotalPages ? ' is-disabled' : '' ?>"
                       href="<?= e($levelCommentPage >= $levelCommentTotalPages ? $levelCommentPageUrl($levelCommentTotalPages) : $levelCommentPageUrl($levelCommentPage + 1)) ?>"
                       aria-disabled="<?= $levelCommentPage >= $levelCommentTotalPages ? 'true' : 'false' ?>">></a>
                </nav>
            <?php endif; ?>
        </section>
    </main>

    <aside class="right">
        <section id="staff-contacts" class="panel fade staff-contact-panel">
            <div class="staff-contact-subsection">
                <h2 class="underlined pad"><?= e(t('home.editors')) ?></h2>
                <p class="staff-contact-note">
                    <?= e(t('home.editors_note')) ?>
                </p>
                <ul class="staff-contact-list">
                    <?php if ($listEditors === []): ?>
                        <li class="staff-contact-empty"><?= e(t('home.no_editors')) ?></li>
                    <?php endif; ?>
                    <?php foreach ($listEditors as $editor): ?>
                        <?php
                        $countryCode = normalize_country_code((string) ($editor['country_code'] ?? ''));
                        $prefix = country_flag_html($countryCode, true);
                        $youtubeChannel = trim((string) ($editor['youtube_channel'] ?? ''));
                        $username = e(user_display_name_from_row($editor));
                        ?>
                        <li>
                            <b><?= $prefix ?><?php if ($youtubeChannel !== ''): ?><a target="_blank" rel="noreferrer" href="<?= e($youtubeChannel) ?>" title="<?= e(t('home.youtube_channel')) ?>" style="color: inherit; text-decoration: none;"><?= $username ?></a><?php else: ?><?= $username ?><?php endif; ?></b>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="staff-contact-subsection">
                <h2 class="underlined pad"><?= e(t('home.helpers')) ?></h2>
                <p class="staff-contact-note">
                    <?= e(t('home.helpers_note')) ?>
                </p>
                <ul class="staff-contact-list">
                    <?php if ($listHelpers === []): ?>
                        <li class="staff-contact-empty"><?= e(t('home.no_helpers')) ?></li>
                    <?php endif; ?>
                    <?php foreach ($listHelpers as $helper): ?>
                        <?php
                        $countryCode = normalize_country_code((string) ($helper['country_code'] ?? ''));
                        $prefix = country_flag_html($countryCode, true);
                        $youtubeChannel = trim((string) ($helper['youtube_channel'] ?? ''));
                        $username = e(user_display_name_from_row($helper));
                        ?>
                        <li>
                            <b><?= $prefix ?><?php if ($youtubeChannel !== ''): ?><a target="_blank" rel="noreferrer" href="<?= e($youtubeChannel) ?>" title="<?= e(t('home.youtube_channel')) ?>" style="color: inherit; text-decoration: none;"><?= $username ?></a><?php else: ?><?= $username ?><?php endif; ?></b>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <section id="rules" class="panel fade">
            <h2 class="underlined pad clickable"><?= e(t('home.guidelines_title')) ?></h2>
            <p><?= e(t('home.guidelines_text')) ?></p>
            <a class="blue hover button" href="<?= e(base_url('guidelines.php')) ?>"><?= e(t('home.read_guidelines')) ?></a>
        </section>

        <section id="submit" class="panel fade">
            <h2 class="underlined pad"><?= e(t('home.submit_title')) ?></h2>
            <p>
                <?= e(t('home.submit_text')) ?>
            </p>
            <a class="blue hover button" href="<?= e(base_url('submit.php')) ?>"><?= e(t('home.submit_button')) ?></a>
        </section>

        <section id="stats-viewer" class="panel fade">
            <h2 class="underlined pad"><?= e(t('home.stats_title')) ?></h2>
            <p>
                <?= e(t('home.stats_text')) ?>
            </p>
            <a class="blue hover button" href="<?= e(base_url('players.php')) ?>"><?= e(t('home.stats_button')) ?></a>
        </section>

        <?php if ($discordWidgetUrl !== null): ?>
            <section id="discord" class="panel fade">
                <h2 class="underlined pad"><?= e(t('home.discord_title')) ?></h2>
                <div class="discord-widget-wrap">
                    <iframe
                        class="discord-widget-frame"
                        src="<?= e($discordWidgetUrl) ?>"
                        title="<?= e(t('home.discord_title')) ?>"
                        sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
                    ></iframe>
                </div>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php render_footer(); ?>
