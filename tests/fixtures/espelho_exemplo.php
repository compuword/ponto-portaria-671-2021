<?php
declare(strict_types=1);

/**
 * Espelho de EXEMPLO montado sem banco, para testar e gerar amostras das saidas (tela, PDF, XLSX, CSV).
 * Batidas do espelho modelo de 10/2021; pessoa e empresa sao FICTICIAS (CPF/CNPJ/PIS de teste conhecidos).
 * Exige config.php, apuracao.php e espelho.php carregados.
 *
 * @param array{aceite?:bool,alterado?:bool,sem_turno?:bool,manual?:bool,parcial?:bool} $o
 */
function espelho_exemplo(array $o = []): array
{
    $modelo = require __DIR__ . '/modelo_2021_10.php';
    $turno = ['ent1' => '08:30', 'sai1' => '12:00', 'ent2' => '13:30', 'sai2' => '18:00'];
    $feriados = ponto_feriados_nacionais(2021);
    $sem_turno = !empty($o['sem_turno']);
    $ctxs = [];
    foreach ($modelo as $m) {
        $dow = ponto_dow($m[0]);
        $ctxs[$m[0]] = [
            'turno' => ($dow >= 1 && $dow <= 5 && !$sem_turno) ? $turno : null,
            'sem_turno' => $sem_turno,
            'batidas' => ['ent1' => $m[1], 'sai1' => $m[2], 'ent2' => $m[3], 'sai2' => $m[4]],
            'manual' => (!empty($o['manual']) && $m[0] === '2021-10-04') ? ['sai1' => true, 'ent2' => true] : [],
            'obs' => (!empty($o['manual']) && $m[0] === '2021-10-04') ? ['esqueci de bater na saida do almoco'] : [],
            'feriado' => $feriados[$m[0]] ?? null,
            'parciais' => (!empty($o['parcial']) && $m[0] === '2021-10-13') ? [['ini' => '10:00', 'fim' => '11:00', 'cid' => 'Z00']] : [],
        ];
    }
    $cfg = ['tolerancia_min' => 10, 'noturno_ini' => 22 * 60, 'noturno_fim' => 5 * 60, 'fator_he1' => 1.0, 'fator_he2' => 2.0, 'abono_remunerado' => true];
    $linhas = ponto_apurar_periodo($ctxs, $cfg, $sem_turno ? 0 : 25 * 60 + 5);
    $totais = ponto_apurar_totais($linhas);
    $horarios = [];
    foreach ([1, 2, 3, 4, 5] as $d) {
        $horarios[$d] = $turno;
    }
    $aceite = null;
    if (!empty($o['aceite'])) {
        $aceite = [
            'aceito_em' => '25/11/2021 10:38:08', 'hash' => 'abc', 'protocolo' => 'e57ee16d4df0958273db9765bb9cc322',
            'ip' => '203.0.113.10', 'plataforma' => 'iOS', 'alterado_depois' => !empty($o['alterado']),
        ];
    }
    return [
        'funcionario' => [
            'id' => 1, 'nome' => 'Maria Exemplo da Silva', 'cargo' => 'Analista de Suporte Júnior', 'cpf' => '52998224725',
            'pis' => '12012345672', 'equipe' => 'Suporte técnico', 'admissao' => '', 'desligamento' => '', 'fuso' => 'America/Sao_Paulo',
        ],
        'empresa' => ['razao_social' => 'Empresa Exemplo Tecnologia Ltda', 'unidade' => 'Empresa Exemplo - Matriz', 'cnpj' => '11222333000181', 'inscricao_estadual' => '123.456.789.000'],
        'cfg' => $cfg,
        'periodo' => ['ano' => 2021, 'mes' => 10, 'de' => '2021-10-01', 'ate' => '2021-10-31'],
        'turno' => $sem_turno ? null : ['nome' => 'Adm - Flex', 'horarios' => $horarios, 'mudou_no_periodo' => false],
        'sem_turno' => $sem_turno,
        'linhas' => array_values($linhas),
        'totais' => $totais,
        'aceite' => $aceite,
        'hash' => 'abc',
        'gerado_em' => '03/12/2021 10:00',
        'hoje' => '2021-12-03',
    ];
}
