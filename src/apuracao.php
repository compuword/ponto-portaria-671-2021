<?php
declare(strict_types=1);

/**
 * Apuracao do espelho de ponto: funcoes PURAS (sem banco, sem sessao).
 *
 * As regras foram extraidas de um espelho de ponto modelo (docs/espelho_modelo.md)
 * e conferidas contra os numeros dele em tests/apuracao_test.php:
 *  - previsto do dia = jornada do turno (1a entrada ate 1a saida + 2a entrada ate 2a saida);
 *  - tolerancia diaria: diferenca de ate N minutos (padrao 10) nao gera credito nem debito;
 *    acima disso conta a diferenca INTEIRA;
 *  - horas normais = tempo trabalhado limitado ao previsto; credito = excedente;
 *    debito = o que faltou; H. intervalo = 2a entrada - 1a saida;
 *  - credito vira H.E. 1 (50%) em dia util/sabado sem jornada e H.E. 2 (100%) em domingo e feriado;
 *  - saldo = banco de horas acumulado: + H.E.1 x fator1 + H.E.2 x fator2 - debito;
 *  - folga (dia sem jornada), feriado e atestado de dia inteiro: sem debito.
 */

const PONTO_DIAS_ABREV = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
const PONTO_DIAS_NOME = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

/** Configuracao padrao da apuracao (a da empresa sobrepoe estes valores). */
function ponto_cfg_padrao(): array
{
    return [
        'tolerancia_min' => 10,
        'noturno_ini' => 22 * 60,
        'noturno_fim' => 5 * 60,
        'fator_he1' => 1.0,
        'fator_he2' => 2.0,
        'abono_remunerado' => true,
    ];
}

/** 'HH:MM[:SS]' -> minutos do dia; vazio/invalido -> null. */
function ponto_min(?string $hhmm): ?int
{
    if ($hhmm === null || !preg_match('/^(\d{1,2}):(\d{2})/', trim($hhmm), $m)) {
        return null;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

/** Minutos -> 'HH:MM' (horas com 2 ou mais digitos; negativo com '-'). */
function ponto_hhmm(int $min): string
{
    $sinal = $min < 0 ? '-' : '';
    $min = abs($min);
    return $sinal . sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/** Dia da semana de 'YYYY-MM-DD': 0 = domingo ... 6 = sabado. */
function ponto_dow(string $data): int
{
    return (int)(new DateTimeImmutable($data))->format('w');
}

/** Une intervalos [ini, fim) em minutos do dia que se tocam ou se sobrepoem (para nao contar o mesmo tempo 2x). */
function ponto_uniao_intervalos(array $intervalos): array
{
    usort($intervalos, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $uniao = [];
    foreach ($intervalos as [$ini, $fim]) {
        if ($fim <= $ini) {
            continue;
        }
        $ultimo = count($uniao) - 1;
        if ($ultimo >= 0 && $ini <= $uniao[$ultimo][1]) {
            $uniao[$ultimo][1] = max($uniao[$ultimo][1], $fim);
        } else {
            $uniao[] = [$ini, $fim];
        }
    }
    return $uniao;
}

/** Minutos de $uniao (intervalos ja unidos) que caem dentro de [$ini, $fim). */
function ponto_minutos_dentro(array $uniao, int $ini, int $fim): int
{
    $total = 0;
    foreach ($uniao as [$a, $b]) {
        $total += max(0, min($b, $fim) - max($a, $ini));
    }
    return $total;
}

/** Minutos dos blocos [ini, fim) que caem na janela noturna (que pode atravessar a meia-noite, ex. 22:00-05:00). */
function ponto_minutos_noturnos(array $blocos, int $ini, int $fim): int
{
    $total = 0;
    foreach ($blocos as [$a, $b]) {
        if ($ini > $fim) {
            $total += max(0, min($b, 1440) - max($a, $ini));
            $total += max(0, min($b, $fim) - max($a, 0));
        } else {
            $total += max(0, min($b, $fim) - max($a, $ini));
        }
    }
    return $total;
}

// ---------------------------------------------------------------- turno

/** Normaliza o turno vindo do JSON do banco: [dow => ['ent1','sai1','ent2'?,'sai2'?]]; dia ausente = folga. */
function ponto_turno_normalizar(?string $json): array
{
    $dados = ($json !== null && $json !== '') ? json_decode($json, true) : null;
    $turno = [];
    if (!is_array($dados)) {
        return $turno;
    }
    for ($dow = 0; $dow <= 6; $dow++) {
        $d = $dados[(string)$dow] ?? ($dados[$dow] ?? null);
        if (!is_array($d)) {
            continue;
        }
        $dia = [];
        foreach (['ent1', 'sai1', 'ent2', 'sai2'] as $campo) {
            $min = ponto_min(isset($d[$campo]) ? (string)$d[$campo] : null);
            $dia[$campo] = $min === null ? null : sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        }
        if ($dia['ent1'] !== null && $dia['sai1'] !== null) {
            $turno[$dow] = $dia;
        }
    }
    return $turno;
}

/** Minutos previstos no dia pela escala ($esc = linha do turno do dia ou null). */
function ponto_previsto(?array $esc): int
{
    if ($esc === null) {
        return 0;
    }
    $total = (ponto_min($esc['sai1'] ?? null) ?? 0) - (ponto_min($esc['ent1'] ?? null) ?? 0);
    $e2 = ponto_min($esc['ent2'] ?? null);
    $s2 = ponto_min($esc['sai2'] ?? null);
    if ($e2 !== null && $s2 !== null) {
        $total += $s2 - $e2;
    }
    return max(0, $total);
}

/**
 * Valida o turno digitado no formulario: $porDia[dow] = ['ent1'=>'08:30', 'sai1'=>..., 'ent2'=>..., 'sai2'=>...].
 * Dia com todos os campos vazios = folga. Retorna [turno normalizado, erro|null].
 */
function ponto_turno_validar(array $porDia): array
{
    $turno = [];
    for ($dow = 0; $dow <= 6; $dow++) {
        $campos = [];
        foreach (['ent1', 'sai1', 'ent2', 'sai2'] as $c) {
            $v = trim((string)($porDia[$dow][$c] ?? ''));
            if ($v !== '' && ponto_min($v) === null) {
                return [[], PONTO_DIAS_NOME[$dow] . ': horario invalido em "' . $v . '".'];
            }
            $campos[$c] = $v === '' ? null : ponto_min($v);
        }
        if ($campos['ent1'] === null && $campos['sai1'] === null && $campos['ent2'] === null && $campos['sai2'] === null) {
            continue;
        }
        if ($campos['ent1'] === null || $campos['sai1'] === null) {
            return [[], PONTO_DIAS_NOME[$dow] . ': preencha a 1ª entrada e a 1ª saída (ou deixe o dia todo vazio para folga).'];
        }
        if ($campos['sai1'] <= $campos['ent1']) {
            return [[], PONTO_DIAS_NOME[$dow] . ': a 1ª saída precisa ser depois da 1ª entrada.'];
        }
        if (($campos['ent2'] === null) !== ($campos['sai2'] === null)) {
            return [[], PONTO_DIAS_NOME[$dow] . ': preencha a 2ª entrada e a 2ª saída juntas (ou nenhuma).'];
        }
        if ($campos['ent2'] !== null && ($campos['ent2'] <= $campos['sai1'] || $campos['sai2'] <= $campos['ent2'])) {
            return [[], PONTO_DIAS_NOME[$dow] . ': a ordem dos horários precisa ser 1ª entrada < 1ª saída < 2ª entrada < 2ª saída.'];
        }
        $turno[$dow] = [
            'ent1' => ponto_hhmm($campos['ent1']),
            'sai1' => ponto_hhmm($campos['sai1']),
            'ent2' => $campos['ent2'] === null ? null : ponto_hhmm($campos['ent2']),
            'sai2' => $campos['sai2'] === null ? null : ponto_hhmm($campos['sai2']),
        ];
    }
    if ($turno === []) {
        return [[], 'Informe o horário de pelo menos um dia da semana.'];
    }
    return [$turno, null];
}

/** Texto curto do turno, ex.: 'Seg-Sex 08:30-12:00 / 13:30-18:00 (8:00/dia)'. */
function ponto_turno_resumo(array $turno): string
{
    $grupos = [];
    foreach ($turno as $dow => $esc) {
        $txt = $esc['ent1'] . '-' . $esc['sai1'] . (($esc['ent2'] ?? null) !== null ? ' / ' . $esc['ent2'] . '-' . $esc['sai2'] : '');
        $txt .= ' (' . ltrim(ponto_hhmm(ponto_previsto($esc)), '0') . '/dia)';
        $grupos[$txt][] = $dow;
    }
    $partes = [];
    foreach ($grupos as $txt => $dias) {
        sort($dias);
        $intervalos = [];
        $inicio = $anterior = $dias[0];
        foreach (array_slice($dias, 1) as $d) {
            if ($d === $anterior + 1) {
                $anterior = $d;
                continue;
            }
            $intervalos[] = [$inicio, $anterior];
            $inicio = $anterior = $d;
        }
        $intervalos[] = [$inicio, $anterior];
        $nomes = array_map(
            static fn(array $i): string => $i[0] === $i[1] ? PONTO_DIAS_ABREV[$i[0]] : PONTO_DIAS_ABREV[$i[0]] . '-' . PONTO_DIAS_ABREV[$i[1]],
            $intervalos
        );
        $partes[] = implode(', ', $nomes) . ' ' . $txt;
    }
    return implode('; ', $partes);
}

// ---------------------------------------------------------------- feriados

/** Domingo de Pascoa (calendario gregoriano; algoritmo de Meeus/Jones/Butcher). */
function ponto_pascoa(int $ano): DateTimeImmutable
{
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
}

/** Feriados nacionais do ano: 'YYYY-MM-DD' => nome. Estaduais/municipais entram pela tabela feriados. */
function ponto_feriados_nacionais(int $ano): array
{
    $f = [
        sprintf('%04d-01-01', $ano) => 'Confraternização Universal',
        ponto_pascoa($ano)->modify('-2 days')->format('Y-m-d') => 'Sexta-feira Santa',
        sprintf('%04d-04-21', $ano) => 'Tiradentes',
        sprintf('%04d-05-01', $ano) => 'Dia do Trabalho',
        sprintf('%04d-09-07', $ano) => 'Independência do Brasil',
        sprintf('%04d-10-12', $ano) => 'Nossa Senhora Aparecida',
        sprintf('%04d-11-02', $ano) => 'Finados',
        sprintf('%04d-11-15', $ano) => 'Proclamação da República',
        sprintf('%04d-12-25', $ano) => 'Natal',
    ];
    if ($ano >= 2024) {
        $f[sprintf('%04d-11-20', $ano)] = 'Dia da Consciência Negra';
    }
    ksort($f);
    return $f;
}

// ---------------------------------------------------------------- apuracao do dia

/**
 * Apura 1 dia. $ctx:
 *  - turno: linha do turno do dia ['ent1','sai1','ent2','sai2'] ou null (folga);  sem_turno: colaborador sem turno algum
 *  - batidas: ['ent1','sai1','ent2','sai2'] => 'HH:MM'|null;  manual: mesmos nomes => bool;  obs: string[]
 *  - feriado: nome|null;  atestado_dia: texto|null;  parciais: [['ini','fim','cid'?], ...]
 *  - fora_vigencia (antes do inicio do controle/admissao ou depois do desligamento), futuro, hoje: bool
 */
function ponto_apurar_dia(string $data, array $ctx, array $cfg): array
{
    $cfg += ponto_cfg_padrao();
    $dow = ponto_dow($data);
    $b = $ctx['batidas'] ?? [];
    $linha = [
        'data' => $data, 'dow' => $dow,
        'ent1' => $b['ent1'] ?? null, 'sai1' => $b['sai1'] ?? null, 'ent2' => $b['ent2'] ?? null, 'sai2' => $b['sai2'] ?? null,
        'manual' => $ctx['manual'] ?? [],
        'tipo' => 'util', 'aplicavel' => false, 'saldo_aplicavel' => true, 'incompleto' => false,
        'previsto' => 0, 'trabalhado' => 0, 'intervalo' => 0, 'normais' => 0, 'credito' => 0, 'debito' => 0,
        'he1' => 0, 'he2' => 0, 'an' => 0, 'abono' => 0, 'delta_saldo' => 0, 'motivo' => '',
    ];

    if (!empty($ctx['fora_vigencia'])) {
        $linha['tipo'] = 'na';
        return $linha;
    }
    if (!empty($ctx['futuro'])) {
        $linha['tipo'] = 'futuro';
        return $linha;
    }

    $e1 = ponto_min($linha['ent1']);
    $s1 = ponto_min($linha['sai1']);
    $e2 = ponto_min($linha['ent2']);
    $s2 = ponto_min($linha['sai2']);
    $blocos = [];
    if ($e1 !== null && $s1 !== null && $s1 > $e1) {
        $blocos[] = [$e1, $s1];
    }
    if ($e2 !== null && $s2 !== null && $s2 > $e2) {
        $blocos[] = [$e2, $s2];
    }
    $bruto = 0;
    foreach ($blocos as [$ini, $fim]) {
        $bruto += $fim - $ini;
    }
    $intervalo = ($s1 !== null && $e2 !== null && $e2 > $s1) ? $e2 - $s1 : 0;

    // atestado parcial (consulta/exame no meio do expediente): o tempo fora nao conta como trabalhado
    $intervalos = [];
    foreach ($ctx['parciais'] ?? [] as $p) {
        $ini = ponto_min($p['ini']);
        $fim = ponto_min($p['fim']);
        if ($ini !== null && $fim !== null) {
            $intervalos[] = [$ini, $fim];
        }
    }
    $uniao = ponto_uniao_intervalos($intervalos);
    $abono = 0;
    foreach ($uniao as [$ini, $fim]) {
        $abono += $fim - $ini;
    }
    $descontar = 0;
    foreach ($blocos as [$ini, $fim]) {
        $descontar += ponto_minutos_dentro($uniao, $ini, $fim);
    }
    $liquido = max(0, $bruto - $descontar);

    $esc = $ctx['turno'] ?? null;
    $previsto = ponto_previsto($esc);
    $presentes = count(array_filter([$e1, $s1, $e2, $s2], static fn($x) => $x !== null));
    // so exige o numero de batidas do turno em dia de trabalho previsto (feriado/atestado/folga: basta o par completo)
    $esperadas = ($esc === null || ($ctx['feriado'] ?? null) !== null || ($ctx['atestado_dia'] ?? null) !== null)
        ? 0
        : (($esc['ent2'] ?? null) !== null ? 4 : 2);
    $incompleto = $presentes > 0 && (
        ($e1 !== null) !== ($s1 !== null) || ($e2 !== null) !== ($s2 !== null) || ($esperadas > 0 && $presentes < $esperadas)
    );

    $linha['trabalhado'] = $liquido;
    $linha['intervalo'] = $intervalo;
    $linha['abono'] = $abono;
    $linha['incompleto'] = $incompleto;
    $linha['an'] = ponto_minutos_noturnos($blocos, (int)$cfg['noturno_ini'], (int)$cfg['noturno_fim']);

    $motivos = [];
    $manual = in_array(true, array_map('boolval', $linha['manual']), true);

    // dia de hoje ainda em andamento: nao apura (nao e falta nem debito ainda)
    if (!empty($ctx['hoje']) && ($presentes === 0 || $incompleto)) {
        $linha['tipo'] = 'andamento';
        $linha['intervalo'] = 0;
        $linha['trabalhado'] = 0;
        $linha['an'] = 0;
        $linha['motivo'] = $presentes > 0 ? 'Em andamento' : '';
        return $linha;
    }

    $linha['aplicavel'] = true;

    if (!empty($ctx['sem_turno'])) {
        // sem turno cadastrado nao ha previsto: so mostra o trabalhado, sem credito/debito/saldo
        $linha['tipo'] = 'sem_turno';
        $linha['saldo_aplicavel'] = false;
        $linha['normais'] = $liquido;
    } else {
        $linha['previsto'] = $previsto;
        $feriado = $ctx['feriado'] ?? null;
        $atestado = $ctx['atestado_dia'] ?? null;
        if ($feriado !== null) {
            $motivos[] = $feriado;
        }
        if ($atestado !== null) {
            $motivos[] = $atestado;
        }

        if ($feriado !== null || $atestado !== null || $previsto === 0) {
            // sem jornada a cumprir: tudo que foi trabalhado e extra (100% em domingo/feriado, 50% nos demais)
            $linha['tipo'] = $feriado !== null ? 'feriado' : ($atestado !== null ? 'atestado' : 'folga');
            if ($liquido > 0) {
                $linha['credito'] = $liquido;
                if ($feriado !== null || $dow === 0) {
                    $linha['he2'] = $liquido;
                } else {
                    $linha['he1'] = $liquido;
                }
            }
            if ($feriado === null && $atestado === null && $presentes === 0) {
                $motivos[] = 'Folga';
            }
        } else {
            $efetivo = $liquido + ($cfg['abono_remunerado'] ? $abono : 0);
            if ($presentes === 0 && $abono === 0) {
                $linha['tipo'] = 'falta';
                $linha['debito'] = $previsto;
                $motivos[] = 'Falta';
            } else {
                $dif = $efetivo - $previsto;
                if (abs($dif) <= (int)$cfg['tolerancia_min']) {
                    $linha['normais'] = $previsto;
                } elseif ($dif > 0) {
                    $linha['normais'] = $previsto;
                    $linha['credito'] = $dif;
                    $linha['he1'] = $dif;
                } else {
                    $linha['normais'] = $efetivo;
                    $linha['debito'] = -$dif;
                }
            }
        }
        $linha['delta_saldo'] = (int)round($linha['he1'] * (float)$cfg['fator_he1'] + $linha['he2'] * (float)$cfg['fator_he2']) - $linha['debito'];
    }

    if ($incompleto) {
        $motivos[] = 'Marcação incompleta';
    }
    if ($manual) {
        $motivos[] = 'Ajuste';
    }
    foreach ($ctx['parciais'] ?? [] as $p) {
        $motivos[] = 'Atestado parcial ' . $p['ini'] . '-' . $p['fim'] . (!empty($p['cid']) ? ' (CID ' . $p['cid'] . ')' : '');
    }
    foreach ($ctx['obs'] ?? [] as $o) {
        if ($o !== '') {
            $motivos[] = $o;
        }
    }
    $linha['motivo'] = implode('; ', $motivos);
    return $linha;
}

/**
 * Apura um periodo corrido. $ctxs: 'YYYY-MM-DD' => contexto do dia, em ordem cronologica.
 * Acrescenta 'saldo' (minutos, banco de horas acumulado; null onde nao se aplica) a cada linha.
 */
function ponto_apurar_periodo(array $ctxs, array $cfg, int $saldo_inicial = 0): array
{
    $saldo = $saldo_inicial;
    $linhas = [];
    foreach ($ctxs as $data => $ctx) {
        $l = ponto_apurar_dia((string)$data, $ctx, $cfg);
        if ($l['aplicavel'] && $l['saldo_aplicavel']) {
            $saldo += $l['delta_saldo'];
        }
        $l['saldo'] = (in_array($l['tipo'], ['na', 'futuro'], true) || !$l['saldo_aplicavel']) ? null : $saldo;
        $linhas[] = $l;
    }
    return $linhas;
}

/** Totais das colunas (so dias apurados), como a linha TOTAIS do espelho. */
function ponto_apurar_totais(array $linhas): array
{
    $t = ['credito' => 0, 'debito' => 0, 'intervalo' => 0, 'normais' => 0, 'he1' => 0, 'he2' => 0, 'an' => 0,
          'abono' => 0, 'trabalhado' => 0, 'faltas' => 0, 'dias_trabalhados' => 0, 'saldo_final' => null];
    foreach ($linhas as $l) {
        if ($l['saldo'] !== null) {
            $t['saldo_final'] = $l['saldo'];
        }
        if (!$l['aplicavel']) {
            continue;
        }
        foreach (['credito', 'debito', 'intervalo', 'normais', 'he1', 'he2', 'an', 'abono', 'trabalhado'] as $c) {
            $t[$c] += $l[$c];
        }
        if ($l['tipo'] === 'falta') {
            $t['faltas']++;
        }
        if ($l['trabalhado'] > 0) {
            $t['dias_trabalhados']++;
        }
    }
    return $t;
}

// ---------------------------------------------------------------- documentos

function ponto_so_digitos(?string $s): string
{
    return preg_replace('/\D/', '', (string)$s) ?? '';
}

function ponto_cpf_valido(?string $cpf): bool
{
    $d = ponto_so_digitos($cpf);
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int)$d[$i] * (($t + 1) - $i);
        }
        if ((int)$d[$t] !== (((10 * $soma) % 11) % 10)) {
            return false;
        }
    }
    return true;
}

function ponto_cnpj_valido(?string $cnpj): bool
{
    $d = ponto_so_digitos($cnpj);
    if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) {
        return false;
    }
    foreach ([[12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]], [13, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]]] as [$pos, $pesos]) {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += (int)$d[$i] * $peso;
        }
        $resto = $soma % 11;
        if ((int)$d[$pos] !== ($resto < 2 ? 0 : 11 - $resto)) {
            return false;
        }
    }
    return true;
}

function ponto_pis_valido(?string $pis): bool
{
    $d = ponto_so_digitos($pis);
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
        return false;
    }
    $soma = 0;
    foreach ([3, 2, 9, 8, 7, 6, 5, 4, 3, 2] as $i => $peso) {
        $soma += (int)$d[$i] * $peso;
    }
    $dv = 11 - ($soma % 11);
    return (int)$d[10] === ($dv >= 10 ? 0 : $dv);
}

function ponto_fmt_cpf(?string $cpf): string
{
    $d = ponto_so_digitos($cpf);
    return strlen($d) === 11 ? sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9, 2)) : (string)$cpf;
}

function ponto_fmt_cnpj(?string $cnpj): string
{
    $d = ponto_so_digitos($cnpj);
    return strlen($d) === 14 ? sprintf('%s.%s.%s/%s-%s', substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12, 2)) : (string)$cnpj;
}

function ponto_fmt_pis(?string $pis): string
{
    $d = ponto_so_digitos($pis);
    return strlen($d) === 11 ? sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 5), substr($d, 8, 2), substr($d, 10, 1)) : (string)$pis;
}
