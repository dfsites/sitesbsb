<?php
declare(strict_types=1);
require __DIR__ . '/_painel.php';
$user = painel_require_login();

$id = (int)($_GET['id'] ?? 0);
$pdo = sb_db();
$load = function () use ($pdo, $id) {
    $st = $pdo->prepare('SELECT * FROM sb_leads WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
$lead = $load();
if (!$lead) { http_response_code(404); painel_head('Não encontrado', $user); echo '<p class="alert">Contato não encontrado.</p><p><a href="/painel/">Voltar</a></p>'; painel_foot(); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $now = sb_now();
    if ($action === 'status') {
        $new = (string)($_POST['status'] ?? '');
        if (array_key_exists($new, SB_STATUS) && $new !== $lead['status']) {
            $pdo->prepare('UPDATE sb_leads SET status = ?, updated_at = ? WHERE id = ?')->execute([$new, $now, $id]);
            $pdo->prepare('INSERT INTO sb_lead_notes (lead_id, created_at, author, body) VALUES (?, ?, ?, ?)')
                ->execute([$id, $now, $user, 'Status: ' . (SB_STATUS[$lead['status']] ?? $lead['status']) . ' → ' . SB_STATUS[$new]]);
            flash('Status atualizado.');
        }
    } elseif ($action === 'nota') {
        $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 5000);
        if ($body !== '') {
            $pdo->prepare('INSERT INTO sb_lead_notes (lead_id, created_at, author, body) VALUES (?, ?, ?, ?)')->execute([$id, $now, $user, $body]);
            $pdo->prepare('UPDATE sb_leads SET updated_at = ? WHERE id = ?')->execute([$now, $id]);
            flash('Anotação registrada.');
        }
    } elseif ($action === 'excluir') {
        // Exclusão definitiva (ex.: pedido do titular dos dados — LGPD). Exige confirmação digitada.
        if (trim((string)($_POST['confirmar'] ?? '')) === 'EXCLUIR') {
            $pdo->prepare('DELETE FROM sb_lead_notes WHERE lead_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM sb_leads WHERE id = ?')->execute([$id]);
            flash("Contato #$id excluído definitivamente.");
            header('Location: /painel/', true, 303);
            exit;
        }
        flash('Para excluir, digite EXCLUIR no campo de confirmação.');
    }
    header('Location: /painel/lead.php?id=' . $id, true, 303);
    exit;
}

$notes = $pdo->prepare('SELECT * FROM sb_lead_notes WHERE lead_id = ? ORDER BY id DESC');
$notes->execute([$id]);
$notes = $notes->fetchAll();
$utm = $lead['utm'] ? json_decode($lead['utm'], true) : null;

painel_head('Contato #' . $id, $user);
?>
<p><a href="/painel/">← Todos os contatos</a></p>
<div class="lead-head">
  <h1><?= e($lead['nome']) ?> <small>#<?= $id ?></small></h1>
  <span class="badge st-<?= e($lead['status']) ?>"><?= e(SB_STATUS[$lead['status']] ?? $lead['status']) ?></span>
</div>

<div class="lead-grid">
  <section class="card">
    <h2>Dados do contato</h2>
    <dl>
      <dt>Recebido</dt><dd><?= e(br_date($lead['created_at'])) ?></dd>
      <?php if ($lead['empresa']): ?><dt>Empresa</dt><dd><?= e($lead['empresa']) ?></dd><?php endif; ?>
      <?php if ($lead['whatsapp'] !== ''): ?><dt>Telefone</dt><dd><a href="<?= e(wa_link($lead['whatsapp'], $lead['nome'])) ?>" target="_blank" rel="noopener"><?= e(fmt_phone($lead['whatsapp'])) ?></a> <span class="muted small">(abre no WhatsApp)</span></dd><?php endif; ?>
      <dt>E-mail</dt><dd><a href="mailto:<?= e($lead['email']) ?>"><?= e($lead['email']) ?></a></dd>
      <dt>Serviço</dt><dd><?= e(SB_SERVICOS[$lead['servico']] ?? $lead['servico']) ?></dd>
      <?php if ($lead['site_atual']): ?><dt>Site atual</dt><dd><a href="<?= e($lead['site_atual']) ?>" target="_blank" rel="noopener noreferrer"><?= e($lead['site_atual']) ?></a></dd><?php endif; ?>
    </dl>
    <?php if ($lead['mensagem']): ?><h3>Mensagem</h3><p class="msg"><?= nl2br(e($lead['mensagem'])) ?></p><?php endif; ?>
    <h3>Origem</h3>
    <dl class="small">
      <dt>Página</dt><dd><?= e($lead['origem'] ?: '—') ?></dd>
      <?php if (is_array($utm)): foreach ($utm as $k => $v): ?><dt><?= e((string)$k) ?></dt><dd><?= e((string)$v) ?></dd><?php endforeach; endif; ?>
    </dl>
  </section>

  <section>
    <div class="card">
      <h2>Status</h2>
      <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="status">
        <label class="sr" for="status">Novo status</label>
        <select id="status" name="status">
          <?php foreach (SB_STATUS as $k => $label): ?><option value="<?= e($k) ?>" <?= $lead['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Salvar</button>
      </form>
    </div>

    <div class="card">
      <h2>Anotações</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="nota">
        <label class="sr" for="body">Nova anotação</label>
        <textarea id="body" name="body" rows="3" required placeholder="Ex.: liguei, pediu proposta até sexta."></textarea>
        <button class="btn" type="submit">Adicionar anotação</button>
      </form>
      <?php if ($notes): ?>
      <ol class="notes">
        <?php foreach ($notes as $n): ?>
          <li><p><?= nl2br(e($n['body'])) ?></p><span class="muted small"><?= e($n['author']) ?> · <?= e(br_date($n['created_at'])) ?></span></li>
        <?php endforeach; ?>
      </ol>
      <?php else: ?><p class="muted small">Nenhuma anotação ainda.</p><?php endif; ?>
    </div>

    <details class="card danger">
      <summary>Excluir contato definitivamente</summary>
      <p class="small">Use para pedidos de exclusão de dados (LGPD). Para tirar da lista sem apagar, prefira o status "Arquivado".</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="excluir">
        <label for="confirmar">Digite <strong>EXCLUIR</strong> para confirmar</label>
        <input id="confirmar" name="confirmar" type="text" autocomplete="off" required>
        <button class="btn btn-danger" type="submit">Excluir definitivamente</button>
      </form>
    </details>
  </section>
</div>
<?php painel_foot();
