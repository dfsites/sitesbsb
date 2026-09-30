<?php
declare(strict_types=1);
require __DIR__ . '/_painel.php';
require __DIR__ . '/_filtros.php';
$user = painel_require_login();

$f = lead_filters();
[$where, $params] = lead_where($f);
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;

try {
    $pdo = sb_db();
    $count = $pdo->prepare("SELECT COUNT(*) FROM sb_leads $where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $st = $pdo->prepare("SELECT id, created_at, status, nome, empresa, whatsapp, email, servico FROM sb_leads $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = $st->fetchAll();
    $byStatus = $pdo->query('SELECT status, COUNT(*) n FROM sb_leads GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    error_log('[sitesbrasilia] painel lista: ' . $e->getMessage());
    painel_head('Contatos', $user);
    echo '<p class="alert">Erro ao ler o banco de dados.</p>';
    painel_foot();
    exit;
}

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge($f, ['p' => null], $over), fn($v) => $v !== null && $v !== '' && $v !== 'ativos'));
$ativos = array_sum(array_diff_key($byStatus, ['arquivado' => 0]));
$pages = max(1, (int)ceil($total / $per));

painel_head('Contatos', $user);
?>
<h1>Contatos <small><?= $total ?></small></h1>

<nav class="chips" aria-label="Filtrar por status">
  <a href="<?= e($qs(['status' => 'ativos'])) ?>" <?= $f['status'] === 'ativos' ? 'aria-current="true"' : '' ?>>Ativos (<?= $ativos ?>)</a>
  <?php foreach (SB_STATUS as $k => $label): ?>
    <a href="<?= e($qs(['status' => $k])) ?>" class="st-<?= e($k) ?>" <?= $f['status'] === $k ? 'aria-current="true"' : '' ?>><?= e($label) ?> (<?= (int)($byStatus[$k] ?? 0) ?>)</a>
  <?php endforeach; ?>
  <a href="<?= e($qs(['status' => 'todos'])) ?>" <?= $f['status'] === 'todos' ? 'aria-current="true"' : '' ?>>Todos</a>
</nav>

<form class="filters" method="get" role="search">
  <?php if ($f['status'] !== 'ativos'): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <label class="sr" for="q">Buscar</label>
  <input id="q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="Buscar nome, empresa, e-mail, WhatsApp ou mensagem">
  <label class="sr" for="servico">Serviço</label>
  <select id="servico" name="servico">
    <option value="">Todos os serviços</option>
    <?php foreach (SB_SERVICOS as $k => $label): ?><option value="<?= e($k) ?>" <?= $f['servico'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filtrar</button>
  <?php if ($f['q'] !== '' || $f['servico'] !== ''): ?><a href="<?= e($qs(['q' => '', 'servico' => ''])) ?>">Limpar</a><?php endif; ?>
</form>

<?php if (!$rows): ?>
  <p class="empty">Nenhum contato encontrado com esses filtros.</p>
<?php else: ?>
<div class="table-wrap">
<table class="leads">
  <thead><tr><th scope="col">#</th><th scope="col">Recebido</th><th scope="col">Nome</th><th scope="col">Serviço</th><th scope="col">Contato</th><th scope="col">Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] === 'novo' ? 'is-new' : '' ?>">
      <td data-label="#"><?= (int)$r['id'] ?></td>
      <td data-label="Recebido"><?= e(br_date($r['created_at'])) ?></td>
      <td data-label="Nome"><a href="/painel/lead.php?id=<?= (int)$r['id'] ?>"><?= e($r['nome']) ?></a><?php if ($r['empresa']): ?><br><span class="muted"><?= e($r['empresa']) ?></span><?php endif; ?></td>
      <td data-label="Serviço"><?= e(SB_SERVICOS[$r['servico']] ?? $r['servico']) ?></td>
      <td data-label="Contato"><a href="<?= e(wa_link($r['whatsapp'], $r['nome'])) ?>" target="_blank" rel="noopener"><?= e(fmt_phone($r['whatsapp'])) ?></a><br><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td>
      <td data-label="Status"><span class="badge st-<?= e($r['status']) ?>"><?= e(SB_STATUS[$r['status']] ?? $r['status']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Paginação">
  <?php if ($page > 1): ?><a href="<?= e($qs(['p' => $page - 1])) ?>">← Anteriores</a><?php endif; ?>
  <span>Página <?= $page ?> de <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= e($qs(['p' => $page + 1])) ?>">Próximos →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php painel_foot();
