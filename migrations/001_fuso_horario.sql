-- Migracao 001: fuso horario por colaborador.
-- Rode UMA vez em bancos criados antes desta versao (idempotente: repetir nao faz mal):
--   sqlcmd -C -S localhost -U sa -d ponto -i migrations/001_fuso_horario.sql
--
-- Os instantes continuam gravados em UTC (registrado_em, criado_em); esta coluna
-- so define em que fuso cada colaborador ve os proprios horarios e fecha os dias.
-- O padrao e o horario de Brasilia (America/Sao_Paulo, UTC-03:00).
-- O ponto_app ja tem SELECT/INSERT/UPDATE na tabela inteira, entao a coluna nova
-- nao precisa de GRANT.

USE ponto;
GO

IF COL_LENGTH('dbo.funcionarios', 'fuso_horario') IS NULL
BEGIN
    ALTER TABLE dbo.funcionarios
        ADD fuso_horario VARCHAR(64) NOT NULL
            CONSTRAINT DF_funcionarios_fuso_horario DEFAULT 'America/Sao_Paulo';
END
GO
