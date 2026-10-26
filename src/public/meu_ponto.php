<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$func = ponto_require_login();

$ano = (int)($_GET['ano'] ?? date('Y'));
$mes = (int)($_GET['mes'] ?? date('n'));
if ($mes < 1 || $mes > 12) {
    $mes = (int)date('n');
}

$linhas = ponto_relatorio_mensal($func['id'], $ano, $mes);

$meses_nome = [1=>'Janeiro',2=>'Fevereiro',3=>'Marco',4=>'Abril',5=>'Maio',6=>'Junho',
               7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
$mes_anterior = $mes === 1 ? 12 : $mes - 1;
$ano_anterior = $mes === 1 ? $ano - 1 : $ano;
$mes_seguinte = $mes === 12 ? 1 : $mes + 1;
$ano_seguinte = $mes === 12 ? $ano + 1 : $ano;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Meu ponto</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa" style="max-width:720px">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Meu ponto -- <?= htmlspecialchars($func['nome']) ?></h1>
    <p><a href="/bater.php">Voltar</a> | <a href="/logout.php">Sair</a></p>

    <p>
        <a href="?ano=<?= $ano_anterior ?>&mes=<?= $mes_anterior ?>">&laquo; anterior</a>
        &nbsp;<strong><?= htmlspecialchars($meses_nome[$mes]) ?> de <?= $ano ?></strong>&nbsp;
        <a href="?ano=<?= $ano_seguinte ?>&mes=<?= $mes_seguinte ?>">seguinte &raquo;</a>
    </p>

    <table>
        <tr><th>Dia</th><th>Entrada</th><th>Pausa</th><th>Retorno</th><th>Saida</th><th>Horas</th><th>Obs.</th></tr>
        <?php foreach ($linhas as $l): ?>
        <tr<?= $l['entrada'] === '' && $l['saida'] === '' ? ' style="color:#aaa"' : '' ?>>
            <td><?= substr($l['data'], 8, 2) ?>/<?= substr($l['data'], 5, 2) ?> <?= htmlspecialchars(substr($l['dia_semana'], 0, 3)) ?></td>
            <td><?= htmlspecialchars($l['entrada']) ?></td>
            <td><?= htmlspecialchars($l['almoco']) ?></td>
            <td><?= htmlspecialchars($l['retorno']) ?></td>
            <td><?= htmlspecialchars($l['saida']) ?></td>
            <td><?= htmlspecialchars($l['horas']) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars($l['observacao']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p style="font-size:.8rem;color:#666">Linhas em cinza = dia sem nenhum registro.</p>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
