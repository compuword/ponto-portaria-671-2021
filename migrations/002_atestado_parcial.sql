-- Migracao 002: atestado PARCIAL (consulta/exame no meio do expediente, com volta no mesmo dia).
-- Rode UMA vez em bancos criados antes desta versao (idempotente: repetir nao faz mal):
--   sqlcmd -C -S localhost -U sa -d ponto -i migrations/002_atestado_parcial.sql
--
-- Atestado de dia(s) inteiro(s) continua igual (hora_inicio e hora_fim NULL). No parcial,
-- hora_inicio/hora_fim sao o horario de parede, no fuso do colaborador, em que ele saiu e voltou
-- em data_inicio (dias_afastamento = 1). A tabela segue so com INSERT para o ponto_app, entao as
-- colunas novas nao precisam de GRANT.

USE ponto;
GO

IF COL_LENGTH('dbo.atestados_medicos', 'hora_inicio') IS NULL
BEGIN
    ALTER TABLE dbo.atestados_medicos ADD hora_inicio TIME(0) NULL, hora_fim TIME(0) NULL;
END
GO

IF OBJECT_ID('dbo.CK_atestados_parcial', 'C') IS NULL
BEGIN
    ALTER TABLE dbo.atestados_medicos ADD CONSTRAINT CK_atestados_parcial CHECK (
        (hora_inicio IS NULL AND hora_fim IS NULL)
        OR (hora_inicio IS NOT NULL AND hora_fim IS NOT NULL AND hora_fim > hora_inicio AND dias_afastamento = 1)
    );
END
GO
