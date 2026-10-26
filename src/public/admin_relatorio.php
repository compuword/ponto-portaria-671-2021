<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';
require __DIR__ . '/../xlsx.php';
require __DIR__ . '/../pdf.php';
require __DIR__ . '/../espelho_saida.php';
require __DIR__ . '/../espelho_pdf.php';

$admin = ponto_require_admin();
$fuso_admin = ponto_fuso_do_funcionario($admin['id']);
$agora_admin = ponto_agora_local($fuso_admin);

if (isset($_GET['baixar'])) {
    $escopo = (string)($_GET['escopo'] ?? 'um');
    $formato = (string)($_GET['formato'] ?? 'xlsx');
    $funcionario_id = (int)($_GET['funcionario_id'] ?? 0);
    $ano = max(2000, min(2100, (int)($_GET['ano'] ?? 0)));
    $mes = max(1, min(12, (int)($_GET['mes'] ?? 0)));

    if (!ponto_tem_espelho()) {
        http_response_code(503);
        exit('Aplique a migracao 003 do banco para exportar o espelho (veja o README).');
    }
    $ids = $escopo === 'todos'
        ? ponto_db()->query('SELECT id FROM dbo.funcionarios WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_COLUMN)
        : [$funcionario_id];
    $espelhos = [];
    foreach ($ids as $id) {
        $e = ponto_espelho((int)$id, $ano, $mes);
        if ($e !== null) {
            $espelhos[] = $e;
        }
    }
    if ($espelhos === []) {
        http_response_code(404);
        exit('Nenhum colaborador encontrado.');
    }

    $emitido_em = $agora_admin->format('d/m/Y H:i');
    $sufixo = $escopo === 'todos' ? 'todos' : (string)$funcionario_id;
    $nome_base = 'espelho_ponto_' . $sufixo . '_' . sprintf('%04d-%02d', $ano, $mes);
    ponto_log($admin['id'], 'exportou_espelho', sprintf('%s %04d-%02d (%d colaborador(es)) %s', $escopo, $ano, $mes, count($espelhos), $formato));

    if ($formato === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nome_base . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM -- Excel abre acentuacao certa
        foreach (ponto_espelho_csv_linhas($espelhos) as $linha) {
            echo implode(';', array_map(static fn($c) => str_replace([';', "\r", "\n"], [',', ' ', ' '], (string)$c), $linha)) . "\r\n";
        }
        exit;
    }

    if ($formato === 'pdf') {
        $conteudo = ponto_pdf_espelhos($espelhos, $admin['nome'], $emitido_em);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nome_base . '.pdf"');
        header('Content-Length: ' . strlen($conteudo));
        echo $conteudo;
        exit;
    }

    // xlsx (padrao)
    $caminho_tmp = tempnam(sys_get_temp_dir(), 'ponto_xlsx_');
    ponto_espelho_xlsx($caminho_tmp, $espelhos, $admin['nome'], $emitido_em);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nome_base . '.xlsx"');
    header('Content-Length: ' . filesize($caminho_tmp));
    readfile($caminho_tmp);
    unlink($caminho_tmp);
    exit;
}

$funcionarios = ponto_db()->query('SELECT id, login, nome FROM dbo.funcionarios ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
$ano_atual = (int)$agora_admin->format('Y');
$mes_atual = (int)$agora_admin->format('n');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Exportacao para contabilidade</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Espelho de ponto -- exportacao</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>
    <p style="font-size:.85rem;color:#666">
        Gera o espelho de ponto no formato do modelo (cabecalho da empresa e do colaborador, turno, creditos, debitos,
        extras, saldo, totais, legenda e comprovante de aceite). XLSX: uma aba por colaborador (mais uma aba "Resumo"
        quando for todos); PDF: uma pagina por colaborador; CSV: uma linha por dia.
    </p>

    <form method="get" action="/admin_relatorio.php" target="_blank" id="form-export">
        <input type="hidden" name="baixar" value="1">
        <label>Escopo<br>
            <select name="escopo" id="escopo" onchange="document.getElementById('bloco-func').style.display = this.value === 'um' ? 'block' : 'none'">
                <option value="um">Um colaborador</option>
                <option value="todos">Todos os colaboradores ativos</option>
            </select>
        </label>
        <div id="bloco-func">
            <label>Funcionario<br>
                <select name="funcionario_id">
                    <?php foreach ($funcionarios as $f): ?>
                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nome']) ?> (<?= htmlspecialchars($f['login']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>Mes<br>
            <select name="mes">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $mes_atual ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label>Ano<br><input type="number" name="ano" value="<?= $ano_atual ?>" min="2020" max="2100" required></label>
        <label>Formato<br>
            <select name="formato">
                <option value="xlsx">Excel (.xlsx)</option>
                <option value="csv">CSV (separado por ;)</option>
                <option value="pdf">PDF</option>
            </select>
        </label>
        <button type="submit">Baixar</button>
        <button type="submit" formaction="/admin_espelho.php" formtarget="_self">Ver na tela (um colaborador)</button>
    </form>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
