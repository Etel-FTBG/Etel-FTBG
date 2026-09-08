<?php
// ============================================================
// ETEL AI - Per-member memory library  v1.0
// Deploy as: EtelAi/api/memory.php
// Required by chat.php. No direct HTTP access.
//
// What this is: the piece that makes ETEL AI actually remember a
// person between conversations. Facts and history live in MySQL
// keyed to the EtelID member id, so they follow the member across
// every Etel surface and every device - unlike the old approach
// (browser IndexedDB / request-supplied history) which forgot
// everything when the tab closed.
//
// Deliberately simple v1: exact-key facts + recent-history window.
// No embeddings, no vector search - that would be premature for
// the current model and hosting. Design leaves room for it later.
// ============================================================

if (!defined('ETELAI_MEMORY_LIB')) {
    define('ETELAI_MEMORY_LIB', 1);

    function etelai_mem_tbl($pdo_unused, $t) {
        return DB_PREFIX . 'etelai_' . $t;
    }

    // Wipes a member's conversation history (all projects) so they can
    // start completely fresh - used by the widget's "clear chat" button.
    // Deliberately does NOT touch etelai_facts (remembered facts like
    // name/preferences survive a chat-history clear on purpose - those
    // are a different kind of memory than "what we said last time").
    function etelai_mem_clear($pdo, $member_id) {
        try {
            $s = $pdo->prepare('DELETE FROM ' . etelai_mem_tbl($pdo, 'messages') . ' WHERE member_id=?');
            $s->execute(array($member_id));
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ---- Facts ------------------------------------------------

    function etelai_mem_get_facts($pdo, $member_id) {
        try {
            $s = $pdo->prepare('SELECT fact_key, fact_value FROM ' . etelai_mem_tbl($pdo, 'facts') .
                               ' WHERE member_id = ? ORDER BY updated_at DESC LIMIT 40');
            $s->execute(array($member_id));
            return $s->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            return array(); // tables not installed yet - degrade gracefully
        }
    }

    function etelai_mem_set_fact($pdo, $member_id, $key, $value, $source = 'stated') {
        $key   = substr(trim($key), 0, 64);
        $value = substr(trim($value), 0, 500);
        if ($key === '' || $value === '') return false;
        try {
            $s = $pdo->prepare('INSERT INTO ' . etelai_mem_tbl($pdo, 'facts') .
                ' (member_id, fact_key, fact_value, source, updated_at) VALUES (?, ?, ?, ?, NOW())' .
                ' ON DUPLICATE KEY UPDATE fact_value = VALUES(fact_value), source = VALUES(source), updated_at = NOW()');
            $s->execute(array($member_id, $key, $value, $source));
            return true;
        } catch (Exception $e) { return false; }
    }

    function etelai_mem_forget_fact($pdo, $member_id, $key) {
        try {
            $s = $pdo->prepare('DELETE FROM ' . etelai_mem_tbl($pdo, 'facts') .
                               ' WHERE member_id = ? AND fact_key = ?');
            $s->execute(array($member_id, substr(trim($key), 0, 64)));
            return $s->rowCount() > 0;
        } catch (Exception $e) { return false; }
    }

    // ---- Conversation history --------------------------------

    // Adds tokens_in/tokens_out to the messages table on first use if
    // they don't exist yet - self-healing the same way the rest of this
    // library treats memory as best-effort infrastructure, not something
    // that requires a manual install step every time it grows a column.
    function etelai_mem_ensure_token_columns($pdo) {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $tbl = etelai_mem_tbl($pdo, 'messages');
            $col = $pdo->query("SHOW COLUMNS FROM {$tbl} LIKE 'tokens_in'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN tokens_in INT NULL, ADD COLUMN tokens_out INT NULL");
            }
        } catch (Exception $e) { /* best-effort */ }
    }

    // Adds conversation_id to the messages table on first use if it
    // doesn't exist yet - same self-healing pattern as the token columns.
    function etelai_mem_ensure_conversation_columns($pdo) {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $tbl = etelai_mem_tbl($pdo, 'messages');
            $col = $pdo->query("SHOW COLUMNS FROM {$tbl} LIKE 'conversation_id'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN conversation_id INT NULL, ADD KEY idx_conversation (conversation_id)");
            }
        } catch (Exception $e) { /* best-effort */ }
    }

    function etelai_conv_tbl() { return DB_PREFIX . 'etelai_conversations'; }

    function etelai_conv_ensure_table($pdo) {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        $tbl = etelai_conv_tbl();
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$tbl}` (
                    `id`         INT(11) NOT NULL AUTO_INCREMENT,
                    `member_id`  INT(11) NOT NULL,
                    `title`      VARCHAR(160) NOT NULL DEFAULT 'New chat',
                    `pinned`     TINYINT(1) NOT NULL DEFAULT 0,
                    `starred`    TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL,
                    `updated_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_member` (`member_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            // Self-heal pinned/starred onto a table created before this
            // feature existed - same pattern as the token/conversation
            // columns on etelai_messages.
            $col = $pdo->query("SHOW COLUMNS FROM {$tbl} LIKE 'pinned'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN starred TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Exception $e) { /* best-effort */ }
    }

    // Title is derived from the first message - same idea as ChatGPT/
    // Claude auto-naming new threads from what you actually said.
    function etelai_conv_create($pdo, $member_id, $firstMessage = '') {
        etelai_conv_ensure_table($pdo);
        $title = trim($firstMessage);
        if ($title === '') $title = 'New chat';
        if (strlen($title) > 60) $title = substr($title, 0, 57) . '...';
        try {
            $s = $pdo->prepare('INSERT INTO ' . etelai_conv_tbl() . ' (member_id, title, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
            $s->execute(array($member_id, $title));
            return (int) $pdo->lastInsertId();
        } catch (Exception $e) { return null; }
    }

    function etelai_conv_touch($pdo, $conversationId) {
        if (!$conversationId) return;
        try {
            $pdo->prepare('UPDATE ' . etelai_conv_tbl() . ' SET updated_at = NOW() WHERE id = ?')->execute(array($conversationId));
        } catch (Exception $e) { /* best-effort */ }
    }

    // Most recently active conversation for a member - used when the
    // frontend asks for history without specifying which conversation
    // (i.e. "just show me where I left off").
    function etelai_conv_most_recent($pdo, $member_id) {
        etelai_conv_ensure_table($pdo);
        try {
            $s = $pdo->prepare('SELECT id FROM ' . etelai_conv_tbl() . ' WHERE member_id = ? ORDER BY updated_at DESC LIMIT 1');
            $s->execute(array($member_id));
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ? (int) $row['id'] : null;
        } catch (Exception $e) { return null; }
    }

    function etelai_mem_save_turn($pdo, $member_id, $project, $role, $content, $tokensIn = null, $tokensOut = null, $conversationId = null) {
        $content = trim($content);
        if ($content === '') return;
        if (strlen($content) > 4000) $content = substr($content, 0, 4000);
        etelai_mem_ensure_token_columns($pdo);
        etelai_mem_ensure_conversation_columns($pdo);
        try {
            $s = $pdo->prepare('INSERT INTO ' . etelai_mem_tbl($pdo, 'messages') .
                ' (member_id, project, role, content, tokens_in, tokens_out, conversation_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $s->execute(array($member_id, substr($project, 0, 40), $role, $content, $tokensIn, $tokensOut, $conversationId));
        } catch (Exception $e) { /* memory is best-effort, never break chat */ }
    }

    function etelai_mem_recent($pdo, $member_id, $limit = 12, $conversationId = null) {
        try {
            etelai_mem_ensure_conversation_columns($pdo);
            if ($conversationId) {
                $s = $pdo->prepare('SELECT role, content FROM ' . etelai_mem_tbl($pdo, 'messages') .
                                   ' WHERE member_id = ? AND conversation_id = ? ORDER BY id DESC LIMIT ' . intval($limit));
                $s->execute(array($member_id, $conversationId));
            } else {
                $s = $pdo->prepare('SELECT role, content FROM ' . etelai_mem_tbl($pdo, 'messages') .
                                   ' WHERE member_id = ? ORDER BY id DESC LIMIT ' . intval($limit));
                $s->execute(array($member_id));
            }
            $rows = $s->fetchAll();
            return array_reverse($rows); // oldest first for the model
        } catch (Exception $e) { return array(); }
    }

    // ---- Lightweight fact extraction --------------------------
    // v1 extraction is intentionally conservative: only explicit
    // "remember ..." style statements become durable facts. We do
    // NOT auto-mine casual chat for personal details - wrong
    // guesses stored forever are worse than asking again.

    function etelai_mem_extract_command($message) {
        $m = trim($message);
        // "remember that X" / "remember: X" / "remember X"
        if (preg_match('/^remember(?:\s+that)?[:,]?\s+(.{3,400})$/i', $m, $mt)) {
            return array('op' => 'remember', 'text' => trim($mt[1]));
        }
        // "forget my X" / "forget X"
        if (preg_match('/^forget(?:\s+my)?[:,]?\s+(.{2,64})$/i', $m, $mt)) {
            return array('op' => 'forget', 'text' => trim($mt[1]));
        }
        return null;
    }

    // Turn a free-text remembered note into a stable fact key.
    function etelai_mem_note_key($text) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', substr($text, 0, 40)));
        $slug = trim($slug, '-');
        if ($slug === '') $slug = 'note';
        return 'note:' . substr($slug, 0, 56);
    }

    // ---- Prompt block ----------------------------------------

    function etelai_mem_prompt_block($facts) {
        if (!$facts) return '';
        $lines = array();
        foreach ($facts as $k => $v) {
            $lines[] = '- ' . $k . ': ' . $v;
        }
        return "\n\nThings you remember about this member from previous conversations "
             . "(use them naturally to personalize, do not recite the list):\n"
             . implode("\n", $lines);
    }
}
