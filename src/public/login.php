<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $login = trim((string)($_POST['login'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');

    $stmt = ponto_db()->prepare('SELECT id, nome, senha_hash FROM dbo.funcionarios WHERE login = ? AND ativo = 1');
    $stmt->execute([$login]);
    $func = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($func && password_verify($senha, $func['senha_hash'])) {
        session_regenerate_id(true);
        $_SESSION['funcionario_id'] = (int)$func['id'];
        $_SESSION['funcionario_nome'] = $func['nome'];
        ponto_log((int)$func['id'], 'login_sucesso');
        header('Location: /bater.php');
        exit;
    }
    ponto_log($func['id'] ?? null, 'login_falha', '', $login);
    $erro = 'Login ou senha invalidos.';
} elseif (($_GET['motivo'] ?? '') === 'inatividade') {
    $erro = 'Sessao encerrada por inatividade.';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Compuword</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Ponto</h1>
    <?php if ($erro !== ''): ?>
        <p class="erro"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Login<br><input type="text" name="login" autofocus required></label><br>
        <label>Senha<br><input type="password" name="senha" required></label><br>
        <button type="submit">Entrar</button>
    </form>
</div>
</body>
</html>
