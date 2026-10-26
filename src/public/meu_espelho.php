<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';
require __DIR__ . '/../apuracao.php';
require __DIR__ . '/../espelho.php';
require __DIR__ . '/../pdf.php';
require __DIR__ . '/../espelho_saida.php';
require __DIR__ . '/../espelho_pdf.php';

// PDF do espelho do PROPRIO colaborador (so o dele: o id vem da sessao, nunca da URL).
$func = ponto_require_login();
$fuso = ponto_fuso_do_funcionario($func['id']);
$agora_local = ponto_agora_local($fuso);
$ano = (int)($_GET['ano'] ?? $agora_local->format('Y'));
$mes = (int)($_GET['mes'] ?? $agora_local->format('n'));

$e = ponto_tem_espelho() ? ponto_espelho($func['id'], $ano, $mes) : null;
if ($e === null) {
    http_response_code(404);
    exit('Espelho indisponivel.');
}
$pdf = ponto_pdf_espelhos([$e], $func['nome'], $agora_local->format('d/m/Y H:i'));
ponto_log($func['id'], 'baixou_espelho', sprintf('%04d-%02d pdf', $e['periodo']['ano'], $e['periodo']['mes']));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="espelho_ponto_' . sprintf('%04d-%02d', $e['periodo']['ano'], $e['periodo']['mes']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
