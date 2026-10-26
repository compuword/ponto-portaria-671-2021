<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$falha_login = false;
$erro_interno = false;
$aviso = '';
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($_POST['login'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');

    if (!ponto_csrf_valido()) {
        $aviso = 'A pagina ficou aberta por muito tempo. Digite o login e a senha novamente.';
    } else {
        try {
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

            // O id volta do ODBC como string e ponto_log() pede int (strict_types):
            // sem o cast, login com senha errada de um usuario existente dava HTTP 500.
            ponto_log($func ? (int)$func['id'] : null, 'login_falha', '', $login);
            $falha_login = true;
        } catch (Throwable $e) {
            error_log('ponto login: ' . $e->getMessage());
            http_response_code(503);
            $erro_interno = true;
        }
    }
} elseif (($_GET['motivo'] ?? '') === 'inatividade') {
    $aviso = 'Sessao encerrada por inatividade.';
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
    <?php if ($falha_login): ?>
        <div class="banner banner-erro" role="alert">
            <strong>Falha no login.</strong> Login ou senha invalidos. Confira os dados e tente novamente.
        </div>
    <?php endif; ?>
    <?php if ($erro_interno): ?>
        <div class="banner banner-erro" role="alert">
            <strong>Nao foi possivel entrar agora.</strong> O servico de ponto teve um erro interno. Tente novamente em instantes; se persistir, avise o suporte.
        </div>
    <?php endif; ?>
    <?php if ($aviso !== ''): ?>
        <div class="banner banner-aviso" role="status"><?= htmlspecialchars($aviso) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Login<br><input type="text" name="login" value="<?= htmlspecialchars($login) ?>" autocomplete="username" <?= $login === '' ? 'autofocus' : '' ?> required></label><br>
        <label>Senha<br><input type="password" name="senha" autocomplete="current-password" <?= $login !== '' ? 'autofocus' : '' ?> required></label><br>
        <button type="submit">Entrar</button>
    </form>
</div>
</body>
</html>
