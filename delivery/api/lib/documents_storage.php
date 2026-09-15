<?php
// Shared file-storage helpers for the admin Documents library
// (DocumentsController.php, documents_upload.php, documents_download.php).
// Pure filesystem/validation logic, no DB/auth here - kept separate so
// the two standalone multipart/streaming endpoints (which can't require
// boot.php's controllers directly without pulling in its JSON-body
// parsing) can share the exact same extension whitelist and storage
// path logic as the JSON controller, instead of three copies drifting
// apart over time.

if (!function_exists('etel_docs_storage_dir')) {
function etel_docs_storage_dir() {
    $dir = __DIR__ . '/../documents_storage';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}
}

// Extension => [mime, is_text]. This is the single source of truth for
// what can be uploaded/created - server-generated storage filenames
// always take their extension from THIS map's keys, never straight from
// a client-supplied filename, so an unrecognized/spoofed extension can
// never reach the filesystem.
if (!function_exists('etel_docs_type_map')) {
function etel_docs_type_map() {
    return array(
        'pdf'      => array('application/pdf', false),
        'doc'      => array('application/msword', false),
        'docx'     => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', false),
        'ppt'      => array('application/vnd.ms-powerpoint', false),
        'pptx'     => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', false),
        'xls'      => array('application/vnd.ms-excel', false),
        'xlsx'     => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false),
        'rtf'      => array('application/rtf', false),
        'odt'      => array('application/vnd.oasis.opendocument.text', false),
        'odp'      => array('application/vnd.oasis.opendocument.presentation', false),
        'ods'      => array('application/vnd.oasis.opendocument.spreadsheet', false),
        'md'       => array('text/markdown', true),
        'markdown' => array('text/markdown', true),
        'txt'      => array('text/plain', true),
        'xml'      => array('application/xml', true),
        'rels'     => array('application/xml', true),
        'csv'      => array('text/csv', true),
        'json'     => array('application/json', true),
        'yml'      => array('application/x-yaml', true),
        'yaml'     => array('application/x-yaml', true),
        'html'     => array('text/html', true),
        'htm'      => array('text/html', true),
        'css'      => array('text/css', true),
        'js'       => array('application/javascript', true),
    );
}
}

if (!function_exists('etel_docs_is_text_ext')) {
function etel_docs_is_text_ext($ext) {
    $map = etel_docs_type_map();
    $ext = strtolower($ext);
    return isset($map[$ext]) && $map[$ext][1] === true;
}
}

if (!function_exists('etel_docs_is_known_ext')) {
function etel_docs_is_known_ext($ext) {
    return isset(etel_docs_type_map()[strtolower($ext)]);
}
}

if (!function_exists('etel_docs_mime_for_ext')) {
function etel_docs_mime_for_ext($ext) {
    $map = etel_docs_type_map();
    $ext = strtolower($ext);
    return isset($map[$ext]) ? $map[$ext][0] : 'application/octet-stream';
}
}

// Server-generated storage filename - deliberately random, never derived
// from the client's original filename (no path-traversal surface, no
// filename-collision surface).
if (!function_exists('etel_docs_new_storage_name')) {
function etel_docs_new_storage_name($ext) {
    return 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
}
}

if (!function_exists('etel_docs_write_new_text')) {
function etel_docs_write_new_text($ext, $content) {
    $name = etel_docs_new_storage_name($ext);
    $path = etel_docs_storage_dir() . '/' . $name;
    if (@file_put_contents($path, $content) === false) {
        throw new Exception('Could not write document file to disk');
    }
    return $name;
}
}

if (!function_exists('etel_docs_read_text')) {
function etel_docs_read_text($storageFilename) {
    $path = etel_docs_storage_dir() . '/' . basename($storageFilename);
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    // Defensive UTF-8 cleanup, same reasoning as audit_agent.php's
    // cleanUtf8(): a file with a handful of invalid bytes shouldn't
    // break json_encode() for the whole admin_docs_get response.
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        $raw = ($cleaned !== false) ? $cleaned : preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $raw);
    }
    return $raw;
}
}
