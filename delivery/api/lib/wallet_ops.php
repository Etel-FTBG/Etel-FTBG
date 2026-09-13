<?php
// Shared wallet operations - used by EqubController.php and available
// to any future feature moving real money through user_wallets. Kept
// in lib/ per this codebase's convention (functions used across
// multiple controllers belong here, with function_exists() guards).
//
// CRITICAL: both functions assume the CALLER has already started a
// PDO transaction if the operation needs to be atomic alongside other
// writes (e.g. debiting 5 people then crediting 1 recipient must all
// succeed or all roll back together). Neither function commits or
// rolls back on its own.

if (!function_exists('wallet_get_or_create')) {
    function wallet_get_or_create($pdo, $userId) {
        $s = $pdo->prepare('SELECT id, balance FROM user_wallets WHERE user_id=? FOR UPDATE');
        $s->execute(array($userId));
        $row = $s->fetch();
        if ($row) return $row;
        $pdo->prepare('INSERT INTO user_wallets (user_id, balance) VALUES (?, 0.00)')->execute(array($userId));
        return array('id' => $pdo->lastInsertId(), 'balance' => 0.00);
    }
}

if (!function_exists('wallet_debit')) {
    // Returns true on success, false on insufficient funds. Never
    // throws for insufficient funds - that's an expected, common
    // outcome the caller must handle explicitly (e.g. mark an Equb
    // contribution 'missed'), not an exceptional error.
    function wallet_debit($pdo, $userId, $amount, $reason, $referenceId = null) {
        if ($amount <= 0) throw new Exception('wallet_debit amount must be positive');
        $wallet = wallet_get_or_create($pdo, $userId);
        if (floatval($wallet['balance']) < $amount) return false;
        $newBalance = round(floatval($wallet['balance']) - $amount, 2);
        $pdo->prepare('UPDATE user_wallets SET balance=? WHERE user_id=?')->execute(array($newBalance, $userId));
        $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, balance_after, reason, reference_id) VALUES (?,?,?,?,?,?)')
            ->execute(array($userId, 'debit', $amount, $newBalance, $reason, $referenceId));
        return true;
    }
}

if (!function_exists('wallet_credit')) {
    function wallet_credit($pdo, $userId, $amount, $reason, $referenceId = null) {
        if ($amount <= 0) throw new Exception('wallet_credit amount must be positive');
        $wallet = wallet_get_or_create($pdo, $userId);
        $newBalance = round(floatval($wallet['balance']) + $amount, 2);
        $pdo->prepare('UPDATE user_wallets SET balance=? WHERE user_id=?')->execute(array($newBalance, $userId));
        $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, balance_after, reason, reference_id) VALUES (?,?,?,?,?,?)')
            ->execute(array($userId, 'credit', $amount, $newBalance, $reason, $referenceId));
        return $pdo->lastInsertId();
    }
}
