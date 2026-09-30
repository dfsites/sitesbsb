<?php
declare(strict_types=1);
require __DIR__ . '/_painel.php';

if (painel_user()) { header('Location: /painel/', true, 303); exit; }

$error = null;
$configured = sb_config() && painel_users();

if ($configured && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    try {
        // Bloqueio: 5 tentativas erradas por IP em 15 minutos.
        if (!sb_rate_ok('login', 5, 900)) {
            $error = 'Muitas tentativas. Aguarde 15 minutos e tente novamente.';
        } else {
            $user = trim((string)($_POST['usuario'] ?? ''));
            $pass = (string)($_POST['senha'] ?? '');
            $users = painel_users();
            // Sempre verifica um hash (mesmo com usuário inexistente) para não revelar pelo tempo quais usuários existem.
            $hash = $users[$user] ?? password_hash(random_bytes(12), PASSWORD_DEFAULT);
            $ok = password_verify($pass, $hash);
            if ($ok && isset($users[$user])) {
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                $_SESSION['seen'] = time();
                unset($_SESSION['csrf']);
                sb_rate_clear('login');
                header('Location: /painel/', true, 303);
                exit;
            }
            sb_rate_hit('login');
            $error = 'Usuário ou senha incorretos.';
        }
    } catch (Throwable $e) {
        error_log('[sitesbrasilia] painel login: ' . $e->getMessage());
        $error = 'Erro ao acessar o banco de dados.';
    }
}

painel_head('Entrar');
?>
<section class="login">
  <h1>Entrar no painel</h1>
  <?php if (!$configured): ?>
    <p class="alert">Painel não configurado. Crie o arquivo <code>sb-config.php</code> fora da pasta pública, com o banco e pelo menos um usuário (veja <code>sb-config.example.php</code>).</p>
  <?php else: ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <label for="usuario">Usuário</label>
      <input id="usuario" name="usuario" type="text" autocomplete="username" required autofocus>
      <label for="senha">Senha</label>
      <input id="senha" name="senha" type="password" autocomplete="current-password" required>
      <button class="btn" type="submit">Entrar</button>
    </form>
  <?php endif; ?>
</section>
<?php painel_foot();
