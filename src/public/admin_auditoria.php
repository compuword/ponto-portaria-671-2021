<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$admin = ponto_require_admin();

$stmt = ponto_db()->query(
    'SELECT TOP 200 l.id, f.nome AS funcionario_nome, l.login_tentado, l.acao, l.detalhe,
            l.ip_origem, l.hostname_origem, CONVERT(VARCHAR(19), l.criado_em, 120) AS quando
     FROM dbo.log_acesso l
     LEFT JOIN dbo.funcionarios f ON f.id = l.funcionario_id
     ORDER BY l.id DESC'
);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Auditoria</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa" style="max-width:900px">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Auditoria -- acessos e atividades</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>
    <p style="font-size:.85rem;color:#666">Ultimos 200 eventos (login, logout, inatividade, criacao/edicao de funcionario, reset de senha, lancamento manual, anotacao).</p>

    <table>
        <tr><th>Quando</th><th>Quem</th><th>Acao</th><th>Detalhe</th><th>IP</th><th>Estacao</th></tr>
        <?php foreach ($linhas as $l): ?>
        <tr>
            <td style="font-size:.8rem"><?= htmlspecialchars($l['quando']) ?></td>
            <td><?= htmlspecialchars((string)($l['funcionario_nome'] ?? $l['login_tentado'] ?? '?')) ?></td>
            <td><?= htmlspecialchars($l['acao']) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars((string)($l['detalhe'] ?? '')) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars($l['ip_origem']) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars((string)($l['hostname_origem'] ?? '-')) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
