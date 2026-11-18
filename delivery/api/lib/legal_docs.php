<?php
// ============================================================
// Privacy policy and Terms the owner can edit (table legal_docs, migration 113).
// A document with no row keeps the built-in page. The text is stored as plain text (tags removed) and always shown as text:
//   a blank line starts a new paragraph, a line that starts with "# " is a heading.
// ============================================================
if (!function_exists('legal_doc_keys')) {

function legal_doc_keys() { return array('privacy', 'terms'); }

function legal_doc_clean_body($v) {
    $v = strip_tags((string) $v);
    $v = str_replace(array("\r\n", "\r"), "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    $v = preg_replace("/\n{3,}/", "\n\n", $v);
    return trim($v);
}

function legal_doc_len($v) { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); }

/* array(title, body, updated_at) or null when the owner has not written this one (or the table is not migrated yet). */
function legal_doc_get($pdo, $key) {
    if (!in_array($key, legal_doc_keys(), true)) return null;
    try {
        $s = $pdo->prepare('SELECT title, body, updated_at FROM legal_docs WHERE doc_key = ?');
        $s->execute(array($key));
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r || trim((string) $r['body']) === '') return null;
        return array('title' => (string) $r['title'], 'body' => (string) $r['body'], 'updated_at' => (string) $r['updated_at']);
    } catch (Exception $e) { return null; }
}

/* '' when saved, else the reason. An empty text removes the row, so the built-in page comes back. */
function legal_doc_save($pdo, $adminId, $key, $title, $body) {
    if (!in_array($key, legal_doc_keys(), true)) return 'Unknown document.';
    $body = legal_doc_clean_body($body);
    $title = trim(preg_replace('/\s+/', ' ', strip_tags((string) $title)));
    if (legal_doc_len($title) > 80) return 'The title is too long (80 letters at most).';
    if (legal_doc_len($body) > 60000) return 'The text is too long (60000 letters at most).';
    if ($body === '') {
        $pdo->prepare('DELETE FROM legal_docs WHERE doc_key = ?')->execute(array($key));
        return '';
    }
    if (legal_doc_len($body) < 200) return 'The text is too short to be a ' . ($key === 'privacy' ? 'privacy policy' : 'terms of service') . ' (200 letters at least). Leave it empty to use the built-in page.';
    $pdo->prepare('INSERT INTO legal_docs (doc_key, title, body, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
        ->execute(array($key, $title, $body, intval($adminId)));
    return '';
}

}
