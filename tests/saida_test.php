<?php
declare(strict_types=1);

// Testes das saidas do espelho (tela, CSV, XLSX e PDF) com um espelho de exemplo, sem banco.
// Uso: php tests/saida_test.php [pasta para gravar as amostras .pdf/.xlsx/.csv/.html]
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/apuracao.php';
require __DIR__ . '/../src/espelho.php';
require __DIR__ . '/../src/pdf.php';
require __DIR__ . '/../src/xlsx.php';
require __DIR__ . '/../src/espelho_saida.php';
require __DIR__ . '/../src/espelho_pdf.php';
require __DIR__ . '/fixtures/espelho_exemplo.php';

$falhas = 0;
$amostras = $argv[1] ?? null;

function confere(string $nome, mixed $esperado, mixed $obtido): void
{
    global $falhas;
    if ($esperado === $obtido) {
        echo "ok     $nome\n";
        return;
    }
    $falhas++;
    echo "FALHOU $nome: esperado " . var_export($esperado, true) . ", obtido " . var_export($obtido, true) . "\n";
}

$e = espelho_exemplo(['aceite' => true, 'manual' => true, 'parcial' => true]);

// ---- celulas e totais
$cel = ponto_espelho_celulas($e['linhas'][0]);
confere('celulas do dia 01/10: 14 colunas', 14, count($cel));
confere('celulas do dia 01/10: data no formato do modelo', 'Sex, 01/10/2021', $cel[0]);
confere('celulas do dia 01/10: debito 00:44 e saldo 24:21', ['00:44', '24:21'], [$cel[6], $cel[12]]);
confere('totais: 14 colunas', 14, count(ponto_espelho_totais_celulas($e)));
$tot = ponto_espelho_totais_celulas(espelho_exemplo());
confere('totais do modelo (sem os ajustes do teste): horas normais 150:01', '150:01', $tot[8]);
confere('colaborador sem turno: credito, debito e saldo ficam em branco', ['', '', ''], [ponto_espelho_celulas(espelho_exemplo(['sem_turno' => true])['linhas'][0], true)[5], ponto_espelho_celulas(espelho_exemplo(['sem_turno' => true])['linhas'][0], true)[6], ponto_espelho_celulas(espelho_exemplo(['sem_turno' => true])['linhas'][0], true)[12]]);
confere('turno: 7 linhas (Dom..Sab) e domingo e folga', ['Dom', '-'], array_slice(ponto_espelho_turno_linhas($e)[0], 0, 2));
confere('identificacao: CNPJ e CPF formatados', ['11.222.333/0001-81', '529.982.247-25'], [ponto_espelho_identificacao($e)['CNPJ'], ponto_espelho_identificacao($e)['CPF']]);
confere('legenda cita o fator do banco de horas', true, str_contains(ponto_espelho_legenda($e)[0], '1.0x'));

// ---- tela
$html = ponto_espelho_html($e);
confere('HTML: titulo, TOTAIS e legenda', [true, true, true], [str_contains($html, 'Espelho ponto'), str_contains($html, 'TOTAIS'), str_contains($html, 'Legenda')]);
confere('HTML: batida manual marcada', true, str_contains($html, 'class="manual"'));
confere('HTML: aceite com protocolo', true, str_contains($html, 'e57ee16d4df0958273db9765bb9cc322'));
confere('HTML: motivo do atestado parcial', true, str_contains($html, 'Atestado parcial 10:00-11:00 (CID Z00)'));
confere('HTML escapa o nome', false, str_contains(ponto_espelho_html(array_replace_recursive($e, ['funcionario' => ['nome' => '<script>x</script>']])), '<script>x'));
confere('HTML: aviso quando alterado depois do aceite', true, str_contains(ponto_espelho_html(espelho_exemplo(['aceite' => true, 'alterado' => true])), 'foi alterado depois deste aceite'));
confere('HTML: aviso de colaborador sem turno', true, str_contains(ponto_espelho_html(espelho_exemplo(['sem_turno' => true])), 'ainda não tem turno cadastrado'));

// ---- CSV
$csv = ponto_espelho_csv_linhas([$e, espelho_exemplo()]);
confere('CSV: 1 cabecalho + (31 dias + TOTAIS) x 2 colaboradores', 1 + 2 * 32, count($csv));
confere('CSV: todas as linhas com 23 colunas', true, count(array_unique(array_map('count', $csv))) === 1 && count($csv[0]) === 23);
confere('CSV: CPF e PIS formatados na linha do dia', ['529.982.247-25', '120.12345.67-2'], [$csv[1][1], $csv[1][2]]);
confere('CSV: data e dia da semana separados', ['01/10/2021', 'Sex'], [$csv[1][5], $csv[1][6]]);
$linha04 = array_values(array_filter($csv, static fn(array $l): bool => $l[5] === '04/10/2021'))[0];
confere('CSV: batidas manuais listadas', '1ª Saída, 2ª Entrada', $linha04[20]);
$totais_csv = array_values(array_filter($csv, static fn(array $l): bool => $l[5] === 'TOTAIS'))[1];
confere('CSV: linha TOTAIS (sem os ajustes): horas normais 150:01 e saldo 18:09', ['150:01', '18:09'], [$totais_csv[14], $totais_csv[18]]);

// ---- XLSX
$xlsx = sys_get_temp_dir() . '/ponto_saida_teste.xlsx';
ponto_espelho_xlsx($xlsx, [$e, espelho_exemplo(['sem_turno' => true])], 'Teste', '03/12/2021 10:00');
$zip = new ZipArchive();
confere('XLSX abre como zip', true, $zip->open($xlsx) === true);
confere('XLSX tem Resumo + 2 abas + estilos', [true, true, true, true], [
    $zip->locateName('xl/worksheets/sheet1.xml') !== false, $zip->locateName('xl/worksheets/sheet3.xml') !== false,
    $zip->locateName('xl/worksheets/sheet4.xml') === false, $zip->locateName('xl/styles.xml') !== false,
]);
$wb = (string)$zip->getFromName('xl/workbook.xml');
confere('XLSX: abas Resumo e nome do colaborador', true, str_contains($wb, 'name="Resumo"') && str_contains($wb, 'Maria Exemplo da Silva'));
$s2 = (string)$zip->getFromName('xl/worksheets/sheet2.xml');
confere('XLSX: aba do colaborador com TOTAIS, legenda e celulas mescladas', [true, true, true], [str_contains($s2, 'TOTAIS'), str_contains($s2, 'Legenda'), str_contains($s2, '<mergeCell ref="A1:H1"/>')]);
confere('XLSX: XML bem formado (aba, estilos e workbook)', [true, true, true], [
    @simplexml_load_string($s2) !== false, @simplexml_load_string((string)$zip->getFromName('xl/styles.xml')) !== false, @simplexml_load_string($wb) !== false,
]);
$zip->close();
confere('XLSX: nome de aba invalido vira texto limpo e unico', ['Joao Silva', 'Joao Silva (2)'], (static function (): array {
    $u = [];
    return [xlsx_nome_aba('Joao/Silva', $u), xlsx_nome_aba('Joao: Silva', $u)];
})());

// ---- PDF
$pdf = ponto_pdf_espelhos([$e, espelho_exemplo(['sem_turno' => true]), espelho_exemplo(['aceite' => true, 'alterado' => true])], 'Teste', '03/12/2021 10:00');
confere('PDF: cabecalho e fim validos', ['%PDF-1.4', '%%EOF'], [substr($pdf, 0, 8), substr($pdf, -5)]);
confere('PDF: 3 espelhos = 3 paginas', 3, preg_match_all('#/Type /Page /#', $pdf));
confere('PDF: fontes Helvetica e Helvetica-Bold com WinAnsi', true, str_contains($pdf, '/BaseFont /Helvetica ') && str_contains($pdf, '/BaseFont /Helvetica-Bold ') && str_contains($pdf, '/WinAnsiEncoding'));
confere('PDF: texto do titulo e da legenda no conteudo', [true, true], [str_contains($pdf, '(Espelho ponto)'), str_contains($pdf, 'LEGENDA')]);
confere('PDF: acento convertido para WinAnsi (Crédito = Cr\\351dito)', true, str_contains($pdf, 'Cr' . chr(0xE9) . 'dito'));
confere('PDF: rodape com numero de paginas', true, str_contains($pdf, 'p' . chr(0xE1) . 'gina 1 de 3'));
// xref coerente: cada offset aponta para "N 0 obj"
preg_match('/startxref\n(\d+)\n/', $pdf, $m);
$xref = substr($pdf, (int)$m[1], 4);
confere('PDF: startxref aponta para a tabela xref', 'xref', $xref);
preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offs);
$ok = true;
foreach ($offs[1] as $i => $off) {
    if (!str_starts_with(substr($pdf, (int)$off, 12), ($i + 1) . ' 0 obj')) {
        $ok = false;
    }
}
confere('PDF: todos os offsets do xref apontam para o objeto certo', true, $ok);
$pw = new PontoPdf();
confere('PDF: largura de texto (digitos de 556): "08:31" a 10pt = 25,02', 25.02, round($pw->largura('08:31', 10.0), 2));
confere('PDF: quebra de linha respeita a largura', true, count($pw->quebrar('Dia de Nossa Senhora Aparecida', 60.0, 6.4)) === 2);
confere('PDF: tabela de larguras Helvetica com 95 entradas', 95, count((new ReflectionClassConstant(PontoPdf::class, 'HELV'))->getValue()));

if ($amostras !== null && is_dir($amostras)) {
    file_put_contents($amostras . '/amostra_espelho.pdf', ponto_pdf_espelhos([$e, espelho_exemplo(['sem_turno' => true]), espelho_exemplo(['aceite' => true, 'alterado' => true]), espelho_exemplo()], 'Teste de amostra', '03/12/2021 10:00'));
    ponto_espelho_xlsx($amostras . '/amostra_espelho.xlsx', [$e, espelho_exemplo(['sem_turno' => true])], 'Teste de amostra', '03/12/2021 10:00');
    $fh = fopen($amostras . '/amostra_espelho.csv', 'wb');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach ($csv as $linha) {
        fwrite($fh, implode(';', array_map(static fn($c) => str_replace(';', ',', (string)$c), $linha)) . "\r\n");
    }
    fclose($fh);
    file_put_contents($amostras . '/amostra_espelho.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="style.css"><body>' . $html . '</body>');
    echo "amostras gravadas em $amostras\n";
}
@unlink($xlsx);
exit($falhas > 0 ? 1 : 0);
