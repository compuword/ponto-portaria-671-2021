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

/**
 * Documento PDF com layout livre (A4 retrato), sem biblioteca externa: texto Helvetica/Helvetica-Bold (WinAnsi, acentos
 * em portugues), retangulos, linhas e imagem JPEG. Coordenadas em pontos, origem no CANTO SUPERIOR ESQUERDO (y cresce
 * para baixo). Usado pelo espelho de ponto (espelho_saida.php).
 */
final class PontoPdf
{
    public const LARGURA = 595.28;
    public const ALTURA = 841.89;

    /** Larguras (1/1000 em) da Helvetica para os codigos 32..126 (AFM padrao). */
    private const HELV = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015,
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
        278, 278, 278, 469, 556, 333,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500,
        334, 260, 334, 584,
    ];

    /** Larguras de acentuadas comuns (bytes da CP1252); o resto cai em 556. */
    private const EXTRA = [
        0xE1 => 556, 0xE0 => 556, 0xE2 => 556, 0xE3 => 556, 0xE4 => 556, 0xE9 => 556, 0xE8 => 556, 0xEA => 556,
        0xED => 278, 0xEC => 278, 0xF3 => 556, 0xF2 => 556, 0xF4 => 556, 0xF5 => 556, 0xFA => 556, 0xFC => 556,
        0xE7 => 500, 0xF1 => 556, 0xC1 => 667, 0xC0 => 667, 0xC2 => 667, 0xC3 => 667, 0xC9 => 667, 0xCA => 667,
        0xCD => 278, 0xD3 => 778, 0xD4 => 778, 0xD5 => 778, 0xDA => 722, 0xC7 => 722, 0xBA => 365, 0xAA => 370,
        0x96 => 556, 0x97 => 1000, 0xD7 => 584,
    ];

    /** @var string[] conteudo (operadores PDF) de cada pagina */
    private array $paginas = [];
    private string $c = '';
    /** @var array<string,array{jpeg:string,w:int,h:int}> */
    private array $imagens = [];
    private string $rodape = '';

    public function __construct()
    {
        $this->novaPagina();
    }

    /** Encerra a pagina atual (se tiver conteudo) e comeca outra. */
    public function novaPagina(): void
    {
        if ($this->c !== '') {
            $this->paginas[] = $this->c;
            $this->c = '';
        }
    }

    /** Texto do rodape de todas as paginas; {p} = pagina, {n} = total. */
    public function rodape(string $modelo): void
    {
        $this->rodape = $modelo;
    }

    private static function cp1252(string $s): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        }
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $r === false ? (string)preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
    }

    /** Largura do texto (UTF-8) em pontos. Negrito usa um fator medio (a Helvetica-Bold e ~6% mais larga). */
    public function largura(string $s, float $tam, bool $negrito = false): float
    {
        $b = self::cp1252($s);
        $w = 0;
        for ($i = 0, $n = strlen($b); $i < $n; $i++) {
            $c = ord($b[$i]);
            $w += ($c >= 32 && $c <= 126) ? self::HELV[$c - 32] : (self::EXTRA[$c] ?? 556);
        }
        return $w / 1000 * $tam * ($negrito ? 1.06 : 1.0);
    }

    /** Quebra o texto em linhas que cabem em $larguraMax (quebra em espacos; palavra maior que a largura fica inteira). */
    public function quebrar(string $s, float $larguraMax, float $tam, bool $negrito = false): array
    {
        $linhas = [];
        $atual = '';
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $palavra) {
            if ($palavra === '') {
                continue;
            }
            $teste = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if ($atual !== '' && $this->largura($teste, $tam, $negrito) > $larguraMax) {
                $linhas[] = $atual;
                $atual = $palavra;
            } else {
                $atual = $teste;
            }
        }
        if ($atual !== '') {
            $linhas[] = $atual;
        }
        return $linhas === [] ? [''] : $linhas;
    }

    /** Texto com a LINHA DE BASE em $yBase (origem no topo). $cor = [r,g,b] de 0 a 1. */
    public function texto(float $x, float $yBase, string $s, float $tam = 7.0, bool $negrito = false, array $cor = [0, 0, 0]): void
    {
        if ($s === '') {
            return;
        }
        $this->c .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $negrito ? 'F2' : 'F1', $tam, $cor[0], $cor[1], $cor[2], $x, self::ALTURA - $yBase, pdf_escapar(self::cp1252($s))
        );
    }

    /** Texto alinhado a direita (termina em $xDireita). */
    public function textoDireita(float $xDireita, float $yBase, string $s, float $tam = 7.0, bool $negrito = false, array $cor = [0, 0, 0]): void
    {
        $this->texto($xDireita - $this->largura($s, $tam, $negrito), $yBase, $s, $tam, $negrito, $cor);
    }

    /** Retangulo com $x,$y no canto superior esquerdo; $fill/$stroke = [r,g,b] ou null. */
    public function ret(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $espessura = 0.4): void
    {
        $yp = self::ALTURA - $y - $h;
        if ($fill !== null) {
            $this->c .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $fill[0], $fill[1], $fill[2], $x, $yp, $w, $h);
        }
        if ($stroke !== null) {
            $this->c .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n", $stroke[0], $stroke[1], $stroke[2], $espessura, $x, $yp, $w, $h);
        }
    }

    public function linha(float $x1, float $y1, float $x2, float $y2, array $cor = [0, 0, 0], float $espessura = 0.4): void
    {
        $this->c .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $cor[0], $cor[1], $cor[2], $espessura, $x1, self::ALTURA - $y1, $x2, self::ALTURA - $y2
        );
    }

    /** Desenha um JPEG ($x,$y = canto superior esquerdo) com a largura/altura pedidas em pontos. */
    public function imagemJpeg(string $nome, string $jpeg, int $pxLargura, int $pxAltura, float $x, float $y, float $w, float $h): void
    {
        $this->imagens[$nome] = ['jpeg' => $jpeg, 'w' => $pxLargura, 'h' => $pxAltura];
        $this->c .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, self::ALTURA - $y - $h, $nome);
    }

    public function gerar(): string
    {
        $paginas = $this->paginas;
        $paginas[] = $this->c;
        $total = count($paginas);
        if ($this->rodape !== '') {
            foreach ($paginas as $i => $conteudo) {
                $texto = str_replace(['{p}', '{n}'], [(string)($i + 1), (string)$total], $this->rodape);
                $paginas[$i] = $conteudo . sprintf(
                    "BT /F1 6.00 Tf 0.45 0.45 0.45 rg %.2F %.2F Td (%s) Tj ET\n",
                    28, 18, pdf_escapar(self::cp1252($texto))
                );
            }
        }

        $obj = [];
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $prox = 5;
        $xobj = '';
        foreach ($this->imagens as $nome => $img) {
            $id = $prox++;
            $obj[$id] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $img['w'], $img['h'], strlen($img['jpeg']), $img['jpeg']
            );
            $xobj .= "/{$nome} {$id} 0 R ";
        }
        $recursos = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xobj !== '' ? " /XObject << {$xobj}>>" : '') . ' >>';
        $kids = [];
        foreach ($paginas as $conteudo) {
            $idPag = $prox++;
            $idCont = $prox++;
            $kids[] = "{$idPag} 0 R";
            $obj[$idPag] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', self::LARGURA, self::ALTURA, $recursos, $idCont);
            $obj[$idCont] = '<< /Length ' . strlen($conteudo) . " >>\nstream\n{$conteudo}\nendstream";
        }
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($obj);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($obj as $id => $corpo) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$corpo}\nendobj\n";
        }
        $n = $prox;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 {$n}\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        return $pdf . "trailer\n<< /Size {$n} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}

/**
 * Logo da empresa (public/img/logo.png) convertida em JPEG sobre fundo branco (o PDF nao carrega o canal alfa do PNG sem
 * decodifica-lo); null se nao houver logo ou a extensao GD. Cache por requisicao.
 */
function pdf_logo_jpeg(): ?array
{
    static $logo = false;
    if ($logo !== false) {
        return $logo;
    }
    $logo = null;
    $caminho = __DIR__ . '/public/img/logo.png';
    if (!is_file($caminho) || !function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
        return $logo;
    }
    $png = @imagecreatefrompng($caminho);
    if ($png === false) {
        return $logo;
    }
    $w = imagesx($png);
    $h = imagesy($png);
    $max = 600; // reduz para o PDF nao ficar pesado
    if ($w > $max) {
        $h = (int)round($h * $max / $w);
        $w = $max;
    }
    $tela = imagecreatetruecolor($w, $h);
    imagefill($tela, 0, 0, (int)imagecolorallocate($tela, 255, 255, 255));
    imagecopyresampled($tela, $png, 0, 0, 0, 0, $w, $h, imagesx($png), imagesy($png));
    ob_start();
    imagejpeg($tela, null, 88);
    $jpeg = (string)ob_get_clean();
    return $logo = ['jpeg' => $jpeg, 'w' => $w, 'h' => $h];
}
