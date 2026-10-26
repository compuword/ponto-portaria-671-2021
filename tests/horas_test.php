<?php
declare(strict_types=1);

// Testes do calculo de horas do dia com atestado PARCIAL (consulta/exame no mesmo dia). Nao usam banco.
// Uso: php tests/horas_test.php   (sai com codigo 1 se algum teste falhar)
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

function dia(?string $entrada, ?string $pausa, ?string $retorno, ?string $saida): array
{
    return ['entrada' => $entrada, 'almoco' => $pausa, 'retorno' => $retorno, 'saida' => $saida, 'obs' => []];
}

// Dia comum, sem atestado
$r = ponto_horas_do_dia(dia('08:00', '12:00', '13:00', '17:00'), []);
confere('dia comum: 8:00 trabalhadas', '8:00', $r['horas']);
confere('dia comum: sem abono', '', $r['abono']);

// Caso pedido: sai 11:00 para o medico, volta 12:00; pausa 12:30, retorno 13:30
$r = ponto_horas_do_dia(dia('08:00', '12:30', '13:30', '17:30'), [['ini' => '11:00', 'fim' => '12:00']]);
confere('consulta 11:00-12:00: horas descontadas (8:30 das batidas - 1:00 de consulta)', '7:30', $r['horas']);
confere('consulta 11:00-12:00: abono 1:00', '1:00', $r['abono']);

// Chegou mais tarde por causa de uma consulta (intervalo antes da entrada): nada a descontar, mas ha abono
$r = ponto_horas_do_dia(dia('10:00', '12:00', '13:00', '18:00'), [['ini' => '08:00', 'fim' => '10:00']]);
confere('consulta antes da entrada: horas intactas', '7:00', $r['horas']);
confere('consulta antes da entrada: abono 2:00', '2:00', $r['abono']);

// Intervalo que cai todo dentro da pausa: nao desconta nada
$r = ponto_horas_do_dia(dia('08:00', '12:00', '13:00', '17:00'), [['ini' => '12:00', 'fim' => '13:00']]);
confere('consulta durante a pausa: horas intactas', '8:00', $r['horas']);
confere('consulta durante a pausa: abono 1:00', '1:00', $r['abono']);

// Intervalo que atravessa a pausa: desconta so a parte dentro dos blocos trabalhados
$r = ponto_horas_do_dia(dia('08:00', '12:00', '13:00', '17:00'), [['ini' => '11:30', 'fim' => '13:45']]);
confere('consulta atravessa a pausa: 8:00 - 0:30 - 0:45', '6:45', $r['horas']);
confere('consulta atravessa a pausa: abono 2:15', '2:15', $r['abono']);

// Dois atestados que se sobrepoem contam uma vez so
$r = ponto_horas_do_dia(dia('08:00', '12:00', '13:00', '17:00'), [['ini' => '10:00', 'fim' => '11:00'], ['ini' => '10:30', 'fim' => '11:30']]);
confere('atestados sobrepostos: une 10:00-11:30', '6:30', $r['horas']);
confere('atestados sobrepostos: abono 1:30', '1:30', $r['abono']);

// Dois atestados separados no mesmo dia
$r = ponto_horas_do_dia(dia('08:00', '12:00', '13:00', '17:00'), [['ini' => '09:00', 'fim' => '09:30'], ['ini' => '15:00', 'fim' => '16:00']]);
confere('dois atestados no dia: 8:00 - 0:30 - 1:00', '6:30', $r['horas']);
confere('dois atestados no dia: abono 1:30', '1:30', $r['abono']);

// Batidas incompletas continuam "Incompleto", mas o abono aparece
$r = ponto_horas_do_dia(dia('08:00', null, null, null), [['ini' => '11:00', 'fim' => '12:00']]);
confere('batida incompleta: Incompleto', 'Incompleto', $r['horas']);
confere('batida incompleta: abono 1:00', '1:00', $r['abono']);

// Sem batidas, so o atestado
$r = ponto_horas_do_dia(dia(null, null, null, null), [['ini' => '11:00', 'fim' => '12:00']]);
confere('so atestado: horas vazias', '', $r['horas']);
confere('so atestado: abono 1:00', '1:00', $r['abono']);

// Nunca fica negativo
$r = ponto_horas_do_dia(dia('08:00', '09:00', '09:30', '10:00'), [['ini' => '08:00', 'fim' => '10:00']]);
confere('atestado cobre tudo: horas 0:00 (nao negativas)', '0:00', $r['horas']);

// Auxiliares
confere('uniao junta intervalos que se tocam', [[600, 720]], ponto_uniao_intervalos([[600, 660], [660, 720]]));
confere('uniao ignora intervalo invertido', [[600, 660]], ponto_uniao_intervalos([[600, 660], [700, 650]]));
confere('minutos dentro do intervalo', 30, ponto_minutos_dentro([[660, 720]], 690, 800));

// Validacao das horas digitadas no formulario
confere('horas validas', ['11:00:00', '12:00:00', null], ponto_validar_horas_atestado('11:00', '12:00'));
confere('horas validas com segundos', ['11:00:00', '12:30:00', null], ponto_validar_horas_atestado('11:00:00', '12:30:00'));
confere('volta antes da saida e recusada', true, ponto_validar_horas_atestado('12:00', '11:00')[2] !== null);
confere('volta igual a saida e recusada', true, ponto_validar_horas_atestado('11:00', '11:00')[2] !== null);
confere('hora vazia e recusada', true, ponto_validar_horas_atestado('', '12:00')[2] !== null);
confere('hora invalida e recusada', true, ponto_validar_horas_atestado('25:00', '26:00')[2] !== null);

exit($falhas > 0 ? 1 : 0);
