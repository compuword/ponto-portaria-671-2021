<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$admin = ponto_require_admin();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $login = trim((string)($_POST['login'] ?? ''));
    $nome = trim((string)($_POST['nome'] ?? ''));
    $senha_inicial = (string)($_POST['senha_inicial'] ?? '');
    $is_admin_novo = isset($_POST['is_admin']) ? 1 : 0;
    $cargo = trim((string)($_POST['cargo'] ?? '')) ?: null;
    $data_admissao = trim((string)($_POST['data_admissao'] ?? '')) ?: null;

    if ($login === '' || $nome === '' || $senha_inicial === '') {
        $erro = 'Preencha login, nome e senha inicial.';
    } else {
        $stmt = ponto_db()->prepare('SELECT id FROM dbo.funcionarios WHERE login = ?');
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            $erro = 'Login ja existe.';
        } else {
            $hash = password_hash($senha_inicial, PASSWORD_BCRYPT);
            $stmt = ponto_db()->prepare(
                'INSERT INTO dbo.funcionarios (login, nome, senha_hash, ativo, is_admin, cargo, data_admissao) VALUES (?, ?, ?, 1, ?, ?, ?)'
            );
            $stmt->execute([$login, $nome, $hash, $is_admin_novo, $cargo, $data_admissao]);
            ponto_log($admin['id'], 'criou_funcionario', 'criou login ' . $login);
            header('Location: /admin.php?ok=1');
            exit;
        }
    }
}

$funcionarios = ponto_db()
    ->query('SELECT id, login, nome, ativo, is_admin, cargo, data_admissao, data_desligamento FROM dbo.funcionarios ORDER BY nome')
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Administracao</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Administracao de funcionarios</h1>
    <p><a href="/bater.php">Voltar para o ponto</a> | <a href="/admin_lancamento.php">Lancamento manual</a> | <a href="/admin_atestado.php">Atestado medico</a> | <a href="/admin_verificar.php">Verificar integridade</a> | <a href="/admin_relatorio.php">Exportar p/ contabilidade</a> | <a href="/admin_auditoria.php">Auditoria</a> | <a href="/logout.php">Sair</a></p>

    <?php if (($_GET['ok'] ?? '') === '1'): ?>
        <p class="ok">Funcionario criado com sucesso.</p>
    <?php endif; ?>
    <?php if ($erro !== ''): ?>
        <p class="erro"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>

    <table>
        <tr><th>Login</th><th>Nome</th><th>Cargo</th><th>Admissao</th><th>Ativo</th><th>Admin</th><th></th></tr>
        <?php foreach ($funcionarios as $f): ?>
        <tr>
            <td><?= htmlspecialchars($f['login']) ?></td>
            <td><?= htmlspecialchars($f['nome']) ?></td>
            <td><?= htmlspecialchars((string)($f['cargo'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($f['data_admissao'] ?? '')) ?></td>
            <td><?= $f['ativo'] ? 'Sim' : 'Nao' ?></td>
            <td><?= $f['is_admin'] ? 'Sim' : 'Nao' ?></td>
            <td><a href="/admin_editar.php?id=<?= (int)$f['id'] ?>">editar</a></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>Novo funcionario</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Login<br><input type="text" name="login" required></label>
        <label>Nome<br><input type="text" name="nome" required></label>
        <label>Cargo<br><input type="text" name="cargo"></label>
        <label>Data de admissao<br><input type="date" name="data_admissao"></label>
        <label>Senha inicial<br><input type="text" name="senha_inicial" required></label>
        <label><input type="checkbox" name="is_admin" style="width:auto"> E administrador</label>
        <button type="submit">Criar funcionario</button>
    </form>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
