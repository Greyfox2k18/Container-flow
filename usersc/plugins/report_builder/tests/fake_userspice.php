<?php
/**
 * Minimal stand-ins for the UserSpice pieces the plugin touches (DB class,
 * fetchPermissionUsers), backed by SQLite — tests only.
 */
if (PHP_SAPI !== 'cli') die();

if (!class_exists('DB')) {
class DB {
    public static $pdo;
    private static $inst;
    private $rows = [], $err = false, $errStr = '', $lastId = 0;

    public static function connect($dsn) {
        self::$pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // MySQL's FIELD(), used by the legacy daily digest's ORDER BY.
        self::$pdo->sqliteCreateFunction('FIELD', function ($v, ...$list) {
            $i = array_search($v, $list); return $i === false ? 0 : $i + 1;
        }, -1);
        return self::$pdo;
    }
    public static function getInstance() { return self::$inst ?: self::$inst = new self(); }

    public function query($sql, $params = []) {
        try {
            $st = self::$pdo->prepare($sql);
            $st->execute(array_values((array) $params));
            $this->rows = $st->columnCount() ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
            $this->err = false;
        } catch (PDOException $e) {
            $this->rows = []; $this->err = true; $this->errStr = $e->getMessage();
        }
        return $this;
    }
    public function results($assoc = false) { return $assoc ? $this->rows : array_map(function ($r) { return (object) $r; }, $this->rows); }
    public function first() { $r = $this->results(); return $r[0] ?? false; }
    public function count() { return count($this->rows); }
    public function error() { return $this->err; }
    public function errorString() { return $this->errStr; }
    public function lastId() { return $this->lastId; }
    public function insert($table, $fields) {
        $cols = array_keys($fields);
        $this->query("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($fields));
        $this->lastId = (int) self::$pdo->lastInsertId();
        return !$this->err;
    }
    public function update($table, $id, $fields) {
        $set = implode(',', array_map(function ($c) { return "$c = ?"; }, array_keys($fields)));
        $this->query("UPDATE $table SET $set WHERE id = ?", array_merge(array_values($fields), [$id]));
        return !$this->err;
    }
}
}

if (!function_exists('fetchPermissionUsers')) {
    function fetchPermissionUsers($perm) {
        return DB::getInstance()->query('SELECT user_id FROM user_permission_matches WHERE permission_id = ?', [$perm])->results();
    }
}

/** Fresh SQLite fixture DB with Container Flow + plugin tables. */
function rb_fixture_db($dsn) {
    $pdo = DB::connect($dsn);
    $pdo->exec("
    CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE warehouses (id INTEGER PRIMARY KEY, name TEXT, sort_order INT DEFAULT 0);
    CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, fname TEXT, lname TEXT, active INT DEFAULT 1);
    CREATE TABLE user_permission_matches (user_id INT, permission_id INT);
    CREATE TABLE containers (
      id INTEGER PRIMARY KEY, container_number TEXT, type TEXT, status TEXT, customer_id INT, warehouse_id INT,
      carrier TEXT, shipment_number TEXT, seal_number TEXT, po_bol_number TEXT, piece_count INT,
      receipt_ship_date TEXT, notes TEXT, created_by INT, assigned_to INT, created_at TEXT, updated_at TEXT,
      archived_at TEXT, drive_backed_up_at TEXT, drive_folder_link TEXT);
    CREATE TABLE plg_rb_reports (
      id INTEGER PRIMARY KEY, name TEXT, description TEXT, active INT DEFAULT 0, layout_json TEXT,
      schedule_frequency TEXT, schedule_day_of_week INT, schedule_day_of_month INT, schedule_hour INT DEFAULT 6,
      attach_csv INT DEFAULT 0, scope_mode TEXT DEFAULT 'creator', created_by INT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, last_sent_at TEXT);
    CREATE TABLE plg_rb_recipients (id INTEGER PRIMARY KEY, report_id INT, kind TEXT, email TEXT, user_id INT, permission_id INT, note TEXT);
    CREATE TABLE plg_rb_run_log (id INTEGER PRIMARY KEY, report_id INT, trigger_type TEXT, run_at TEXT DEFAULT CURRENT_TIMESTAMP,
      recipient_count INT, row_count INT, success INT, error_message TEXT);
    INSERT INTO customers VALUES (1,'Acme Imports'),(2,'Beta & Sons <Foods>');
    INSERT INTO warehouses VALUES (1,'North',0),(2,'South',1);
    INSERT INTO users VALUES (1,'dan@example.com','Dan','R',1),(2,'sue@example.com','Sue','North',1),(3,'old@example.com','Old','Sup',0);
    INSERT INTO user_permission_matches VALUES (1,3),(2,3),(3,3);
    ");
    return $pdo;
}
