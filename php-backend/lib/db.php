<?php
/**
 * Arch3 — conexão MySQL (PDO) + criação automática do schema.
 *
 * As tabelas são criadas no primeiro acesso (CREATE TABLE IF NOT EXISTS),
 * então não há passo de migração manual no HostGator: basta criar o banco
 * vazio no cPanel e preencher DB_* em arch3-config.php.
 */

require_once __DIR__ . '/config.php';

function arch3_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = cfg('DB_HOST', 'localhost');
    $name = cfg('DB_NAME', '');
    $user = cfg('DB_USER', '');
    $pass = cfg('DB_PASS', '');
    $port = (int) cfg('DB_PORT', 3306);

    if ($name === '') {
        throw new RuntimeException('Banco de dados não configurado (DB_NAME).');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    arch3_db_init($pdo);
    return $pdo;
}

function arch3_db_init(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                  VARCHAR(120) NOT NULL,
            email                 VARCHAR(190) NOT NULL,
            password_hash         VARCHAR(255) NOT NULL,
            created_at            DATETIME NOT NULL,
            last_login            DATETIME NULL,
            generations_used      INT UNSIGNED NOT NULL DEFAULT 0,
            credits_remaining     INT NOT NULL DEFAULT 1,
            subscription_plan     VARCHAR(40) NOT NULL DEFAULT 'free',
            subscription_status   VARCHAR(40) NOT NULL DEFAULT 'inactive',
            subscription_renews_at DATETIME NULL,
            stripe_customer_id    VARCHAR(120) NULL,
            country               VARCHAR(80) NULL,
            email_verified        TINYINT(1) NOT NULL DEFAULT 0,
            email_verify_token    VARCHAR(64) NULL,
            is_admin              TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Histórico de gerações — alimenta a métrica Total Generations.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS generations (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     INT UNSIGNED NOT NULL,
            created_at  DATETIME NOT NULL,
            prompt      TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Compras / receita — alimenta Monthly Revenue.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchases (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      INT UNSIGNED NOT NULL,
            plan         VARCHAR(40) NOT NULL,
            amount_cents INT UNSIGNED NOT NULL,
            credits      INT UNSIGNED NOT NULL,
            provider     VARCHAR(40) NOT NULL DEFAULT 'dev',
            reference    VARCHAR(190) NULL,
            created_at   DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
