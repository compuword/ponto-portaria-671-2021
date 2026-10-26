<?php
declare(strict_types=1);

function xlsx_coluna(int $n): string
{
    $letras = '';
    while ($n > 0) {
        $n--;
        $letras = chr(65 + ($n % 26)) . $letras;
        $n = intdiv($n, 26);
    }
    return $letras;
}

/** Estilos disponiveis para as celulas: ['v' => valor, 'e' => 'nome do estilo'] (ver xlsx_estilos_xml). */
const XLSX_ESTILOS = [
    'padrao' => 0, 'titulo' => 1, 'negrito' => 2, 'rotulo' => 3, 'valor' => 4, 'cab' => 5, 'cel' => 6,
    'centro' => 7, 'manual' => 8, 'total' => 9, 'cinza' => 10, 'cinza_esq' => 11, 'nota' => 12,
];

function xlsx_estilos_xml(): string
{
    $borda = '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
        . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>';
    $xf = static fn(int $fonte, int $fill, int $borda, string $alinha = ''): string =>
        '<xf numFmtId="0" fontId="' . $fonte . '" fillId="' . $fill . '" borderId="' . $borda . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1"'
        . ($alinha !== '' ? ' applyAlignment="1">' . $alinha . '</xf>' : '/>');
    $esq = '<alignment horizontal="left" vertical="center" wrapText="1"/>';
    $cen = '<alignment horizontal="center" vertical="center"/>';
    $cab = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="6">'
        . '<font><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><sz val="14"/><name val="Arial"/></font>'
        . '<font><b/><sz val="10"/><name val="Arial"/></font>'
        . '<font><u/><sz val="10"/><color rgb="FFC62828"/><name val="Arial"/></font>'
        . '<font><sz val="10"/><color rgb="FF7F7F7F"/><name val="Arial"/></font>'
        . '<font><i/><sz val="8"/><color rgb="FF595959"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $borda . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="13">'
        . $xf(0, 0, 0) . $xf(1, 0, 0) . $xf(2, 0, 0) . $xf(2, 2, 1, $esq) . $xf(2, 0, 1, $esq) . $xf(2, 3, 1, $cab)
        . $xf(0, 0, 1, $esq) . $xf(0, 0, 1, $cen) . $xf(3, 0, 1, $cen) . $xf(2, 2, 1, $cen) . $xf(4, 2, 1, $cen) . $xf(4, 2, 1, $esq)
        . $xf(5, 0, 0, '<alignment horizontal="left" vertical="top" wrapText="0"/>')
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** Nome de aba valido (ate 31 caracteres, sem []:*?/\), unico entre as abas ja usadas. */
function xlsx_nome_aba(string $nome, array &$usados): string
{
    $limpo = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $nome)));
    $limpo = $limpo === '' ? 'Planilha' : $limpo;
    $base = mb_substr($limpo, 0, 31);
    $final = $base;
    for ($i = 2; in_array(mb_strtolower($final), $usados, true); $i++) {
        $sufixo = ' (' . $i . ')';
        $final = mb_substr($base, 0, 31 - mb_strlen($sufixo)) . $sufixo;
    }
    $usados[] = mb_strtolower($final);
    return $final;
}

/**
 * Gera um .xlsx com varias abas, estilos e larguras de coluna. Sem dependencia externa -- so ZipArchive.
 * $abas: lista de ['nome' => string, 'linhas' => [[celula, ...], ...], 'larguras' => [largura da col 1, ...],
 *        'mesclas' => ['A1:D1', ...], 'retrato' => bool]; celula = escalar ou ['v' => valor, 'e' => estilo de XLSX_ESTILOS].
 */
function xlsx_escrever_abas(string $caminho, array $abas): void
{
    $zip = new ZipArchive();
    $zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $sheets = '';
    $rels = '';
    $usados = [];
    foreach ($abas as $i => $aba) {
        $n = $i + 1;
        $tipos .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $nome = htmlspecialchars(xlsx_nome_aba((string)($aba['nome'] ?? 'Planilha'), $usados), ENT_XML1, 'UTF-8');
        $sheets .= '<sheet name="' . $nome . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $estilosId = count($abas) + 1;
    $rels .= '<Relationship Id="rId' . $estilosId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('[Content_Types].xml', $tipos . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->addFromString('xl/styles.xml', xlsx_estilos_xml());

    foreach ($abas as $i => $aba) {
        $linhasXml = '';
        $maxCol = 1;
        foreach ($aba['linhas'] as $r => $linha) {
            $celulas = '';
            foreach (array_values($linha) as $c => $celula) {
                $estilo = 0;
                $valor = $celula;
                if (is_array($celula)) {
                    $valor = $celula['v'] ?? '';
                    $estilo = XLSX_ESTILOS[$celula['e'] ?? 'padrao'] ?? 0;
                }
                $ref = xlsx_coluna($c + 1) . ($r + 1);
                $maxCol = max($maxCol, $c + 1);
                $s = $estilo > 0 ? ' s="' . $estilo . '"' : '';
                if ($valor === '' || $valor === null) {
                    if ($estilo > 0) {
                        $celulas .= '<c r="' . $ref . '"' . $s . '/>';
                    }
                } elseif (is_int($valor) || is_float($valor)) {
                    $celulas .= '<c r="' . $ref . '"' . $s . '><v>' . $valor . '</v></c>';
                } else {
                    $celulas .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars((string)$valor, ENT_XML1, 'UTF-8') . '</t></is></c>';
                }
            }
            $linhasXml .= '<row r="' . ($r + 1) . '">' . $celulas . '</row>';
        }

        $cols = '';
        foreach (($aba['larguras'] ?? []) as $c => $largura) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $largura . '" customWidth="1"/>';
        }
        $mesclas = '';
        $qtd = 0;
        foreach (($aba['mesclas'] ?? []) as $m) {
            $mesclas .= '<mergeCell ref="' . $m . '"/>';
            $qtd++;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="14"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $linhasXml . '</sheetData>'
            . ($qtd > 0 ? '<mergeCells count="' . $qtd . '">' . $mesclas . '</mergeCells>' : '')
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="' . (($aba['retrato'] ?? true) ? 'portrait' : 'landscape') . '" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
    }
    $zip->close();
}

/**
 * Gera um .xlsx de 1 aba a partir de uma lista de linhas (cada linha = array de valores), sem estilos.
 * Sem dependencia externa -- so ZipArchive (ja vem no PHP core em quase toda distro).
 */
function xlsx_escrever(string $caminho, array $linhas): void
{
    xlsx_escrever_abas($caminho, [['nome' => 'Ponto', 'linhas' => $linhas]]);
}
