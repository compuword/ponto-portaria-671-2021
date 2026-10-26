<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$func = ponto_require_login();
$erro = '';
$ok = false;

$tamanho_min = 8;
$tamanho_max = 72; // o bcrypt ignora o que passa de 72 bytes

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ponto_csrf_valido()) {
        $erro = 'A pagina expirou. Preencha de novo.';
    } else {
        $atual = (string)($_POST['senha_atual'] ?? '');
        $nova = (string)($_POST['nova_senha'] ?? '');
        $confirma = (string)($_POST['confirmar_senha'] ?? '');

        $stmt = ponto_db()->prepare('SELECT login, senha_hash FROM dbo.funcionarios WHERE id = ? AND ativo = 1');
        $stmt->execute([$func['id']]);
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conta || !password_verify($atual, (string)$conta['senha_hash'])) {
            $erro = 'Senha atual incorreta.';
            ponto_log($func['id'], 'trocar_senha_falha', 'senha atual incorreta');
        } elseif ($nova !== $confirma) {
            $erro = 'A confirmacao nao confere com a nova senha.';
        } elseif (strlen($nova) < $tamanho_min) {
            $erro = 'A nova senha precisa ter pelo menos ' . $tamanho_min . ' caracteres.';
        } elseif (strlen($nova) > $tamanho_max) {
            $erro = 'A nova senha pode ter no maximo ' . $tamanho_max . ' bytes.';
        } elseif ($nova === $atual) {
            $erro = 'A nova senha precisa ser diferente da atual.';
        } elseif (strcasecmp($nova, (string)$conta['login']) === 0) {
            $erro = 'A senha nao pode ser igual ao seu login.';
        } else {
            $stmt = ponto_db()->prepare('UPDATE dbo.funcionarios SET senha_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($nova, PASSWORD_BCRYPT), $func['id']]);
            ponto_log($func['id'], 'trocou_senha');
            session_regenerate_id(true);
            $ok = true;
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Alterar minha senha</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Alterar minha senha</h1>
    <p><a href="/bater.php">Voltar</a> | <a href="/logout.php">Sair</a></p>

    <?php if ($ok): ?>
        <div class="banner banner-ok" role="status"><strong>Senha alterada.</strong> Use a nova senha no proximo login.</div>
    <?php endif; ?>
    <?php if ($erro !== ''): ?>
        <div class="banner banner-erro" role="alert"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <p style="font-size:.85rem;color:#666">
        Escolha uma senha so sua, com pelo menos <?= $tamanho_min ?> caracteres, e nao a compartilhe.
        A administracao nunca ve a sua senha (ela fica guardada so como hash) e so consegue
        redefini-la por uma nova.
    </p>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Senha atual<br><input type="password" name="senha_atual" autocomplete="current-password" required></label>
        <label>Nova senha (minimo <?= $tamanho_min ?> caracteres)<br><input type="password" name="nova_senha" autocomplete="new-password" minlength="<?= $tamanho_min ?>" maxlength="<?= $tamanho_max ?>" required></label>
        <label>Repita a nova senha<br><input type="password" name="confirmar_senha" autocomplete="new-password" minlength="<?= $tamanho_min ?>" maxlength="<?= $tamanho_max ?>" required></label>
        <button type="submit">Alterar senha</button>
    </form>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
