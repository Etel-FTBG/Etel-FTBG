<?php
/* Papers a business hands in before it goes live (business licence, owner's ID, optional tax certificate).
 * The owner (or admin on their behalf) uploads a photo; admin approves it or rejects it with a reason; a rejected paper can be sent again.
 * The photos live in uploads/driver_documents/, which is private: API answers carry a signed, expiring link (lib/private_files.php).
 * The table is also created by migration 099_sg_documents.sql; etel_sg_docs_ensure() makes it on first use.
 */

function etel_sg_docs_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sg_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sg_id INT NOT NULL,
        doc_type VARCHAR(30) NOT NULL,
        file_url VARCHAR(500) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        reject_reason VARCHAR(255) NULL,
        uploaded_by INT NOT NULL DEFAULT 0,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sg_doc (sg_id, doc_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* type => label, and whether go-live needs it */
function etel_sg_doc_types() {
    return array(
        'business_licence' => array('label' => 'Business licence', 'required' => true),
        'owner_id'         => array('label' => "Owner's ID", 'required' => true),
        'tax_certificate'  => array('label' => 'Tax (TIN) certificate', 'required' => false),
    );
}

/* Only pictures this system stored in the private documents folder are accepted. */
function etel_sg_doc_url_ok($url) {
    return is_string($url) && strlen($url) <= 500 && preg_match('#^https?://[^\s"<>]+/uploads/driver_documents/[A-Za-z0-9_.-]+$#', $url) === 1;
}

/* Every paper a business has handed in, newest state per type. */
function etel_sg_docs_list($pdo, $sg_id) {
    try {
        etel_sg_docs_ensure($pdo);
        $q = $pdo->prepare('SELECT id, doc_type, file_url, status, reject_reason, uploaded_by, reviewed_at, updated_at FROM sg_documents WHERE sg_id = ? ORDER BY id');
        $q->execute(array($sg_id));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return array(); }
    foreach ($rows as &$r) { $r['id'] = intval($r['id']); $r['uploaded_by'] = intval($r['uploaded_by']); }
    return $rows;
}

/* True when every required paper has been approved. */
function etel_sg_docs_complete($docs) {
    $ok = array();
    foreach ($docs as $d) if ($d['status'] === 'approved') $ok[$d['doc_type']] = true;
    foreach (etel_sg_doc_types() as $k => $t) if ($t['required'] && empty($ok[$k])) return false;
    return true;
}
