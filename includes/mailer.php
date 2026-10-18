<?php
declare(strict_types=1);

function email_smtp_setting(string $key, mixed $default = null): mixed
{
    return config('email.' . $key, $default);
}
function email_smtp_config(): array
{
    $encryption = strtolower(trim((string) email_smtp_setting('smtp_encryption', 'tls')));
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }

    return [
        'host' => trim((string) email_smtp_setting('smtp_host', '')),
        'port' => max(1, min(65535, (int) email_smtp_setting('smtp_port', 587))),
        'encryption' => $encryption,
        'username' => trim((string) email_smtp_setting('smtp_username', '')),
        'password' => (string) email_smtp_setting('smtp_password', ''),
        'from_address' => trim((string) email_smtp_setting('from_address', '')),
        'from_name' => trim((string) email_smtp_setting('from_name', '')),
    ];
}

function email_features_enabled(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (email_smtp_setting('enabled', false) !== true) {
        return $cached = false;
    }

    $config = email_smtp_config();
    if ($config['host'] === '' || $config['from_address'] === '') {
        error_log('mailer: email.enabled is true but smtp_host / from_address is missing in config.php');

        return $cached = false;
    }

    if (filter_var($config['from_address'], FILTER_VALIDATE_EMAIL) === false) {
        error_log('mailer: email.from_address in config.php is not a valid address');

        return $cached = false;
    }

    return $cached = true;
}

function email_verification_required(): bool
{
    return email_features_enabled();
}
function email_fail_open_enabled(): bool
{
    return email_smtp_setting('fail_open', false) === true;
}

function email_make_token(): string
{
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable) {
        throw new RuntimeException('Could not generate a secure token.');
    }
}

function email_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function email_verification_ttl_seconds(): int
{
    return 24 * 3600;
}

function email_reset_ttl_seconds(): int
{
    return 3600;
}

function users_has_email_verification_columns(?PDO $pdo = null): bool
{
    static $cache = [];
    $pdo = $pdo instanceof PDO ? $pdo : db();
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    foreach (
        [
            'email_verified_at',
            'pending_email',
            'verify_token_hash',
            'verify_token_expires_at',
            'reset_token_hash',
            'reset_token_expires_at',
        ] as $column
    ) {
        if (!schema_col_exists($pdo, 'users', $column)) {
            return $cache[$key] = false;
        }
    }

    return $cache[$key] = true;
}

function email_encode_header_value(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function email_format_address(string $address, string $name = ''): string
{
    $name = trim($name);
    if ($name === '') {
        return '<' . $address . '>';
    }

    return email_encode_header_value($name) . ' <' . $address . '>';
}

function email_build_message(array $config, string $to, string $subject, string $text, string $html): string
{
    $boundary = '=_dl_' . bin2hex(random_bytes(12));
    $fromDomain = (string) (parse_url('http://' . $config['from_address'], PHP_URL_HOST) ?: '');
    if ($fromDomain === '') {
        $fromDomain = gethostname() ?: 'localhost';
    }

    $headers = [
        'From: ' . email_format_address($config['from_address'], $config['from_name']),
        'To: <' . $to . '>',
        'Subject: ' . email_encode_header_value($subject),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $fromDomain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $parts = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($text)),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html)),
        '--' . $boundary . '--',
        '',
    ];

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
}

function email_smtp_read_reply($stream): int
{
    $code = 0;
    while (($line = fgets($stream, 1024)) !== false) {
        $code = (int) substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return $code;
}

function email_smtp_command($stream, string $command, array $expectedCodes): int
{
    fwrite($stream, $command . "\r\n");
    $code = email_smtp_read_reply($stream);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP server replied ' . $code . ' after "' . strtok($command, ' ') . '".');
    }

    return $code;
}

function email_smtp_send(array $config, string $to, string $subject, string $text, string $html): void
{
    $remote = ($config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://')
        . $config['host'] . ':' . $config['port'];

    $stream = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if ($stream === false) {
        throw new RuntimeException('SMTP connection failed: ' . ($errstr !== '' ? $errstr : 'error ' . $errno));
    }

    stream_set_timeout($stream, 20);

    try {
        if (email_smtp_read_reply($stream) !== 220) {
            throw new RuntimeException('SMTP server did not send a greeting.');
        }

        email_smtp_command($stream, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);

        if ($config['encryption'] === 'tls') {
            email_smtp_command($stream, 'STARTTLS', [220]);
            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            email_smtp_command($stream, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
        }

        if ($config['username'] !== '') {
            email_smtp_command($stream, 'AUTH LOGIN', [334]);
            email_smtp_command($stream, base64_encode($config['username']), [334]);
            email_smtp_command($stream, base64_encode($config['password']), [235]);
        }

        email_smtp_command($stream, 'MAIL FROM:<' . $config['from_address'] . '>', [250]);
        email_smtp_command($stream, 'RCPT TO:<' . $to . '>', [250, 251]);
        email_smtp_command($stream, 'DATA', [354]);

        $message = email_build_message($config, $to, $subject, $text, $html);
        foreach (explode("\n", str_replace("\r\n", "\n", rtrim($message, "\r\n"))) as $line) {
            fwrite($stream, (str_starts_with($line, '.') ? '.' . $line : $line) . "\r\n");
        }

        email_smtp_command($stream, '.', [250]);
        email_smtp_command($stream, 'QUIT', [221, 250]);
    } finally {
        fclose($stream);
    }
}

function send_app_email(string $to, string $subject, string $text, string $html): bool
{
    $to = trim($to);
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        error_log('mailer: refused to send to an invalid address.');

        return false;
    }

    if (!email_features_enabled()) {
        error_log('mailer: attempted to send while the feature is disabled or misconfigured.');

        return false;
    }

    try {
        email_smtp_send(email_smtp_config(), $to, $subject, $text, $html);

        return true;
    } catch (Throwable $exception) {
        error_log('mailer: ' . $exception->getMessage());

        return false;
    }
}

function email_button_html(string $label, string $url): string
{
    return '<p style="margin:22px 0"><a href="' . e($url) . '" style="background:#2f6feb;color:#ffffff;'
        . 'padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">'
        . e($label) . '</a></p>';
}

function email_page_html(string $innerHtml): string
{
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;'
        . 'color:#1c2430;max-width:560px;margin:0 auto;padding:24px">' . $innerHtml . '</div>';
}

function email_note_html(): string
{
    return '<p style="color:#5b6675;font-size:13px">' . e(t('email.ignore_note')) . '</p>';
}

function send_verification_email(string $to, string $username, string $verifyUrl): bool
{
    $intro = t('email.verify_body', ['name' => $username]);
    $subject = t('email.subject_verify', ['app' => app_name()]);

    $text = $intro . "\n\n" . $verifyUrl . "\n\n" . t('email.ignore_note') . "\n";
    $html = email_page_html(
        '<p>' . e($intro) . '</p>'
        . email_button_html(t('email.open_button'), $verifyUrl)
        . email_note_html()
    );

    return send_app_email($to, $subject, $text, $html);
}

function send_password_reset_email(string $to, array $requests): bool
{
    $intro = t('email.reset_body');
    $subject = t('email.subject_reset', ['app' => app_name()]);

    $text = $intro . "\n";
    $html = '<p>' . e($intro) . '</p>';

    foreach ($requests as $request) {
        $text .= "\n" . $request['username'] . ': ' . $request['url'] . "\n";
        $html .= '<p><strong>' . e($request['username']) . '</strong></p>'
            . email_button_html(t('email.open_button'), $request['url']);
    }

    $text .= "\n" . t('email.ignore_note') . "\n";
    $html .= email_note_html();

    return send_app_email($to, $subject, $text, email_page_html($html));
}

function send_username_email(string $to, array $usernames): bool
{
    $intro = t('email.username_body');
    $subject = t('email.subject_username', ['app' => app_name()]);

    $text = $intro . "\n\n- " . implode("\n- ", $usernames) . "\n";

    $items = '';
    foreach ($usernames as $username) {
        $items .= '<li>' . e($username) . '</li>';
    }
    $html = email_page_html('<p>' . e($intro) . '</p><ul>' . $items . '</ul>');

    return send_app_email($to, $subject, $text, $html);
}

function email_create_verification_token(PDO $pdo, int $userId): string
{
    $token = email_make_token();
    $stmt = $pdo->prepare(
        'UPDATE users
         SET verify_token_hash = :hash,
             verify_token_expires_at = DATE_ADD(NOW(), INTERVAL :seconds SECOND)
         WHERE id = :id'
    );
    $stmt->execute([
        ':hash' => email_hash_token($token),
        ':seconds' => email_verification_ttl_seconds(),
        ':id' => $userId,
    ]);

    return $token;
}

function email_create_reset_token(PDO $pdo, int $userId): string
{
    $token = email_make_token();
    $stmt = $pdo->prepare(
        'UPDATE users
         SET reset_token_hash = :hash,
             reset_token_expires_at = DATE_ADD(NOW(), INTERVAL :seconds SECOND)
         WHERE id = :id'
    );
    $stmt->execute([
        ':hash' => email_hash_token($token),
        ':seconds' => email_reset_ttl_seconds(),
        ':id' => $userId,
    ]);

    return $token;
}

function email_find_user_by_verification_token(PDO $pdo, string $token): ?array
{
    if (trim($token) === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, email, verify_token_expires_at
         FROM users
         WHERE verify_token_hash = :hash
         LIMIT 1'
    );
    $stmt->execute([':hash' => email_hash_token($token)]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function email_find_user_by_reset_token(PDO $pdo, string $token): ?array
{
    if (trim($token) === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, email, reset_token_expires_at
         FROM users
         WHERE reset_token_hash = :hash
         LIMIT 1'
    );
    $stmt->execute([':hash' => email_hash_token($token)]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function email_token_expired(?string $expiresAt): bool
{
    if ($expiresAt === null || $expiresAt === '') {
        return true;
    }

    $timestamp = strtotime($expiresAt);

    return $timestamp === false || $timestamp < time();
}

function email_mark_verified(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'UPDATE users
         SET email = COALESCE(pending_email, email),
             pending_email = NULL,
             email_verified_at = NOW(),
             verify_token_hash = NULL,
             verify_token_expires_at = NULL
         WHERE id = :id'
    );
    $stmt->execute([':id' => $userId]);
}

function email_clear_verification_token(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE users SET verify_token_hash = NULL, verify_token_expires_at = NULL WHERE id = :id');
    $stmt->execute([':id' => $userId]);
}

function email_promote_pending_email(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'UPDATE users
         SET email = COALESCE(pending_email, email),
             pending_email = NULL,
             verify_token_hash = NULL,
             verify_token_expires_at = NULL
         WHERE id = :id'
    );
    $stmt->execute([':id' => $userId]);
}

function email_clear_reset_token(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE users SET reset_token_hash = NULL, reset_token_expires_at = NULL WHERE id = :id');
    $stmt->execute([':id' => $userId]);
}

function email_users_by_email(PDO $pdo, string $email, string $mode = 'any'): array
{
    $email = trim($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return [];
    }

    $sql = match ($mode) {
        'pending' => 'SELECT id, username, email, pending_email, email_verified_at, verify_token_hash
                      FROM users
                      WHERE LOWER(pending_email) = LOWER(:email)
                      ORDER BY id ASC',
        'confirmed' => 'SELECT id, username, email, pending_email, email_verified_at, verify_token_hash
                        FROM users
                        WHERE LOWER(email) = LOWER(:email)
                        ORDER BY id ASC',
        default => 'SELECT id, username, email, pending_email, email_verified_at, verify_token_hash
                    FROM users
                    WHERE LOWER(email) = LOWER(:email) OR LOWER(pending_email) = LOWER(:email_pending)
                    ORDER BY id ASC',
    };

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $email, ':email_pending' => $email]);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function email_address_available(PDO $pdo, string $email, int $ignoreUserId = 0, bool $includePending = true): bool
{
    $email = trim($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $condition = $includePending
        ? '(LOWER(email) = LOWER(:email) OR LOWER(pending_email) = LOWER(:email_pending))'
        : 'LOWER(email) = LOWER(:email)';

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE id != :id
           AND ' . $condition
    );
    $stmt->execute([':email' => $email, ':email_pending' => $email, ':id' => $ignoreUserId]);

    return (int) $stmt->fetchColumn() === 0;
}
