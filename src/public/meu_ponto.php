<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';
require __DIR__ . '/../espelho_saida.php';

$func = ponto_require_login();
$fuso = ponto_fuso_do_funcionario($func['id']);
$agora_local = ponto_agora_local($fuso);

$ano = (int)($_GET['ano'] ?? $agora_local->format('Y'));
$mes = (int)($_GET['mes'] ?? $agora_local->format('n'));
if ($mes < 1 || $mes > 12) {
    $mes = (int)$agora_local->format('n');
}

$tem_espelho = ponto_tem_espelho();
$erro = '';

// Aceite eletronico do espelho do mes pelo proprio colaborador (so INSERT; o hash prova o que foi aceito)
if ($tem_espelho && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $ano = (int)($_POST['ano'] ?? $ano);
    $mes = (int)($_POST['mes'] ?? $mes);
    $e = ponto_espelho($func['id'], $ano, $mes);
    $apurados = $e === null ? 0 : count(array_filter($e['linhas'], static fn(array $l): bool => $l['aplicavel']));
    if ($e === null || $e['periodo']['de'] > $e['hoje']) {
        $erro = 'Nao e possivel aceitar o espelho de um mes que ainda nao comecou.';
    } elseif ($apurados === 0) {
        $erro = 'Este mes ainda nao tem dias apurados para aceitar.';
    } else {
        $protocolo = ponto_registrar_aceite($e, (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        ponto_log($func['id'], 'aceitou_espelho', sprintf('%04d-%02d protocolo %s', $ano, $mes, $protocolo));
        header('Location: /meu_ponto.php?ano=' . $ano . '&mes=' . $mes . '&aceito=1');
        exit;
    }
}

$e = $tem_espelho ? ponto_espelho($func['id'], $ano, $mes) : null;
if ($e !== null) {
    $ano = $e['periodo']['ano'];
    $mes = $e['periodo']['mes'];
}

$meses_nome = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
               7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$mes_anterior = $mes === 1 ? 12 : $mes - 1;
$ano_anterior = $mes === 1 ? $ano - 1 : $ano;
$mes_seguinte = $mes === 12 ? 1 : $mes + 1;
$ano_seguinte = $mes === 12 ? $ano + 1 : $ano;
$pode_aceitar = $e !== null && $e['periodo']['de'] <= $e['hoje']
    && count(array_filter($e['linhas'], static fn(array $l): bool => $l['aplicavel'])) > 0
    && ($e['aceite'] === null || $e['aceite']['alterado_depois']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Meu espelho de ponto</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa caixa-larga">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Meu espelho de ponto -- <?= htmlspecialchars($func['nome']) ?></h1>
    <p><a href="/bater.php">Voltar</a> | <a href="/minha_senha.php">Alterar minha senha</a> | <a href="/logout.php">Sair</a></p>

    <p>
        <a href="?ano=<?= $ano_anterior ?>&mes=<?= $mes_anterior ?>">&laquo; anterior</a>
        &nbsp;<strong><?= htmlspecialchars($meses_nome[$mes]) ?> de <?= $ano ?></strong>&nbsp;
        <a href="?ano=<?= $ano_seguinte ?>&mes=<?= $mes_seguinte ?>">seguinte &raquo;</a>
    </p>

    <?php if (($_GET['aceito'] ?? '') === '1'): ?>
        <div class="banner banner-ok" role="status"><strong>Espelho aceito.</strong> O comprovante com data, hora e protocolo aparece abaixo e no PDF.</div>
    <?php endif; ?>
    <?php if ($erro !== ''): ?>
        <div class="banner banner-erro" role="alert"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <?php if (!$tem_espelho || $e === null): ?>
        <div class="banner banner-aviso" role="status">O espelho de ponto ainda nao esta disponivel neste servidor (falta aplicar a migracao 003 do banco).</div>
    <?php else: ?>
        <div class="esp-barra">
            <a class="botao" href="/meu_espelho.php?ano=<?= $ano ?>&mes=<?= $mes ?>" target="_blank">Baixar PDF</a>
            <?php if ($pode_aceitar): ?>
            <form method="post" onsubmit="return confirm('Confirmar o aceite do espelho de <?= sprintf('%02d/%04d', $mes, $ano) ?>? Depois de aceito, qualquer alteracao no mes exigira um novo aceite.')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
                <input type="hidden" name="ano" value="<?= $ano ?>">
                <input type="hidden" name="mes" value="<?= $mes ?>">
                <button type="submit"><?= $e['aceite'] === null ? 'Li e aceito meu espelho de ' : 'Aceitar de novo o espelho de ' ?><?= sprintf('%02d/%04d', $mes, $ano) ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?= ponto_espelho_html($e) ?>
    <?php endif; ?>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
