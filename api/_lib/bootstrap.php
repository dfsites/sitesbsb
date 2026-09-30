<?php
/**
 * Base compartilhada do backend (formulário de contato + painel de leads).
 *
 * CONFIGURAÇÃO: arquivo sb-config.php FORA da pasta pública.
 *   Ordem de busca: variável de ambiente SB_CONFIG → <pasta acima do document root>/sb-config.php
 *   Ex. produção: /home/sitesbrasilia/sb-config.php (document root /home/sitesbrasilia/www)
 *   Ex. local:    <projeto>/sb-config.php (document root <projeto>/dist)
 *   Modelo: sb-config.example.php. Nunca versionar o arquivo real.
 *
 * Compatível com PHP 8.0+.
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function sb_is_local(): bool {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || PHP_SAPI === 'cli';
}

function sb_config(): ?array {
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $candidates = array_filter([
        getenv('SB_CONFIG') ?: null,
        isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? dirname(realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) . '/sb-config.php' : null,
    ]);
    foreach ($candidates as $file) {
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) { $cfg = $loaded; return $cfg; }
        }
    }
    $cfg = null;
    return $cfg;
}

function sb_json(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sb_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function sb_now(): string {
    return gmdate('Y-m-d H:i:s');
}

/** Conexão PDO. SQLite (padrão) ou MySQL, conforme sb-config.php. Cria as tabelas se não existirem. */
function sb_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = sb_config();
    if (!$cfg || empty($cfg['db'])) throw new RuntimeException('Banco não configurado.');
    $db = $cfg['db'];
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];

    if (($db['driver'] ?? 'sqlite') === 'mysql') {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int)($db['port'] ?? 3306), $db['name']);
        $pdo = new PDO($dsn, $db['user'], $db['pass'], $opts);
        // Compatível com MySQL 5.5 (servidor atual): InnoDB + utf8mb4, sem JSON nem DEFAULT em DATETIME.
        $auto = 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $text = 'TEXT';
        $vc = fn(int $n) => "VARCHAR($n)";
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    } else {
        $path = $db['path'];
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('Não foi possível criar a pasta do banco.');
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 4000;');
        $auto = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = 'TEXT';
        $vc = fn(int $n) => 'TEXT';
        $suffix = '';
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS sb_leads (
        id $auto,
        created_at {$vc(19)} NOT NULL,
        updated_at {$vc(19)} NOT NULL,
        status {$vc(20)} NOT NULL DEFAULT 'novo',
        nome {$vc(120)} NOT NULL,
        empresa {$vc(160)},
        whatsapp {$vc(20)} NOT NULL,
        email {$vc(160)} NOT NULL,
        servico {$vc(30)} NOT NULL,
        site_atual {$vc(300)},
        mensagem $text,
        origem {$vc(300)},
        utm {$vc(500)},
        ip {$vc(45)},
        user_agent {$vc(300)}
    )$suffix");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sb_lead_notes (
        id $auto,
        lead_id INTEGER NOT NULL,
        created_at {$vc(19)} NOT NULL,
        author {$vc(60)} NOT NULL,
        body $text NOT NULL
    )$suffix");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sb_rate_events (
        id $auto,
        kind {$vc(20)} NOT NULL,
        ip {$vc(45)} NOT NULL,
        created_at {$vc(19)} NOT NULL
    )$suffix");
    if (!$suffix) { $pdo->exec("CREATE INDEX IF NOT EXISTS sb_leads_status ON sb_leads (status)"); $pdo->exec("CREATE INDEX IF NOT EXISTS sb_notes_lead ON sb_lead_notes (lead_id)"); $pdo->exec("CREATE INDEX IF NOT EXISTS sb_rate_ip ON sb_rate_events (kind, ip, created_at)"); }
    return $pdo;
}

/** Limite simples por IP e janela de tempo. Retorna true se ainda pode prosseguir. */
function sb_rate_ok(string $kind, int $max, int $windowSeconds): bool {
    $pdo = sb_db();
    $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    $pdo->prepare('DELETE FROM sb_rate_events WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM sb_rate_events WHERE kind = ? AND ip = ? AND created_at >= ?');
    $st->execute([$kind, sb_client_ip(), $since]);
    return (int)$st->fetchColumn() < $max;
}

function sb_rate_hit(string $kind): void {
    sb_db()->prepare('INSERT INTO sb_rate_events (kind, ip, created_at) VALUES (?, ?, ?)')->execute([$kind, sb_client_ip(), sb_now()]);
}

function sb_rate_clear(string $kind): void {
    sb_db()->prepare('DELETE FROM sb_rate_events WHERE kind = ? AND ip = ?')->execute([$kind, sb_client_ip()]);
}

const SB_STATUS = [
    'novo' => 'Novo',
    'em_contato' => 'Em contato',
    'proposta' => 'Proposta enviada',
    'ganho' => 'Fechado',
    'perdido' => 'Perdido',
    'arquivado' => 'Arquivado',
];

const SB_SERVICOS = [
    'site' => 'Site institucional',
    'landing' => 'Landing page',
    'app' => 'Aplicativo / sistema',
    'traducao' => 'Site multilíngue / tradução',
    'seo' => 'SEO',
    'google' => 'Google Meu Negócio',
    'redes' => 'Redes sociais',
    'hospedagem' => 'Hospedagem gerenciada',
    'dominio' => 'Registro de domínio',
    'manutencao' => 'Manutenção',
    'automacao' => 'Automação',
    'outro' => 'Outro / não sabe',
];
