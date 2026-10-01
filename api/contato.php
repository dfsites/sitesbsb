<?php
/**
 * Recebe o formulário de contato e grava o lead no banco (visível em /painel/).
 * Resposta JSON quando o cliente pede (fetch); redirecionamento para /obrigado/ no envio sem JavaScript.
 * Sem sb-config.php: responde 503 e NÃO descarta silenciosamente o pedido.
 */
declare(strict_types=1);
require __DIR__ . '/_lib/bootstrap.php';

$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function finish(bool $wantsJson, int $status, array $data): void {
    if ($wantsJson) sb_json($status, $data);
    if ($status < 400) { header('Location: /obrigado/', true, 303); exit; }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $msg = htmlspecialchars($data['error'] ?? 'Não foi possível enviar.', ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta charset=utf-8><meta name=robots content=noindex><title>Erro no envio</title><p>$msg</p><p><a href=\"/contato/\">Voltar ao formulário</a></p>";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    finish(true, 405, ['ok' => false, 'error' => 'Método não permitido.']);
}

if (!sb_config()) {
    error_log('[sitesbrasilia] contato.php: sb-config.php ausente — lead não gravado.');
    finish($wantsJson, 503, ['ok' => false, 'error' => 'Formulário temporariamente indisponível. Use outro canal de contato.']);
}

$in = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);

// Honeypot: robôs preenchem o campo oculto. Responde "ok" sem gravar.
if ($in('website', 200) !== '') finish($wantsJson, 200, ['ok' => true]);

try {
    if (!sb_rate_ok('lead', 5, 600)) {
        finish($wantsJson, 429, ['ok' => false, 'error' => 'Muitos envios em sequência. Tente novamente em alguns minutos.']);
    }

    $nome = $in('nome', 120);
    $empresa = $in('empresa', 160);
    // Campo "Telefone" (opcional). Com "+" e código de país diferente de 55, guarda como internacional ("+dígitos").
    $rawPhone = $in('whatsapp', 30);
    $whatsapp = preg_replace('/\D+/', '', $rawPhone) ?? '';
    if ($whatsapp !== '' && str_starts_with(ltrim($rawPhone), '+')) {
        $whatsapp = str_starts_with($whatsapp, '55') ? substr($whatsapp, 2) : '+' . $whatsapp;
    }
    $email = $in('email', 160);
    $servico = $in('servico', 30);
    $siteAtual = $in('site_atual', 300);
    $mensagem = $in('mensagem', 3000);
    $origem = $in('origem', 300);
    $utm = $in('utm', 500);

    $errors = [];
    if (mb_strlen($nome) < 2) $errors['nome'] = 'Informe seu nome.';
    $phoneDigits = ltrim($whatsapp, '+');
    if ($whatsapp !== '' && (strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15)) $errors['whatsapp'] = 'Telefone inválido.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'E-mail inválido.';
    if (!array_key_exists($servico, SB_SERVICOS)) $errors['servico'] = 'Selecione o serviço.';
    if ($siteAtual !== '' && !preg_match('#^https?://[^\s.]+\.[^\s]{2,}$#i', $siteAtual)) $errors['site_atual'] = 'Endereço inválido.';
    if ($utm !== '' && json_decode($utm, true) === null) $utm = '';
    if ($errors) finish($wantsJson, 422, ['ok' => false, 'error' => 'Revise os campos.', 'fields' => $errors]);

    $now = sb_now();
    $st = sb_db()->prepare('INSERT INTO sb_leads (created_at, updated_at, status, nome, empresa, whatsapp, email, servico, site_atual, mensagem, origem, utm, ip, user_agent)
        VALUES (?, ?, \'novo\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$now, $now, $nome, $empresa ?: null, $whatsapp, $email, $servico, $siteAtual ?: null, $mensagem ?: null, $origem ?: null, $utm ?: null, sb_client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)]);
    $id = (int)sb_db()->lastInsertId();
    sb_rate_hit('lead');

    // Aviso opcional por e-mail (não bloqueia o registro se falhar).
    $cfg = sb_config();
    if (!empty($cfg['notify_email']) && !sb_is_local()) {
        $subject = '=?UTF-8?B?' . base64_encode("Novo contato #$id — " . (SB_SERVICOS[$servico] ?? $servico)) . '?=';
        $body = "Novo contato pelo site.\n\nNome: $nome\nEmpresa: $empresa\nTelefone: $whatsapp\nE-mail: $email\nServiço: " . (SB_SERVICOS[$servico] ?? $servico)
            . "\nSite atual: $siteAtual\n\nMensagem:\n$mensagem\n\nVer no painel: https://sitesbrasilia.com.br/painel/lead.php?id=$id\n";
        $from = $cfg['mail_from'] ?? $cfg['notify_email'];
        $headers = "From: Sites Brasília <$from>\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8";
        @mail($cfg['notify_email'], $subject, $body, $headers);
    }

    finish($wantsJson, 200, ['ok' => true, 'id' => $id, 'servico' => $servico]);
} catch (Throwable $e) {
    error_log('[sitesbrasilia] contato.php: ' . $e->getMessage());
    finish($wantsJson, 500, ['ok' => false, 'error' => 'Erro ao registrar. Tente novamente em instantes.']);
}
