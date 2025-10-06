<?php
$databasePath = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Maʼlumotlar bazasiga ulana olmadik: ' . $e->getMessage());
}

$pdo->exec('CREATE TABLE IF NOT EXISTS books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account TEXT NOT NULL,
    title TEXT NOT NULL,
    author TEXT,
    category TEXT,
    buy_price REAL NOT NULL,
    sell_price REAL NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0,
    last_quantity_snapshot INTEGER,
    last_quantity_change INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TRIGGER IF NOT EXISTS update_books_timestamp
AFTER UPDATE ON books
FOR EACH ROW
BEGIN
    UPDATE books SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;');

$pdo->exec('CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account TEXT NOT NULL,
    book_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    total_cost REAL NOT NULL,
    total_revenue REAL NOT NULL,
    profit REAL NOT NULL,
    payment_method TEXT NOT NULL DEFAULT "cash",
    note TEXT,
    sold_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(book_id) REFERENCES books(id)
)');

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $info) {
        if (strcasecmp($info['name'], $column) === 0) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

ensureColumn($pdo, 'books', 'last_quantity_snapshot', 'INTEGER');
ensureColumn($pdo, 'books', 'last_quantity_change', 'INTEGER NOT NULL DEFAULT 0');
ensureColumn($pdo, 'sales', 'payment_method', 'TEXT NOT NULL DEFAULT "cash"');
ensureColumn($pdo, 'sales', 'note', 'TEXT');
