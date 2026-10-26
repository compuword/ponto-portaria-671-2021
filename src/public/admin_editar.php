<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';

$admin = ponto_require_admin();
$tem_ident = ponto_tem_coluna('funcionarios', 'cpf');

$id = (int)($_GET['id'] ?? 0);
$stmt = ponto_db()->prepare(
    'SELECT id, login, nome, ativo, is_admin, cargo, data_admissao, data_desligamento, fuso_horario'
    . ($tem_ident ? ', cpf, pis, equipe' : '') . ' FROM dbo.funcionarios WHERE id = ?'
);
$stmt->execute([$id]);
$f = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = null;

if (!$f) {
    http_response_code(404);
    exit('Funcionario nao encontrado.');
}

$erro = '';
$atual = ['cpf' => (string)($f['cpf'] ?? ''), 'pis' => (string)($f['pis'] ?? ''), 'equipe' => (string)($f['equipe'] ?? '')];

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
    $atual = [
        'cpf' => ponto_so_digitos((string)($_POST['cpf'] ?? '')),
        'pis' => ponto_so_digitos((string)($_POST['pis'] ?? '')),
        'equipe' => trim((string)($_POST['equipe'] ?? '')),
    ];

    if (!ponto_fuso_valido($fuso_novo)) {
        $erro = 'Fuso horario invalido.';
    } elseif ($tem_ident && $atual['cpf'] !== '' && !ponto_cpf_valido($atual['cpf'])) {
        $erro = 'CPF invalido (confira os digitos).';
    } elseif ($tem_ident && $atual['pis'] !== '' && !ponto_pis_valido($atual['pis'])) {
        $erro = 'PIS invalido (confira os digitos).';
    } elseif ($tem_ident && mb_strlen($atual['equipe']) > 100) {
        $erro = 'A equipe aceita ate 100 caracteres.';
    } else {
        $sets = ['nome = ?', 'ativo = ?', 'is_admin = ?', 'cargo = ?', 'data_admissao = ?', 'data_desligamento = ?', 'fuso_horario = ?'];
        $params = [$nome, $ativo, $is_admin_novo, $cargo, $data_admissao, $data_desligamento, $fuso_novo];
        if ($tem_ident) {
            array_push($sets, 'cpf = ?', 'pis = ?', 'equipe = ?');
            array_push($params, $atual['cpf'] !== '' ? $atual['cpf'] : null, $atual['pis'] !== '' ? $atual['pis'] : null, $atual['equipe'] !== '' ? $atual['equipe'] : null);
        }
        $params[] = $id;
        $stmt = ponto_db()->prepare('UPDATE dbo.funcionarios SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);

        $detalhe = 'editou id ' . $id . ' (' . $f['login'] . ')';
        if ($fuso_novo !== (string)$f['fuso_horario']) {
            $detalhe .= '; fuso ' . $f['fuso_horario'] . ' -> ' . $fuso_novo;
        }
        if ($tem_ident && ($atual['cpf'] !== (string)($f['cpf'] ?? '') || $atual['pis'] !== (string)($f['pis'] ?? ''))) {
            $detalhe .= '; documentos atualizados'; // CPF/PIS nunca vao para o log
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

$turno_atual = '';
$atribuicoes = ponto_turnos_do_funcionario($id);
if ($atribuicoes !== []) {
    $a = end($atribuicoes);
    $turno_atual = $a['nome'] . ' (desde ' . (new DateTimeImmutable($a['vigencia']))->format('d/m/Y') . ')';
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
        <?php if ($tem_ident): ?>
        <label>CPF<br><input type="text" name="cpf" maxlength="14" placeholder="000.000.000-00" value="<?= htmlspecialchars(ponto_fmt_cpf($atual['cpf'])) ?>"></label>
        <label>PIS/PASEP<br><input type="text" name="pis" maxlength="14" placeholder="000.00000.00-0" value="<?= htmlspecialchars(ponto_fmt_pis($atual['pis'])) ?>"></label>
        <label>Equipe<br><input type="text" name="equipe" maxlength="100" value="<?= htmlspecialchars($atual['equipe']) ?>"></label>
        <p style="font-size:.8rem;color:#666">CPF, PIS e equipe saem no cabecalho do espelho de ponto. Sao dados pessoais: so o proprio colaborador e a administracao os veem.</p>
        <?php endif; ?>
        <label>Data de admissao<br><input type="date" name="data_admissao" value="<?= htmlspecialchars((string)($f['data_admissao'] ?? '')) ?>"></label>
        <label>Data de desligamento (deixe vazio se ainda trabalha aqui)<br><input type="date" name="data_desligamento" value="<?= htmlspecialchars((string)($f['data_desligamento'] ?? '')) ?>"></label>
        <label>Fuso horario<br>
            <select name="fuso_horario"><?= ponto_opcoes_fuso_html(ponto_fuso_ou_padrao((string)$f['fuso_horario'])) ?></select>
        </label>
        <p style="font-size:.8rem;color:#666">
            Define em que horario os pontos deste colaborador aparecem e como os dias
            fecham. Muda so a exibicao: os registros guardados (em UTC) nao sao alterados.
        </p>
        <p style="font-size:.85rem">Turno em vigor: <strong><?= htmlspecialchars($turno_atual !== '' ? $turno_atual : 'nenhum') ?></strong>
            (<a href="/admin_turnos.php">gerenciar turnos</a>)</p>
        <label><input type="checkbox" name="ativo" style="width:auto" <?= $f['ativo'] ? 'checked' : '' ?>> Ativo</label>
        <label><input type="checkbox" name="is_admin" style="width:auto" <?= $f['is_admin'] ? 'checked' : '' ?>> E administrador</label>
        <label>Nova senha (deixe vazio para nao alterar)<br><input type="text" name="nova_senha"></label>
        <button type="submit">Salvar</button>
    </form>
    <p><a href="/admin.php">Cancelar</a> | <a href="/admin_espelho.php?funcionario_id=<?= (int)$f['id'] ?>">Ver espelho</a> | <a href="/logout.php">Sair</a></p>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
