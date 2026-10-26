<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$admin = ponto_require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = ponto_db()->prepare('SELECT id, login, nome, ativo, is_admin, cargo, data_admissao, data_desligamento, fuso_horario FROM dbo.funcionarios WHERE id = ?');
$stmt->execute([$id]);
$f = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$f) {
    http_response_code(404);
    exit('Funcionario nao encontrado.');
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $nome = trim((string)($_POST['nome'] ?? ''));
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $is_admin_novo = isset($_POST['is_admin']) ? 1 : 0;
    $nova_senha = (string)($_POST['nova_senha'] ?? '');
    $cargo = trim((string)($_POST['cargo'] ?? '')) ?: null;
    $data_admissao = trim((string)($_POST['data_admissao'] ?? '')) ?: null;
    $data_desligamento = trim((string)($_POST['data_desligamento'] ?? '')) ?: null;
    $fuso_novo = (string)($_POST['fuso_horario'] ?? '');

    if (!ponto_fuso_valido($fuso_novo)) {
        $erro = 'Fuso horario invalido.';
    } else {
        $stmt = ponto_db()->prepare(
            'UPDATE dbo.funcionarios SET nome = ?, ativo = ?, is_admin = ?, cargo = ?, data_admissao = ?, data_desligamento = ?, fuso_horario = ? WHERE id = ?'
        );
        $stmt->execute([$nome, $ativo, $is_admin_novo, $cargo, $data_admissao, $data_desligamento, $fuso_novo, $id]);

        $detalhe = 'editou id ' . $id . ' (' . $f['login'] . ')';
        if ($fuso_novo !== (string)$f['fuso_horario']) {
            $detalhe .= '; fuso ' . $f['fuso_horario'] . ' -> ' . $fuso_novo;
        }
        ponto_log($admin['id'], 'editou_funcionario', $detalhe);

        if ($nova_senha !== '') {
            $hash = password_hash($nova_senha, PASSWORD_BCRYPT);
            $stmt = ponto_db()->prepare('UPDATE dbo.funcionarios SET senha_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $id]);
            ponto_log($admin['id'], 'resetou_senha', 'resetou senha de id ' . $id . ' (' . $f['login'] . ')');
        }

        header('Location: /admin.php?ok=1');
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Editar funcionario</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Editar: <?= htmlspecialchars($f['login']) ?></h1>
    <?php if ($erro !== ''): ?>
        <div class="banner banner-erro" role="alert"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Nome<br><input type="text" name="nome" value="<?= htmlspecialchars($f['nome']) ?>" required></label>
        <label>Cargo<br><input type="text" name="cargo" value="<?= htmlspecialchars((string)($f['cargo'] ?? '')) ?>"></label>
        <label>Data de admissao<br><input type="date" name="data_admissao" value="<?= htmlspecialchars((string)($f['data_admissao'] ?? '')) ?>"></label>
        <label>Data de desligamento (deixe vazio se ainda trabalha aqui)<br><input type="date" name="data_desligamento" value="<?= htmlspecialchars((string)($f['data_desligamento'] ?? '')) ?>"></label>
        <label>Fuso horario<br>
            <select name="fuso_horario"><?= ponto_opcoes_fuso_html(ponto_fuso_ou_padrao((string)$f['fuso_horario'])) ?></select>
        </label>
        <p style="font-size:.8rem;color:#666">
            Define em que horario os pontos deste colaborador aparecem e como os dias
            fecham. Muda so a exibicao: os registros guardados (em UTC) nao sao alterados.
        </p>
        <label><input type="checkbox" name="ativo" style="width:auto" <?= $f['ativo'] ? 'checked' : '' ?>> Ativo</label>
        <label><input type="checkbox" name="is_admin" style="width:auto" <?= $f['is_admin'] ? 'checked' : '' ?>> E administrador</label>
        <label>Nova senha (deixe vazio para nao alterar)<br><input type="text" name="nova_senha"></label>
        <button type="submit">Salvar</button>
    </form>
    <p><a href="/admin.php">Cancelar</a> | <a href="/logout.php">Sair</a></p>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
