# Ponto Eletronico -- Portaria 671/2021

Sistema de ponto eletronico (REP-P) simples, em PHP puro, construido com os
**principios** da Portaria MTE 671/2021 (que regula o art. 74 da CLT) em
mente: imutabilidade dos registros, trilha de auditoria, e acesso do
colaborador ao proprio historico.

**Aviso importante**: isto NAO e um sistema homologado/certificado junto ao
MTE e NAO gera o arquivo AFD (Arquivo Fonte de Dados) que um fiscal do
trabalho pede. Se sua empresa precisa de homologacao formal, isso aqui serve
como base/referencia de design, nao como substituto de um REP-P certificado.
Consulte seu contador/advogado trabalhista antes de usar como ponto oficial.

## Por que nao usar um sistema pronto (Odoo, ERPNext, etc.)?

Nenhuma opcao open source mantida e ativa que avaliamos implementa as
caracteristicas da Portaria 671 (imutabilidade do registro, visibilidade do
colaborador) fora da caixa -- a maioria e generica (controle de horas pra
projeto/fatura) ou academica/desatualizada. Daqui a decisao de construir algo
pequeno e focado em vez de adaptar algo que nao foi pensado pra isso.

## Funcionalidades

- Login individual por colaborador (bcrypt).
- Ponto com **sequencia obrigatoria** entrada -> pausa -> retorno -> saida --
  o sistema so libera o botao da vez certa (client-side E server-side, nao
  da pra burlar so editando o HTML).
- Confirmacao antes de registrar, observacao opcional por registro.
- Colaborador ve o **proprio** historico completo, mes a mes.
- Atestado medico (CID, CRM, dias, arquivo anexo) -- o proprio colaborador
  pode enviar, ou o admin lanca em nome dele. Arquivo fica em BLOB no banco
  (nunca em disco). Dias cobertos por atestado aparecem automaticamente no
  relatorio no lugar das horas.
- Lancamento manual pelo admin, para dias em que nao foi possivel bater o
  ponto pelo sistema (ex. atendimento externo fora da rede da empresa).
- **Cadeia de hash separada** (tabela propria, so INSERT) -- qualquer
  alteracao de um registro ja gravado, feita por fora do app, e detectavel
  numa verificacao (`admin_verificar.php`).
- Anotacao nao destrutiva sobre um registro (ex. marcar um ponto de teste
  sem apagar nem alterar nada).
- Auditoria: todo login, logout (inclusive por inatividade), criacao/edicao
  de colaborador, reset de senha, lancamento manual e anotacao fica
  registrado com IP e, quando disponivel, o hostname da estacao de origem
  (lido do lease do DHCP -- ver "Limitacoes").
- Logout automatico por inatividade (5 minutos, configuravel em
  `PONTO_LIMITE_INATIVIDADE_SEG` no `src/config.php` e `LIMITE_MS` no
  `src/public/js/inatividade.js` -- altere os dois juntos).
- Falha de login mostra um banner de erro na propria tela de login (nunca
  uma pagina de erro do servidor).
- Exportacao para contabilidade: um colaborador ou todos, em XLSX, CSV
  (separado por `;`) ou PDF -- os 3 formatos gerados do zero em PHP puro,
  sem biblioteca externa.
- Cadastro de colaborador com cargo, data de admissao e desligamento (dias
  fora desse intervalo aparecem como "N/A" no relatorio, nao como falta).

## Stack

PHP 8.3+ (sem framework, sem Composer) + SQL Server (via PDO_ODBC + ODBC
Driver 18) + qualquer servidor web com PHP-FPM (nginx nos exemplos abaixo).
Sem dependencia de biblioteca externa nenhuma -- so extensoes do PHP core
(`pdo_odbc`, `zip` para o XLSX).

## Instalacao

1. **Banco**: rode `schema.sql` contra seu SQL Server (`sqlcmd -C -S
   localhost -U sa -i schema.sql`). Troque a senha do `ponto_app` que esta
   no script antes de rodar em produção, ou altere depois com `ALTER LOGIN`.

2. **PHP**: instale o driver ODBC (`apt install php8.X-odbc` no
   Debian/Ubuntu + [ODBC Driver 18 da Microsoft](https://learn.microsoft.com/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server)).

3. **Config**: copie `src/config.local.php.example` para
   `src/config.local.php` e preencha com os dados do seu banco (ou defina as
   mesmas chaves como variavel de ambiente do PHP-FPM -- nesse caso nao
   precisa do arquivo). `src/config.local.php` esta no `.gitignore` de
   proposito -- nunca comite credencial real.

4. **Web server**: aponte a raiz do site para `src/public/`. Exemplo de vhost
   nginx:

   ```nginx
   server {
       listen 80;
       server_name ponto.seudominio.local;
       root /caminho/para/src/public;
       index index.php;
       client_max_body_size 12M;   # upload de atestado

       location / {
           try_files $uri $uri/ /index.php?$args;
       }
       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.3-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       }
       location ~ /\.(ht|svn|git) { deny all; }
   }
   ```

   Ajuste tambem `upload_max_filesize`/`post_max_size` (ex. via
   `src/public/.user.ini`, ja incluido com 10M/12M).

5. **Primeiro acesso** (nao ha tela de "criar primeiro admin" -- e
   intencional, pra ninguem conseguir se promover a admin sem acesso direto
   ao banco):

   ```bash
   # gere o hash da senha
   php -r 'echo password_hash("SUA_SENHA_AQUI", PASSWORD_BCRYPT);'
   ```

   ```sql
   INSERT INTO dbo.funcionarios (login, nome, senha_hash, ativo, is_admin)
   VALUES ('seu_login', 'Seu Nome', '<hash gerado acima>', 1, 1);
   ```

   Depois disso, todo o resto (criar outros colaboradores, dar admin, reset
   de senha) e feito pela interface.

## Estrutura

```
schema.sql                      -- schema completo do banco
src/config.php                  -- conexao PDO + funcoes compartilhadas (auth, hash, relatorio)
src/config.local.php.example    -- template de credenciais (copie e edite)
src/xlsx.php                    -- gerador de .xlsx do zero (so ZipArchive)
src/pdf.php                     -- gerador de .pdf do zero (objetos PDF 1.4 a mao)
src/public/                     -- raiz web (as unicas paginas acessiveis via HTTP)
```

## Logo

As paginas referenciam `src/public/img/logo.png` (nao incluido aqui --
coloque a logo da sua empresa nesse caminho, ou remova a tag `<img>` de
`src/public/css` e das paginas se nao quiser logo).

## Limitacoes conhecidas

- Turno que atravessa a meia-noite (ex. entrada 23h, saida 4h do dia
  seguinte) aparece como 2 dias "Incompleto" no relatorio, nao soma como 1
  turno so -- o dado esta todo gravado, so o fechamento automatico do total
  nao junta os 2 dias.
- O hostname da estacao (lido do lease do DHCP) so aparece se o cliente
  tiver renovado o lease recentemente com hostname -- nao e garantido pra
  100% dos acessos. Nao tentamos SSO/Kerberos de proposito: adicionaria uma
  dependencia de infraestrutura (recompilar o nginx com modulo SPNEGO +
  keytab do AD) que, se mal configurada, pode travar o acesso ao site
  inteiro -- trade-off que nao vale a pena pro ganho.
- Sem HTTPS por padrao -- use um certificado (interno da sua CA ou
  Let's Encrypt) antes de usar em producao real, o login trafega senha.
