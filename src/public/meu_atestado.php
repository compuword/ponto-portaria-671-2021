<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$func = ponto_require_login();
$fuso = ponto_fuso_do_funcionario($func['id']);
$tem_parcial = ponto_tem_atestado_parcial();
$erro = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $parcial = $tem_parcial && (string)($_POST['tipo_atestado'] ?? 'dias') === 'parcial';
    $data_inicio = (string)($_POST['data_inicio'] ?? '');
    $dias = $parcial ? 1 : (int)($_POST['dias_afastamento'] ?? 0);
    $cid = trim((string)($_POST['cid'] ?? '')) ?: null;
    $crm = trim((string)($_POST['crm_medico'] ?? '')) ?: null;
    $observacao = trim((string)($_POST['observacao'] ?? '')) ?: null;

    $hora_inicio = null;
    $hora_fim = null;
    $erro_horas = null;
    if ($parcial) {
        [$hora_inicio, $hora_fim, $erro_horas] = ponto_validar_horas_atestado(
            (string)($_POST['hora_inicio'] ?? ''),
            (string)($_POST['hora_fim'] ?? '')
        );
    }

    if ($data_inicio === '' || $dias <= 0) {
        $erro = 'Preencha a data e, se for dia inteiro, a quantidade de dias.';
    } elseif ($erro_horas !== null) {
        $erro = $erro_horas;
    } else {
        $arquivo_nome = null;
        $arquivo_tipo = null;
        $arquivo_blob = null;

        if (!empty($_FILES['arquivo']['tmp_name']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            $arquivo_nome = basename((string)$_FILES['arquivo']['name']);
            $arquivo_tipo = (string)$_FILES['arquivo']['type'];
            $arquivo_blob = file_get_contents($_FILES['arquivo']['tmp_name']);
        } elseif (!empty($_FILES['arquivo']['error']) && $_FILES['arquivo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $erro = 'Falha no upload do arquivo (codigo ' . $_FILES['arquivo']['error'] . '). Tamanho maximo: 10MB.';
        }

        if ($erro === '') {
            $pdo = ponto_db();
            $ip_atual = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $colunas = 'funcionario_id, data_inicio, dias_afastamento, cid, crm_medico, observacao, arquivo_nome, arquivo_tipo, arquivo_blob, registrado_por, ip_origem, user_agent, hostname_origem';
            $marcas = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
            if ($tem_parcial) {
                $colunas .= ', hora_inicio, hora_fim';
                $marcas .= ', ?, ?';
            }
            $stmt = $pdo->prepare('INSERT INTO dbo.atestados_medicos (' . $colunas . ') VALUES (' . $marcas . ')');
            $stmt->bindValue(1, $func['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $data_inicio);
            $stmt->bindValue(3, $dias, PDO::PARAM_INT);
            $stmt->bindValue(4, $cid);
            $stmt->bindValue(5, $crm);
            $stmt->bindValue(6, $observacao);
            $stmt->bindValue(7, $arquivo_nome);
            $stmt->bindValue(8, $arquivo_tipo);
            $stmt->bindValue(9, $arquivo_blob, PDO::PARAM_LOB);
            $stmt->bindValue(10, $func['id'], PDO::PARAM_INT); // registrado_por = o proprio -- e assim que o admin sabe que foi autoenvio
            $stmt->bindValue(11, $ip_atual);
            $stmt->bindValue(12, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $stmt->bindValue(13, ponto_hostname_dhcp($ip_atual));
            if ($tem_parcial) {
                $stmt->bindValue(14, $hora_inicio);
                $stmt->bindValue(15, $hora_fim);
            }
            $stmt->execute();

            $detalhe = $parcial
                ? 'parcial em ' . $data_inicio . ' das ' . substr((string)$hora_inicio, 0, 5) . ' as ' . substr((string)$hora_fim, 0, 5)
                : $dias . ' dia(s) a partir de ' . $data_inicio;
            ponto_log($func['id'], 'atestado_autoenvio', $detalhe);
            $ok = true;
        }
    }
}

$colunas_parcial = $tem_parcial
    ? ', CONVERT(VARCHAR(5), hora_inicio, 108) AS hi, CONVERT(VARCHAR(5), hora_fim, 108) AS hf'
    : ', NULL AS hi, NULL AS hf';
$stmt = ponto_db()->prepare(
    'SELECT data_inicio, dias_afastamento, cid, CONVERT(VARCHAR(19), criado_em, 120) AS quando' . $colunas_parcial . '
     FROM dbo.atestados_medicos WHERE funcionario_id = ? ORDER BY id DESC'
);
$stmt->execute([$func['id']]);
$meus = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Meu atestado</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Enviar atestado medico</h1>
    <p><a href="/bater.php">Voltar</a> | <a href="/logout.php">Sair</a></p>
    <p style="font-size:.85rem;color:#666">
        O arquivo fica guardado no banco, visivel so para a administracao.
        Preencha com os dados exatos do seu atestado.
    </p>

    <?php if ($ok): ?>
        <div class="banner banner-ok" role="status">Atestado enviado.</div>
    <?php endif; ?>
    <?php if ($erro !== ''): ?>
        <div class="banner banner-erro" role="alert"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <?php if ($tem_parcial): ?>
        <label>Tipo de afastamento<br>
            <select name="tipo_atestado" id="tipo_atestado">
                <option value="dias">Dia(s) inteiro(s)</option>
                <option value="parcial" <?= (string)($_POST['tipo_atestado'] ?? '') === 'parcial' ? 'selected' : '' ?>>Parte do dia (consulta ou exame; volto a trabalhar no mesmo dia)</option>
            </select>
        </label>
        <?php endif; ?>
        <label>Data (de inicio, ou do dia da consulta/exame)<br><input type="date" name="data_inicio" required></label>
        <div id="bloco-dias">
            <label>Dias de afastamento<br><input type="number" name="dias_afastamento" min="1" value="1"></label>
        </div>
        <?php if ($tem_parcial): ?>
        <div id="bloco-parcial" style="display:none">
            <label>Hora em que voce saiu do trabalho<br><input type="time" name="hora_inicio"></label>
            <label>Hora em que voce voltou<br><input type="time" name="hora_fim"></label>
            <p style="font-size:.8rem;color:#666">
                Horarios do seu relogio (<?= htmlspecialchars(ponto_rotulo_fuso($fuso)) ?>). Continue batendo o ponto normalmente
                (entrada, pausa, retorno e saida): o tempo fora entra no espelho como atestado parcial e e descontado das horas trabalhadas.
            </p>
        </div>
        <?php endif; ?>
        <label>CID (se souber)<br><input type="text" name="cid" placeholder="Ex.: J11"></label>
        <label>CRM do medico<br><input type="text" name="crm_medico" placeholder="Ex.: CRM/SP 123456"></label>
        <label>Observacao<br><input type="text" name="observacao"></label>
        <label>Arquivo do atestado (PDF ou imagem, max 10MB)<br><input type="file" name="arquivo" accept=".pdf,.jpg,.jpeg,.png" required></label>
        <button type="submit">Enviar atestado</button>
    </form>

    <h2>Meus atestados enviados</h2>
    <table>
        <tr><th>Data</th><th>Periodo</th><th>CID</th><th>Enviado em</th></tr>
        <?php foreach ($meus as $m): ?>
        <tr>
            <td><?= htmlspecialchars((string)$m['data_inicio']) ?></td>
            <td><?= $m['hi'] !== null ? 'Parcial ' . htmlspecialchars((string)$m['hi']) . '-' . htmlspecialchars((string)$m['hf']) : (int)$m['dias_afastamento'] . ' dia(s)' ?></td>
            <td><?= htmlspecialchars((string)($m['cid'] ?? '')) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars(ponto_utc_para_local((string)$m['quando'], $fuso, 'd/m/Y H:i')) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<script src="/js/atestado_tipo.js"></script>
<script src="/js/inatividade.js"></script>
</body>
</html>
