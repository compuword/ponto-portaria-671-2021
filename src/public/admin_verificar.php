<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$admin = ponto_require_admin();

$stmt = ponto_db()->query(
    'SELECT r.id, r.funcionario_id, r.tipo,
            CONVERT(VARCHAR(33), r.registrado_em, 126) AS registrado_texto,
            CONVERT(VARCHAR(19), r.registrado_em, 120) AS quando_curto,
            r.ip_origem, r.hostname_origem, r.user_agent, r.manual, r.observacao, r.lancado_por,
            f.nome AS funcionario_nome,
            a.motivo AS anotacao,
            i.hash_anterior, i.hash_linha
     FROM dbo.registros_ponto r
     JOIN dbo.funcionarios f ON f.id = r.funcionario_id
     LEFT JOIN dbo.anotacoes_ponto a ON a.registro_id = r.id
     LEFT JOIN dbo.integridade_ponto i ON i.registro_id = r.id
     ORDER BY r.id'
);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$problemas = [];
$hash_esperado_anterior = null;

foreach ($linhas as $l) {
    if ($l['hash_linha'] === null) {
        $problemas[] = 'Registro id ' . $l['id'] . ': nao tem linha de integridade correspondente (inserido fora do app?).';
        continue;
    }

    if ($l['hash_anterior'] !== $hash_esperado_anterior) {
        $problemas[] = 'Registro id ' . $l['id'] . ': hash_anterior nao corresponde ao hash da linha anterior na cadeia.';
    }

    $hash_recalculado = hash('sha256', ponto_texto_hash($l['hash_anterior'], (int)$l['id'], $l));

    if (!hash_equals($hash_recalculado, (string)$l['hash_linha'])) {
        $problemas[] = 'Registro id ' . $l['id'] . ': hash nao confere -- dado foi alterado depois de gravado.';
    }

    $hash_esperado_anterior = $l['hash_linha'];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Verificacao de integridade</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Verificacao de integridade</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>

    <?php if (empty($linhas)): ?>
        <p>Nenhum registro de ponto ainda.</p>
    <?php elseif (empty($problemas)): ?>
        <p class="ok">OK -- todos os <?= count($linhas) ?> registros conferem com a cadeia de hash. Nenhuma alteracao detectada.</p>
    <?php else: ?>
        <p class="erro"><?= count($problemas) ?> problema(s) encontrado(s):</p>
        <ul>
        <?php foreach ($problemas as $p): ?>
            <li class="erro"><?= htmlspecialchars($p) ?></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p style="font-size:.85rem;color:#666">
        Esta verificacao prova alteracao em dado ja gravado (ex.: UPDATE direto no
        banco por fora do aplicativo). Ela nao substitui controle de quem tem
        acesso de administrador do banco (sa) -- esse acesso continua podendo
        desabilitar a verificacao ou recriar a cadeia do zero; o que ela impede
        e alteracao silenciosa que passe despercebida numa auditoria normal.
    </p>

    <h2>Todos os registros</h2>
    <p style="font-size:.85rem;color:#666">
        Um registro NUNCA e alterado ou apagado aqui. Se um ponto foi lancado
        errado ou so de teste, use "anotar" -- isso cria uma nota separada
        explicando o caso, sem tocar no registro original nem quebrar a cadeia
        de hash. A nota aparece nos relatorios no lugar das horas.
    </p>
    <table>
        <tr><th>Id</th><th>Funcionario</th><th>Tipo</th><th>Quando</th><th>IP / Estacao</th><th>Obs./Anotacao</th><th></th></tr>
        <?php foreach ($linhas as $l): ?>
        <tr>
            <td><?= (int)$l['id'] ?></td>
            <td><?= htmlspecialchars($l['funcionario_nome']) ?></td>
            <td><?= htmlspecialchars($l['tipo']) ?></td>
            <td><?= htmlspecialchars($l['quando_curto']) ?></td>
            <td style="font-size:.75rem"><?= htmlspecialchars($l['ip_origem']) ?><?= $l['hostname_origem'] ? '<br>' . htmlspecialchars((string)$l['hostname_origem']) : '' ?></td>
            <td style="font-size:.8rem">
                <?= htmlspecialchars((string)($l['observacao'] ?? '')) ?>
                <?php if ($l['anotacao']): ?>
                    <br><strong>Anotacao:</strong> <?= htmlspecialchars((string)$l['anotacao']) ?>
                <?php endif; ?>
            </td>
            <td><?php if (!$l['anotacao']): ?><a href="/admin_anotar.php?id=<?= (int)$l['id'] ?>">anotar</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
