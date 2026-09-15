<?php
// Admin Documents library: browse/view/edit/version PDFs, Office files
// (docx/pptx/xlsx/etc), Markdown, XML/.rels, and other reference/review
// documents from the admin dashboard - see migration 056_admin_documents.sql.
//
// Binary upload (new doc) and replace (new version) go through the
// separate standalone api/documents_upload.php, same reason every other
// file-upload flow in this codebase is standalone: boot.php only parses
// a JSON body, it cannot read multipart/form-data at all. Streaming a
// file back to the browser (view/download) similarly can't go through
// boot.php (it always sends a JSON content-type header up front), so
// that lives in the separate api/documents_download.php. Everything
// else - list, metadata, text-file content, text edits, version
// history, restore, delete - is plain JSON and belongs here.
//
// Text-editable files (md, txt, xml, rels, csv, json, yml/yaml,
// html/htm, css, js) are still stored as real files on disk under
// documents_storage/, same as binaries - admin_docs_update_text() just
// writes a new file and a new version row rather than updating a DB
// content column, so there is exactly one code path (JSON metadata +
// files-on-disk) for every document type instead of two.

require_once __DIR__ . '/../lib/documents_storage.php';

switch ($action) {

case 'admin_docs_list': {
    $pdo = db();
    require_admin($pdo, 'documents.view');
    $category = isset($_GET['category']) ? trim($_GET['category']) : (isset($body['category']) ? trim($body['category']) : '');
    $search   = isset($_GET['search'])   ? trim($_GET['search'])   : (isset($body['search'])   ? trim($body['search'])   : '');
    $where = 'WHERE d.deleted_at IS NULL';
    $params = array();
    if ($category !== '' && $category !== 'all') { $where .= ' AND d.category = ?'; $params[] = $category; }
    if ($search !== '') { $where .= ' AND (d.title LIKE ? OR d.original_filename LIKE ? OR d.description LIKE ?)'; $like = '%' . $search . '%'; $params[] = $like; $params[] = $like; $params[] = $like; }
    try {
        $s = $pdo->prepare("SELECT d.id, d.title, d.category, d.description, d.original_filename, d.file_ext,
            d.mime_type, d.is_text_editable, d.file_size, d.current_version, d.created_at, d.updated_at,
            u.name AS uploaded_by_name
            FROM admin_documents d LEFT JOIN users u ON u.id = d.uploaded_by
            $where ORDER BY d.updated_at DESC LIMIT 500");
        $s->execute($params);
        $docs = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load documents - has migration 056_admin_documents.sql been applied?', 500);
    }
    ok(array('documents' => $docs, 'count' => count($docs)));
    break;
}

case 'admin_docs_get': {
    $pdo = db();
    require_admin($pdo, 'documents.view');
    $id = isset($_GET['document_id']) ? intval($_GET['document_id']) : (isset($body['document_id']) ? intval($body['document_id']) : 0);
    if (!$id) fail('document_id is required', 400);
    try {
        $s = $pdo->prepare("SELECT d.*, u.name AS uploaded_by_name FROM admin_documents d
            LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.id=? AND d.deleted_at IS NULL");
        $s->execute(array($id));
        $doc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$doc) fail('Document not found', 404);

        $vs = $pdo->prepare("SELECT v.id, v.version_number, v.file_size, v.change_note, v.created_at, u.name AS uploaded_by_name
            FROM admin_document_versions v LEFT JOIN users u ON u.id = v.uploaded_by
            WHERE v.document_id=? ORDER BY v.version_number DESC");
        $vs->execute(array($id));
        $doc['versions'] = $vs->fetchAll(PDO::FETCH_ASSOC);

        // Inline the current content for text-editable files under the
        // size cap, so the front-end can show/edit it with no second
        // request - anything larger falls back to view/download via
        // documents_download.php like every binary file already does.
        $doc['content'] = null;
        if (!empty($doc['is_text_editable']) && intval($doc['file_size']) <= 3 * 1024 * 1024) {
            $doc['content'] = etel_docs_read_text($doc['storage_filename']);
        }
        ok(array('document' => $doc));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load document', 500);
    }
    break;
}

case 'admin_docs_create_text': {
    $pdo = db();
    $u = require_admin($pdo, 'documents.manage');
    $title    = isset($body['title']) ? trim($body['title']) : '';
    $filename = isset($body['filename']) ? trim($body['filename']) : '';
    $content  = isset($body['content']) ? (string) $body['content'] : '';
    $category = isset($body['category']) ? trim($body['category']) : 'other';
    $desc     = isset($body['description']) ? trim($body['description']) : '';
    if ($title === '') fail('title is required', 400);
    if ($filename === '') fail('filename is required (used to pick the file type, e.g. "notes.md")', 400);

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!etel_docs_is_text_ext($ext)) fail('That file type is not a plain-text type. Use Upload for binary files.', 400);
    if (strlen($content) > 3 * 1024 * 1024) fail('Content is too large (3 MB max for text files)', 400);

    try {
        $storageName = etel_docs_write_new_text($ext, $content);
        $size = strlen($content);
        $mime = etel_docs_mime_for_ext($ext);
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO admin_documents
            (title, category, description, original_filename, file_ext, mime_type, is_text_editable, storage_filename, file_size, current_version, uploaded_by)
            VALUES (?,?,?,?,?,?,1,?,?,1,?)');
        $ins->execute(array($title, $category ?: 'other', $desc, $filename, $ext, $mime, $storageName, $size, $u['id']));
        $docId = intval($pdo->lastInsertId());
        $pdo->prepare('INSERT INTO admin_document_versions (document_id, version_number, storage_filename, file_size, change_note, uploaded_by) VALUES (?,1,?,?,?,?)')
            ->execute(array($docId, $storageName, $size, 'Initial version', $u['id']));
        $pdo->commit();
        ok(array('message' => 'Document created', 'document_id' => $docId));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not create document', 500);
    }
    break;
}

case 'admin_docs_update_text': {
    $pdo = db();
    $u = require_admin($pdo, 'documents.manage');
    $id = isset($body['document_id']) ? intval($body['document_id']) : 0;
    $content = isset($body['content']) ? (string) $body['content'] : '';
    $note = isset($body['change_note']) ? trim($body['change_note']) : '';
    if (!$id) fail('document_id is required', 400);
    if (strlen($content) > 3 * 1024 * 1024) fail('Content is too large (3 MB max for text files)', 400);

    try {
        $s = $pdo->prepare('SELECT * FROM admin_documents WHERE id=? AND deleted_at IS NULL');
        $s->execute(array($id));
        $doc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$doc) fail('Document not found', 404);
        if (!$doc['is_text_editable']) fail('This document is not a plain-text type and cannot be edited inline - use Replace instead', 400);

        $storageName = etel_docs_write_new_text($doc['file_ext'], $content);
        $size = strlen($content);
        $newVersion = intval($doc['current_version']) + 1;

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE admin_documents SET storage_filename=?, file_size=?, current_version=? WHERE id=?')
            ->execute(array($storageName, $size, $newVersion, $id));
        $pdo->prepare('INSERT INTO admin_document_versions (document_id, version_number, storage_filename, file_size, change_note, uploaded_by) VALUES (?,?,?,?,?,?)')
            ->execute(array($id, $newVersion, $storageName, $size, ($note !== '' ? $note : 'Edited'), $u['id']));
        $pdo->commit();
        ok(array('message' => 'Saved', 'version' => $newVersion));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not save document', 500);
    }
    break;
}

case 'admin_docs_update_meta': {
    $pdo = db();
    require_admin($pdo, 'documents.manage');
    $id = isset($body['document_id']) ? intval($body['document_id']) : 0;
    if (!$id) fail('document_id is required', 400);
    $fields = array(); $params = array();
    if (isset($body['title'])) { $t = trim($body['title']); if ($t === '') fail('title cannot be empty', 400); $fields[] = 'title=?'; $params[] = $t; }
    if (isset($body['category'])) { $fields[] = 'category=?'; $params[] = trim($body['category']) ?: 'other'; }
    if (isset($body['description'])) { $fields[] = 'description=?'; $params[] = trim($body['description']); }
    if (!$fields) fail('Nothing to update', 400);
    $params[] = $id;
    try {
        $r = $pdo->prepare('UPDATE admin_documents SET ' . implode(', ', $fields) . ' WHERE id=? AND deleted_at IS NULL');
        $r->execute($params);
        if ($r->rowCount() === 0) fail('Document not found', 404);
        ok(array('message' => 'Updated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not update document', 500);
    }
    break;
}

case 'admin_docs_restore_version': {
    $pdo = db();
    $u = require_admin($pdo, 'documents.manage');
    $id = isset($body['document_id']) ? intval($body['document_id']) : 0;
    $versionId = isset($body['version_id']) ? intval($body['version_id']) : 0;
    if (!$id || !$versionId) fail('document_id and version_id are required', 400);
    try {
        $s = $pdo->prepare('SELECT * FROM admin_documents WHERE id=? AND deleted_at IS NULL');
        $s->execute(array($id));
        $doc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$doc) fail('Document not found', 404);

        $vs = $pdo->prepare('SELECT * FROM admin_document_versions WHERE id=? AND document_id=?');
        $vs->execute(array($versionId, $id));
        $ver = $vs->fetch(PDO::FETCH_ASSOC);
        if (!$ver) fail('Version not found', 404);

        // The old version's file is immutable and was never overwritten,
        // so the "restore" just makes it current again via a new version
        // row pointing at the same storage_filename - no file copy needed.
        $newVersion = intval($doc['current_version']) + 1;
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE admin_documents SET storage_filename=?, file_size=?, current_version=? WHERE id=?')
            ->execute(array($ver['storage_filename'], $ver['file_size'], $newVersion, $id));
        $pdo->prepare('INSERT INTO admin_document_versions (document_id, version_number, storage_filename, file_size, change_note, uploaded_by) VALUES (?,?,?,?,?,?)')
            ->execute(array($id, $newVersion, $ver['storage_filename'], $ver['file_size'], 'Restored from version ' . $ver['version_number'], $u['id']));
        $pdo->commit();
        ok(array('message' => 'Restored version ' . $ver['version_number'] . ' as the new current version', 'version' => $newVersion));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not restore version', 500);
    }
    break;
}

case 'admin_docs_delete': {
    $pdo = db();
    require_admin($pdo, 'documents.manage');
    $id = isset($body['document_id']) ? intval($body['document_id']) : 0;
    if (!$id) fail('document_id is required', 400);
    try {
        // Soft delete only - files on disk are left in place. A document
        // library is exactly the kind of thing an accidental click
        // shouldn't be able to destroy with no recovery path.
        $r = $pdo->prepare('UPDATE admin_documents SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL');
        $r->execute(array($id));
        if ($r->rowCount() === 0) fail('Document not found (or already deleted)', 404);
        ok(array('message' => 'Document deleted'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not delete document', 500);
    }
    break;
}

}
