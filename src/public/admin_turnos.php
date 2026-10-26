<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';

$admin = ponto_require_admin();
$disponivel = ponto_tem_tabela('turnos') && ponto_tem_tabela('funcionario_turno');
$erro = '';
$ok = '';
$hoje_padrao = ponto_agora_local(PONTO_FUSO_PADRAO)->format('Y-m-d');
$form = ['nome' => '', 'h' => []];

if ($disponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'criar') {
        $nome = trim((string)($_POST['nome'] ?? ''));
        $horas = is_array($_POST['h'] ?? null) ? $_POST['h'] : [];
        $form = ['nome' => $nome, 'h' => $horas];
        [$turno, $erro_turno] = ponto_turno_validar($horas);
        if ($nome === '' || mb_strlen($nome) > 100) {
            $erro = 'De um nome ao turno (ate 100 caracteres).';
        } elseif ($erro_turno !== null) {
            $erro = $erro_turno;
        } else {
            try {
                $stmt = ponto_db()->prepare('INSERT INTO dbo.turnos (nome, horarios, criado_por) VALUES (?, ?, ?)');
                $stmt->execute([$nome, json_encode($turno, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), $admin['id']]);
                ponto_log($admin['id'], 'criou_turno', $nome . ': ' . ponto_turno_resumo($turno));
                $ok = 'Turno "' . $nome . '" criado. Agora atribua-o aos colaboradores.';
                $form = ['nome' => '', 'h' => []];
            } catch (PDOException $e) {
                $erro = 'Ja existe um turno com esse nome (um turno nao pode ser alterado: crie outro com nome diferente).';
            }
        }
    } elseif ($acao === 'atribuir') {
        $funcionario_id = (int)($_POST['funcionario_id'] ?? 0);
        $turno_id = (int)($_POST['turno_id'] ?? 0);
        $vigencia = trim((string)($_POST['vigencia'] ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d', $vigencia);
        $stmt = ponto_db()->prepare('SELECT COUNT(*) FROM dbo.funcionarios WHERE id = ?');
        $stmt->execute([$funcionario_id]);
        $existe_func = (int)$stmt->fetchColumn() > 0;
        $stmt = ponto_db()->prepare('SELECT COUNT(*) FROM dbo.turnos WHERE id = ?');
        $stmt->execute([$turno_id]);
        $existe_turno = (int)$stmt->fetchColumn() > 0;
        if (!$existe_func || !$existe_turno) {
            $erro = 'Escolha o colaborador e o turno.';
        } elseif ($dt === false || $dt->format('Y-m-d') !== $vigencia) {
            $erro = 'Informe a data de vigencia (dia em que o turno passa a valer).';
        } else {
            $stmt = ponto_db()->prepare('INSERT INTO dbo.funcionario_turno (funcionario_id, turno_id, vigencia_inicio, criado_por) VALUES (?, ?, ?, ?)');
            $stmt->execute([$funcionario_id, $turno_id, $vigencia, $admin['id']]);
            ponto_log($admin['id'], 'atribuiu_turno', 'funcionario_id ' . $funcionario_id . ', turno_id ' . $turno_id . ' a partir de ' . $vigencia);
            $ok = 'Turno atribuido a partir de ' . (new DateTimeImmutable($vigencia))->format('d/m/Y') . '. Os dias anteriores continuam com o turno que valia na epoca.';
        }
    }
}

$turnos = [];
$funcionarios = [];
$atribuicoes = [];
if ($disponivel) {
    $turnos = ponto_db()->query(
        'SELECT t.id, t.nome, t.horarios, COUNT(ft.id) AS usos
         FROM dbo.turnos t LEFT JOIN dbo.funcionario_turno ft ON ft.turno_id = t.id
         GROUP BY t.id, t.nome, t.horarios ORDER BY t.nome'
    )->fetchAll(PDO::FETCH_ASSOC);
    $funcionarios = ponto_db()->query('SELECT id, login, nome FROM dbo.funcionarios WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
    $atribuicoes = ponto_db()->query(
        "SELECT TOP 200 f.nome, t.nome AS turno, CONVERT(VARCHAR(10), ft.vigencia_inicio, 120) AS vigencia
         FROM dbo.funcionario_turno ft JOIN dbo.funcionarios f ON f.id = ft.funcionario_id JOIN dbo.turnos t ON t.id = ft.turno_id
         ORDER BY f.nome, ft.vigencia_inicio DESC, ft.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}
$valor_h = static fn(int $dow, string $campo): string => htmlspecialchars((string)($form['h'][$dow][$campo] ?? ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Turnos</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa" style="max-width:820px">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Turnos (jornada de cada colaborador)</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>

    <?php if (!$disponivel): ?>
        <div class="banner banner-aviso" role="status">Falta aplicar a migracao 003 do banco (veja o README).</div>
    <?php else: ?>
        <?php if ($ok !== ''): ?><div class="banner banner-ok" role="status"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
        <?php if ($erro !== ''): ?><div class="banner banner-erro" role="alert"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

        <p style="font-size:.85rem;color:#666">
            O turno define quantas horas o colaborador deve cumprir em cada dia da semana: e dele que saem o credito, o debito
            e o saldo do espelho. Um turno nao pode ser editado depois de criado (para nao mudar o passado): para mudar a
            jornada, crie um turno novo e atribua-o a partir da data em que passa a valer.
        </p>

        <h2>Turnos cadastrados</h2>
        <table>
            <tr><th>Turno</th><th>Jornada</th><th>Colaboradores</th></tr>
            <?php foreach ($turnos as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['nome']) ?></td>
                <td style="font-size:.8rem"><?= htmlspecialchars(ponto_turno_resumo(ponto_turno_normalizar((string)$t['horarios']))) ?></td>
                <td><?= (int)$t['usos'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($turnos === []): ?><tr><td colspan="3">Nenhum turno ainda.</td></tr><?php endif; ?>
        </table>

        <h2>Novo turno</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
            <input type="hidden" name="acao" value="criar">
            <label>Nome do turno<br><input type="text" name="nome" maxlength="100" required value="<?= htmlspecialchars($form['nome']) ?>" placeholder="Ex.: Administrativo 08:00-17:00"></label>
            <table id="grade-turno">
                <tr><th>Dia</th><th>1ª Entrada</th><th>1ª Saída</th><th>2ª Entrada</th><th>2ª Saída</th></tr>
                <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $dow): ?>
                <tr data-dow="<?= $dow ?>">
                    <th><?= htmlspecialchars(PONTO_DIAS_NOME[$dow]) ?></th>
                    <?php foreach (['ent1', 'sai1', 'ent2', 'sai2'] as $campo): ?>
                    <td><input type="time" name="h[<?= $dow ?>][<?= $campo ?>]" value="<?= $valor_h($dow, $campo) ?>"></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </table>
            <p style="font-size:.8rem;color:#666">Deixe o dia inteiro em branco para folga. Para jornada sem intervalo, preencha so a 1ª entrada e a 1ª saída.</p>
            <button type="button" id="copiar-segunda">Copiar a segunda para terça a sexta</button>
            <button type="submit">Criar turno</button>
        </form>

        <h2>Atribuir turno a um colaborador</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
            <input type="hidden" name="acao" value="atribuir">
            <label>Colaborador<br>
                <select name="funcionario_id" required>
                    <option value="">-- selecione --</option>
                    <?php foreach ($funcionarios as $f): ?>
                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nome']) ?> (<?= htmlspecialchars($f['login']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Turno<br>
                <select name="turno_id" required>
                    <option value="">-- selecione --</option>
                    <?php foreach ($turnos as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Vale a partir de<br><input type="date" name="vigencia" required value="<?= htmlspecialchars($hoje_padrao) ?>"></label>
            <button type="submit">Atribuir</button>
        </form>

        <h2>Historico de atribuicoes</h2>
        <table>
            <tr><th>Colaborador</th><th>Turno</th><th>Desde</th></tr>
            <?php foreach ($atribuicoes as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['nome']) ?></td>
                <td><?= htmlspecialchars($a['turno']) ?></td>
                <td><?= htmlspecialchars((new DateTimeImmutable((string)$a['vigencia']))->format('d/m/Y')) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($atribuicoes === []): ?><tr><td colspan="3">Nenhuma atribuicao ainda.</td></tr><?php endif; ?>
        </table>
    <?php endif; ?>
</div>
<script src="/js/turno_form.js"></script>
<script src="/js/inatividade.js"></script>
</body>
</html>
