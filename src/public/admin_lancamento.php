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
    $tipo = (string)($_POST['tipo'] ?? '');
    $data = (string)($_POST['data'] ?? '');
    $hora = (string)($_POST['hora'] ?? '');
    $observacao = trim((string)($_POST['observacao'] ?? ''));

    $tipos_validos = ['entrada', 'almoco', 'retorno', 'saida'];

    if (!in_array($tipo, $tipos_validos, true)) {
        $erro = 'Tipo invalido.';
    } elseif ($observacao === '') {
        $erro = 'Observacao e obrigatoria num lancamento manual (ex.: atendimento externo - cliente X).';
    } else {
        $stmt = ponto_db()->prepare('SELECT COUNT(*) FROM dbo.funcionarios WHERE id = ?');
        $stmt->execute([$funcionario_id]);
        $dt = DateTime::createFromFormat('Y-m-d H:i', $data . ' ' . $hora);
        if ((int)$stmt->fetchColumn() === 0) {
            $erro = 'Selecione um funcionario.';
        } elseif (!$dt) {
            $erro = 'Data/hora invalida.';
        } else {
            // A data/hora digitada e a do relogio do colaborador (no fuso dele);
            // o banco guarda tudo em UTC.
            $fuso = ponto_fuso_do_funcionario($funcionario_id);
            $horario_local = $dt->format('Y-m-d H:i:00');
            $registrado_em = ponto_local_para_utc($horario_local, $fuso);
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            ponto_gravar_registro($funcionario_id, $tipo, $ip, $ua, true, $observacao, $admin['id'], $registrado_em);
            ponto_log($admin['id'], 'lancamento_manual', 'funcionario_id ' . $funcionario_id . ', ' . $tipo . ' em ' . $horario_local . ' (' . $fuso . ') = ' . $registrado_em . ' UTC: ' . $observacao);
            $ok = true;
        }
    }
}

$funcionarios = ponto_db()->query('SELECT id, login, nome, fuso_horario FROM dbo.funcionarios WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);

$stmt = ponto_db()->prepare(
    "SELECT TOP 15 r.id, f.nome, f.fuso_horario, r.tipo, CONVERT(VARCHAR(19), r.registrado_em, 120) AS quando, r.observacao
     FROM dbo.registros_ponto r JOIN dbo.funcionarios f ON f.id = r.funcionario_id
     WHERE r.manual = 1 ORDER BY r.id DESC"
);
$stmt->execute();
$recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Lancamento manual</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Lancamento manual</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>
    <p style="font-size:.85rem;color:#666">
        Para situacoes onde nao foi possivel bater o ponto pelo sistema (ex.:
        atendimento noturno num cliente, sem acesso a rede interna). Isso
        SEMPRE cria um registro novo marcado como manual -- nunca altera
        nem apaga nada que ja existe.
    </p>

    <?php if ($ok): ?>
        <p class="ok">Lancamento registrado.</p>
    <?php endif; ?>
    <?php if ($erro !== ''): ?>
        <p class="erro"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
        <label>Funcionario<br>
            <select name="funcionario_id" required>
                <option value="">-- selecione --</option>
                <?php foreach ($funcionarios as $f): ?>
                <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nome']) ?> (<?= htmlspecialchars($f['login']) ?>) -- <?= htmlspecialchars((string)$f['fuso_horario']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Tipo<br>
            <select name="tipo" required>
                <option value="entrada">Entrada</option>
                <option value="almoco">Pausa</option>
                <option value="retorno">Retorno</option>
                <option value="saida">Saida</option>
            </select>
        </label>
        <label>Data (no fuso do colaborador)<br><input type="date" name="data" required></label>
        <label>Hora (no fuso do colaborador)<br><input type="time" name="hora" required></label>
        <label>Observacao (obrigatorio)<br>
            <input type="text" name="observacao" placeholder="Ex.: atendimento noturno - cliente C-Trends" required>
        </label>
        <button type="submit">Registrar lancamento</button>
    </form>

    <h2>Ultimos lancamentos manuais</h2>
    <table>
        <tr><th>Funcionario</th><th>Tipo</th><th>Quando</th><th>Observacao</th></tr>
        <?php foreach ($recentes as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['nome']) ?></td>
            <td><?= htmlspecialchars($r['tipo']) ?></td>
            <td><?= htmlspecialchars(ponto_utc_para_local((string)$r['quando'], ponto_fuso_ou_padrao((string)$r['fuso_horario']), 'd/m/Y H:i')) ?></td>
            <td><?= htmlspecialchars((string)$r['observacao']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
