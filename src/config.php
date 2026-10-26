<?php
declare(strict_types=1);

/**
 * Credenciais vem de variavel de ambiente (ideal, ex. via pool do PHP-FPM
 * ou systemd) ou de um config.local.php (nao versionado, ver
 * config.local.php.example). NUNCA hardcode segredo neste arquivo.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

function ponto_env(string $nome, string $padrao = ''): string
{
    $valor = getenv($nome);
    if ($valor !== false) {
        return $valor;
    }
    if (defined($nome)) {
        return (string)constant($nome);
    }
    return $padrao;
}

function ponto_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'odbc:Driver={ODBC Driver 18 for SQL Server};'
             . 'Server=' . ponto_env('PONTO_DB_HOST', 'localhost') . ';'
             . 'Database=' . ponto_env('PONTO_DB_NAME', 'ponto') . ';'
             . 'Encrypt=yes;TrustServerCertificate=yes;';
        $pdo = new PDO($dsn, ponto_env('PONTO_DB_USER', 'ponto_app'), ponto_env('PONTO_DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
    return $pdo;
}

function ponto_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function ponto_csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Token invalido.');
    }
}

const PONTO_LIMITE_INATIVIDADE_SEG = 60;

function ponto_require_login(): array
{
    if (empty($_SESSION['funcionario_id'])) {
        header('Location: /login.php');
        exit;
    }

    $agora = time();
    if (!empty($_SESSION['ultima_atividade']) && ($agora - $_SESSION['ultima_atividade']) > PONTO_LIMITE_INATIVIDADE_SEG) {
        $fid = (int)$_SESSION['funcionario_id'];
        session_destroy();
        ponto_log($fid, 'logout_inatividade');
        header('Location: /login.php?motivo=inatividade');
        exit;
    }
    $_SESSION['ultima_atividade'] = $agora;

    return [
        'id' => (int)$_SESSION['funcionario_id'],
        'nome' => (string)($_SESSION['funcionario_nome'] ?? ''),
    ];
}

/**
 * Grava 1 registro de ponto + a linha de integridade correspondente, numa
 * transacao so. Usado tanto pelo ponto normal (bater.php) quanto pelo
 * lancamento manual do admin (admin_lancamento.php) -- e o UNICO lugar que
 * faz INSERT em registros_ponto, pra garantir que a cadeia de hash nunca
 * fique dessincronizada.
 *
 * $registrado_em_manual: null = o banco decide a hora (SYSUTCDATETIME, ponto
 * normal). Se vier preenchido (formato 'YYYY-MM-DD HH:MM:SS'), usa esse valor
 * -- so o lancamento manual do admin deve passar isso, nunca o ponto normal.
 */
function ponto_gravar_registro(
    int $funcionario_id,
    string $tipo,
    string $ip,
    string $ua,
    bool $manual = false,
    ?string $observacao = null,
    ?int $lancado_por = null,
    ?string $registrado_em_manual = null
): int {
    $pdo = ponto_db();
    $pdo->beginTransaction();
    try {
        $host = ponto_hostname_dhcp($ip);

        // PDO_ODBC nao suporta lastInsertId() contra SQL Server -- usar
        // OUTPUT inserted.id pra pegar o novo id direto do proprio INSERT.
        if ($registrado_em_manual !== null) {
            $stmt = $pdo->prepare(
                'INSERT INTO dbo.registros_ponto (funcionario_id, tipo, registrado_em, ip_origem, user_agent, manual, observacao, lancado_por, hostname_origem) OUTPUT inserted.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$funcionario_id, $tipo, $registrado_em_manual, $ip, $ua, $manual ? 1 : 0, $observacao, $lancado_por, $host]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO dbo.registros_ponto (funcionario_id, tipo, ip_origem, user_agent, manual, observacao, lancado_por, hostname_origem) OUTPUT inserted.id VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$funcionario_id, $tipo, $ip, $ua, $manual ? 1 : 0, $observacao, $lancado_por, $host]);
        }
        $novo_id = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT funcionario_id, tipo, CONVERT(VARCHAR(33), registrado_em, 126) AS registrado_texto,
                    ip_origem, user_agent, manual, observacao, lancado_por
             FROM dbo.registros_ponto WHERE id = ?'
        );
        $stmt->execute([$novo_id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        // trava a ultima linha ate o commit, pra 2 gravacoes simultaneas nao
        // lerem o mesmo hash anterior e bifurcarem a cadeia
        $stmt = $pdo->prepare(
            'SELECT TOP 1 hash_linha FROM dbo.integridade_ponto WITH (UPDLOCK, HOLDLOCK) ORDER BY id DESC'
        );
        $stmt->execute();
        $hash_anterior = $stmt->fetchColumn();
        $hash_anterior = $hash_anterior !== false ? $hash_anterior : null;

        $hash_linha = hash('sha256', ponto_texto_hash($hash_anterior, $novo_id, $linha));

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.integridade_ponto (registro_id, hash_anterior, hash_linha) VALUES (?, ?, ?)'
        );
        $stmt->execute([$novo_id, $hash_anterior, $hash_linha]);

        $pdo->commit();
        return $novo_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Monta o texto que vira o hash de uma linha -- usado tanto na gravacao
 * quanto na verificacao (admin_verificar.php). Os dois lados tem que montar
 * EXATAMENTE igual, ou a verificacao da falso-positivo de alteracao.
 */
function ponto_texto_hash(?string $hash_anterior, int $id, array $linha): string
{
    return ($hash_anterior ?? 'GENESIS') . '|' . $id . '|' . $linha['funcionario_id'] . '|'
        . $linha['tipo'] . '|' . $linha['registrado_texto'] . '|' . $linha['ip_origem'] . '|'
        . $linha['user_agent'] . '|' . (string)$linha['manual'] . '|' . (string)($linha['observacao'] ?? '')
        . '|' . (string)($linha['lancado_por'] ?? '');
}

function ponto_minutos(string $hhmm): int
{
    [$h, $m] = explode(':', $hhmm);
    return ((int)$h) * 60 + (int)$m;
}

/**
 * Monta o relatorio mensal de 1 funcionario, 1 linha por DIA DO MES (inclui
 * dias sem nenhum registro). Usado tanto pela tela do colaborador
 * (meu_ponto.php) quanto pela exportacao pra contabilidade (admin_relatorio.php).
 *
 * Limitacao conhecida: turno que atravessa a meia-noite (ex. entrada 23h,
 * saida 4h do dia seguinte) aparece como 2 dias incompletos, um com a entrada
 * e outro com a saida -- nao soma como 1 turno so. O dado esta todo ali,
 * so nao fecha o total do dia automaticamente nesse caso.
 */
function ponto_relatorio_mensal(int $funcionario_id, int $ano, int $mes): array
{
    $stmt = ponto_db()->prepare('SELECT data_admissao, data_desligamento FROM dbo.funcionarios WHERE id = ?');
    $stmt->execute([$funcionario_id]);
    $datas = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $admissao = $datas['data_admissao'] ?? null;
    $desligamento = $datas['data_desligamento'] ?? null;

    $stmt = ponto_db()->prepare(
        'SELECT data_inicio, dias_afastamento, cid FROM dbo.atestados_medicos WHERE funcionario_id = ?'
    );
    $stmt->execute([$funcionario_id]);
    $diasAtestado = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $at) {
        $inicio = new DateTime((string)$at['data_inicio']);
        for ($i = 0; $i < (int)$at['dias_afastamento']; $i++) {
            $dataAt = $inicio->format('Y-m-d');
            $diasAtestado[$dataAt] = 'Atestado' . ($at['cid'] ? ' (CID ' . $at['cid'] . ')' : '');
            $inicio->modify('+1 day');
        }
    }

    $stmt = ponto_db()->prepare(
        'SELECT r.id, r.tipo, CONVERT(VARCHAR(19), r.registrado_em, 120) AS quando, r.manual, r.observacao,
                a.motivo AS anotacao
         FROM dbo.registros_ponto r
         LEFT JOIN dbo.anotacoes_ponto a ON a.registro_id = r.id
         WHERE r.funcionario_id = ? AND YEAR(r.registrado_em) = ? AND MONTH(r.registrado_em) = ?
         ORDER BY r.registrado_em'
    );
    $stmt->execute([$funcionario_id, $ano, $mes]);
    $regs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $porDia = [];
    foreach ($regs as $r) {
        $data = substr((string)$r['quando'], 0, 10);
        if (!isset($porDia[$data])) {
            $porDia[$data] = ['entrada' => null, 'almoco' => null, 'retorno' => null, 'saida' => null, 'obs' => []];
        }
        $hora = substr((string)$r['quando'], 11, 5);
        if ($porDia[$data][$r['tipo']] === null) {
            $porDia[$data][$r['tipo']] = $hora;
        }
        if ($r['observacao']) {
            $porDia[$data]['obs'][] = (string)$r['observacao'];
        }
        if ($r['anotacao']) {
            $porDia[$data]['obs'][] = 'ANOTACAO: ' . (string)$r['anotacao'];
        }
    }

    $dias_semana = [1 => 'Segunda', 2 => 'Terca', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sabado', 7 => 'Domingo'];
    $ultimo_dia = (int)date('t', mktime(0, 0, 0, $mes, 1, $ano));

    $linhas = [];
    for ($d = 1; $d <= $ultimo_dia; $d++) {
        $data = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
        $dow = (int)date('N', strtotime($data));
        $p = $porDia[$data] ?? ['entrada' => null, 'almoco' => null, 'retorno' => null, 'saida' => null, 'obs' => []];

        if (($admissao !== null && $data < $admissao) || ($desligamento !== null && $data > $desligamento)) {
            $linhas[] = [
                'data' => $data, 'dia_semana' => $dias_semana[$dow],
                'entrada' => '', 'almoco' => '', 'retorno' => '', 'saida' => '',
                'horas' => 'N/A', 'observacao' => '',
            ];
            continue;
        }

        if (isset($diasAtestado[$data])) {
            $linhas[] = [
                'data' => $data, 'dia_semana' => $dias_semana[$dow],
                'entrada' => '', 'almoco' => '', 'retorno' => '', 'saida' => '',
                'horas' => $diasAtestado[$data], 'observacao' => $diasAtestado[$data],
            ];
            continue;
        }

        $horas = '';
        if ($p['entrada'] !== null && $p['almoco'] !== null && $p['retorno'] !== null && $p['saida'] !== null) {
            $min = ponto_minutos($p['almoco']) - ponto_minutos($p['entrada'])
                 + ponto_minutos($p['saida']) - ponto_minutos($p['retorno']);
            if ($min < 0) {
                $min += 24 * 60;
            }
            $horas = sprintf('%d:%02d', intdiv($min, 60), $min % 60);
        } elseif ($p['entrada'] !== null || $p['almoco'] !== null || $p['retorno'] !== null || $p['saida'] !== null) {
            $horas = 'Incompleto';
        }

        $linhas[] = [
            'data' => $data,
            'dia_semana' => $dias_semana[$dow],
            'entrada' => $p['entrada'] ?? '',
            'almoco' => $p['almoco'] ?? '',
            'retorno' => $p['retorno'] ?? '',
            'saida' => $p['saida'] ?? '',
            'horas' => $horas,
            'observacao' => implode('; ', $p['obs']),
        ];
    }
    return $linhas;
}

/**
 * Qual o proximo tipo esperado no ciclo do dia (entrada -> almoco -> retorno
 * -> saida), ou null se o ciclo de hoje ja esta completo. So olha registros
 * NAO manuais -- lancamento manual do admin nao entra nessa sequencia (e
 * usado justamente pra excecao, ex. atendimento noturno fora do padrao).
 */
function ponto_proximo_tipo_do_dia(int $funcionario_id): ?string
{
    $sequencia = ['entrada', 'almoco', 'retorno', 'saida'];
    $stmt = ponto_db()->prepare(
        'SELECT tipo FROM dbo.registros_ponto
         WHERE funcionario_id = ? AND manual = 0 AND CAST(registrado_em AS DATE) = CAST(SYSUTCDATETIME() AS DATE)'
    );
    $stmt->execute([$funcionario_id]);
    $feitos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($sequencia as $tipo) {
        if (!in_array($tipo, $feitos, true)) {
            return $tipo;
        }
    }
    return null;
}

const PONTO_DHCP_LEASES = '/var/lib/dhcp/dhcpd.leases';

/**
 * Descobre o nome da estacao pelo arquivo de leases do DHCP (ja roda na
 * propria fs) -- alternativa simples ao SSO/Kerberos: sem risco de travar o
 * site, sem infra nova, so le um arquivo que o DHCP ja mantem. Pode nao
 * achar nome se o cliente nao mandou hostname naquela renovacao -- nesse
 * caso retorna null (nao e garantido pra 100% dos IPs).
 */
function ponto_hostname_dhcp(string $ip): ?string
{
    if ($ip === '' || !is_readable(PONTO_DHCP_LEASES)) {
        return null;
    }
    $conteudo = file_get_contents(PONTO_DHCP_LEASES);
    if ($conteudo === false) {
        return null;
    }
    if (!preg_match_all('/^lease ' . preg_quote($ip, '/') . ' \{(.*?)\n\}/ms', $conteudo, $blocos)) {
        return null;
    }
    $ultimo = end($blocos[1]);
    if ($ultimo === false) {
        return null;
    }
    if (preg_match('/client-hostname\s+"([^"]*)"/', $ultimo, $m)) {
        return $m[1] !== '' ? $m[1] : null;
    }
    return null;
}

function ponto_log(?int $funcionario_id, string $acao, string $detalhe = '', ?string $login_tentado = null): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $host = ponto_hostname_dhcp($ip);
    $stmt = ponto_db()->prepare(
        'INSERT INTO dbo.log_acesso (funcionario_id, login_tentado, acao, detalhe, ip_origem, user_agent, hostname_origem) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$funcionario_id, $login_tentado, $acao, $detalhe, $ip, $ua, $host]);
}

function ponto_require_admin(): array
{
    $func = ponto_require_login();
    $stmt = ponto_db()->prepare('SELECT is_admin FROM dbo.funcionarios WHERE id = ?');
    $stmt->execute([$func['id']]);
    $is_admin = (bool)$stmt->fetchColumn();
    if (!$is_admin) {
        http_response_code(403);
        exit('Acesso restrito ao administrador.');
    }
    $func['is_admin'] = true;
    return $func;
}
