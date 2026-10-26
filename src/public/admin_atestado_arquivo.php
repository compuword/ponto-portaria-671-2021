<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config.php';

$admin = ponto_require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = ponto_db()->prepare('SELECT arquivo_nome, arquivo_tipo, arquivo_blob FROM dbo.atestados_medicos WHERE id = ?');
$stmt->execute([$id]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$a || $a['arquivo_blob'] === null) {
    http_response_code(404);
    exit('Arquivo nao encontrado.');
}

ponto_log($admin['id'], 'baixou_atestado', 'atestado id ' . $id);

// O ODBC Driver 18 devolve VARBINARY via PDO como string hexadecimal (sem
// prefixo 0x), nao como os bytes crus -- decodificar antes de servir.
$conteudo = (string)$a['arquivo_blob'];
if (ctype_xdigit($conteudo) && strlen($conteudo) % 2 === 0) {
    $conteudo = hex2bin($conteudo);
}

header('Content-Type: ' . ($a['arquivo_tipo'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . ($a['arquivo_nome'] ?: 'atestado') . '"');
header('Content-Length: ' . strlen($conteudo));
echo $conteudo;
