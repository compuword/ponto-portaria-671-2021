<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$admin = ponto_require_admin();
$erro = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $funcionario_id = (int)($_POST['funcionario_id'] ?? 0);
    $data_inicio = (string)($_POST['data_inicio'] ?? '');
    $dias = (int)($_POST['dias_afastamento'] ?? 0);
    $cid = trim((string)($_POST['cid'] ?? '')) ?: null;
    $crm = trim((string)($_POST['crm_medico'] ?? '')) ?: null;
    $observacao = trim((string)($_POST['observacao'] ?? '')) ?: null;

    if ($funcionario_id <= 0 || $data_inicio === '' || $dias <= 0) {
        $erro = 'Preencha funcionario, data de inicio e quantidade de dias.';
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
            $stmt = $pdo->prepare(
                'INSERT INTO dbo.atestados_medicos
                    (funcionario_id, data_inicio, dias_afastamento, cid, crm_medico, observacao, arquivo_nome, arquivo_tipo, arquivo_blob, registrado_por, ip_origem, user_agent, hostname_origem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bindValue(1, $funcionario_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $data_inicio);
            $stmt->bindValue(3, $dias, PDO::PARAM_INT);
            $stmt->bindValue(4, $cid);
            $stmt->bindValue(5, $crm);
            $stmt->bindValue(6, $observacao);
            $stmt->bindValue(7, $arquivo_nome);
            $stmt->bindValue(8, $arquivo_tipo);
            $stmt->bindValue(9, $arquivo_blob, PDO::PARAM_LOB);
            $stmt->bindValue(10, $admin['id'], PDO::PARAM_INT);
            $stmt->bindValue(11, $ip_atual);
            $stmt->bindValue(12, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $stmt->bindValue(13, ponto_hostname_dhcp($ip_atual));
            $stmt->execute();

            ponto_log($admin['id'], 'lancou_atestado', 'funcionario_id ' . $funcionario_id . ', ' . $dias . ' dia(s) a partir de ' . $data_inicio);
            $ok = true;
        }
    }
}

$funcionarios = ponto_db()->query('SELECT id, login, nome FROM dbo.funcionarios WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);

$stmt = ponto_db()->query(
    "SELECT a.id, f.nome, a.data_inicio, a.dias_afastamento, a.cid, a.ip_origem,
            CONVERT(VARCHAR(19), a.criado_em, 120) AS quando,
            CASE WHEN a.arquivo_blob IS NOT NULL THEN 1 ELSE 0 END AS tem_arquivo,
            CASE WHEN a.registrado_por = a.funcionario_id THEN 'Colaborador' ELSE 'Admin' END AS enviado_por
     FROM dbo.atestados_medicos a JOIN dbo.funcionarios f ON f.id = a.funcionario_id
     ORDER BY a.id DESC"
);
$recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Atestado medico</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Atestado medico / afastamento</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>
    <p style="font-size:.85rem;color:#666">
        Dado sensivel (saude) -- visivel so pra administradores. O arquivo
        anexado fica guardado como blob no proprio banco (nunca no disco).
    </p>

    <?php if ($ok): ?>
        <p class="ok">Atestado registrado.</p>
    <?php endif; ?>
    <?php if ($erro !== ''): ?>
        <p class="erro"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Funcionario<br>
            <select name="funcionario_id" required>
                <option value="">-- selecione --</option>
                <?php foreach ($funcionarios as $f): ?>
                <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nome']) ?> (<?= htmlspecialchars($f['login']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Data de inicio<br><input type="date" name="data_inicio" required></label>
        <label>Dias de afastamento<br><input type="number" name="dias_afastamento" min="1" required></label>
        <label>CID<br><input type="text" name="cid" placeholder="Ex.: J11"></label>
        <label>CRM do medico<br><input type="text" name="crm_medico" placeholder="Ex.: CRM/SP 123456"></label>
        <label>Observacao<br><input type="text" name="observacao"></label>
        <label>Arquivo do atestado (PDF ou imagem, max 10MB)<br><input type="file" name="arquivo" accept=".pdf,.jpg,.jpeg,.png"></label>
        <button type="submit">Registrar atestado</button>
    </form>

    <h2>Ultimos atestados</h2>
    <table>
        <tr><th>Funcionario</th><th>Inicio</th><th>Dias</th><th>CID</th><th>Enviado por</th><th>Quando</th><th>IP</th><th>Arquivo</th></tr>
        <?php foreach ($recentes as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['nome']) ?></td>
            <td><?= htmlspecialchars((string)$r['data_inicio']) ?></td>
            <td><?= (int)$r['dias_afastamento'] ?></td>
            <td><?= htmlspecialchars((string)($r['cid'] ?? '')) ?></td>
            <td><?= htmlspecialchars($r['enviado_por']) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars($r['quando']) ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars((string)($r['ip_origem'] ?? '')) ?></td>
            <td><?= $r['tem_arquivo'] ? '<a href="/admin_atestado_arquivo.php?id=' . (int)$r['id'] . '">baixar</a>' : '-' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
