<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../xlsx.php';
require __DIR__ . '/../pdf.php';

$admin = ponto_require_admin();

function ponto_linhas_relatorio(string $escopo, int $funcionario_id, int $ano, int $mes): array
{
    if ($escopo === 'todos') {
        $funcs = ponto_db()->query('SELECT id, login, nome FROM dbo.funcionarios WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = ponto_db()->prepare('SELECT id, login, nome FROM dbo.funcionarios WHERE id = ?');
        $stmt->execute([$funcionario_id]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        $funcs = $f ? [$f] : [];
    }

    $linhas = [];
    foreach ($funcs as $f) {
        $dias = ponto_relatorio_mensal((int)$f['id'], $ano, $mes);
        foreach ($dias as $d) {
            $linhas[] = [
                'funcionario' => $f['nome'],
                'dia' => substr($d['data'], 8, 2) . '/' . substr($d['data'], 5, 2),
                'dia_semana' => $d['dia_semana'],
                'entrada' => $d['entrada'],
                'almoco' => $d['almoco'],
                'retorno' => $d['retorno'],
                'saida' => $d['saida'],
                'horas' => $d['horas'],
                'observacao' => $d['observacao'],
                'atestado_parcial' => $d['atestado_horas'],
            ];
        }
    }
    return $linhas;
}

if (isset($_GET['baixar'])) {
    $escopo = (string)($_GET['escopo'] ?? 'um');
    $formato = (string)($_GET['formato'] ?? 'xlsx');
    $funcionario_id = (int)($_GET['funcionario_id'] ?? 0);
    $ano = (int)($_GET['ano'] ?? 0);
    $mes = (int)($_GET['mes'] ?? 0);

    $linhas = ponto_linhas_relatorio($escopo, $funcionario_id, $ano, $mes);
    $sufixo = $escopo === 'todos' ? 'todos' : (string)$funcionario_id;
    $nome_base = 'ponto_' . $sufixo . '_' . sprintf('%04d-%02d', $ano, $mes);

    // "Atestado parcial (horas)" = tempo de consulta/exame no mesmo dia, ja descontado de "Horas trabalhadas"; ultima coluna de proposito.
    $cabecalho = ['Funcionario', 'Dia', 'Dia da semana', 'Entrada', 'Pausa', 'Retorno', 'Saida', 'Horas trabalhadas', 'Observacao', 'Atestado parcial (horas)'];
    $nota_fuso = $escopo === 'todos'
        ? 'Horarios no fuso de cada colaborador (padrao: ' . ponto_rotulo_fuso(PONTO_FUSO_PADRAO) . ')'
        : 'Horarios no fuso: ' . ponto_rotulo_fuso(ponto_fuso_do_funcionario($funcionario_id));

    if ($formato === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nome_base . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM -- Excel abre acentuacao certa
        $linha_csv = function (array $campos): string {
            return implode(';', array_map(static fn($c) => str_replace(';', ',', (string)$c), $campos)) . "\r\n";
        };
        echo $linha_csv($cabecalho);
        foreach ($linhas as $l) {
            echo $linha_csv(array_values($l));
        }
        exit;
    }

    if ($formato === 'pdf') {
        $texto_linhas = [];
        $texto_linhas[] = 'Compuword - Espelho de ponto - ' . sprintf('%02d/%04d', $mes, $ano);
        $texto_linhas[] = $nota_fuso;
        $texto_linhas[] = '';
        $texto_linhas[] = sprintf('%-20s %-6s %-5s %-7s %-7s %-7s %-7s %-6s %s', 'Funcionario', 'Dia', 'Sem.', 'Entrada', 'Pausa', 'Retorno', 'Saida', 'Horas', 'Obs');
        foreach ($linhas as $l) {
            $texto_linhas[] = sprintf(
                '%-20s %-6s %-5s %-7s %-7s %-7s %-7s %-6s %s',
                substr($l['funcionario'], 0, 20), $l['dia'], substr($l['dia_semana'], 0, 3),
                $l['entrada'], $l['almoco'], $l['retorno'], $l['saida'], $l['horas'],
                substr($l['observacao'], 0, 40)
            );
        }
        $caminho_tmp = tempnam(sys_get_temp_dir(), 'ponto_pdf_');
        pdf_escrever_linhas($caminho_tmp, $texto_linhas);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nome_base . '.pdf"');
        header('Content-Length: ' . filesize($caminho_tmp));
        readfile($caminho_tmp);
        unlink($caminho_tmp);
        exit;
    }

    // xlsx (padrao)
    $linhas_xlsx = [];
    $linhas_xlsx[] = ['Compuword - Espelho de ponto - ' . sprintf('%02d/%04d', $mes, $ano)];
    $linhas_xlsx[] = [$nota_fuso];
    $linhas_xlsx[] = [];
    $linhas_xlsx[] = $cabecalho;
    foreach ($linhas as $l) {
        $linhas_xlsx[] = array_values($l);
    }

    $caminho_tmp = tempnam(sys_get_temp_dir(), 'ponto_xlsx_');
    xlsx_escrever($caminho_tmp, $linhas_xlsx);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nome_base . '.xlsx"');
    header('Content-Length: ' . filesize($caminho_tmp));
    readfile($caminho_tmp);
    unlink($caminho_tmp);
    exit;
}

$funcionarios = ponto_db()->query('SELECT id, login, nome FROM dbo.funcionarios ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
$agora_local = ponto_agora_local(ponto_fuso_do_funcionario($admin['id']));
$ano_atual = (int)$agora_local->format('Y');
$mes_atual = (int)$agora_local->format('n');
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
    <h1>Exportacao para contabilidade</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>

    <form method="get" action="/admin_relatorio.php" target="_blank" id="form-export">
        <input type="hidden" name="baixar" value="1">
        <label>Escopo<br>
            <select name="escopo" id="escopo" onchange="document.getElementById('bloco-func').style.display = this.value === 'um' ? 'block' : 'none'">
                <option value="um">Um colaborador</option>
                <option value="todos">Todos os colaboradores</option>
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
    </form>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
