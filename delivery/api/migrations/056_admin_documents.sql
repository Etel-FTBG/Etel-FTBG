-- Admin Documents library: lets admin staff (documents.view/documents.manage
-- capability) upload, browse, view, edit, and version PDFs, Office files,
-- Markdown, XML/.rels, and other reference/reviewable documents from the
-- admin dashboard, independent of the git repo.
--
-- Files themselves live on disk under api/documents_storage/ (denied to
-- direct web access via .htaccess, same pattern as api/backups/) - this
-- table only tracks metadata + which storage_filename is current.
-- admin_document_versions keeps every prior version's file so a replace
-- or an edit is never destructive.

CREATE TABLE IF NOT EXISTS admin_documents (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    title             VARCHAR(200) NOT NULL,
    category          VARCHAR(60)  NOT NULL DEFAULT 'other',
    description       TEXT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_ext          VARCHAR(20)  NOT NULL,
    mime_type         VARCHAR(120) NOT NULL,
    is_text_editable  TINYINT(1)   NOT NULL DEFAULT 0,
    storage_filename  VARCHAR(255) NOT NULL,
    file_size         INT NOT NULL DEFAULT 0,
    current_version   INT NOT NULL DEFAULT 1,
    uploaded_by       INT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_admin_documents_category (category),
    INDEX idx_admin_documents_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_document_versions (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    document_id       INT NOT NULL,
    version_number    INT NOT NULL,
    storage_filename  VARCHAR(255) NOT NULL,
    file_size         INT NOT NULL DEFAULT 0,
    change_note       VARCHAR(500) NULL,
    uploaded_by       INT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_document_versions_doc (document_id),
    CONSTRAINT fk_admin_document_versions_doc
        FOREIGN KEY (document_id) REFERENCES admin_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
