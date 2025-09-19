<?php
declare(strict_types=1);

/**
 * Stubs for static analysis only (NOT used at runtime).
 * They make globals like $router / $pdo visible to PHPStan.
 */

/** Minimal router stub with get/post signatures. */
class Router
{
    /** @param callable():void $handler */
    public function get(string $path, callable $handler): void {}
    /** @param callable():void $handler */
    public function post(string $path, callable $handler): void {}
}

/** Global router used by the routes/*.php files. */
$router = new Router();

/** Very small PDO-ish stubs so PHPStan sees methods exist. */
class _PdoStmtStub {
    public function execute(array $params = []): void {}
    /** @return array<string,mixed>|false */
    public function fetch() { return []; }
    /** @return array<int,array<string,mixed>> */
    public function fetchAll(): array { return []; }
    /** @return mixed */
    public function fetchColumn() { return null; }
}
class _PdoStub {
    /** @return _PdoStmtStub */ public function prepare(string $q) { return new _PdoStmtStub(); }
    /** @return _PdoStmtStub */ public function query(string $q)   { return new _PdoStmtStub(); }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
    /** @return string */ public function lastInsertId(): string { return '0'; }
}

/** Global $pdo used throughout the app. */
$pdo = new _PdoStub();
