<?php
declare(strict_types=1);

// Testes da apuracao do espelho de ponto (src/apuracao.php). Nao usam banco.
// Uso: php tests/apuracao_test.php   (sai com codigo 1 se algum teste falhar)
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/apuracao.php';

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

/** Contexto de um dia util com o turno de 8h (08:00-12:00 / 13:00-17:00), batidas dadas e extras opcionais. */
function ctx_util(array $batidas, array $extra = []): array
{
    return $extra + [
        'turno' => ['ent1' => '08:00', 'sai1' => '12:00', 'ent2' => '13:00', 'sai2' => '17:00'],
        'batidas' => ['ent1' => $batidas[0] ?? null, 'sai1' => $batidas[1] ?? null, 'ent2' => $batidas[2] ?? null, 'sai2' => $batidas[3] ?? null],
    ];
}

$segunda = '2026-10-19'; // segunda-feira
$sabado = '2026-10-24';
$domingo = '2026-10-25';

// ------------------------------------------------------------------
// 1) Espelho MODELO (10/2021): batidas e colunas conferidas uma a uma contra o PDF de referencia.
//    Turno seg-sex 08:30-12:00 / 13:30-18:00 (8:00), tolerancia 10 min, saldo inicial 25:05, feriado 12/10.
// ------------------------------------------------------------------
$modelo = require __DIR__ . '/fixtures/modelo_2021_10.php';
$turno_modelo = ['ent1' => '08:30', 'sai1' => '12:00', 'ent2' => '13:30', 'sai2' => '18:00'];
$feriados_modelo = ponto_feriados_nacionais(2021);
$ctxs = [];
foreach ($modelo as $m) {
    $dow = ponto_dow($m[0]);
    $ctxs[$m[0]] = [
        'turno' => ($dow >= 1 && $dow <= 5) ? $turno_modelo : null,
        'batidas' => ['ent1' => $m[1], 'sai1' => $m[2], 'ent2' => $m[3], 'sai2' => $m[4]],
        'feriado' => $feriados_modelo[$m[0]] ?? null,
    ];
}
$linhas = ponto_apurar_periodo($ctxs, ['tolerancia_min' => 10], 25 * 60 + 5);
$divergencias = 0;
foreach ($modelo as $i => $m) {
    $l = $linhas[$i];
    $obtido = [ponto_hhmm($l['credito']), ponto_hhmm($l['debito']), ponto_hhmm($l['intervalo']), ponto_hhmm($l['normais']), ponto_hhmm($l['he1']), ponto_hhmm((int)$l['saldo'])];
    $esperado = [$m[5], $m[6], $m[7], $m[8], $m[9], $m[10]];
    if ($obtido !== $esperado) {
        $divergencias++;
        echo "FALHOU modelo {$m[0]}: esperado " . implode(' ', $esperado) . " | obtido " . implode(' ', $obtido) . "\n";
    }
}
$falhas += $divergencias;
echo $divergencias === 0 ? "ok     modelo 10/2021: os 31 dias batem com o PDF (credito, debito, intervalo, normais, H.E.1, saldo)\n" : '';
$tot = ponto_apurar_totais($linhas);
confere('modelo: total credito', '03:03', ponto_hhmm($tot['credito']));
confere('modelo: total debito', '09:59', ponto_hhmm($tot['debito']));
confere('modelo: total H. intervalo', '28:57', ponto_hhmm($tot['intervalo']));
confere('modelo: total horas normais', '150:01', ponto_hhmm($tot['normais']));
confere('modelo: total H.E. 1', '03:03', ponto_hhmm($tot['he1']));
confere('modelo: total H.E. 2', '00:00', ponto_hhmm($tot['he2']));
confere('modelo: total A.N.', '00:00', ponto_hhmm($tot['an']));
confere('modelo: saldo final', '18:09', ponto_hhmm((int)$tot['saldo_final']));
confere('modelo: sabado e domingo = Folga', 'Folga', $linhas[1]['motivo']);
confere('modelo: 12/10 e o feriado de Nossa Senhora Aparecida', 'Nossa Senhora Aparecida', $linhas[11]['motivo']);
confere('modelo: 21/10 (so a 1a metade) e marcacao incompleta', true, $linhas[20]['incompleto']);
confere('modelo: 21/10 aparece como Marcação incompleta', 'Marcação incompleta', $linhas[20]['motivo']);

// ------------------------------------------------------------------
// 2) Tolerancia diaria
// ------------------------------------------------------------------
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '16:50']), []);   // -10
confere('tolerancia: -10 min nao gera debito', 0, $l['debito']);
confere('tolerancia: -10 min conta como 8:00 normais', 480, $l['normais']);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '16:49']), []);   // -11
confere('tolerancia: -11 min gera debito de 11 (inteiro)', 11, $l['debito']);
confere('tolerancia: -11 min normais = trabalhado', 469, $l['normais']);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:10']), []);   // +10
confere('tolerancia: +10 min nao gera credito', 0, $l['credito']);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:11']), []);   // +11
confere('tolerancia: +11 min gera credito de 11 (inteiro)', 11, $l['credito']);
confere('tolerancia: credito em dia util e H.E. 1', 11, $l['he1']);
confere('tolerancia configuravel (30 min): -30 nao gera debito', 0, ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '16:30']), ['tolerancia_min' => 30])['debito']);

// ------------------------------------------------------------------
// 3) Atestado parcial (consulta no meio do expediente)
// ------------------------------------------------------------------
$parcial = [['ini' => '11:00', 'fim' => '12:00']];
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['parciais' => $parcial]), []);
confere('consulta 11:00-12:00: trabalhado descontado (8:00 - 1:00)', 420, $l['trabalhado']);
confere('consulta 11:00-12:00: abono 1:00', 60, $l['abono']);
confere('consulta 11:00-12:00 remunerada: sem debito nem credito', [0, 0, 480], [$l['debito'], $l['credito'], $l['normais']]);
confere('consulta 11:00-12:00: motivo cita o atestado parcial', 'Atestado parcial 11:00-12:00', $l['motivo']);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['parciais' => $parcial]), ['abono_remunerado' => false]);
confere('consulta nao remunerada: debito de 1:00', [60, 420], [$l['debito'], $l['normais']]);
// o caso relatado: 08:00 / pausa 12:30 / retorno 13:30 / saida 17:30, consulta 11:00-12:00
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:30', '13:30', '17:30'], ['parciais' => $parcial]), []);
confere('caso relatado: trabalhado 7:30 (8:30 das batidas - 1:00)', 450, $l['trabalhado']);
confere('caso relatado: abono 1:00', 60, $l['abono']);
confere('caso relatado: horas normais 8:00 + credito 0:30', [480, 30], [$l['normais'], $l['credito']]);
$l = ponto_apurar_dia($segunda, ctx_util(['10:00', '12:00', '13:00', '17:00'], ['parciais' => [['ini' => '08:00', 'fim' => '10:00']]]), []);
confere('consulta antes da entrada: nada a descontar, abono 2:00', [360, 120, 0], [$l['trabalhado'], $l['abono'], $l['debito']]);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['parciais' => [['ini' => '12:00', 'fim' => '13:00']]]), []);
confere('consulta durante a pausa: horas intactas', [480, 60], [$l['trabalhado'], $l['abono']]);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['parciais' => [['ini' => '11:30', 'fim' => '13:45']]]), []);
confere('consulta atravessa a pausa: desconta so o que cai nos blocos', [405, 135], [$l['trabalhado'], $l['abono']]);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['parciais' => [['ini' => '10:00', 'fim' => '11:00'], ['ini' => '10:30', 'fim' => '11:30']]]), []);
confere('atestados sobrepostos contam uma vez so (10:00-11:30)', [390, 90], [$l['trabalhado'], $l['abono']]);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['parciais' => [['ini' => '09:00', 'fim' => '09:30'], ['ini' => '15:00', 'fim' => '16:00']]]), []);
confere('dois atestados no dia', [390, 90], [$l['trabalhado'], $l['abono']]);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '09:00', '09:30', '10:00'], ['parciais' => [['ini' => '08:00', 'fim' => '10:00']]]), []);
confere('atestado cobre tudo: trabalhado 0:00 e nunca negativo', 0, $l['trabalhado']);
$l = ponto_apurar_dia($segunda, ctx_util([], ['parciais' => $parcial]), []);
confere('so atestado parcial, sem nenhuma batida: nao e falta; debito so do restante (7:00)', ['util', 420], [$l['tipo'], $l['debito']]);

// ------------------------------------------------------------------
// 4) Tipos de dia
// ------------------------------------------------------------------
$l = ponto_apurar_dia($segunda, ctx_util([]), []);
confere('dia util sem batida e falta (debito = previsto)', ['falta', 480, 'Falta'], [$l['tipo'], $l['debito'], $l['motivo']]);
$l = ponto_apurar_dia($domingo, ['turno' => null, 'batidas' => ['ent1' => '09:00', 'sai1' => '11:00']], []);
confere('domingo trabalhado: 2:00 em H.E. 2 (100%)', [120, 120, 0], [$l['credito'], $l['he2'], $l['he1']]);
confere('domingo trabalhado: saldo + 2:00 x fator 2.0', 240, $l['delta_saldo']);
$l = ponto_apurar_dia($sabado, ['turno' => null, 'batidas' => ['ent1' => '09:00', 'sai1' => '12:00']], []);
confere('sabado sem jornada trabalhado: 3:00 em H.E. 1 (50%)', [180, 180, 0], [$l['credito'], $l['he1'], $l['he2']]);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00'], ['feriado' => 'Natal']), []);
confere('feriado trabalhado: H.E. 2 e motivo com o nome do feriado', [240, 240, 'Natal'], [$l['he2'], $l['credito'], $l['motivo']]);
$l = ponto_apurar_dia($segunda, ctx_util([], ['feriado' => 'Natal']), []);
confere('feriado sem trabalho: sem debito', [0, 0], [$l['debito'], $l['delta_saldo']]);
$l = ponto_apurar_dia($segunda, ctx_util([], ['atestado_dia' => 'Atestado (CID J11)']), []);
confere('atestado de dia inteiro: sem debito, motivo com o CID', [0, 'Atestado (CID J11)'], [$l['debito'], $l['motivo']]);
$l = ponto_apurar_dia($sabado, ['turno' => null, 'batidas' => []], []);
confere('sabado sem batida: Folga', ['folga', 'Folga', 0], [$l['tipo'], $l['motivo'], $l['debito']]);
$l = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['manual' => ['sai2' => true], 'obs' => ['atendimento externo']]), []);
confere('batida manual: motivo Ajuste + observacao', 'Ajuste; atendimento externo', $l['motivo']);
confere('antes do inicio/depois do desligamento: na (sem apuracao)', ['na', false], [($x = ponto_apurar_dia($segunda, ctx_util([], ['fora_vigencia' => true]), []))['tipo'], $x['aplicavel']]);
confere('dia futuro: nao apurado', ['futuro', false, 0], [($x = ponto_apurar_dia($segunda, ctx_util([], ['futuro' => true]), []))['tipo'], $x['aplicavel'], $x['debito']]);
$x = ponto_apurar_dia($segunda, ctx_util([], ['hoje' => true]), []);
confere('hoje sem batida: ainda nao e falta', ['andamento', false, 0], [$x['tipo'], $x['aplicavel'], $x['debito']]);
$x = ponto_apurar_dia($segunda, ctx_util(['08:00'], ['hoje' => true]), []);
confere('hoje com batida incompleta: Em andamento, sem debito', ['andamento', 'Em andamento', 0], [$x['tipo'], $x['motivo'], $x['debito']]);
$x = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['hoje' => true]), []);
confere('hoje com o ciclo completo: apura normalmente', ['util', true, 480], [$x['tipo'], $x['aplicavel'], $x['normais']]);
$x = ponto_apurar_dia($segunda, ctx_util(['08:00', '12:00', '13:00', '17:00'], ['sem_turno' => true, 'turno' => null]), []);
confere('colaborador sem turno: so mostra o trabalhado, sem credito/debito/saldo', ['sem_turno', 480, 0, 0, false], [$x['tipo'], $x['normais'], $x['credito'], $x['debito'], $x['saldo_aplicavel']]);

// adicional noturno: trabalho entre 22:00 e 05:00
$x = ponto_apurar_dia($segunda, ['turno' => ['ent1' => '21:00', 'sai1' => '23:30', 'ent2' => null, 'sai2' => null], 'batidas' => ['ent1' => '21:00', 'sai1' => '23:30']], []);
confere('adicional noturno: 22:00-23:30 = 1:30', 90, $x['an']);
$x = ponto_apurar_dia($segunda, ['turno' => null, 'batidas' => ['ent1' => '03:00', 'sai1' => '06:00']], []);
confere('adicional noturno: 03:00-06:00 = 2:00 (ate 05:00)', 120, $x['an']);

// saldo corrente com periodo (futuro nao apura e nao tem saldo)
$per = ponto_apurar_periodo([
    '2026-10-19' => ctx_util(['08:00', '12:00', '13:00', '17:30']),
    '2026-10-20' => ctx_util(['08:00', '12:00', '13:00', '16:00']),
    '2026-10-21' => ctx_util([], ['futuro' => true]),
], [], 0);
confere('saldo corrente: +0:30 e depois -1:00 = -0:30', [30, -30, null], [$per[0]['saldo'], $per[1]['saldo'], $per[2]['saldo']]);
confere('saldo negativo formatado', '-00:30', ponto_hhmm(-30));
confere('horas acima de 99 mantem 3 digitos', '150:01', ponto_hhmm(9001));

// ------------------------------------------------------------------
// 5) Turno, feriados, documentos
// ------------------------------------------------------------------
$form = [];
foreach ([1, 2, 3, 4, 5] as $d) {
    $form[$d] = ['ent1' => '08:30', 'sai1' => '12:00', 'ent2' => '13:30', 'sai2' => '18:00'];
}
[$turno, $erro] = ponto_turno_validar($form);
confere('turno seg-sex valido', [null, 5, '08:30'], [$erro, count($turno), $turno[1]['ent1']]);
confere('turno: resumo agrupa os dias', 'Seg-Sex 08:30-12:00 / 13:30-18:00 (8:00/dia)', ponto_turno_resumo($turno));
confere('turno: normaliza o JSON do banco', 5, count(ponto_turno_normalizar(json_encode($turno))));
$form[6] = ['ent1' => '09:00', 'sai1' => '', 'ent2' => '', 'sai2' => ''];
confere('turno: dia sem a 1a saida e recusado', true, ponto_turno_validar($form)[1] !== null);
$form[6] = ['ent1' => '09:00', 'sai1' => '13:00', 'ent2' => '12:00', 'sai2' => '14:00'];
confere('turno: ordem invalida e recusada', true, ponto_turno_validar($form)[1] !== null);
confere('turno vazio e recusado', true, ponto_turno_validar([])[1] !== null);
confere('turno: texto invalido e recusado', true, ponto_turno_validar([1 => ['ent1' => 'abc', 'sai1' => '12:00']])[1] !== null);

confere('pascoa 2021', '2021-04-04', ponto_pascoa(2021)->format('Y-m-d'));
confere('pascoa 2024', '2024-03-31', ponto_pascoa(2024)->format('Y-m-d'));
confere('pascoa 2026', '2026-04-05', ponto_pascoa(2026)->format('Y-m-d'));
$f2026 = ponto_feriados_nacionais(2026);
confere('feriados 2026: Sexta-feira Santa em 03/04', 'Sexta-feira Santa', $f2026['2026-04-03'] ?? null);
confere('feriados 2026: Consciencia Negra em 20/11', 'Dia da Consciência Negra', $f2026['2026-11-20'] ?? null);
confere('feriados 2021: sem Consciencia Negra (so a partir de 2024)', false, isset(ponto_feriados_nacionais(2021)['2021-11-20']));
confere('feriados 2026: 10 datas', 10, count($f2026));

confere('CPF valido', true, ponto_cpf_valido('529.982.247-25'));
confere('CPF invalido (digito)', false, ponto_cpf_valido('529.982.247-24'));
confere('CPF repetido invalido', false, ponto_cpf_valido('111.111.111-11'));
confere('CNPJ valido', true, ponto_cnpj_valido('11.222.333/0001-81'));
confere('CNPJ invalido', false, ponto_cnpj_valido('11.222.333/0001-82'));
confere('PIS valido', true, ponto_pis_valido('120.12345.67-2'));
confere('PIS invalido', false, ponto_pis_valido('120.12345.67-3'));
confere('formata CPF', '529.982.247-25', ponto_fmt_cpf('52998224725'));
confere('formata CNPJ', '11.222.333/0001-81', ponto_fmt_cnpj('11222333000181'));
confere('formata PIS', '120.12345.67-2', ponto_fmt_pis('12012345672'));

exit($falhas > 0 ? 1 : 0);
