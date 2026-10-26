<?php
declare(strict_types=1);

/**
 * PDF do espelho de ponto no layout do modelo (A4 retrato): cabecalho com logo, quadros de identificacao e de turno,
 * tabela de 14 colunas com TOTAIS, e rodape com o comprovante de aceite e a legenda. Carregue depois de pdf.php e
 * espelho_saida.php. Uma pagina por colaborador (mais, se a tabela ou os quadros nao couberem).
 */

const PDF_CINZA = [0.949, 0.949, 0.949];
const PDF_CINZA_CAB = [0.851, 0.851, 0.851];
const PDF_CINZA_DIA = [0.972, 0.972, 0.972];
const PDF_BORDA = [0.73, 0.73, 0.73];
const PDF_VERMELHO = [0.78, 0.16, 0.16];
const PDF_TEXTO = [0.08, 0.08, 0.08];

/** Corta o texto com reticencias para caber em $max pontos. */
function ponto_pdf_cortar(PontoPdf $pdf, string $s, float $max, float $tam, bool $negrito = false): string
{
    if ($pdf->largura($s, $tam, $negrito) <= $max) {
        return $s;
    }
    while ($s !== '' && $pdf->largura($s . '…', $tam, $negrito) > $max) {
        $s = mb_substr($s, 0, -1);
    }
    return $s . '…';
}

/** Cabecalho da tabela (linha de grupos + linha de colunas); devolve o y logo abaixo. */
function ponto_pdf_cabecalho_tabela(PontoPdf $pdf, float $x0, float $y, array $larguras): float
{
    $grupos = [[1, 4, 'Pontos'], [5, 4, 'Totais da jornada'], [9, 3, 'Total de H. Extras']];
    $hGrupo = 10.0;
    $xs = [$x0];
    foreach ($larguras as $w) {
        $xs[] = end($xs) + $w;
    }
    $pdf->ret($xs[0], $y, $xs[14] - $xs[0], $hGrupo, PDF_CINZA, PDF_BORDA);
    foreach ($grupos as [$ini, $n, $nome]) {
        $w = $xs[$ini + $n] - $xs[$ini];
        $pdf->ret($xs[$ini], $y, $w, $hGrupo, PDF_CINZA_CAB, PDF_BORDA);
        $pdf->texto($xs[$ini] + ($w - $pdf->largura($nome, 6.4, true)) / 2, $y + 7.2, $nome, 6.4, true, PDF_TEXTO);
    }
    $y += $hGrupo;
    $hCab = 19.0;
    foreach (ponto_espelho_colunas() as $i => $nome) {
        $w = $larguras[$i];
        $pdf->ret($xs[$i], $y, $w, $hCab, PDF_CINZA_CAB, PDF_BORDA);
        $linhas = $pdf->quebrar($nome, $w - 4, 6.2, true);
        foreach (array_slice($linhas, 0, 2) as $k => $linha) {
            $pdf->texto($xs[$i] + 2.5, $y + 7.6 + $k * 7.2, $linha, 6.2, true, PDF_TEXTO);
        }
    }
    return $y + $hCab;
}

/** Desenha 1 espelho a partir de uma pagina nova (o chamador ja abriu a pagina). */
function ponto_pdf_espelho(PontoPdf $pdf, array $e, string $emitido_por, string $emitido_em): void
{
    $ml = 28.0;
    $xd = PontoPdf::LARGURA - $ml;
    $limite = PontoPdf::ALTURA - 40.0;
    $f = $e['funcionario'];
    $emp = $e['empresa'];

    // ---- topo: logo, titulo, periodo e quem emitiu
    $logo = pdf_logo_jpeg();
    if ($logo !== null) {
        $h = 34.0;
        $w = $h * $logo['w'] / $logo['h'];
        if ($w > 150.0) {
            $w = 150.0;
            $h = $w * $logo['h'] / $logo['w'];
        }
        $pdf->imagemJpeg('Im1', $logo['jpeg'], $logo['w'], $logo['h'], $ml, 24.0, $w, $h);
    } else {
        $nome_emp = $emp['unidade'] !== '' ? $emp['unidade'] : ($emp['razao_social'] !== '' ? $emp['razao_social'] : '');
        $pdf->texto($ml, 46.0, ponto_pdf_cortar($pdf, $nome_emp, 150, 12, true), 12, true, [0, 0.29, 0.38]);
    }
    $pdf->texto(238.0, 44.0, 'Espelho ponto', 14, true, PDF_TEXTO);
    $pdf->textoDireita($xd, 36.0, 'De ' . ponto_espelho_fmt_data($e['periodo']['de']) . ' até ' . ponto_espelho_fmt_data($e['periodo']['ate']), 7.5, false, PDF_TEXTO);
    $pdf->textoDireita($xd, 46.0, ponto_pdf_cortar($pdf, 'Por ' . $emitido_por . ' em ' . $emitido_em, 200, 6.5), 6.5, false, [0.35, 0.35, 0.35]);

    // ---- identificacao (esquerda) e turno (direita)
    $y0 = 68.0;
    $rh = 12.0;
    $i = 0;
    foreach (ponto_espelho_identificacao($e) as $rotulo => $valor) {
        $y = $y0 + $i * $rh;
        $pdf->ret($ml, $y, 92.0, $rh, PDF_CINZA, PDF_BORDA);
        $pdf->ret($ml + 92.0, $y, 250.0, $rh, null, PDF_BORDA);
        $pdf->texto($ml + 3.0, $y + 8.4, $rotulo, 6.8, true, PDF_TEXTO);
        $pdf->texto($ml + 95.0, $y + 8.4, ponto_pdf_cortar($pdf, (string)$valor, 244.0, 6.8, true), 6.8, true, PDF_TEXTO);
        $i++;
    }
    $tx = $ml + 352.0;
    $tw = $xd - $tx;
    $c0 = 31.0;
    $cw = ($tw - $c0) / 4;
    $hl = 10.5;
    $pdf->ret($tx, $y0, $tw, $rh, PDF_CINZA_CAB, PDF_BORDA);
    $pdf->texto($tx + 3.0, $y0 + 8.4, ponto_pdf_cortar($pdf, $e['turno'] === null ? 'Sem turno' : 'Turno ' . $e['turno']['nome'], $tw - 6, 6.8, true), 6.8, true, PDF_TEXTO);
    $y = $y0 + $rh;
    foreach (['', '1ª Ent', '1ª Saí', '2ª Ent', '2ª Saí'] as $k => $txt) {
        $x = $k === 0 ? $tx : $tx + $c0 + ($k - 1) * $cw;
        $w = $k === 0 ? $c0 : $cw;
        $pdf->ret($x, $y, $w, $hl, PDF_CINZA, PDF_BORDA);
        $pdf->texto($x + 2.5, $y + 7.6, $txt, 6.2, true, PDF_TEXTO);
    }
    $y += $hl;
    foreach (ponto_espelho_turno_linhas($e) as $linha) {
        foreach ($linha as $k => $txt) {
            $x = $k === 0 ? $tx : $tx + $c0 + ($k - 1) * $cw;
            $w = $k === 0 ? $c0 : $cw;
            $pdf->ret($x, $y, $w, $hl, $k === 0 ? PDF_CINZA : null, PDF_BORDA);
            $pdf->texto($x + 2.5, $y + 7.6, $txt, 6.2, $k === 0, PDF_TEXTO);
        }
        $y += $hl;
    }
    $y = max($y, $y0 + 8 * $rh) + 8.0;

    // ---- aviso quando o colaborador nao tem turno
    if ($e['sem_turno']) {
        $pdf->texto($ml, $y + 6.0, 'Colaborador sem turno cadastrado: crédito, débito e saldo não são calculados.', 6.6, true, PDF_VERMELHO);
        $y += 11.0;
    }

    // ---- tabela
    $larguras = [66.0, 33.0, 33.0, 33.0, 33.0, 31.0, 31.0, 33.0, 33.0, 33.0, 35.0, 27.0, 33.0];
    $larguras[] = ($xd - $ml) - array_sum($larguras);
    $xs = [$ml];
    foreach ($larguras as $w) {
        $xs[] = end($xs) + $w;
    }
    $y = ponto_pdf_cabecalho_tabela($pdf, $ml, $y, $larguras);
    $tam = 6.4;
    foreach ($e['linhas'] as $l) {
        $cel = ponto_espelho_celulas($l, $e['sem_turno']);
        $motivo = $pdf->quebrar($cel[13], $larguras[13] - 5.0, $tam);
        $rowH = max(9.8, 3.0 + count($motivo) * 7.2);
        if ($y + $rowH > $limite) {
            $pdf->novaPagina();
            $y = ponto_pdf_cabecalho_tabela($pdf, $ml, 40.0, $larguras);
        }
        $sombra = in_array($l['tipo'], ['folga', 'feriado', 'na', 'futuro'], true) ? PDF_CINZA_DIA : null;
        $yb = $y + ($rowH + $tam * 0.7) / 2 - ($rowH > 9.8 ? ($rowH - 9.8) / 2 : 0);
        for ($c = 0; $c < 14; $c++) {
            $pdf->ret($xs[$c], $y, $larguras[$c], $rowH, $sombra, PDF_BORDA, 0.3);
        }
        $pdf->texto($xs[0] + 2.5, $yb, $cel[0], $tam, false, PDF_TEXTO);
        foreach ([1 => 'ent1', 2 => 'sai1', 3 => 'ent2', 4 => 'sai2'] as $c => $slot) {
            if ($cel[$c] === '') {
                continue;
            }
            $manual = !empty($l['manual'][$slot]);
            $pdf->texto($xs[$c] + 2.5, $yb, $cel[$c], $tam, false, $manual ? PDF_VERMELHO : PDF_TEXTO);
            if ($manual) {
                $pdf->linha($xs[$c] + 2.5, $yb + 1.4, $xs[$c] + 2.5 + $pdf->largura($cel[$c], $tam), $yb + 1.4, PDF_VERMELHO, 0.5);
            }
        }
        for ($c = 5; $c <= 12; $c++) {
            $negativo = $c === 12 && $l['saldo'] !== null && $l['saldo'] < 0;
            $pdf->texto($xs[$c] + 2.5, $yb, $cel[$c], $tam, false, $negativo ? PDF_VERMELHO : PDF_TEXTO);
        }
        foreach ($motivo as $k => $linhaMotivo) {
            $pdf->texto($xs[13] + 2.5, $yb + $k * 7.2, $linhaMotivo, $tam, false, PDF_TEXTO);
        }
        $y += $rowH;
    }
    // ---- totais
    if ($y + 12.0 > $limite) {
        $pdf->novaPagina();
        $y = ponto_pdf_cabecalho_tabela($pdf, $ml, 40.0, $larguras);
    }
    $tot = ponto_espelho_totais_celulas($e);
    $pdf->ret($xs[0], $y, $xs[5] - $xs[0], 11.0, PDF_CINZA_CAB, PDF_BORDA, 0.3);
    $pdf->texto($xs[0] + 2.5, $y + 7.8, 'TOTAIS', $tam, true, PDF_TEXTO);
    for ($c = 5; $c <= 13; $c++) {
        $pdf->ret($xs[$c], $y, $larguras[$c], 11.0, PDF_CINZA_CAB, PDF_BORDA, 0.3);
        $pdf->texto($xs[$c] + 2.5, $y + 7.8, $tot[$c], $tam, true, PDF_TEXTO);
    }
    $y += 11.0 + 14.0;

    // ---- rodape: aceite (esquerda) e legenda (direita)
    $legenda = ponto_espelho_legenda($e);
    $xl = $ml + 285.0;
    $wl = $xd - $xl;
    $altLegenda = 14.0;
    $linhasLegenda = [];
    foreach ($legenda as $texto) {
        $q = $pdf->quebrar($texto, $wl - 4.0, 6.2);
        $linhasLegenda[] = $q;
        $altLegenda += count($q) * 7.4 + 1.6;
    }
    if ($y + max($altLegenda, 100.0) > $limite) {
        $pdf->novaPagina();
        $y = 40.0;
    }
    $pdf->texto($xl, $y + 8.0, 'LEGENDA', 7.2, true, PDF_TEXTO);
    $yl = $y + 17.0;
    foreach ($linhasLegenda as $q) {
        foreach ($q as $linha) {
            $pdf->texto($xl, $yl, $linha, 6.2, false, PDF_TEXTO);
            $yl += 7.4;
        }
        $yl += 1.6;
    }

    $pdf->texto($ml, $y + 10.0, 'Comprovante de aceite eletrônico', 9.5, true, PDF_TEXTO);
    $ya = $y + 22.0;
    $a = $e['aceite'];
    if ($a === null) {
        $pdf->texto($ml, $ya, 'Aceite pendente: o colaborador ainda não aceitou este espelho.', 6.8, false, PDF_TEXTO);
        $pdf->texto($ml, $ya + 32.0, 'Assinatura do colaborador:', 6.8, true, PDF_TEXTO);
        $pdf->linha($ml + 98.0, $ya + 33.0, $ml + 250.0, $ya + 33.0, PDF_TEXTO, 0.5);
        $pdf->texto($ml, $ya + 50.0, 'Data: ____ / ____ / ________', 6.8, false, PDF_TEXTO);
    } else {
        $pdf->texto($ml, $ya, 'Assinatura', 7.5, true, PDF_TEXTO);
        $pdf->texto($ml, $ya + 10.0, ponto_pdf_cortar($pdf, $f['nome'] . ' (Colaborador)', 270.0, 7.2, true), 7.2, true, PDF_TEXTO);
        $pdf->texto($ml, $ya + 20.0, 'Data/Hora: ' . preg_replace('/ /', ' às ', $a['aceito_em'], 1), 6.8, false, PDF_TEXTO);
        $pdf->texto($ml, $ya + 29.0, 'Protocolo: ' . $a['protocolo'], 6.8, false, PDF_TEXTO);
        $pdf->texto($ml, $ya + 38.0, 'IP: ' . $a['ip'] . ' / Plataforma: ' . $a['plataforma'], 6.8, false, PDF_TEXTO);
        $pdf->texto($ml, $ya + 49.0, 'Aceite eletrônico feito pelo próprio colaborador, autenticado por login e senha.', 6.0, false, [0.4, 0.4, 0.4]);
        if ($a['alterado_depois']) {
            $k = 0;
            foreach ($pdf->quebrar('ATENÇÃO: o espelho foi alterado depois deste aceite; é preciso aceitar de novo.', 270.0, 6.8, true) as $linha) {
                $pdf->texto($ml, $ya + 61.0 + $k * 8.0, $linha, 6.8, true, PDF_VERMELHO);
                $k++;
            }
        }
    }
}

/** PDF com 1 espelho por colaborador (uma ou mais paginas cada). */
function ponto_pdf_espelhos(array $espelhos, string $emitido_por, string $emitido_em): string
{
    $pdf = new PontoPdf();
    $pdf->rodape('Documento gerado pelo sistema de ponto | página {p} de {n}');
    foreach (array_values($espelhos) as $i => $e) {
        if ($i > 0) {
            $pdf->novaPagina();
        }
        ponto_pdf_espelho($pdf, $e, $emitido_por, $emitido_em);
    }
    return $pdf->gerar();
}
