<?php
declare(strict_types=1);
require __DIR__ . '/_painel.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
}
header('Location: /painel/entrar.php', true, 303);
