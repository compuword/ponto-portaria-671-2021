<?php
declare(strict_types=1);

function pdf_escapar(string $s): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

/**
 * Gerador de PDF minimo, sem biblioteca externa -- so texto monoespacado
 * (Courier, fonte padrao do PDF, sem precisar embutir nada), com paginacao
 * automatica. Suficiente pra um relatorio tabular simples.
 */
function pdf_escrever_linhas(string $caminho, array $linhas): void
{
    $largura = 595;
    $altura = 842; // A4 em pontos
    $margem = 36;
    $tamanho_fonte = 8;
    $altura_linha = 11;
    $linhas_por_pagina = max(1, (int)floor(($altura - 2 * $margem) / $altura_linha) - 1);

    $paginas = [];
    $buffer = [];
    foreach ($linhas as $l) {
        $buffer[] = $l;
        if (count($buffer) >= $linhas_por_pagina) {
            $paginas[] = $buffer;
            $buffer = [];
        }
    }
    if ($buffer !== [] || $paginas === []) {
        $paginas[] = $buffer;
    }

    $objetos = [];
    $objetos[1] = null; // Pages, preenchido no final
    $objetos[2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

    $idsPaginas = [];
    $proxId = 3;

    foreach ($paginas as $pagLinhas) {
        $topo = $altura - $margem;
        $cmds = "BT /F1 {$tamanho_fonte} Tf {$margem} {$topo} Td {$altura_linha} TL\n";
        $primeira = true;
        foreach ($pagLinhas as $linha) {
            $texto = pdf_escapar($linha);
            if ($primeira) {
                $cmds .= "({$texto}) Tj\n";
                $primeira = false;
            } else {
                $cmds .= "T* ({$texto}) Tj\n";
            }
        }
        $cmds .= 'ET';

        $idPagina = $proxId++;
        $idConteudo = $proxId++;
        $idsPaginas[] = $idPagina;
        $objetos[$idPagina] = "<< /Type /Page /Parent 1 0 R /MediaBox [0 0 {$largura} {$altura}] /Resources << /Font << /F1 2 0 R >> >> /Contents {$idConteudo} 0 R >>";
        $objetos[$idConteudo] = "<< /Length " . strlen($cmds) . " >>\nstream\n{$cmds}\nendstream";
    }

    $kids = implode(' ', array_map(static fn($id) => "{$id} 0 R", $idsPaginas));
    $objetos[1] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($idsPaginas) . ' >>';

    $idCatalogo = $proxId++;
    $objetos[$idCatalogo] = "<< /Type /Catalog /Pages 1 0 R >>";

    ksort($objetos);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objetos as $id => $corpo) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$corpo}\nendobj\n";
    }

    $totalObjetos = $idCatalogo + 1;
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 {$totalObjetos}\n0000000000 65535 f \n";
    for ($i = 1; $i < $totalObjetos; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size {$totalObjetos} /Root {$idCatalogo} 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    file_put_contents($caminho, $pdf);
}
