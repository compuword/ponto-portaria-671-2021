-- Migracao 003: dados complementares do espelho de ponto (empresa, CPF/PIS/equipe, turnos, feriados e aceite do espelho).
-- Rode UMA vez em bancos criados antes desta versao (idempotente: repetir nao faz mal):
--   sqlcmd -C -S localhost -U sa -d ponto -i migrations/003_espelho.sql
--
-- Principio do sistema mantido: o que e REGISTRO (turnos, atribuicao de turno, aceite do espelho) e so INSERT para o
-- ponto_app -- mudar a escala de alguem e criar um turno novo e atribui-lo a partir de uma data (vigencia), sem reescrever
-- o passado. So configuracao simples (empresa, feriados) tem UPDATE/DELETE.

USE ponto;
GO

-- 1) colaborador: CPF e PIS (so digitos), equipe
IF COL_LENGTH('dbo.funcionarios', 'cpf') IS NULL
BEGIN
    ALTER TABLE dbo.funcionarios ADD cpf VARCHAR(11) NULL, pis VARCHAR(11) NULL, equipe NVARCHAR(100) NULL;
END
GO

-- 2) empresa (uma linha so) + regras de apuracao
IF OBJECT_ID('dbo.empresa', 'U') IS NULL
BEGIN
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
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.empresa)
BEGIN
    INSERT INTO dbo.empresa (id) VALUES (1);
END
GO

-- 3) turnos (escala semanal em JSON: {"1":{"ent1":"08:30","sai1":"12:00","ent2":"13:30","sai2":"18:00"}, ...}; dia 0 = domingo; dia ausente = folga)
IF OBJECT_ID('dbo.turnos', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.turnos (
        id INT IDENTITY(1,1) PRIMARY KEY,
        nome NVARCHAR(100) NOT NULL CONSTRAINT UQ_turnos_nome UNIQUE,
        horarios NVARCHAR(1000) NOT NULL CONSTRAINT CK_turnos_json CHECK (ISJSON(horarios) = 1),
        criado_por INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
        criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );
END
GO

-- 4) qual turno cada colaborador cumpre a partir de que data (historico: vale o de maior vigencia_inicio <= dia)
IF OBJECT_ID('dbo.funcionario_turno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.funcionario_turno (
        id INT IDENTITY(1,1) PRIMARY KEY,
        funcionario_id INT NOT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
        turno_id INT NOT NULL FOREIGN KEY REFERENCES dbo.turnos(id),
        vigencia_inicio DATE NOT NULL,
        criado_por INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
        criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_funcionario_turno ON dbo.funcionario_turno (funcionario_id, vigencia_inicio);
END
GO

-- 5) feriados estaduais/municipais/pontes (os nacionais ja vem calculados pelo sistema)
IF OBJECT_ID('dbo.feriados', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.feriados (
        data DATE NOT NULL PRIMARY KEY,
        nome NVARCHAR(100) NOT NULL,
        criado_por INT NULL FOREIGN KEY REFERENCES dbo.funcionarios(id),
        criado_em DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );
END
GO

-- 6) aceite eletronico do espelho do mes pelo proprio colaborador (so INSERT; o hash prova qual conteudo foi aceito)
IF OBJECT_ID('dbo.aceites_espelho', 'U') IS NULL
BEGIN
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
END
GO

IF USER_ID('ponto_app') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON dbo.empresa TO ponto_app;
    GRANT SELECT, INSERT ON dbo.turnos TO ponto_app;
    GRANT SELECT, INSERT ON dbo.funcionario_turno TO ponto_app;
    GRANT SELECT, INSERT, DELETE ON dbo.feriados TO ponto_app;
    GRANT SELECT, INSERT ON dbo.aceites_espelho TO ponto_app;
END
GO
