<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$func = ponto_require_login();
$msg = '';
$msg_erro = '';

$stmt = ponto_db()->prepare('SELECT is_admin FROM dbo.funcionarios WHERE id = ?');
$stmt->execute([$func['id']]);
$eh_admin = (bool)$stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $tipo = (string)($_POST['tipo'] ?? '');
    $tipos_validos = ['entrada', 'almoco', 'retorno', 'saida'];

    if (in_array($tipo, $tipos_validos, true)) {
        $proximo_tipo_post = ponto_proximo_tipo_do_dia($func['id']);

        if ($tipo !== $proximo_tipo_post) {
            $msg_erro = $proximo_tipo_post === null
                ? 'Voce ja completou o ciclo do dia (entrada, pausa, retorno e saida).'
                : 'Fora de ordem -- o proximo registro esperado e "' . $proximo_tipo_post . '".';
        } else {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            $obs = trim((string)($_POST['observacao'] ?? '')) ?: null;
            ponto_gravar_registro($func['id'], $tipo, $ip, $ua, false, $obs);
            $msg = 'Ponto registrado: ' . $tipo;
        }
    }
}

$proximo_tipo = ponto_proximo_tipo_do_dia($func['id']);

$fuso = ponto_fuso_do_funcionario($func['id']);
$agora_local = ponto_agora_local($fuso);

$stmt = ponto_db()->prepare(
    'SELECT TOP 20 tipo, CONVERT(VARCHAR(19), registrado_em, 120) AS quando, observacao FROM dbo.registros_ponto WHERE funcionario_id = ? ORDER BY registrado_em DESC'
);
$stmt->execute([$func['id']]);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <h1>Ola, <?= htmlspecialchars($func['nome']) ?></h1>
    <p><a href="/logout.php">Sair</a> | <a href="/minha_senha.php">Alterar minha senha</a><?= $eh_admin ? ' | <a href="/admin.php">Administracao</a>' : '' ?></p>
    <?php if ($msg !== ''): ?>
        <p class="ok"><?= htmlspecialchars($msg) ?></p>
    <?php endif; ?>
    <?php if ($msg_erro !== ''): ?>
        <p class="erro"><?= htmlspecialchars($msg_erro) ?></p>
    <?php endif; ?>

    <?php if ($proximo_tipo === null): ?>
        <p class="ok">Ciclo do dia completo (entrada, pausa, retorno e saida).</p>
    <?php endif; ?>

    <p style="font-size:.85rem;color:#666">Agora: <strong><?= htmlspecialchars($agora_local->format('d/m/Y H:i')) ?></strong> -- horario de <?= htmlspecialchars(ponto_rotulo_fuso($fuso)) ?></p>

    <form method="post" onsubmit="return confirm('Confirmar registro de ' + document.activeElement.value + '?')">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <div class="botoes">
            <button type="submit" name="tipo" value="entrada" <?= $proximo_tipo !== 'entrada' ? 'disabled' : '' ?>>Entrada</button>
            <button type="submit" name="tipo" value="almoco" <?= $proximo_tipo !== 'almoco' ? 'disabled' : '' ?>>Pausa</button>
            <button type="submit" name="tipo" value="retorno" <?= $proximo_tipo !== 'retorno' ? 'disabled' : '' ?>>Retorno</button>
            <button type="submit" name="tipo" value="saida" <?= $proximo_tipo !== 'saida' ? 'disabled' : '' ?>>Saida</button>
        </div>
        <label>Observacao (opcional)<br><input type="text" name="observacao" placeholder="so se precisar explicar algo"></label>
    </form>

    <h2>Meus ultimos registros</h2>
    <p><a href="/meu_ponto.php">Ver meu ponto completo (por mes)</a> | <a href="/meu_atestado.php">Atestado medico / consulta no mesmo dia</a></p>
    <table>
        <tr><th>Tipo</th><th>Quando</th><th>Obs.</th></tr>
        <?php foreach ($registros as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['tipo']) ?></td>
            <td><?= htmlspecialchars(ponto_utc_para_local((string)$r['quando'], $fuso, 'd/m/Y H:i:s')) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars((string)($r['observacao'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
