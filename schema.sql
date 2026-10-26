-- Schema completo do banco "ponto" (SQL Server). Rode com sqlcmd como sa
-- (ou outro login com permissao de CREATE DATABASE):
--   sqlcmd -C -S localhost -U sa -i schema.sql
--
-- Troque a senha de ponto_app abaixo antes de rodar em produção.

IF DB_ID('ponto') IS NULL
    CREATE DATABASE ponto;
GO

USE ponto;
GO

CREATE TABLE dbo.funcionarios (
    id INT IDENTITY(1,1) PRIMARY KEY,
    login NVARCHAR(50) NOT NULL UNIQUE,
    nome NVARCHAR(200) NOT NULL,
    senha_hash NVARCHAR(255) NOT NULL,
    ativo BIT NOT NULL DEFAULT 1,
    is_admin BIT NOT NULL DEFAULT 0,
    cargo NVARCHAR(100) NULL,
    data_admissao DATE NULL,
    data_desligamento DATE NULL,
    -- Fuso em que o colaborador ve os proprios horarios e fecha os dias
    -- (identificador IANA). Os instantes continuam gravados em UTC.
    fuso_horario VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
    -- Dados que aparecem no cabecalho do espelho de ponto (so digitos em cpf/pis).
    cpf VARCHAR(11) NULL,
    pis VARCHAR(11) NULL,
    equipe NVARCHAR(100) NULL,
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE TABLE dbo.registros_ponto (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    funcionario_id INT NOT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('entrada','almoco','retorno','saida')),
    registrado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    ip_origem VARCHAR(45) NOT NULL,
    user_agent NVARCHAR(500) NULL,
    hostname_origem VARCHAR(255) NULL,
    manual BIT NOT NULL DEFAULT 0,
    observacao NVARCHAR(500) NULL,
    lancado_por INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- Cadeia de hash EM TABELA SEPARADA de registros_ponto (de proposito --
-- quem conseguir alterar um registro direto no banco teria que tambem
-- recalcular esta tabela, que nenhum app tem permissao de UPDATE).
CREATE TABLE dbo.integridade_ponto (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    registro_id BIGINT NOT NULL FOREIGN KEY REFERENCES dbo.registros_ponto(id),
    hash_anterior VARCHAR(64) NULL,
    hash_linha VARCHAR(64) NOT NULL,
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- Nota NAO destrutiva sobre um registro (ex. "foi teste, nao computar").
-- Nunca altera o registro original.
CREATE TABLE dbo.anotacoes_ponto (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    registro_id BIGINT NOT NULL FOREIGN KEY REFERENCES dbo.registros_ponto(id),
    motivo NVARCHAR(500) NOT NULL,
    criado_por INT NOT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE TABLE dbo.log_acesso (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    funcionario_id INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    login_tentado NVARCHAR(50) NULL,
    acao VARCHAR(40) NOT NULL,
    detalhe NVARCHAR(500) NULL,
    ip_origem VARCHAR(45) NOT NULL,
    user_agent NVARCHAR(500) NULL,
    hostname_origem VARCHAR(255) NULL,
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- Atestado medico / afastamento. O arquivo fica em BLOB no proprio banco
-- (nunca em disco) -- dado sensivel de saude, so admin acessa.
CREATE TABLE dbo.atestados_medicos (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    funcionario_id INT NOT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    data_inicio DATE NOT NULL,
    dias_afastamento INT NOT NULL,
    -- Atestado PARCIAL (consulta/exame no meio do expediente, volta no mesmo dia):
    -- hora de saida e de volta em data_inicio, no fuso do colaborador. Ambas NULL =
    -- afastamento de dia(s) inteiro(s).
    hora_inicio TIME(0) NULL,
    hora_fim TIME(0) NULL,
    cid VARCHAR(10) NULL,
    crm_medico VARCHAR(20) NULL,
    observacao NVARCHAR(500) NULL,
    arquivo_nome NVARCHAR(255) NULL,
    arquivo_tipo VARCHAR(100) NULL,
    arquivo_blob VARBINARY(MAX) NULL,
    registrado_por INT NOT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    ip_origem VARCHAR(45) NULL,
    user_agent NVARCHAR(500) NULL,
    hostname_origem VARCHAR(255) NULL,
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT CK_atestados_parcial CHECK (
        (hora_inicio IS NULL AND hora_fim IS NULL)
        OR (hora_inicio IS NOT NULL AND hora_fim IS NOT NULL AND hora_fim > hora_inicio AND dias_afastamento = 1)
    )
);
GO

-- Empresa (uma linha so): cabecalho do espelho e regras de apuracao.
CREATE TABLE dbo.empresa (
    id TINYINT NOT NULL CONSTRAINT PK_empresa PRIMARY KEY CONSTRAINT CK_empresa_unica CHECK (id = 1),
    razao_social NVARCHAR(200) NULL,
    unidade NVARCHAR(200) NULL,
    cnpj VARCHAR(14) NULL,
    inscricao_estadual VARCHAR(30) NULL,
    tolerancia_min SMALLINT NOT NULL CONSTRAINT DF_empresa_tol DEFAULT 10 CONSTRAINT CK_empresa_tol CHECK (tolerancia_min BETWEEN 0 AND 60),
    noturno_inicio TIME(0) NOT NULL CONSTRAINT DF_empresa_ni DEFAULT '22:00',
    noturno_fim TIME(0) NOT NULL CONSTRAINT DF_empresa_nf DEFAULT '05:00',
    fator_he1 DECIMAL(4,2) NOT NULL CONSTRAINT DF_empresa_f1 DEFAULT 1.0,
    fator_he2 DECIMAL(4,2) NOT NULL CONSTRAINT DF_empresa_f2 DEFAULT 2.0,
    abono_remunerado BIT NOT NULL CONSTRAINT DF_empresa_abono DEFAULT 1,
    inicio_controle DATE NULL,
    atualizado_em DATETIME2 NOT NULL CONSTRAINT DF_empresa_atu DEFAULT SYSUTCDATETIME()
);
GO

INSERT INTO dbo.empresa (id) VALUES (1);
GO

-- Turnos (escala semanal em JSON; dia 0 = domingo; dia ausente = folga) e quem cumpre qual turno a partir de que data.
-- So INSERT: mudar a escala de alguem = turno novo + atribuicao com nova vigencia, sem reescrever o passado.
CREATE TABLE dbo.turnos (
    id INT IDENTITY(1,1) PRIMARY KEY,
    nome NVARCHAR(100) NOT NULL CONSTRAINT UQ_turnos_nome UNIQUE,
    horarios NVARCHAR(1000) NOT NULL CONSTRAINT CK_turnos_json CHECK (ISJSON(horarios) = 1),
    criado_por INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE TABLE dbo.funcionario_turno (
    id INT IDENTITY(1,1) PRIMARY KEY,
    funcionario_id INT NOT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    turno_id INT NOT NULL FOREIGN KEY REFERENCES dbo.turnos(id),
    vigencia_inicio DATE NOT NULL,
    criado_por INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
CREATE INDEX IX_funcionario_turno ON dbo.funcionario_turno (funcionario_id, vigencia_inicio);
GO

-- Feriados estaduais/municipais/pontes (os nacionais sao calculados pelo sistema).
CREATE TABLE dbo.feriados (
    data DATE NOT NULL PRIMARY KEY,
    nome NVARCHAR(100) NOT NULL,
    criado_por INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- Aceite eletronico do espelho do mes pelo proprio colaborador; o hash prova qual conteudo foi aceito.
CREATE TABLE dbo.aceites_espelho (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    funcionario_id INT NOT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
    ano SMALLINT NOT NULL,
    mes TINYINT NOT NULL,
    espelho_hash CHAR(64) NOT NULL,
    protocolo CHAR(32) NOT NULL,
    ip_origem VARCHAR(45) NOT NULL,
    user_agent NVARCHAR(500) NULL,
    hostname_origem VARCHAR(255) NULL,
    aceito_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
CREATE INDEX IX_aceites_espelho ON dbo.aceites_espelho (funcionario_id, ano, mes);
GO

-- Usuario da aplicacao -- TROQUE a senha abaixo.
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = 'ponto_app')
    CREATE LOGIN ponto_app WITH PASSWORD = 'TROQUE_ESTA_SENHA_123!', CHECK_POLICY = ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = 'ponto_app')
    CREATE USER ponto_app FOR LOGIN ponto_app;
GO

-- Principio central do sistema: nenhuma tabela de REGISTRO tem UPDATE/DELETE
-- liberado pra app. So "funcionarios" (cadastro) e editavel -- correcao de
-- ponto e sempre uma linha nova (anotacao), nunca um UPDATE no original.
GRANT SELECT, INSERT, UPDATE ON dbo.funcionarios TO ponto_app;
GRANT SELECT, INSERT ON dbo.registros_ponto TO ponto_app;
GRANT SELECT, INSERT ON dbo.integridade_ponto TO ponto_app;
GRANT SELECT, INSERT ON dbo.anotacoes_ponto TO ponto_app;
GRANT SELECT, INSERT ON dbo.log_acesso TO ponto_app;
GRANT SELECT, INSERT ON dbo.atestados_medicos TO ponto_app;
-- Configuracao simples: empresa e feriados sao editaveis; turnos, atribuicao de turno e aceite so INSERT.
GRANT SELECT, INSERT, UPDATE ON dbo.empresa TO ponto_app;
GRANT SELECT, INSERT ON dbo.turnos TO ponto_app;
GRANT SELECT, INSERT ON dbo.funcionario_turno TO ponto_app;
GRANT SELECT, INSERT, DELETE ON dbo.feriados TO ponto_app;
GRANT SELECT, INSERT ON dbo.aceites_espelho TO ponto_app;
GO

PRINT 'Schema criado. Crie o primeiro admin manualmente (veja o README, secao "Primeiro acesso").';
GO
