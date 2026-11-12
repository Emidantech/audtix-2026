<?php
/* Public uploads (logos, favicon, post images, publication PDFs) go to /uploads/<sub>/.
   Files are checked by their content, renamed to random names, and /uploads/.htaccess stops anything but images and PDFs from being served. */
const IMG_MAX = 4 * 1024 * 1024, PDF_MAX = 15 * 1024 * 1024;

function uploads_root(): string { return dirname(__DIR__, 2) . '/uploads'; }

/** Returns [ok, url_or_error, info]. $kinds: list of allowed: png, jpg, webp, gif, ico, pdf. */
function save_upload(array $f, string $sub, array $kinds, int $max): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [false, '', []];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) return [false, 'The upload failed. Try a smaller file.', []];
    if ((int) $f['size'] > $max) return [false, 'The file is larger than ' . round($max / 1048576) . ' MB.', []];
    $tmp = (string) $f['tmp_name'];
    if (!is_uploaded_file($tmp) && !(cfg('allow_test_uploads') && is_file($tmp))) return [false, 'Upload not accepted.', []];
    $h = (string) file_get_contents($tmp, false, null, 0, 16);
    $type = null;
    if (strncmp($h, "\x89PNG\r\n\x1a\n", 8) === 0) $type = ['png', 'image/png'];
    elseif (strncmp($h, "\xFF\xD8\xFF", 3) === 0) $type = ['jpg', 'image/jpeg'];
    elseif (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') $type = ['webp', 'image/webp'];
    elseif (strncmp($h, 'GIF8', 4) === 0) $type = ['gif', 'image/gif'];
    elseif (strncmp($h, "\x00\x00\x01\x00", 4) === 0) $type = ['ico', 'image/x-icon'];
    elseif (strncmp($h, '%PDF', 4) === 0) $type = ['pdf', 'application/pdf'];
    if (!$type || !in_array($type[0], $kinds, true)) return [false, 'Use a ' . strtoupper(implode(', ', $kinds)) . ' file.', []];
    $w = $hh = null;
    if (!in_array($type[0], ['pdf', 'ico'], true)) { $sz = @getimagesize($tmp); if (!$sz) return [false, 'That image could not be read.', []]; [$w, $hh] = $sz; }
    $dir = uploads_root() . '/' . $sub;
    if (!(@mkdir($dir, 0755, true) || is_dir($dir))) return [false, 'The uploads folder is not writable on the server.', []];
    $name = gmdate('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $type[0];
    $ok = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, "$dir/$name") : @copy($tmp, "$dir/$name");
    if (!$ok) return [false, 'Could not save the file on the server.', []];
    @chmod("$dir/$name", 0644);
    return [true, "/uploads/$sub/$name", ['mime' => $type[1], 'size' => (int) $f['size'], 'w' => $w, 'h' => $hh, 'orig' => mb_substr(basename((string) $f['name']), 0, 200)]];
}
/** Deletes a file previously saved by save_upload (only inside /uploads). */
function delete_upload(?string $url): void {
    if (!$url || !preg_match('#^/uploads/[a-z]+/[0-9]{8}-[a-f0-9]{12}\.(png|jpg|webp|gif|ico|pdf)$#', $url)) return;
    @unlink(dirname(__DIR__, 2) . $url);
}
