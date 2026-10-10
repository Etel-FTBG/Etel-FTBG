<?php
/** Thin PDO wrapper. Times are Africa/Addis_Ababa end to end (PHP and MySQL session). */
class Db
{
    private static $pdo = null;

    public static function set($pdo)
    {
        self::$pdo = $pdo;
    }

    public static function pdo()
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . Env::get('DB_HOST', 'localhost') . ';port=' . Env::get('DB_PORT', '3306')
                 . ';dbname=' . Env::get('DB_NAME', 'EtelERP') . ';charset=utf8mb4';
            self::$pdo = new PDO($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''), array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ));
            self::$pdo->exec("SET time_zone = '+03:00'");
        }
        return self::$pdo;
    }

    public static function all($sql, $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one($sql, $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function val($sql, $params = array())
    {
        $row = self::one($sql, $params);
        return $row === null ? null : reset($row);
    }

    public static function run($sql, $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert($sql, $params = array())
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Run $fn inside a transaction; commit on success, roll back on any exception. */
    public static function tx($fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn();   // already inside one: the outer call commits or rolls back
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function now()
    {
        return date('Y-m-d H:i:s');
    }
}
