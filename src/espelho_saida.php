<?php
declare(strict_types=1);

/**
 * Saidas do espelho de ponto: tela (HTML), CSV e XLSX (o PDF fica em espelho_pdf.php). Todas partem do array
 * devolvido por ponto_espelho() e seguem as colunas do espelho modelo. Carregue depois de config.php, apuracao.php e espelho.php.
 */

/** Colunas da tabela do espelho, na ordem do modelo. */
function ponto_espelho_colunas(): array
{
    return ['Data', '1ª Entrada', '1ª Saída', '2ª Entrada', '2ª Saída', 'Crédito', 'Débito', 'H. intervalo', 'Horas normais',
            'H.E. 1 (50%)', 'H.E. 2 (100%)', 'A. N.', 'Saldo', 'Motivo'];
}

/** Texto das 14 colunas de um dia (vazio onde nao se aplica: dia futuro, fora do periodo, em andamento, colaborador sem turno). */
function ponto_espelho_celulas(array $l, bool $sem_turno = false): array
{
    $ap = $l['aplicavel'];
    $sem = $sem_turno || $l['tipo'] === 'sem_turno';
    $n = static fn(int $m, bool $mostrar = true): string => ($ap && $mostrar) ? ponto_hhmm($m) : '';
    return [
        PONTO_DIAS_ABREV[$l['dow']] . ', ' . (new DateTimeImmutable($l['data']))->format('d/m/Y'),
        (string)($l['ent1'] ?? ''), (string)($l['sai1'] ?? ''), (string)($l['ent2'] ?? ''), (string)($l['sai2'] ?? ''),
        $n($l['credito'], !$sem), $n($l['debito'], !$sem), $n($l['intervalo']), $n($l['normais']),
        $n($l['he1'], !$sem), $n($l['he2'], !$sem), $n($l['an']),
        ($l['saldo'] === null || $sem) ? '' : ponto_hhmm($l['saldo']),
        (string)$l['motivo'],
    ];
}

/** Linha TOTAIS (mesmas 14 colunas). */
function ponto_espelho_totais_celulas(array $e): array
{
    $t = $e['totais'];
    $sem = $e['sem_turno'];
    return [
        'TOTAIS', '', '', '', '',
        $sem ? '' : ponto_hhmm($t['credito']), $sem ? '' : ponto_hhmm($t['debito']), ponto_hhmm($t['intervalo']), ponto_hhmm($t['normais']),
        $sem ? '' : ponto_hhmm($t['he1']), $sem ? '' : ponto_hhmm($t['he2']), ponto_hhmm($t['an']),
        ($sem || $t['saldo_final'] === null) ? '' : ponto_hhmm((int)$t['saldo_final']), '',
    ];
}

function ponto_espelho_fmt_data(string $ymd): string
{
    return (new DateTimeImmutable($ymd))->format('d/m/Y');
}

/** Pares rotulo/valor do bloco de identificacao (empresa e colaborador), na ordem do modelo. */
function ponto_espelho_identificacao(array $e): array
{
    $emp = $e['empresa'];
    $f = $e['funcionario'];
    return [
        'Unidade de negócio' => $emp['unidade'] !== '' ? $emp['unidade'] : $emp['razao_social'],
        'CNPJ' => ponto_fmt_cnpj($emp['cnpj']),
        'Inscrição estadual' => $emp['inscricao_estadual'],
        'Nome' => $f['nome'],
        'PIS' => ponto_fmt_pis($f['pis']),
        'CPF' => ponto_fmt_cpf($f['cpf']),
        'Cargo' => $f['cargo'],
        'Equipe' => $f['equipe'],
    ];
}

/** Linhas do bloco do turno: Dom..Sab => [rotulo, 1a ent, 1a sai, 2a ent, 2a sai] ('-' quando e folga). */
function ponto_espelho_turno_linhas(array $e): array
{
    $horarios = $e['turno']['horarios'] ?? [];
    $out = [];
    for ($dow = 0; $dow <= 6; $dow++) {
        $d = $horarios[$dow] ?? null;
        $out[] = [PONTO_DIAS_ABREV[$dow], $d['ent1'] ?? '-', $d['sai1'] ?? '-', $d['ent2'] ?? '-', $d['sai2'] ?? '-'];
    }
    return $out;
}

function ponto_espelho_fator(float $f): string
{
    return number_format($f, 1, '.', '');
}

/** Textos da legenda (iguais na tela, no PDF e no XLSX). */
function ponto_espelho_legenda(array $e): array
{
    $cfg = $e['cfg'];
    return [
        'H.E. 1: Horas extras fator 1 (50%), fator de multiplicação do banco de horas ' . ponto_espelho_fator((float)$cfg['fator_he1']) . 'x',
        'H.E. 2: Horas extras fator 2 (100%), fator de multiplicação do banco de horas ' . ponto_espelho_fator((float)$cfg['fator_he2']) . 'x (domingos e feriados)',
        'A. N.: Adicional noturno (tempo trabalhado entre ' . ponto_hhmm((int)$cfg['noturno_ini']) . ' e ' . ponto_hhmm((int)$cfg['noturno_fim']) . ')',
        '(_): Ponto lançado manualmente ou alterado por um administrador (sublinhado em vermelho)',
        'Tolerância: diferença de até ' . (int)$cfg['tolerancia_min'] . ' min por dia não gera crédito nem débito',
        'Saldo: banco de horas acumulado (H.E. × fator - débitos) desde o início do controle do colaborador',
        'Atestado parcial: consulta/exame com volta no mesmo dia; o tempo fora é descontado das horas trabalhadas e '
            . ($cfg['abono_remunerado'] ? 'abonado (não gera débito)' : 'não abonado (gera débito)'),
    ];
}

// ---------------------------------------------------------------- HTML

function ponto_espelho_html(array $e): string
{
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $periodo = 'De ' . ponto_espelho_fmt_data($e['periodo']['de']) . ' até ' . ponto_espelho_fmt_data($e['periodo']['ate']);

    $o = '<div class="espelho">';
    $o .= '<div class="esp-topo"><h2>Espelho ponto</h2><div class="esp-periodo">' . $h($periodo) . '<br>' . $h($e['funcionario']['fuso']) . '</div></div>';

    if ($e['sem_turno']) {
        $o .= '<div class="banner banner-aviso" role="status">Este colaborador ainda não tem turno cadastrado: o espelho mostra só as batidas e o tempo trabalhado, sem crédito, débito e saldo.</div>';
    } elseif ($e['turno'] !== null && $e['turno']['mudou_no_periodo']) {
        $o .= '<div class="banner banner-aviso" role="status">O turno mudou durante o período: o quadro abaixo mostra o turno em vigor no fim dele; cada dia usa o turno da sua data.</div>';
    }

    $o .= '<div class="esp-blocos"><table class="esp-ident">';
    foreach (ponto_espelho_identificacao($e) as $rotulo => $valor) {
        $o .= '<tr><th>' . $h($rotulo) . '</th><td>' . $h($valor) . '</td></tr>';
    }
    $o .= '</table><table class="esp-turno"><tr><th colspan="5">' . ($e['turno'] === null ? 'Sem turno' : 'Turno ' . $h($e['turno']['nome'])) . '</th></tr>';
    $o .= '<tr><th></th><th>1ª Ent</th><th>1ª Saí</th><th>2ª Ent</th><th>2ª Saí</th></tr>';
    foreach (ponto_espelho_turno_linhas($e) as $t) {
        $o .= '<tr><th>' . $h($t[0]) . '</th><td>' . $h($t[1]) . '</td><td>' . $h($t[2]) . '</td><td>' . $h($t[3]) . '</td><td>' . $h($t[4]) . '</td></tr>';
    }
    $o .= '</table></div>';

    $cols = ponto_espelho_colunas();
    $o .= '<div class="esp-rolagem"><table class="esp-grade"><thead>';
    $o .= '<tr class="esp-grupos"><th></th><th colspan="4">Pontos</th><th colspan="4">Totais da jornada</th><th colspan="3">Total de H. Extras</th><th></th><th></th></tr><tr>';
    foreach ($cols as $c) {
        $o .= '<th>' . $h($c) . '</th>';
    }
    $o .= '</tr></thead><tbody>';
    foreach ($e['linhas'] as $l) {
        $cel = ponto_espelho_celulas($l, $e['sem_turno']);
        $classe = 'tipo-' . $l['tipo'];
        $o .= '<tr class="' . $h($classe) . '"><td class="esq">' . $h($cel[0]) . '</td>';
        foreach (['ent1' => 1, 'sai1' => 2, 'ent2' => 3, 'sai2' => 4] as $slot => $i) {
            $manual = !empty($l['manual'][$slot]) && $cel[$i] !== '';
            $o .= '<td' . ($manual ? ' class="manual" title="Ponto lançado manualmente ou alterado"' : '') . '>' . $h($cel[$i]) . '</td>';
        }
        for ($i = 5; $i <= 11; $i++) {
            $o .= '<td>' . $h($cel[$i]) . '</td>';
        }
        $negativo = $l['saldo'] !== null && $l['saldo'] < 0;
        $o .= '<td' . ($negativo ? ' class="negativo"' : '') . '>' . $h($cel[12]) . '</td><td class="esq motivo">' . $h($cel[13]) . '</td></tr>';
    }
    $o .= '</tbody><tfoot><tr>';
    foreach (ponto_espelho_totais_celulas($e) as $i => $c) {
        if ($i >= 1 && $i <= 4) {
            continue; // "TOTAIS" ocupa as colunas de data e das 4 batidas
        }
        $o .= '<td' . ($i === 0 ? ' class="esq" colspan="5"' : '') . '>' . $h($c) . '</td>';
    }
    $o .= '</tr></tfoot></table></div>';
    return $o . ponto_espelho_html_legenda($e) . '</div>';
}

/** Legenda e (se houver) o resumo do aceite eletronico; o botao de aceite fica na pagina, que tem o token CSRF. */
function ponto_espelho_html_legenda(array $e): string
{
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = '<div class="esp-rodape"><div class="esp-aceite"><h3>Comprovante de aceite eletrônico</h3>';
    $a = $e['aceite'];
    if ($a === null) {
        $o .= '<p>Aceite pendente: o colaborador ainda não aceitou este espelho.</p>';
    } else {
        $o .= '<p><strong>' . $h($e['funcionario']['nome']) . '</strong> (Colaborador)<br>Data/Hora: ' . $h(preg_replace('/ /', ' às ', $a['aceito_em'], 1))
            . '<br>Protocolo: ' . $h($a['protocolo']) . '<br>IP: ' . $h($a['ip']) . ' / Plataforma: ' . $h($a['plataforma']) . '</p>';
        if ($a['alterado_depois']) {
            $o .= '<p class="erro"><strong>ATENÇÃO:</strong> o espelho foi alterado depois deste aceite (novas batidas, ajustes ou atestados). É preciso aceitar de novo.</p>';
        }
    }
    $o .= '</div><div class="esp-legenda"><h3>Legenda</h3><ul>';
    foreach (ponto_espelho_legenda($e) as $linha) {
        $o .= '<li>' . $h($linha) . '</li>';
    }
    return $o . '</ul></div></div>';
}

// ---------------------------------------------------------------- CSV

/** Linhas do CSV (cabecalho + dias + TOTAIS de cada colaborador). A exportacao grava com ';' e BOM. */
function ponto_espelho_csv_linhas(array $espelhos): array
{
    $linhas = [[
        'Funcionário', 'CPF', 'PIS', 'Cargo', 'Equipe', 'Data', 'Dia da semana', '1ª Entrada', '1ª Saída', '2ª Entrada', '2ª Saída',
        'Crédito', 'Débito', 'H. intervalo', 'Horas normais', 'H.E. 1 (50%)', 'H.E. 2 (100%)', 'A. N.', 'Saldo', 'Motivo',
        'Batidas manuais', 'Horas trabalhadas', 'Atestado parcial (horas)',
    ]];
    $nomes = ['ent1' => '1ª Entrada', 'sai1' => '1ª Saída', 'ent2' => '2ª Entrada', 'sai2' => '2ª Saída'];
    foreach ($espelhos as $e) {
        $f = $e['funcionario'];
        $id = [$f['nome'], ponto_fmt_cpf($f['cpf']), ponto_fmt_pis($f['pis']), $f['cargo'], $f['equipe']];
        foreach ($e['linhas'] as $l) {
            $c = ponto_espelho_celulas($l, $e['sem_turno']);
            $manuais = [];
            foreach ($nomes as $slot => $nome) {
                if (!empty($l['manual'][$slot]) && (string)($l[$slot] ?? '') !== '') {
                    $manuais[] = $nome;
                }
            }
            $ap = $l['aplicavel'];
            $linhas[] = array_merge(
                $id,
                [ponto_espelho_fmt_data($l['data']), PONTO_DIAS_ABREV[$l['dow']]],
                array_slice($c, 1, 12),
                [$c[13], implode(', ', $manuais), $ap ? ponto_hhmm($l['trabalhado']) : '', ($ap && $l['abono'] > 0) ? ponto_hhmm($l['abono']) : '']
            );
        }
        $t = ponto_espelho_totais_celulas($e);
        $linhas[] = array_merge(
            $id,
            ['TOTAIS', ''],
            ['', '', '', ''],
            array_slice($t, 5, 8),
            ['', '', ponto_hhmm($e['totais']['trabalhado']), $e['totais']['abono'] > 0 ? ponto_hhmm($e['totais']['abono']) : '']
        );
    }
    return $linhas;
}

// ---------------------------------------------------------------- XLSX

/** Aba do espelho de 1 colaborador (identificacao, turno, tabela, totais, legenda e aceite). */
function ponto_espelho_aba_xlsx(array $e, string $emitido_por, string $emitido_em): array
{
    $v = static fn($valor, string $estilo): array => ['v' => $valor, 'e' => $estilo];
    $vazias = static fn(int $n): array => array_fill(0, $n, '');
    $linhas = [];
    $mesclas = [];

    $periodo = 'De ' . ponto_espelho_fmt_data($e['periodo']['de']) . ' até ' . ponto_espelho_fmt_data($e['periodo']['ate']);
    $linhas[] = array_merge([$v('Espelho ponto', 'titulo')], $vazias(7), [$v($periodo, 'negrito')], $vazias(5));
    $mesclas[] = 'A1:H1';
    $mesclas[] = 'I1:N1';
    $linhas[] = array_merge([$v('Por ' . $emitido_por . ' em ' . $emitido_em . ' | fuso ' . $e['funcionario']['fuso'], 'nota')], $vazias(13));
    $mesclas[] = 'A2:N2';
    $linhas[] = $vazias(14);

    // identificacao (A:B rotulo, C:G valor) + turno (J:N)
    $ident = array_map(null, array_keys(ponto_espelho_identificacao($e)), array_values(ponto_espelho_identificacao($e)));
    $turno = ponto_espelho_turno_linhas($e);
    $tituloTurno = $e['turno'] === null ? 'Sem turno' : 'Turno ' . $e['turno']['nome'];
    for ($i = 0; $i < 9; $i++) {
        $r = 4 + $i;
        $linha = $vazias(14);
        if ($i < 8) {
            $linha[0] = $v($ident[$i][0], 'rotulo');
            $linha[1] = $v('', 'rotulo');
            $linha[2] = $v((string)$ident[$i][1], 'valor');
            foreach ([3, 4, 5, 6] as $c) {
                $linha[$c] = $v('', 'valor');
            }
            $mesclas[] = "A{$r}:B{$r}";
            $mesclas[] = "C{$r}:G{$r}";
        }
        if ($i === 0) {
            $linha[9] = $v($tituloTurno, 'cab');
            foreach ([10, 11, 12, 13] as $c) {
                $linha[$c] = $v('', 'cab');
            }
            $mesclas[] = "J{$r}:N{$r}";
        } elseif ($i === 1) {
            foreach (['', '1ª Ent', '1ª Saí', '2ª Ent', '2ª Saí'] as $k => $txt) {
                $linha[9 + $k] = $v($txt, 'cab');
            }
        } else {
            foreach ($turno[$i - 2] as $k => $txt) {
                $linha[9 + $k] = $v($txt, $k === 0 ? 'rotulo' : 'centro');
            }
        }
        $linhas[] = $linha;
    }
    $linhas[] = $vazias(14);

    // cabecalho da tabela (2 linhas)
    $rg = count($linhas) + 1;
    $g = $vazias(14);
    $g[0] = $v('', 'cab');
    foreach ([[1, 'Pontos', 4], [5, 'Totais da jornada', 4], [9, 'Total de H. Extras', 3]] as [$c0, $txt, $n]) {
        for ($k = 0; $k < $n; $k++) {
            $g[$c0 + $k] = $v($k === 0 ? $txt : '', 'cab');
        }
        $mesclas[] = xlsx_coluna($c0 + 1) . $rg . ':' . xlsx_coluna($c0 + $n) . $rg;
    }
    $g[12] = $v('', 'cab');
    $g[13] = $v('', 'cab');
    $linhas[] = $g;
    $linhas[] = array_map(static fn(string $c): array => ['v' => $c, 'e' => 'cab'], ponto_espelho_colunas());

    foreach ($e['linhas'] as $l) {
        $cel = ponto_espelho_celulas($l, $e['sem_turno']);
        $cinza = in_array($l['tipo'], ['folga', 'feriado', 'na', 'futuro'], true);
        $linha = [$v($cel[0], $cinza ? 'cinza_esq' : 'cel')];
        foreach ([1 => 'ent1', 2 => 'sai1', 3 => 'ent2', 4 => 'sai2'] as $i => $slot) {
            $manual = !empty($l['manual'][$slot]) && $cel[$i] !== '';
            $linha[] = $v($cel[$i], $manual ? 'manual' : ($cinza ? 'cinza' : 'centro'));
        }
        for ($i = 5; $i <= 12; $i++) {
            $linha[] = $v($cel[$i], $cinza ? 'cinza' : 'centro');
        }
        $linha[] = $v($cel[13], $cinza ? 'cinza_esq' : 'cel');
        $linhas[] = $linha;
    }
    $tot = ponto_espelho_totais_celulas($e);
    $rt = count($linhas) + 1;
    $linha = [$v('TOTAIS', 'total'), $v('', 'total'), $v('', 'total'), $v('', 'total'), $v('', 'total')];
    for ($i = 5; $i <= 13; $i++) {
        $linha[] = $v($tot[$i], 'total');
    }
    $linhas[] = $linha;
    $mesclas[] = "A{$rt}:E{$rt}";
    $linhas[] = $vazias(14);

    $linhas[] = array_merge([$v('Comprovante de aceite eletrônico', 'negrito')], $vazias(13));
    $a = $e['aceite'];
    if ($a === null) {
        $linhas[] = array_merge([$v('Aceite pendente: o colaborador ainda não aceitou este espelho.', 'nota')], $vazias(13));
    } else {
        $linhas[] = array_merge([$v($e['funcionario']['nome'] . ' (Colaborador) | ' . preg_replace('/ /', ' às ', $a['aceito_em'], 1)
            . ' | Protocolo ' . $a['protocolo'] . ' | IP ' . $a['ip'] . ' / ' . $a['plataforma'], 'nota')], $vazias(13));
        if ($a['alterado_depois']) {
            $linhas[] = array_merge([$v('ATENÇÃO: o espelho foi alterado depois deste aceite; é preciso aceitar de novo.', 'manual')], $vazias(13));
        }
    }
    $linhas[] = $vazias(14);
    $linhas[] = array_merge([$v('Legenda', 'negrito')], $vazias(13));
    foreach (ponto_espelho_legenda($e) as $texto) {
        $linhas[] = array_merge([$v($texto, 'nota')], $vazias(13));
    }

    return [
        'nome' => $e['funcionario']['nome'],
        'linhas' => $linhas,
        'larguras' => [17, 9, 9, 9, 9, 8, 8, 9, 9, 10, 10, 7, 9, 38],
        'mesclas' => $mesclas,
        'retrato' => true,
    ];
}

/** Aba "Resumo" com 1 linha por colaborador (so quando a exportacao tem mais de um). */
function ponto_espelho_aba_resumo(array $espelhos): array
{
    $cab = ['Funcionário', 'CPF', 'PIS', 'Cargo', 'Equipe', 'Turno', 'Dias trabalhados', 'Faltas', 'Horas normais', 'Crédito', 'Débito',
            'H.E. 1 (50%)', 'H.E. 2 (100%)', 'A. N.', 'Atestado parcial (horas)', 'Saldo final', 'Aceite do espelho'];
    $linhas = [array_map(static fn(string $c): array => ['v' => $c, 'e' => 'cab'], $cab)];
    foreach ($espelhos as $e) {
        $f = $e['funcionario'];
        $t = $e['totais'];
        $sem = $e['sem_turno'];
        $aceite = $e['aceite'] === null ? 'Pendente' : ($e['aceite']['alterado_depois'] ? 'Alterado após o aceite' : 'Aceito');
        $dados = [
            $f['nome'], ponto_fmt_cpf($f['cpf']), ponto_fmt_pis($f['pis']), $f['cargo'], $f['equipe'], $e['turno']['nome'] ?? 'Sem turno',
            $t['dias_trabalhados'], $t['faltas'], ponto_hhmm($t['normais']),
            $sem ? '' : ponto_hhmm($t['credito']), $sem ? '' : ponto_hhmm($t['debito']),
            $sem ? '' : ponto_hhmm($t['he1']), $sem ? '' : ponto_hhmm($t['he2']), ponto_hhmm($t['an']),
            $t['abono'] > 0 ? ponto_hhmm($t['abono']) : '', ($sem || $t['saldo_final'] === null) ? '' : ponto_hhmm((int)$t['saldo_final']), $aceite,
        ];
        $linhas[] = array_map(static fn($d, int $i): array => ['v' => $d, 'e' => $i <= 5 || $i === 16 ? 'cel' : 'centro'], $dados, array_keys($dados));
    }
    return [
        'nome' => 'Resumo',
        'linhas' => $linhas,
        'larguras' => [26, 15, 15, 20, 18, 22, 10, 8, 11, 9, 9, 11, 11, 8, 13, 10, 20],
        'mesclas' => [],
        'retrato' => false,
    ];
}

function ponto_espelho_xlsx(string $caminho, array $espelhos, string $emitido_por, string $emitido_em): void
{
    $abas = [];
    if (count($espelhos) > 1) {
        $abas[] = ponto_espelho_aba_resumo($espelhos);
    }
    foreach ($espelhos as $e) {
        $abas[] = ponto_espelho_aba_xlsx($e, $emitido_por, $emitido_em);
    }
    xlsx_escrever_abas($caminho, $abas);
}
