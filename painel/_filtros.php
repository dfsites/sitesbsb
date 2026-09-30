<?php
/** Filtros compartilhados entre a lista e a exportação CSV. */
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function lead_filters(): array {
    $status = (string)($_GET['status'] ?? 'ativos');
    if ($status !== 'ativos' && $status !== 'todos' && !array_key_exists($status, SB_STATUS)) $status = 'ativos';
    $servico = (string)($_GET['servico'] ?? '');
    if ($servico !== '' && !array_key_exists($servico, SB_SERVICOS)) $servico = '';
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    return ['status' => $status, 'servico' => $servico, 'q' => $q];
}

/** @return array{0:string,1:array} cláusula WHERE e parâmetros */
function lead_where(array $f): array {
    $w = []; $p = [];
    if ($f['status'] === 'ativos') { $w[] = "status <> 'arquivado'"; }
    elseif ($f['status'] !== 'todos') { $w[] = 'status = ?'; $p[] = $f['status']; }
    if ($f['servico'] !== '') { $w[] = 'servico = ?'; $p[] = $f['servico']; }
    if ($f['q'] !== '') {
        // Escape com "!" (funciona igual em SQLite e MySQL; "\" é tratado diferente pelos dois).
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']) . '%';
        $digits = preg_replace('/\D+/', '', $f['q']);
        $w[] = "(nome LIKE ? ESCAPE '!' OR empresa LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR mensagem LIKE ? ESCAPE '!'" . ($digits !== '' ? ' OR whatsapp LIKE ?' : '') . ')';
        array_push($p, $like, $like, $like, $like);
        if ($digits !== '') $p[] = '%' . $digits . '%';
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}
