<?php
/** Exporta os contatos (com os filtros atuais) em CSV compatível com Excel pt-BR. */
declare(strict_types=1);
require __DIR__ . '/_painel.php';
require __DIR__ . '/_filtros.php';
painel_require_login();

$f = lead_filters();
[$where, $params] = lead_where($f);
$st = sb_db()->prepare("SELECT * FROM sb_leads $where ORDER BY id DESC");
$st->execute($params);

// Evita injeção de fórmula ao abrir no Excel/Sheets.
$safe = function ($v): string {
    $v = (string)$v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="contatos-sitesbrasilia-' . gmdate('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8
fputcsv($out, ['ID', 'Recebido (Brasília)', 'Status', 'Nome', 'Empresa', 'WhatsApp', 'E-mail', 'Serviço', 'Site atual', 'Mensagem', 'Página de origem', 'UTM'], ';');
while ($r = $st->fetch()) {
    fputcsv($out, array_map($safe, [
        $r['id'], br_date($r['created_at']), SB_STATUS[$r['status']] ?? $r['status'], $r['nome'], $r['empresa'], fmt_phone($r['whatsapp']),
        $r['email'], SB_SERVICOS[$r['servico']] ?? $r['servico'], $r['site_atual'], $r['mensagem'], $r['origem'], $r['utm'],
    ]), ';');
}
fclose($out);
