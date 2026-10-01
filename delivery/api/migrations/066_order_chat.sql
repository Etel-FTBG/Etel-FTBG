-- Migration 066: order chat.
-- order_messages holds one group chat per order (customer, shop, courier, admin support).
-- order_message_reads remembers the last message each person has read, which gives unread counts.
-- The code also creates these tables on first use (api/lib/order_chat.php), so code and
-- migration can deploy in either order.
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-terminating
-- semicolon last on its line (api/migrate.php splits by line).

CREATE TABLE IF NOT EXISTS `order_messages` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `order_id`    INT NOT NULL,
    `sender_id`   INT NOT NULL,
    `sender_role` VARCHAR(10) NOT NULL,
    `body`        VARCHAR(600) NOT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order` (`order_id`, `id`),
    INDEX `idx_sender_time` (`sender_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `order_message_reads` (
    `order_id`     INT NOT NULL,
    `user_id`      INT NOT NULL,
    `last_read_id` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`order_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
