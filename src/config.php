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

function ponto_csrf_valido(): bool
{
    $token = $_POST['csrf'] ?? '';
    return isset($_SESSION['csrf']) && is_string($token) && hash_equals((string)$_SESSION['csrf'], $token);
}

function ponto_csrf_check(): void
{
    if (!ponto_csrf_valido()) {
        http_response_code(403);
        exit('Token invalido.');
    }
}

// Sessao encerrada apos 5 minutos sem atividade. O mesmo valor existe no
// navegador (public/js/inatividade.js, LIMITE_MS) -- altere os dois juntos.
const PONTO_LIMITE_INATIVIDADE_SEG = 300;

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
 * Fuso horario. O banco guarda TODO instante em UTC (registrado_em,
 * criado_em) e isso nunca muda: o registro e a cadeia de hash continuam
 * exatamente como foram gravados. A conversao para o horario local do
 * colaborador acontece so na EXIBICAO e na agregacao por dia/mes. O fuso de
 * cada colaborador fica em funcionarios.fuso_horario (identificador IANA).
 */
const PONTO_FUSO_PADRAO = 'America/Sao_Paulo';

/** Identificadores aceitos, agrupados (Brasil primeiro). */
function ponto_fusos_disponiveis(): array
{
    $brasil = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'BR');
    $outros = array_values(array_diff(DateTimeZone::listIdentifiers(), $brasil));
    return ['Brasil' => $brasil, 'Outros' => $outros];
}

function ponto_fuso_valido(string $fuso): bool
{
    return in_array($fuso, DateTimeZone::listIdentifiers(), true);
}

/** O proprio fuso se for valido, senao o padrao. */
function ponto_fuso_ou_padrao(?string $fuso): string
{
    return $fuso !== null && ponto_fuso_valido($fuso) ? $fuso : PONTO_FUSO_PADRAO;
}

/** Ex.: 'America/Sao_Paulo (UTC-03:00)'. */
function ponto_rotulo_fuso(string $fuso): string
{
    $deslocamento = (new DateTimeImmutable('now', new DateTimeZone($fuso)))->format('P');
    return $fuso . ' (UTC' . $deslocamento . ')';
}

/** <optgroup>/<option> prontos para um <select name="fuso_horario">. */
function ponto_opcoes_fuso_html(string $selecionado): string
{
    $html = '';
    foreach (ponto_fusos_disponiveis() as $grupo => $ids) {
        $html .= '<optgroup label="' . htmlspecialchars($grupo) . '">';
        foreach ($ids as $id) {
            $html .= '<option value="' . htmlspecialchars($id) . '"' . ($id === $selecionado ? ' selected' : '') . '>'
                   . htmlspecialchars(ponto_rotulo_fuso($id)) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

/**
 * Fuso do colaborador. Cai no padrao se a coluna ainda nao existe (banco sem
 * a migracao migrations/001_fuso_horario.sql) ou se o valor for invalido.
 */
function ponto_fuso_do_funcionario(int $funcionario_id): string
{
    static $cache = [];
    if (!isset($cache[$funcionario_id])) {
        try {
            $stmt = ponto_db()->prepare('SELECT fuso_horario FROM dbo.funcionarios WHERE id = ?');
            $stmt->execute([$funcionario_id]);
            $fuso = (string)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $fuso = '';
        }
        $cache[$funcionario_id] = ponto_fuso_ou_padrao($fuso);
    }
    return $cache[$funcionario_id];
}

function ponto_agora_local(string $fuso): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone($fuso));
}

/** Instante UTC como vem do banco ('YYYY-MM-DD HH:MM:SS[.fff]') -> texto no fuso pedido. */
function ponto_utc_para_local(?string $utc, string $fuso, string $formato = 'Y-m-d H:i:s'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable(substr($utc, 0, 19), new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone($fuso))->format($formato);
    } catch (Exception $e) {
        return $utc;
    }
}

/** Horario de parede no fuso do colaborador ('Y-m-d H:i:s') -> UTC ('Y-m-d H:i:s'), para gravar. */
function ponto_local_para_utc(string $local, string $fuso): string
{
    $dt = new DateTimeImmutable($local, new DateTimeZone($fuso));
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * [inicio, fim) do dia civil que contem $referencia (padrao: agora) no fuso,
 * ja em UTC -- para filtrar registros_ponto por dia LOCAL do colaborador.
 */
function ponto_limites_dia_utc(string $fuso, ?DateTimeImmutable $referencia = null): array
{
    $tz = new DateTimeZone($fuso);
    $utc = new DateTimeZone('UTC');
    $dia = ($referencia ?? new DateTimeImmutable('now', $tz))->setTimezone($tz)->setTime(0, 0, 0);
    return [
        $dia->setTimezone($utc)->format('Y-m-d H:i:s'),
        $dia->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
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
 * normal). Se vier preenchido (formato 'YYYY-MM-DD HH:MM:SS', SEMPRE EM UTC --
 * use ponto_local_para_utc() para converter o horario digitado), usa esse valor
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

/** A coluna existe no banco? Cache por requisicao; banco sem a migracao correspondente -> false. */
function ponto_tem_coluna(string $tabela, string $coluna): bool
{
    static $cache = [];
    $chave = $tabela . '.' . $coluna;
    if (!isset($cache[$chave])) {
        try {
            $stmt = ponto_db()->prepare('SELECT COL_LENGTH(?, ?)');
            $stmt->execute(['dbo.' . $tabela, $coluna]);
            $valor = $stmt->fetchColumn();
            $cache[$chave] = $valor !== false && $valor !== null;
        } catch (PDOException $e) {
            $cache[$chave] = false;
        }
    }
    return $cache[$chave];
}

/** A tabela existe no banco? Cache por requisicao. */
function ponto_tem_tabela(string $tabela): bool
{
    static $cache = [];
    if (!isset($cache[$tabela])) {
        try {
            $stmt = ponto_db()->prepare("SELECT OBJECT_ID(?, 'U')");
            $stmt->execute(['dbo.' . $tabela]);
            $valor = $stmt->fetchColumn();
            $cache[$tabela] = $valor !== false && $valor !== null;
        } catch (PDOException $e) {
            $cache[$tabela] = false;
        }
    }
    return $cache[$tabela];
}

/** O banco ja tem as colunas do atestado parcial (migrations/002_atestado_parcial.sql)? */
function ponto_tem_atestado_parcial(): bool
{
    return ponto_tem_coluna('atestados_medicos', 'hora_inicio');
}

/**
 * Valida o par de horas de um atestado parcial (consulta/exame no mesmo dia).
 * Retorna [inicio 'HH:MM:SS', fim 'HH:MM:SS', erro]; erro null = ok.
 */
function ponto_validar_horas_atestado(string $inicio, string $fim): array
{
    $formato_ok = static fn(string $h): bool => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $h);
    if (!$formato_ok($inicio) || !$formato_ok($fim)) {
        return [null, null, 'Informe a hora em que saiu e a hora em que voltou (formato HH:MM).'];
    }
    $inicio = substr($inicio, 0, 5);
    $fim = substr($fim, 0, 5);
    if ($fim <= $inicio) {
        return [null, null, 'A hora de volta precisa ser depois da hora de saida (mesmo dia).'];
    }
    return [$inicio . ':00', $fim . ':00', null];
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
    // "hoje" e o dia civil no fuso do colaborador (e nao o dia UTC, que vira as 21h em Brasilia)
    [$inicio_dia, $fim_dia] = ponto_limites_dia_utc(ponto_fuso_do_funcionario($funcionario_id));
    $stmt = ponto_db()->prepare(
        'SELECT tipo FROM dbo.registros_ponto
         WHERE funcionario_id = ? AND manual = 0 AND registrado_em >= ? AND registrado_em < ?'
    );
    $stmt->execute([$funcionario_id, $inicio_dia, $fim_dia]);
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
