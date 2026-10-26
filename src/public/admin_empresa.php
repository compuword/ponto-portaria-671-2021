<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';

$admin = ponto_require_admin();
$disponivel = ponto_tem_tabela('empresa');
$erro = '';

$emp = ponto_empresa(true);
$v = [
    'razao_social' => $emp['razao_social'],
    'unidade' => $emp['unidade'],
    'cnpj' => ponto_fmt_cnpj($emp['cnpj']),
    'inscricao_estadual' => $emp['inscricao_estadual'],
    'tolerancia_min' => (string)$emp['cfg']['tolerancia_min'],
    'noturno_inicio' => ponto_hhmm((int)$emp['cfg']['noturno_ini']),
    'noturno_fim' => ponto_hhmm((int)$emp['cfg']['noturno_fim']),
    'fator_he1' => ponto_espelho_fator_texto((float)$emp['cfg']['fator_he1']),
    'fator_he2' => ponto_espelho_fator_texto((float)$emp['cfg']['fator_he2']),
    'abono_remunerado' => $emp['cfg']['abono_remunerado'],
    'inicio_controle' => (string)($emp['inicio_controle'] ?? ''),
];

function ponto_espelho_fator_texto(float $f): string
{
    return number_format($f, 2, '.', '');
}

if ($disponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ponto_csrf_check();
    $v = [
        'razao_social' => trim((string)($_POST['razao_social'] ?? '')),
        'unidade' => trim((string)($_POST['unidade'] ?? '')),
        'cnpj' => trim((string)($_POST['cnpj'] ?? '')),
        'inscricao_estadual' => trim((string)($_POST['inscricao_estadual'] ?? '')),
        'tolerancia_min' => trim((string)($_POST['tolerancia_min'] ?? '')),
        'noturno_inicio' => trim((string)($_POST['noturno_inicio'] ?? '')),
        'noturno_fim' => trim((string)($_POST['noturno_fim'] ?? '')),
        'fator_he1' => str_replace(',', '.', trim((string)($_POST['fator_he1'] ?? ''))),
        'fator_he2' => str_replace(',', '.', trim((string)($_POST['fator_he2'] ?? ''))),
        'abono_remunerado' => isset($_POST['abono_remunerado']),
        'inicio_controle' => trim((string)($_POST['inicio_controle'] ?? '')),
    ];
    $data_ok = static function (string $d): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    };
    if (mb_strlen($v['razao_social']) > 200 || mb_strlen($v['unidade']) > 200 || mb_strlen($v['inscricao_estadual']) > 30) {
        $erro = 'Razao social e unidade aceitam ate 200 caracteres e a inscricao estadual ate 30.';
    } elseif ($v['cnpj'] !== '' && !ponto_cnpj_valido($v['cnpj'])) {
        $erro = 'CNPJ invalido (confira os digitos).';
    } elseif (!ctype_digit($v['tolerancia_min']) || (int)$v['tolerancia_min'] > 60) {
        $erro = 'A tolerancia diaria precisa ser um numero de minutos entre 0 e 60.';
    } elseif (ponto_min($v['noturno_inicio']) === null || ponto_min($v['noturno_fim']) === null) {
        $erro = 'Informe o inicio e o fim do periodo noturno no formato HH:MM.';
    } elseif (!is_numeric($v['fator_he1']) || !is_numeric($v['fator_he2']) || (float)$v['fator_he1'] < 0 || (float)$v['fator_he1'] > 5 || (float)$v['fator_he2'] < 0 || (float)$v['fator_he2'] > 5) {
        $erro = 'Os fatores do banco de horas precisam ser numeros entre 0 e 5 (ex.: 1.0 e 2.0).';
    } elseif ($v['inicio_controle'] !== '' && !$data_ok($v['inicio_controle'])) {
        $erro = 'Data de inicio do controle invalida.';
    } else {
        $stmt = ponto_db()->prepare(
            'UPDATE dbo.empresa SET razao_social = ?, unidade = ?, cnpj = ?, inscricao_estadual = ?, tolerancia_min = ?,
                    noturno_inicio = ?, noturno_fim = ?, fator_he1 = ?, fator_he2 = ?, abono_remunerado = ?, inicio_controle = ?,
                    atualizado_em = SYSUTCDATETIME()
             WHERE id = 1'
        );
        $stmt->execute([
            $v['razao_social'] !== '' ? $v['razao_social'] : null,
            $v['unidade'] !== '' ? $v['unidade'] : null,
            $v['cnpj'] !== '' ? ponto_so_digitos($v['cnpj']) : null,
            $v['inscricao_estadual'] !== '' ? $v['inscricao_estadual'] : null,
            (int)$v['tolerancia_min'],
            sprintf('%s:00', ponto_hhmm((int)ponto_min($v['noturno_inicio']))),
            sprintf('%s:00', ponto_hhmm((int)ponto_min($v['noturno_fim']))),
            number_format((float)$v['fator_he1'], 2, '.', ''),
            number_format((float)$v['fator_he2'], 2, '.', ''),
            $v['abono_remunerado'] ? 1 : 0,
            $v['inicio_controle'] !== '' ? $v['inicio_controle'] : null,
        ]);
        ponto_log($admin['id'], 'editou_empresa', sprintf(
            'tolerancia %s min, noturno %s-%s, fatores %s/%s, abono %s, inicio do controle %s',
            $v['tolerancia_min'], $v['noturno_inicio'], $v['noturno_fim'], $v['fator_he1'], $v['fator_he2'],
            $v['abono_remunerado'] ? 'remunerado' : 'nao remunerado', $v['inicio_controle'] !== '' ? $v['inicio_controle'] : '(vazio)'
        ));
        header('Location: /admin_empresa.php?ok=1');
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ponto - Empresa e regras do espelho</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="caixa" style="max-width:640px">
    <img src="/img/logo.png" alt="Compuword" class="logo">
    <h1>Empresa e regras do espelho</h1>
    <p><a href="/admin.php">Voltar</a> | <a href="/logout.php">Sair</a></p>

    <?php if (!$disponivel): ?>
        <div class="banner banner-aviso" role="status">Falta aplicar a migracao 003 do banco (veja o README).</div>
    <?php else: ?>
        <?php if (($_GET['ok'] ?? '') === '1'): ?>
            <div class="banner banner-ok" role="status">Dados salvos. Os espelhos ja usam os novos valores.</div>
        <?php endif; ?>
        <?php if ($erro !== ''): ?>
            <div class="banner banner-erro" role="alert"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(ponto_csrf_token()) ?>">
            <h2>Dados que saem no cabecalho do espelho</h2>
            <label>Razao social<br><input type="text" name="razao_social" maxlength="200" value="<?= htmlspecialchars($v['razao_social']) ?>"></label>
            <label>Unidade de negocio (nome que aparece no espelho; se vazio, usa a razao social)<br><input type="text" name="unidade" maxlength="200" value="<?= htmlspecialchars($v['unidade']) ?>"></label>
            <label>CNPJ<br><input type="text" name="cnpj" maxlength="18" placeholder="00.000.000/0000-00" value="<?= htmlspecialchars($v['cnpj']) ?>"></label>
            <label>Inscricao estadual<br><input type="text" name="inscricao_estadual" maxlength="30" value="<?= htmlspecialchars($v['inscricao_estadual']) ?>"></label>

            <h2>Regras de apuracao</h2>
            <label>Tolerancia diaria (minutos): diferenca de ate esse tempo entre o trabalhado e o previsto nao gera credito nem debito (CLT art. 58 §1: 5 min por marcacao, 10 min por dia)<br>
                <input type="number" name="tolerancia_min" min="0" max="60" required value="<?= htmlspecialchars($v['tolerancia_min']) ?>"></label>
            <label>Inicio do periodo noturno (adicional noturno)<br><input type="time" name="noturno_inicio" required value="<?= htmlspecialchars($v['noturno_inicio']) ?>"></label>
            <label>Fim do periodo noturno<br><input type="time" name="noturno_fim" required value="<?= htmlspecialchars($v['noturno_fim']) ?>"></label>
            <label>Fator do banco de horas para H.E. 1 (50%)<br><input type="text" name="fator_he1" required value="<?= htmlspecialchars($v['fator_he1']) ?>"></label>
            <label>Fator do banco de horas para H.E. 2 (100%: domingos e feriados)<br><input type="text" name="fator_he2" required value="<?= htmlspecialchars($v['fator_he2']) ?>"></label>
            <label><input type="checkbox" name="abono_remunerado" style="width:auto" <?= $v['abono_remunerado'] ? 'checked' : '' ?>>
                Atestado parcial (consulta/exame no mesmo dia) e abonado: o tempo fora nao gera debito</label>
            <label>Inicio do controle de ponto (opcional): dias antes desta data nao entram na apuracao nem no saldo<br>
                <input type="date" name="inicio_controle" value="<?= htmlspecialchars($v['inicio_controle']) ?>"></label>
            <button type="submit">Salvar</button>
        </form>
        <p style="font-size:.8rem;color:#666">
            O saldo (banco de horas) de cada colaborador acumula a partir da maior data entre a primeira batida dele, a data de
            admissao e o inicio do controle acima. Mudar uma regra aqui muda tambem o espelho dos meses ja fechados.
        </p>
    <?php endif; ?>
</div>
<script src="/js/inatividade.js"></script>
</body>
</html>
