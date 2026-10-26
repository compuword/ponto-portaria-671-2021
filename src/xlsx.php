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

/**
 * Gera um .xlsx de 1 aba a partir de uma lista de linhas (cada linha = array de valores).
 * Sem dependencia externa -- so ZipArchive (ja vem no PHP core em quase toda distro).
 */
function xlsx_escrever(string $caminho, array $linhas): void
{
    $zip = new ZipArchive();
    $zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Ponto" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '</Relationships>'
    );

    $rowsXml = '';
    $r = 1;
    foreach ($linhas as $linha) {
        $cellsXml = '';
        $c = 1;
        foreach ($linha as $valor) {
            $col = xlsx_coluna($c);
            if (is_int($valor) || is_float($valor)) {
                $cellsXml .= '<c r="' . $col . $r . '"><v>' . $valor . '</v></c>';
            } else {
                $texto = htmlspecialchars((string)$valor, ENT_XML1, 'UTF-8');
                $cellsXml .= '<c r="' . $col . $r . '" t="inlineStr"><is><t xml:space="preserve">' . $texto . '</t></is></c>';
            }
            $c++;
        }
        $rowsXml .= '<row r="' . $r . '">' . $cellsXml . '</row>';
        $r++;
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>' . $rowsXml . '</sheetData>' .
        '</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}
