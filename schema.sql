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
    criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
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
GO

PRINT 'Schema criado. Crie o primeiro admin manualmente (veja o README, secao "Primeiro acesso").';
GO
