<?php
declare(strict_types=1);

/**
 * Espelho de ponto: carrega os dados do banco, aplica a apuracao (apuracao.php) e devolve o espelho pronto para a
 * tela e para as exportacoes (espelho_saida.php). Carregue depois de config.php e apuracao.php.
 *
 * Tudo vem em UTC do banco e e convertido para o fuso do colaborador; dia, mes e "hoje" sao os do fuso dele.
 */

const PONTO_SLOT_DO_TIPO = ['entrada' => 'ent1', 'almoco' => 'sai1', 'retorno' => 'ent2', 'saida' => 'sai2'];

/** O banco ja tem a migracao 003 (empresa, turnos, feriados, aceite, CPF/PIS)? */
function ponto_tem_espelho(): bool
{
    return ponto_tem_tabela('empresa') && ponto_tem_tabela('turnos') && ponto_tem_tabela('funcionario_turno')
        && ponto_tem_tabela('feriados') && ponto_tem_tabela('aceites_espelho') && ponto_tem_coluna('funcionarios', 'cpf');
}

/** Dados da empresa + regras de apuracao (padrao se a tabela nao existe). $recarregar=true ignora o cache. */
function ponto_empresa(bool $recarregar = false): array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) {
        return $cache;
    }
    $emp = ['razao_social' => '', 'unidade' => '', 'cnpj' => '', 'inscricao_estadual' => '', 'inicio_controle' => null, 'cfg' => ponto_cfg_padrao()];
    if (ponto_tem_tabela('empresa')) {
        $stmt = ponto_db()->query(
            "SELECT razao_social, unidade, cnpj, inscricao_estadual, tolerancia_min,
                    CONVERT(VARCHAR(5), noturno_inicio, 108) AS ni, CONVERT(VARCHAR(5), noturno_fim, 108) AS nf,
                    fator_he1, fator_he2, abono_remunerado, CONVERT(VARCHAR(10), inicio_controle, 120) AS inicio_controle
             FROM dbo.empresa WHERE id = 1"
        );
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;
        if ($l) {
            $emp['razao_social'] = (string)($l['razao_social'] ?? '');
            $emp['unidade'] = (string)($l['unidade'] ?? '');
            $emp['cnpj'] = (string)($l['cnpj'] ?? '');
            $emp['inscricao_estadual'] = (string)($l['inscricao_estadual'] ?? '');
            $emp['inicio_controle'] = ($l['inicio_controle'] ?? '') !== '' ? (string)$l['inicio_controle'] : null;
            $emp['cfg'] = [
                'tolerancia_min' => (int)$l['tolerancia_min'],
                'noturno_ini' => ponto_min((string)$l['ni']) ?? 22 * 60,
                'noturno_fim' => ponto_min((string)$l['nf']) ?? 5 * 60,
                'fator_he1' => (float)$l['fator_he1'],
                'fator_he2' => (float)$l['fator_he2'],
                'abono_remunerado' => (bool)(int)$l['abono_remunerado'],
            ];
        }
    }
    return $cache = $emp;
}

/** Atribuicoes de turno do colaborador, em ordem de vigencia: [['vigencia','turno_id','nome','horarios' => [dow => escala]]]. */
function ponto_turnos_do_funcionario(int $funcionario_id): array
{
    if (!ponto_tem_tabela('funcionario_turno')) {
        return [];
    }
    $stmt = ponto_db()->prepare(
        "SELECT CONVERT(VARCHAR(10), ft.vigencia_inicio, 120) AS vigencia, t.id AS turno_id, t.nome, t.horarios
         FROM dbo.funcionario_turno ft JOIN dbo.turnos t ON t.id = ft.turno_id
         WHERE ft.funcionario_id = ? ORDER BY ft.vigencia_inicio, ft.id"
    );
    $stmt->execute([$funcionario_id]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'vigencia' => (string)$r['vigencia'],
            'turno_id' => (int)$r['turno_id'],
            'nome' => (string)$r['nome'],
            'horarios' => ponto_turno_normalizar((string)$r['horarios']),
        ];
    }
    return $out;
}

/** A atribuicao de turno em vigor em $data ('Y-m-d'): a de maior vigencia <= data; null se ainda nao havia. */
function ponto_turno_vigente(array $atribuicoes, string $data): ?array
{
    $vigente = null;
    foreach ($atribuicoes as $a) {
        if ($a['vigencia'] <= $data) {
            $vigente = $a;
        } else {
            break;
        }
    }
    return $vigente;
}

/** Feriados de [$de, $ate]: 'Y-m-d' => nome (nacionais calculados + tabela feriados). */
function ponto_feriados_periodo(string $de, string $ate): array
{
    $f = [];
    for ($ano = (int)substr($de, 0, 4); $ano <= (int)substr($ate, 0, 4); $ano++) {
        $f += ponto_feriados_nacionais($ano);
    }
    if (ponto_tem_tabela('feriados')) {
        $stmt = ponto_db()->prepare("SELECT CONVERT(VARCHAR(10), data, 120) AS d, nome FROM dbo.feriados WHERE data >= ? AND data <= ?");
        $stmt->execute([$de, $ate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $f[(string)$r['d']] = (string)$r['nome'];
        }
    }
    return $f;
}

/** Atestados que tocam [$de, $ate]: ['dias' => [data => 'Atestado (CID x)'], 'parciais' => [data => [['ini','fim','cid']]]]. */
function ponto_atestados_periodo(int $funcionario_id, string $de, string $ate): array
{
    $dias = [];
    $parciais = [];
    $tem_parcial = ponto_tem_atestado_parcial();

    $stmt = ponto_db()->prepare(
        "SELECT CONVERT(VARCHAR(10), data_inicio, 120) AS d, dias_afastamento, cid FROM dbo.atestados_medicos WHERE funcionario_id = ?"
        . ($tem_parcial ? ' AND hora_inicio IS NULL' : '')
    );
    $stmt->execute([$funcionario_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $at) {
        $inicio = new DateTimeImmutable((string)$at['d']);
        for ($i = 0; $i < (int)$at['dias_afastamento']; $i++) {
            $data = $inicio->modify("+{$i} day")->format('Y-m-d');
            if ($data >= $de && $data <= $ate) {
                $dias[$data] = 'Atestado' . (($at['cid'] ?? '') !== '' ? ' (CID ' . $at['cid'] . ')' : '');
            }
        }
    }

    if ($tem_parcial) {
        $stmt = ponto_db()->prepare(
            "SELECT CONVERT(VARCHAR(10), data_inicio, 120) AS d,
                    CONVERT(VARCHAR(5), hora_inicio, 108) AS ini, CONVERT(VARCHAR(5), hora_fim, 108) AS fim, cid
             FROM dbo.atestados_medicos
             WHERE funcionario_id = ? AND hora_inicio IS NOT NULL AND data_inicio >= ? AND data_inicio <= ?
             ORDER BY data_inicio, hora_inicio"
        );
        $stmt->execute([$funcionario_id, $de, $ate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pa) {
            $parciais[(string)$pa['d']][] = ['ini' => (string)$pa['ini'], 'fim' => (string)$pa['fim'], 'cid' => $pa['cid']];
        }
    }
    return ['dias' => $dias, 'parciais' => $parciais];
}

/** Batidas de [$de, $ate] (datas LOCAIS) agrupadas por dia local: data => ['batidas','manual','obs']. A primeira de cada tipo no dia vale. */
function ponto_batidas_periodo(int $funcionario_id, string $fuso, string $de, string $ate): array
{
    $ini_utc = ponto_local_para_utc($de . ' 00:00:00', $fuso);
    $fim_utc = ponto_local_para_utc((new DateTimeImmutable($ate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $fuso);
    $stmt = ponto_db()->prepare(
        "SELECT r.tipo, CONVERT(VARCHAR(19), r.registrado_em, 120) AS quando, r.manual, r.observacao, a.motivo AS anotacao
         FROM dbo.registros_ponto r
         LEFT JOIN dbo.anotacoes_ponto a ON a.registro_id = r.id
         WHERE r.funcionario_id = ? AND r.registrado_em >= ? AND r.registrado_em < ?
         ORDER BY r.registrado_em, r.id"
    );
    $stmt->execute([$funcionario_id, $ini_utc, $fim_utc]);
    $dias = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $slot = PONTO_SLOT_DO_TIPO[(string)$r['tipo']] ?? null;
        if ($slot === null) {
            continue;
        }
        $local = ponto_utc_para_local((string)$r['quando'], $fuso);
        $data = substr($local, 0, 10);
        if (!isset($dias[$data])) {
            $dias[$data] = [
                'batidas' => ['ent1' => null, 'sai1' => null, 'ent2' => null, 'sai2' => null],
                'manual' => ['ent1' => false, 'sai1' => false, 'ent2' => false, 'sai2' => false],
                'obs' => [],
            ];
        }
        if ($dias[$data]['batidas'][$slot] === null) {
            $dias[$data]['batidas'][$slot] = substr($local, 11, 5);
            $dias[$data]['manual'][$slot] = (bool)(int)$r['manual'];
        }
        if (($r['observacao'] ?? '') !== '') {
            $dias[$data]['obs'][] = (string)$r['observacao'];
        }
        if (($r['anotacao'] ?? '') !== '') {
            $dias[$data]['obs'][] = 'ANOTAÇÃO: ' . (string)$r['anotacao'];
        }
    }
    return $dias;
}

/** Hash do conteudo do espelho (prova do que foi aceito): so os campos que o colaborador ve. */
function ponto_espelho_hash(array $linhas, array $totais, int $funcionario_id, int $ano, int $mes): string
{
    $campos = ['data', 'ent1', 'sai1', 'ent2', 'sai2', 'credito', 'debito', 'intervalo', 'normais', 'he1', 'he2', 'an', 'saldo', 'abono', 'motivo'];
    $norm = [];
    foreach ($linhas as $l) {
        $r = [];
        foreach ($campos as $c) {
            $r[] = $l[$c] ?? null;
        }
        $norm[] = $r;
    }
    return hash('sha256', (string)json_encode([$funcionario_id, $ano, $mes, $norm, $totais], JSON_UNESCAPED_UNICODE));
}

/** Plataforma legivel a partir do User-Agent (para o comprovante de aceite). */
function ponto_plataforma_ua(string $ua): string
{
    foreach (['iPhone' => 'iOS', 'iPad' => 'iOS', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'macOS', 'Linux' => 'Linux'] as $marca => $nome) {
        if (stripos($ua, $marca) !== false) {
            return $nome;
        }
    }
    return 'Desconhecida';
}

/** Ultimo aceite do espelho do mes (ou null). */
function ponto_aceite_atual(int $funcionario_id, int $ano, int $mes, string $fuso): ?array
{
    if (!ponto_tem_tabela('aceites_espelho')) {
        return null;
    }
    $stmt = ponto_db()->prepare(
        "SELECT TOP 1 CONVERT(VARCHAR(19), aceito_em, 120) AS aceito_em, espelho_hash, protocolo, ip_origem, user_agent
         FROM dbo.aceites_espelho WHERE funcionario_id = ? AND ano = ? AND mes = ? ORDER BY id DESC"
    );
    $stmt->execute([$funcionario_id, $ano, $mes]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return [
        'aceito_em' => ponto_utc_para_local((string)$r['aceito_em'], $fuso, 'd/m/Y H:i:s'),
        'hash' => (string)$r['espelho_hash'],
        'protocolo' => (string)$r['protocolo'],
        'ip' => (string)$r['ip_origem'],
        'plataforma' => ponto_plataforma_ua((string)($r['user_agent'] ?? '')),
    ];
}

/** Registra o aceite eletronico do espelho pelo proprio colaborador (so INSERT). Retorna o protocolo. */
function ponto_registrar_aceite(array $espelho, string $ip, string $ua): string
{
    $uid = (int)$espelho['funcionario']['id'];
    $ano = (int)$espelho['periodo']['ano'];
    $mes = (int)$espelho['periodo']['mes'];
    $agora = gmdate('Y-m-d H:i:s');
    $protocolo = substr(hash('sha256', $uid . '|' . $ano . '|' . $mes . '|' . $espelho['hash'] . '|' . $agora . '|' . bin2hex(random_bytes(8))), 0, 32);
    $stmt = ponto_db()->prepare(
        'INSERT INTO dbo.aceites_espelho (funcionario_id, ano, mes, espelho_hash, protocolo, ip_origem, user_agent, hostname_origem, aceito_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$uid, $ano, $mes, $espelho['hash'], $protocolo, $ip, $ua, ponto_hostname_dhcp($ip), $agora]);
    return $protocolo;
}

/**
 * Monta o espelho do mes de 1 colaborador: cabecalho (empresa, colaborador, turno), 1 linha por dia, totais, aceite.
 * O saldo (banco de horas) acumula desde o inicio do controle do colaborador: a maior data entre a 1a batida dele, a
 * admissao e o "inicio do controle" da empresa; dias antes disso (ou depois do desligamento) ficam em branco.
 * $agora: so para teste (padrao: hora atual).
 */
function ponto_espelho(int $funcionario_id, int $ano, int $mes, ?DateTimeImmutable $agora = null): ?array
{
    $mes = max(1, min(12, $mes));
    $ano = max(2000, min(2100, $ano));

    $cols = ['id', 'nome', 'cargo', 'data_admissao', 'data_desligamento'];
    foreach (['fuso_horario', 'cpf', 'pis', 'equipe'] as $c) {
        if (ponto_tem_coluna('funcionarios', $c)) {
            $cols[] = $c;
        }
    }
    $stmt = ponto_db()->prepare('SELECT ' . implode(', ', $cols) . ' FROM dbo.funcionarios WHERE id = ?');
    $stmt->execute([$funcionario_id]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null;
    if (!$f) {
        return null;
    }

    $fuso = ponto_fuso_ou_padrao($f['fuso_horario'] ?? null);
    $emp = ponto_empresa();
    $cfg = $emp['cfg'];
    $agora_local = ($agora ?? new DateTimeImmutable('now', new DateTimeZone($fuso)))->setTimezone(new DateTimeZone($fuso));
    $hoje = $agora_local->format('Y-m-d');

    $ini_mes = sprintf('%04d-%02d-01', $ano, $mes);
    $fim_mes = (new DateTimeImmutable($ini_mes))->modify('last day of this month')->format('Y-m-d');

    // inicio do controle: so conta (debito/falta/saldo) a partir da 1a batida, da admissao e do inicio do controle da empresa
    $stmt = ponto_db()->prepare('SELECT MIN(CONVERT(VARCHAR(19), registrado_em, 120)) FROM dbo.registros_ponto WHERE funcionario_id = ?');
    $stmt->execute([$funcionario_id]);
    $primeira = $stmt->fetchColumn();
    $stmt = null;
    $inicio = ($primeira !== false && $primeira !== null) ? substr(ponto_utc_para_local((string)$primeira, $fuso), 0, 10) : null;
    if ($inicio !== null) {
        foreach ([$f['data_admissao'] ?? null, $emp['inicio_controle']] as $c) {
            if ($c !== null && $c !== '' && (string)$c > $inicio) {
                $inicio = (string)$c;
            }
        }
    }
    $desligamento = ($f['data_desligamento'] ?? '') !== '' ? (string)$f['data_desligamento'] : null;

    $ini_loop = ($inicio !== null && $inicio < $ini_mes) ? $inicio : $ini_mes;
    $batidas = ponto_batidas_periodo($funcionario_id, $fuso, $ini_loop, $fim_mes);
    $atestados = ponto_atestados_periodo($funcionario_id, $ini_loop, $fim_mes);
    $feriados = ponto_feriados_periodo($ini_loop, $fim_mes);
    $atribuicoes = ponto_turnos_do_funcionario($funcionario_id);

    $vazio = ['batidas' => ['ent1' => null, 'sai1' => null, 'ent2' => null, 'sai2' => null], 'manual' => [], 'obs' => []];
    $ctxs = [];
    $fim_dt = new DateTimeImmutable($fim_mes);
    for ($d = new DateTimeImmutable($ini_loop); $d <= $fim_dt; $d = $d->modify('+1 day')) {
        $data = $d->format('Y-m-d');
        $dow = (int)$d->format('w');
        $a = ponto_turno_vigente($atribuicoes, $data);
        $dia = $batidas[$data] ?? $vazio;
        $ctxs[$data] = [
            'turno' => $a['horarios'][$dow] ?? null,
            'sem_turno' => $a === null,
            'batidas' => $dia['batidas'],
            'manual' => $dia['manual'],
            'obs' => $dia['obs'],
            'feriado' => $feriados[$data] ?? null,
            'atestado_dia' => $atestados['dias'][$data] ?? null,
            'parciais' => $atestados['parciais'][$data] ?? [],
            'fora_vigencia' => $inicio === null || $data < $inicio || ($desligamento !== null && $data > $desligamento),
            'futuro' => $data > $hoje,
            'hoje' => $data === $hoje,
        ];
    }
    $todas = ponto_apurar_periodo($ctxs, $cfg, 0);
    $linhas = array_values(array_filter($todas, static fn(array $l): bool => $l['data'] >= $ini_mes));
    $totais = ponto_apurar_totais($linhas);

    // turno de referencia do cabecalho: o vigente no ultimo dia do periodo que ja ocorreu
    $ref = $hoje < $ini_mes ? $ini_mes : min($fim_mes, $hoje);
    $atrib_ref = ponto_turno_vigente($atribuicoes, $ref);
    $mudou_turno = false;
    foreach ($atribuicoes as $a) {
        if ($a['vigencia'] > $ini_mes && $a['vigencia'] <= $fim_mes) {
            $mudou_turno = true;
        }
    }

    $hash = ponto_espelho_hash($linhas, $totais, $funcionario_id, $ano, $mes);
    $aceite = ponto_aceite_atual($funcionario_id, $ano, $mes, $fuso);
    if ($aceite !== null) {
        $aceite['alterado_depois'] = $aceite['hash'] !== $hash;
    }

    return [
        'funcionario' => [
            'id' => (int)$f['id'],
            'nome' => (string)$f['nome'],
            'cargo' => (string)($f['cargo'] ?? ''),
            'cpf' => (string)($f['cpf'] ?? ''),
            'pis' => (string)($f['pis'] ?? ''),
            'equipe' => (string)($f['equipe'] ?? ''),
            'admissao' => (string)($f['data_admissao'] ?? ''),
            'desligamento' => $desligamento ?? '',
            'fuso' => $fuso,
        ],
        'empresa' => ['razao_social' => $emp['razao_social'], 'unidade' => $emp['unidade'], 'cnpj' => $emp['cnpj'], 'inscricao_estadual' => $emp['inscricao_estadual']],
        'cfg' => $cfg,
        'periodo' => ['ano' => $ano, 'mes' => $mes, 'de' => $ini_mes, 'ate' => $fim_mes],
        'turno' => $atrib_ref === null ? null : ['nome' => $atrib_ref['nome'], 'horarios' => $atrib_ref['horarios'], 'mudou_no_periodo' => $mudou_turno],
        'sem_turno' => $atribuicoes === [],
        'linhas' => $linhas,
        'totais' => $totais,
        'aceite' => $aceite,
        'hash' => $hash,
        'gerado_em' => $agora_local->format('d/m/Y H:i'),
        'hoje' => $hoje,
    ];
}
