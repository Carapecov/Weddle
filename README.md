# Weddell Pay

API educacional de pagamentos e Split Payment tributário **inteiramente simulados**.
Não movimenta dinheiro, emite NF-e válida, recolhe tributos ou se conecta a bancos/PSPs/RFB/CGIBS.
CBS e IBS são valores nominais fictícios do documento de demonstração; não são alíquotas oficiais.

## Escopo

Monólito modular Laravel 13, PostgreSQL 17, BRL em centavos inteiros e gateway determinístico.
Vendedores/documentos fictícios, criação/autorização/liquidação, razão de partidas dobradas,
consultas/auditoria e estorno integral. A demonstração local também inclui uma interface web
sem login real: cada sessão cria um vendedor fictício isolado e credenciais permanecem apenas no servidor.

- [Contrato OpenAPI](docs/openapi.yaml) — JSON formatado, válido como YAML 1.2.
- [Arquitetura](docs/arquitetura.md), [modelo](docs/modelo-de-dados.md), [premissas](docs/premissas-simulacao.md).
- [Ameaças/controles](docs/ameacas-e-controles.md), [riscos](docs/registro-riscos.md).
- [Plano de testes](docs/plano-testes.md), [matriz](docs/matriz-rastreabilidade.md).
- [Coleção HTTP](docs/weddell-pay.http).
- [Contrato web](docs/contrato-web.md) e [OpenAPI complementar](docs/openapi-web.yaml).
- [Planejamento e histórico MCP](BlackBoard/tasks/PLAN-001.md).

## Preparação local (Windows / PowerShell)

Requisitos: PHP de 64 bits compatível com composer.lock (validado em 8.5), Composer,
Docker Desktop com mecanismo Linux ativo e extensão PHP pdo_pgsql disponível.
As versões do framework e bibliotecas estão em src/api/composer.lock.
Scripts carregam pdo_pgsql somente no comando, sem editar o php.ini global.

Na raiz:

~~~powershell
.\scripts\setup.ps1
.\scripts\serve.ps1
~~~

A aplicação escuta http://127.0.0.1:8000; abra essa URL para acessar a landing page, o painel,
o simulador, o histórico e a documentação. PostgreSQL é publicado somente em 127.0.0.1:55432.
O setup gera senhas/chaves privadas em .env e src/api/.env[.testing], ignorados pelo Git.
Não copie esses arquivos para exemplos públicos. Nunca use credenciais ou dados de produção.

O setup preserva o volume existente e aplica migrações; não executa migrate:fresh no banco de demonstração.
Migrações usam weddell_owner; a API usa weddell_app sem superuser, sem DDL e sem alteração/exclusão
de lançamentos, eventos ou marca de liquidação. O banco weddell_pay_test é separado.

Se pdo_pgsql não estiver instalado, habilite/instale o driver correspondente ao seu PHP antes do setup.
Se sua distribuição Composer não tiver composer.phar ao lado do comando, execute composer install
em src/api usando um PHP com pdo_pgsql e depois o script scripts/database.php.

## Credenciais de demonstração e fluxo

Provisionamento é uma operação local de CLI; nenhuma rota HTTP emite chaves:

~~~powershell
.\scripts\php.ps1 artisan demo:operator
# Use a chave mostrada uma vez como Authorization: Bearer <chave> no POST /api/v1/merchants.
# Depois de criar o vendedor, use seu UUID:
.\scripts\php.ps1 artisan demo:merchant-key <UUID_DO_VENDEDOR>
~~~

Não cole chaves em URLs, logs ou repositórios. Exemplos HTTP contêm apenas placeholders.
A credencial de operador cadastra vendedores; a de vendedor acessa somente seus documentos/pagamentos.
Recursos de outros vendedores respondem 404.

1. Criar vendedor fictício (operador).
2. Emitir sua chave localmente e criar documento com bruto 100000, CBS 8000 e IBS 12000 centavos.
3. Criar pagamento com scenario approved ou declined e Idempotency-Key.
4. Autorizar; somente então liquidar.
5. Consultar pagamento/ledger/events; o exemplo liquidado apresenta líquido 80000 centavos.
6. Estornar integralmente; os lançamentos originais permanecem e são compensados.

Nomes/referências usam convenções DEMO do contrato. Não há campos de cartão, CPF/CNPJ ou banco real.
Os quatro comandos de pagamento exigem Idempotency-Key (1 a 128 caracteres ASCII permitidos pelo contrato).
Mesmo vendedor/operação/recurso/chave e mesmo comando reapresentam status/corpo originais, inclusive depois de um estorno.
Conteúdo diferente no mesmo escopo retorna 409. Chave nova não reaplica transição concluída (409).
GET retorna o estado atual. Falhas transitórias fazem rollback e permitem tentativa segura.
Uma liquidação por documento é permitida durante toda sua existência; o estorno não libera nova cobrança.
Não há edição/cancelamento público de documentos no MVP.

## Testes

~~~powershell
.\scripts\test.ps1
~~~

Testes de integração usam exclusivamente weddell_pay_test e podem apagar os dados desse banco.
A suíte deve recusar outro nome antes de qualquer operação destrutiva.
Nunca aponte o ambiente de testes para bancos pessoais ou de produção.
Concorrência e rollback são comprovados em PostgreSQL real, com conexões/processos independentes.

Resultados e limites estão em [evidências](docs/test-evidence.md), [relatório QA](docs/relatorio-validacao.md),
[evidências web](docs/test-evidence-web.md), [relatório web](docs/relatorio-validacao-web.md),
[parecer de Segurança](docs/parecer-seguranca.md), [parecer web](docs/relatorio-seguranca-web.md) e no BlackBoard.
Não interpretar casos planejados na matriz como testes executados.

## Operação

Limites: JSON 16 KiB, 60 requisições/minuto por credencial e 30 falhas de autenticação/minuto por IP.
Teto por valor monetário: 1.000.000.000.000 centavos; sem float ou cálculo de tributos no cliente.
Logs técnicos sanitizados retidos por 7 dias; razão e auditoria não expiram por essa política.
Falhas injetadas existem somente no ambiente de testes e não são parâmetros HTTP públicos.
/health retorna estado mínimo, sem segredos. Não há autorização para publicação.

~~~powershell
docker compose stop
# Para retomar sem apagar o volume:
docker compose up -d --wait postgres
~~~

Docker Compose não deve ser usado para publicar esta demonstração na Internet sem revisão e autorização próprias.
O servidor BlackBoard é uma memória local separada da API; seu campo agent não autentica o autor.

Referências técnicas de compatibilidade:
[Laravel](https://laravel.com/framework/docs/releases) e
[PDO PostgreSQL](https://www.php.net/manual/en/ref.pdo-pgsql.php).

## Apache/XAMPP

A raiz do repositório bloqueia acesso HTTP por .htaccess porque contém configuração privada.
Use scripts/serve.ps1 ou um virtual host cujo DocumentRoot seja exclusivamente src/api/public.
Confirme AllowOverride no Apache; .gitignore não protege HTTP. A pasta inteira não deve ser publicada.
Uma prova com sentinela sem segredo no Apache isolado confirmou 403.

## Demonstração HTTP reproduzível

Com scripts/serve.ps1 em execução:

~~~powershell
php -d extension=pdo_pgsql scripts/demo.php
~~~

O script gera credenciais efêmeras via CLI, captura os tokens apenas em memória, usa HTTP local com
o papel restrito, verifica liquidação/estorno/replay e revoga as chaves. Dados persistidos são fictícios.
Para revogar manualmente:

~~~powershell
.\scripts\php.ps1 artisan demo:revoke-key <UUID_DA_CREDENCIAL>
~~~

Rotação: emitir uma nova chave e revogar a anterior.

Para carregar os seeds ficticios de forma idempotente: scripts/php.ps1 artisan db:seed --force.
Para conferir os privilegios do runtime sem alterar dados: php -d extension=pdo_pgsql scripts/check-runtime.php.
