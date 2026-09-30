<?php
/**
 * Núcleo do painel interno: sessão, login, CSRF e layout.
 * Usuários e hashes de senha ficam em sb-config.php ('users' => ['usuario' => password_hash]).
 * Gere o hash com: php scripts/painel-senha.php
 */
declare(strict_types=1);
require __DIR__ . '/../api/_lib/bootstrap.php';

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('sb_painel');
session_set_cookie_params(['lifetime' => 0, 'path' => '/painel/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

const SB_IDLE_TIMEOUT = 60 * 60 * 4; // 4 h sem atividade encerra a sessão

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function painel_users(): array {
    $cfg = sb_config();
    return is_array($cfg['users'] ?? null) ? $cfg['users'] : [];
}

function painel_user(): ?string {
    if (empty($_SESSION['user'])) return null;
    if (time() - (int)($_SESSION['seen'] ?? 0) > SB_IDLE_TIMEOUT) { session_unset(); return null; }
    $_SESSION['seen'] = time();
    return array_key_exists($_SESSION['user'], painel_users()) ? $_SESSION['user'] : null;
}

function painel_require_login(): string {
    $u = painel_user();
    if ($u) return $u;
    header('Location: /painel/entrar.php', true, 303);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $sent = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) { http_response_code(400); exit('Sessão expirada. Volte e tente novamente.'); }
}

function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}

/** Datas gravadas em UTC; exibidas no horário de Brasília. */
function br_date(?string $utc): string {
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return $d->format('d/m/Y H:i');
}

function wa_link(string $digits, string $nome): string {
    $msg = rawurlencode("Olá, $nome! Aqui é do Sites Brasília, recebemos seu contato pelo site.");
    return 'https://wa.me/55' . $digits . '?text=' . $msg;
}

function fmt_phone(string $d): string {
    return strlen($d) === 11 ? sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7))
        : (strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)) : $d);
}

function painel_head(string $title, ?string $user = null): void { ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Painel Sites Brasília</title>
<link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
<link rel="stylesheet" href="/painel/painel.css">
</head>
<body>
<header class="top">
  <a class="brand" href="/painel/">Sites Brasília <span>Painel</span></a>
  <?php if ($user): ?>
  <nav>
    <a href="/painel/">Contatos</a>
    <a href="/painel/exportar.php<?= isset($_SERVER['QUERY_STRING']) && basename($_SERVER['SCRIPT_NAME']) === 'index.php' && $_SERVER['QUERY_STRING'] !== '' ? '?' . e($_SERVER['QUERY_STRING']) : '' ?>">Exportar CSV</a>
    <form method="post" action="/painel/sair.php"><?= csrf_field() ?><button class="link" type="submit">Sair (<?= e($user) ?>)</button></form>
  </nav>
  <?php endif; ?>
</header>
<main class="wrap">
<?php if ($m = flash()): ?><p class="flash" role="status"><?= e($m) ?></p><?php endif;
}

function painel_foot(): void { ?>
</main>
</body>
</html>
<?php }
