<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$admin = ponto_require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = ponto_db()->prepare(
    'SELECT r.id, r.tipo, CONVERT(VARCHAR(19), r.registrado_em, 120) AS quando, f.nome AS funcionario_nome, f.fuso_horario AS fuso
     FROM dbo.registros_ponto r JOIN dbo.funcionarios f ON f.id = r.funcionario_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    http_response_code(404);
    exit('Registro nao encontrado.');
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $motivo = trim((string)($_POST['motivo'] ?? ''));
    if ($motivo === '') {
        $erro = 'Motivo e obrigatorio.';
    } else {
        $stmt = ponto_db()->prepare(
            'INSERT INTO dbo.anotacoes_ponto (registro_id, motivo, criado_por) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id, $motivo, $admin['id']]);
        ponto_log($admin['id'], 'anotou_registro', 'anotou registro id ' . $id . ': ' . $motivo);
        header('Location: /admin_verificar.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Anotar registro</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Anotar registro</h1>
    <p><a href="/admin_verificar.php">Cancelar</a> | <a href="/logout.php">Sair</a></p>
    <p style="font-size:.85rem;color:#666">
        Isso NAO altera nem apaga o registro original -- so cria uma nota
        explicando que ele nao deve ser computado (ex.: foi teste). A nota
        aparece nos relatorios no lugar das horas desse dia.
    </p>

    <p><strong><?= htmlspecialchars($r['funcionario_nome']) ?></strong> -- <?= htmlspecialchars($r['tipo']) ?> em <?= htmlspecialchars(ponto_utc_para_local((string)$r['quando'], ponto_fuso_ou_padrao((string)$r['fuso']), 'd/m/Y H:i:s')) ?></p>

    <?php if ($erro !== ''): ?>
        <p class="erro"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Motivo<br><input type="text" name="motivo" placeholder="Ex.: entrada de teste, nao computar" required></label>
        <button type="submit">Salvar anotacao</button>
    </form>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
