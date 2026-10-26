<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';
require __DIR__ . '/../espelho_saida.php';

// Visualizacao na tela do espelho de qualquer colaborador (so admin).
$admin = ponto_require_admin();
$agora_admin = ponto_agora_local(ponto_fuso_do_funcionario($admin['id']));
$id = (int)($_GET['funcionario_id'] ?? 0);
$ano = (int)($_GET['ano'] ?? $agora_admin->format('Y'));
$mes = (int)($_GET['mes'] ?? $agora_admin->format('n'));
if ($mes < 1 || $mes > 12) {
    $mes = (int)$agora_admin->format('n');
}

$funcionarios = ponto_db()->query('SELECT id, login, nome FROM dbo.funcionarios ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
$e = (ponto_tem_espelho() && $id > 0) ? ponto_espelho($id, $ano, $mes) : null;
if ($e !== null) {
    $ano = $e['periodo']['ano'];
    $mes = $e['periodo']['mes'];
}
$mes_anterior = $mes === 1 ? 12 : $mes - 1;
$ano_anterior = $mes === 1 ? $ano - 1 : $ano;
$mes_seguinte = $mes === 12 ? 1 : $mes + 1;
$ano_seguinte = $mes === 12 ? $ano + 1 : $ano;
$base = '/admin_espelho.php?funcionario_id=' . $id;
$exp = '/admin_relatorio.php?baixar=1&escopo=um&funcionario_id=' . $id . '&ano=' . $ano . '&mes=' . $mes;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Espelho do colaborador</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa caixa-larga">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Espelho do colaborador</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/admin_relatorio.php">Exportar</a> | <a href="/logout.php">Sair</a></p>

    <form method="get" class="esp-barra">
        <select name="funcionario_id" style="width:auto">
            <option value="">-- colaborador --</option>
            <?php foreach ($funcionarios as $f): ?>
            <option value="<?= (int)$f['id'] ?>" <?= (int)$f['id'] === $id ? 'selected' : '' ?>><?= htmlspecialchars($f['nome']) ?> (<?= htmlspecialchars($f['login']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="mes" value="<?= $mes ?>" min="1" max="12" style="width:5rem">
        <input type="number" name="ano" value="<?= $ano ?>" min="2020" max="2100" style="width:6rem">
        <button type="submit">Ver</button>
    </form>

    <?php if (!ponto_tem_espelho()): ?>
        <div class="banner banner-aviso" role="status">Falta aplicar a migracao 003 do banco (veja o README).</div>
    <?php elseif ($e === null): ?>
        <p>Escolha um colaborador.</p>
    <?php else: ?>
        <p>
            <a href="<?= htmlspecialchars($base) ?>&ano=<?= $ano_anterior ?>&mes=<?= $mes_anterior ?>">&laquo; anterior</a>
            &nbsp;<strong><?= sprintf('%02d/%04d', $mes, $ano) ?></strong>&nbsp;
            <a href="<?= htmlspecialchars($base) ?>&ano=<?= $ano_seguinte ?>&mes=<?= $mes_seguinte ?>">seguinte &raquo;</a>
            &nbsp;|&nbsp; Baixar:
            <a href="<?= htmlspecialchars($exp) ?>&formato=pdf">PDF</a> ·
            <a href="<?= htmlspecialchars($exp) ?>&formato=xlsx">Excel</a> ·
            <a href="<?= htmlspecialchars($exp) ?>&formato=csv">CSV</a>
        </p>
        <?= ponto_espelho_html($e) ?>
    <?php endif; ?>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
