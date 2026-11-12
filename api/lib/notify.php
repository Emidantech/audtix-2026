<?php
declare(strict_types=1);
/** Adds a system notification to the admin bell. $module is the permission module needed to see it. Never throws. */
function notify_admins(?PDO $pdo, string $type, string $module, string $title, string $body = '', string $link = ''): void {
    if (!$pdo) return;
    try {
        $pdo->prepare('INSERT INTO notifications (type, module, title, body, link, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([mb_substr($type, 0, 40), mb_substr($module, 0, 30), mb_substr($title, 0, 190), mb_substr($body, 0, 500) ?: null, mb_substr($link, 0, 255) ?: null, now()]);
    } catch (Throwable $e) { error_log('audtix notify: ' . $e->getMessage()); }
}
