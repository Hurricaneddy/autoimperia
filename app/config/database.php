<?php
declare(strict_types=1);

if (!is_file(DEMO_DB_FILE)) {
    $directory = dirname(DEMO_DB_FILE);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог базы');
    }
    $lock = fopen(DEMO_DB_FILE . '.init', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Не удалось подготовить базу');
    }
    try {
        if (!is_file(DEMO_DB_FILE)) {
            $temporary = DEMO_DB_FILE . '.new';
            if (!copy(__DIR__ . '/../demo-seed.sqlite', $temporary) || !rename($temporary, DEMO_DB_FILE)) {
                throw new RuntimeException('Не удалось открыть базу демонстрационных данных');
            }
            chmod(DEMO_DB_FILE, 0600);
        }
    } finally {
        fclose($lock);
    }
}
$pdo = new PDO('sqlite:' . DEMO_DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000;');
$pdo->sqliteCreateFunction('concat', static fn (...$parts) => implode('', $parts));
$pdo->sqliteCreateFunction('rand', static fn ($min = 0, $max = 32767) => mt_rand($min, $max));
if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
    $pdo->beginTransaction();
    try {
        $pdo->exec(file_get_contents(__DIR__ . '/client_schema.sql'));
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
