# Espelho de ponto: análise do modelo e regras aplicadas

O espelho de ponto deste sistema segue o formato de um **espelho de ponto modelo** (documento de exemplo de um sistema comercial,
uma página, mês de 10/2021, jornada de 8 h com 1 h30 de intervalo). Este arquivo registra o que o modelo mostra, as regras de
cálculo que dele se extraem e onde cada uma está no código. As regras foram conferidas **dia a dia** contra os números do
modelo (`tests/apuracao_test.php` reproduz as 31 linhas, os totais e o saldo). O repositório guarda só horários e números do
modelo (`tests/fixtures/modelo_2021_10.php`), nenhum dado pessoal.

## Estrutura do documento

1. **Cabeçalho**: logo, título "Espelho ponto", período ("De 01/10/2021 até 31/10/2021") e quem gerou e quando.
2. **Quadro de identificação**: Unidade de negócio, CNPJ, Inscrição estadual, Nome, PIS, CPF, Cargo, Equipe.
3. **Quadro do turno**: nome do turno e a jornada prevista de cada dia da semana (1ª Ent, 1ª Saí, 2ª Ent, 2ª Saí).
4. **Tabela diária** (uma linha por dia, "Sex, 01/10/2021"), em 14 colunas e 3 grupos:
   `Pontos` (1ª Entrada, 1ª Saída, 2ª Entrada, 2ª Saída) · `Totais da jornada` (Crédito, Débito, H. intervalo, Horas normais) ·
   `Total de H. Extras` (H.E. 1 (50%), H.E. 2 (100%), A. N.) · Saldo · Motivo.
5. **Linha TOTAIS** das colunas de horas.
6. **Comprovante de aceite eletrônico**: colaborador, data e hora, protocolo, IP e plataforma (e a imagem da assinatura).
7. **Legenda**: H.E. 1 / H.E. 2 e o fator do banco de horas, A. N., marcações manuais, e as marcas de desconto de DSR.

## Regras de cálculo extraídas

| Regra | O que o modelo mostra | No código |
|---|---|---|
| **Previsto do dia** = (1ª saída − 1ª entrada) + (2ª saída − 2ª entrada) do turno | Seg a Sex 08:30-12:00 e 13:30-18:00 = 8:00; sábado e domingo sem jornada | `ponto_previsto()` |
| **Trabalhado** = soma dos blocos das batidas | 01/10: 08:31-12:09 + 14:27-18:05 = 7:16 | `ponto_apurar_dia()` |
| **H. intervalo** = 2ª entrada − 1ª saída | 01/10: 14:27 − 12:09 = 02:18 | `ponto_apurar_dia()` |
| **Tolerância diária de 10 min**: diferença de até 10 min não gera crédito nem débito (conta como o previsto) | 15/10 (−2), 19/10 (+2), 20/10 (−1), 28/10 (−8) e 26/10 (−10) ficam com Horas normais 08:00 e zeros | `cfg.tolerancia_min` |
| Acima da tolerância conta a diferença **inteira** | 04/10: +20 min vira Crédito 00:20; 08/10: −14 min vira Débito 00:14 | `ponto_apurar_dia()` |
| **Horas normais** = trabalhado limitado ao previsto; **Crédito** = excedente; **Débito** = o que faltou | 07/10: trabalhou 6:49 → Horas normais 06:49, Débito 01:11 | idem |
| Em dia útil o crédito é **H.E. 1 (50%)** | Crédito = H.E. 1 em todos os dias com excedente | idem |
| **Saldo** = banco de horas acumulado: + H.E. × fator − débitos, com saldo inicial | começa em 25:05 e termina em 18:09; 21/10: −04:03 | `ponto_apurar_periodo()` |
| **Folga** (dia sem jornada) e **feriado**: tudo zero, Motivo preenchido | sáb/dom = "Folga"; 12/10 = "Dia de Nossa Senhora Aparecida" | `ponto_feriados_nacionais()` |
| Marcações faltando: usa só o que existe | 21/10: só a 1ª metade (3:57) e Débito de 4:03 | `incompleto` |
| **Motivo** "Ajuste" quando a batida foi lançada/alterada | linhas com batida sublinhada em vermelho | `manual` |

## O que ficou diferente de propósito

- **Assinatura**: o modelo mostra uma imagem de assinatura. Aqui o colaborador faz o **aceite eletrônico** do espelho do mês
  (autenticado por login e senha, com data/hora, protocolo, IP e plataforma). O protocolo é um hash do conteúdo aceito: se algo
  mudar depois (nova batida, ajuste, atestado, regra da empresa), o espelho mostra "ATENÇÃO: alterado depois do aceite".
- **Marcações "P" (pré-assinalada) e "O" (offline)**: este sistema não tem esses tipos de batida; ficam fora da legenda.
- **DSR** (marcas `*`, `**`, `***`): o desconto do descanso semanal remunerado é regra de folha, **não é calculado**. O espelho
  mostra "Falta" nos dias sem batida, sem justificativa e sem jornada dispensada, e a contabilidade aplica a regra.
- **Adicional noturno (A. N.)**: mostra o **tempo trabalhado** entre 22:00 e 05:00 (janela configurável). A hora noturna reduzida
  de 52 min 30 s e o percentual ficam para a contabilidade.
- **Saldo inicial**: o saldo começa em 00:00 no início do controle do colaborador (maior data entre a primeira batida, a admissão e
  o "início do controle" da empresa). Não há importação de saldo de outro sistema.
- **Turno que atravessa a meia-noite** continua uma limitação conhecida (aparece como dois dias incompletos).

## Acréscimos deste sistema em relação ao modelo

- **Atestado parcial** (consulta ou exame no meio do expediente, com volta no mesmo dia): o tempo fora é descontado das horas
  trabalhadas e, se a empresa o abona (padrão), não gera débito; aparece no Motivo ("Atestado parcial 11:00-12:00 (CID ...)") e
  na coluna "Atestado parcial (horas)" das exportações CSV e XLSX.
- **Fuso horário por colaborador**: dias, meses e horários seguem o fuso do colaborador; o banco guarda tudo em UTC.
- **Turnos com vigência**: mudar a escala de alguém é criar um turno novo e atribuí-lo a partir de uma data; o passado não muda.

## Dados que o espelho pede (e onde se cadastra)

| Dado | Onde |
|---|---|
| Razão social, unidade, CNPJ, inscrição estadual, tolerância, janela noturna, fatores do banco de horas, abono do atestado parcial, início do controle | Administração → Empresa |
| CPF, PIS/PASEP, equipe | Administração → editar colaborador |
| Turnos e quem cumpre qual turno a partir de que data | Administração → Turnos |
| Feriados estaduais, municipais e pontes (os nacionais já vêm calculados) | Administração → Feriados |
