<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';

$admin = ponto_require_admin();
$disponivel = ponto_tem_tabela('feriados');
$erro = '';
$ok = '';
$ano = (int)($_GET['ano'] ?? ponto_agora_local(PONTO_FUSO_PADRAO)->format('Y'));
$ano = max(2000, min(2100, $ano));

if ($disponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $acao = (string)($_POST['acao'] ?? '');
    $data = trim((string)($_POST['data'] ?? ''));
    $dt = DateTime::createFromFormat('Y-m-d', $data);
    $data_ok = $dt !== false && $dt->format('Y-m-d') === $data;
    if (!$data_ok) {
        $erro = 'Informe uma data valida.';
    } elseif ($acao === 'adicionar') {
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 100) {
            $erro = 'De um nome ao feriado (ate 100 caracteres).';
        } else {
            try {
                $stmt = ponto_db()->prepare('INSERT INTO dbo.feriados (data, nome, criado_por) VALUES (?, ?, ?)');
                $stmt->execute([$data, $nome, $admin['id']]);
                ponto_log($admin['id'], 'criou_feriado', $data . ': ' . $nome);
                $ok = 'Feriado adicionado.';
                $ano = (int)substr($data, 0, 4);
            } catch (PDOException $e) {
                $erro = 'Essa data ja esta cadastrada como feriado.';
            }
        }
    } elseif ($acao === 'remover') {
        $stmt = ponto_db()->prepare('DELETE FROM dbo.feriados WHERE data = ?');
        $stmt->execute([$data]);
        ponto_log($admin['id'], 'removeu_feriado', $data);
        $ok = 'Feriado removido.';
        $ano = (int)substr($data, 0, 4);
    }
}

$nacionais = ponto_feriados_nacionais($ano);
$proprios = [];
if ($disponivel) {
    $stmt = ponto_db()->prepare("SELECT CONVERT(VARCHAR(10), data, 120) AS d, nome FROM dbo.feriados WHERE data >= ? AND data <= ? ORDER BY data");
    $stmt->execute([sprintf('%04d-01-01', $ano), sprintf('%04d-12-31', $ano)]);
    $proprios = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$fmt = static fn(string $ymd): string => PONTO_DIAS_ABREV[ponto_dow($ymd)] . ', ' . (new DateTimeImmutable($ymd))->format('d/m/Y');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Feriados</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa" style="max-width:640px">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Feriados</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>

    <?php if (!$disponivel): ?>
        <div class="banner banner-aviso" role="status">Falta aplicar a migracao 003 do banco (veja o README).</div>
    <?php else: ?>
        <?php if ($ok !== ''): ?><div class="banner banner-ok" role="status"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
        <?php if ($erro !== ''): ?><div class="banner banner-erro" role="alert"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

        <p>
            <a href="?ano=<?= $ano - 1 ?>">&laquo; <?= $ano - 1 ?></a>
            &nbsp;<strong><?= $ano ?></strong>&nbsp;
            <a href="?ano=<?= $ano + 1 ?>"><?= $ano + 1 ?> &raquo;</a>
        </p>
        <p style="font-size:.85rem;color:#666">
            Nos feriados nao ha jornada a cumprir (sem debito) e o que for trabalhado conta como H.E. 2 (100%).
            Os feriados nacionais ja vem calculados; cadastre abaixo os estaduais, municipais e pontes da empresa.
        </p>

        <h2>Nacionais (calculados)</h2>
        <table>
            <?php foreach ($nacionais as $d => $nome): ?>
            <tr><td><?= htmlspecialchars($fmt($d)) ?></td><td><?= htmlspecialchars($nome) ?></td></tr>
            <?php endforeach; ?>
        </table>

        <h2>Cadastrados pela empresa</h2>
        <table>
            <?php foreach ($proprios as $p): ?>
            <tr>
                <td><?= htmlspecialchars($fmt((string)$p['d'])) ?></td>
                <td><?= htmlspecialchars((string)$p['nome']) ?></td>
                <td>
                    <form method="post" style="margin:0" onsubmit="return confirm('Remover este feriado?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
                        <input type="hidden" name="acao" value="remover">
                        <input type="hidden" name="data" value="<?= htmlspecialchars((string)$p['d']) ?>">
                        <button type="submit">remover</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ($proprios === []): ?><tr><td colspan="3">Nenhum em <?= $ano ?>.</td></tr><?php endif; ?>
        </table>

        <h2>Adicionar feriado</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
            <input type="hidden" name="acao" value="adicionar">
            <label>Data<br><input type="date" name="data" required></label>
            <label>Nome<br><input type="text" name="nome" maxlength="100" required placeholder="Ex.: Aniversario da cidade"></label>
            <button type="submit">Adicionar</button>
        </form>
    <?php endif; ?>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
