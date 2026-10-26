<?php
declare(strict_types=1);

// Testes dos helpers de fuso horario de src/config.php. Nao usam banco.
// Uso: php tests/fuso_test.php   (sai com codigo 1 se algum teste falhar)
require __DIR__ . '/../src/config.php';

$falhas = 0;

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

$sp = 'America/Sao_Paulo';

// Caso real: bateu o ponto as 08:00 em Brasilia, o banco guarda 11:00 UTC.
confere('UTC -> Sao Paulo', '2026-10-26 08:00:01', ponto_utc_para_local('2026-10-26 11:00:01', $sp));
confere('UTC com fracao de segundo', '2026-10-26 08:00:01', ponto_utc_para_local('2026-10-26 11:00:01.1234567', $sp));
confere('UTC no formato ISO com T', '2026-10-26 08:00:01', ponto_utc_para_local('2026-10-26T11:00:01.123', $sp));
confere('virada de dia (01:30 UTC e 22:30 do dia anterior)', '2026-10-25 22:30:00', ponto_utc_para_local('2026-10-26 01:30:00', $sp));
confere('outro fuso (Manaus, UTC-4)', '2026-10-26 07:00:01', ponto_utc_para_local('2026-10-26 11:00:01', 'America/Manaus'));
confere('formato brasileiro', '26/10/2026 08:00', ponto_utc_para_local('2026-10-26 11:00:01', $sp, 'd/m/Y H:i'));
confere('vazio fica vazio', '', ponto_utc_para_local('', $sp));
confere('null fica vazio', '', ponto_utc_para_local(null, $sp));

confere('local -> UTC', '2026-10-26 11:00:00', ponto_local_para_utc('2026-10-26 08:00:00', $sp));
confere('ida e volta', '2026-03-15 19:45:00', ponto_local_para_utc(ponto_utc_para_local('2026-03-15 19:45:00', $sp), $sp));

$referencia = new DateTimeImmutable('2026-10-26 22:30:00', new DateTimeZone($sp));
[$inicio, $fim] = ponto_limites_dia_utc($sp, $referencia);
confere('dia local comeca as 03:00 UTC', '2026-10-26 03:00:00', $inicio);
confere('dia local termina as 03:00 UTC do dia seguinte', '2026-10-27 03:00:00', $fim);

// 22:30 em Brasilia ja e o dia seguinte em UTC, mas ainda e o mesmo dia local
$ts_22h30 = ponto_local_para_utc('2026-10-26 22:30:00', $sp); // 2026-10-27 01:30:00 UTC
confere('22:30 local cai dentro do dia local', true, $ts_22h30 >= $inicio && $ts_22h30 < $fim);

confere('fuso valido', true, ponto_fuso_valido($sp));
confere('fuso invalido', false, ponto_fuso_valido('Marte/Olimpo'));
confere('fuso vazio cai no padrao', PONTO_FUSO_PADRAO, ponto_fuso_ou_padrao(''));
confere('fuso null cai no padrao', PONTO_FUSO_PADRAO, ponto_fuso_ou_padrao(null));
confere('fuso invalido cai no padrao', PONTO_FUSO_PADRAO, ponto_fuso_ou_padrao('Marte/Olimpo'));
confere('rotulo', 'America/Sao_Paulo (UTC-03:00)', ponto_rotulo_fuso($sp));
confere('Brasil vem primeiro e inclui Sao Paulo', true, in_array($sp, ponto_fusos_disponiveis()['Brasil'], true));

exit($falhas > 0 ? 1 : 0);
