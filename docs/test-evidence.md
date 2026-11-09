# Evidências de testes — Weddell Pay

Tarefas: PAY-013 e PAY-015. Revisão: qualidade_testes, 2026-11-02.

## Resultados e procedência

A coordenação executou os comandos. QA leu os logs completos e revisou documentos; não executou nova suíte nesta consolidação.

| Evidência | Resultado observado | Saída |
|---|---|---|
| E0 — [primeira suíte](../.runtime/evidence/phpunit-first.txt) | 79 testes; 9.546 asserções; 76 aprovados e três falhas | 1, confirmado pela coordenação |
| E1 — [suíte final](../.runtime/evidence/phpunit-final.txt) | 80 testes; 9.835 asserções; todos aprovados; 36,387 s; 56 MB | 0, confirmado pela coordenação |
| E2 — [HTTP real final](../.runtime/evidence/http-demo-final.txt) | 13 requisições loopback; papel weddell_app; fluxo e revogação aprovados | 0, confirmado pela coordenação |
| E3 — [HTTP inicial](../.runtime/evidence/http-demo.txt) | GET /health recebeu 503 quando se esperava 200 | 1, confirmado pela coordenação |

Os logs residem em .runtime/evidence, diretório local não versionado. Preservar cópias sanitizadas para compartilhar a entrega. A execução foi sobre a árvore de trabalho local; nenhum commit não informado é atribuído aos resultados.

## Ambiente e comandos executados

Windows/PowerShell, repositório C:\xampp2\htdocs\Weddle. PHP CLI 8.5.12 com pdo_pgsql; PHPUnit 12.5.43, identificados em E1. Laravel 13.39.0 e PostgreSQL 17, conforme ambiente confirmado pela coordenação; dependências fixadas em src/api/composer.lock.

PostgreSQL local: 127.0.0.1:55432. A suíte usa credencial owner privada exclusivamente em weddell_pay_test. A prova HTTP E2 usa o papel restrito weddell_app no banco de demonstração e servidor loopback 127.0.0.1:8000. Não participa qualquer serviço financeiro/fiscal externo.

Com diretório de trabalho src/api:

~~~powershell
php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit --testdox
~~~

Com diretório de trabalho na raiz:

~~~powershell
php -d extension=pdo_pgsql scripts/demo.php
~~~

A coordenação capturou stdout por Tee-Object com caminho absoluto para E1/E2 e confirmou o código de saída do PHP. Uma tentativa anterior de captura por caminho relativo após Push-Location não produziu execução funcional válida e não compõe os totais.

Para reprodução, o README fornece scripts/test.ps1 e scripts/serve.ps1. Esta revisão não executou novamente todo o setup ou os wrappers.

## Verificações da suíte

T01–T14 estão aprovados conforme a [matriz](matriz-rastreabilidade.md). As seis classes Feature verificam:

- Criação, autorização approved/declined, transições inválidas, liquidação e estorno.
- Centavos exatos, teto 1e12, fronteiras/tipos, parcelas zero omitidas e conservação do total.
- Idempotência nas quatro operações, escopo, payload divergente, replay após estorno e erro preservado após mudança de estado.
- Uma liquidação por documento durante toda sua existência, sem liberação pelo estorno.
- Rollback após razão/compensação e retry com mesma chave; falhas transitória/terminal; hooks inativos fora de testing.
- Partidas dobradas, contrapartidas exatas, imutabilidade e constraints diferíveis no commit.
- Marca permanente rejeitada quando aponta para evento não financeiro; UUID fiscal imutável mesmo sem pagamento.
- Bearer demo, papéis, isolamento, revogação, erros nos cenários exercitados, rate limit por credencial, cursores e CORS.

Os cinco casos de concorrência usam dois processos PHP e sessões PostgreSQL independentes. A sessão coordenadora mantém locks até observar ambos os workers esperando por lock no PostgreSQL; então libera a disputa. Verificam liquidação/documento/compensação únicos, com mesma chave ou chaves diferentes. Não são chamadas sequenciais apresentadas como concorrentes.

Antes de migrar ou limpar dados, a base confirma testing, pgsql, 127.0.0.1:55432, banco configurado weddell_pay_test e SELECT current_database(). Não há fallback para SQLite. A execução não usa transação externa de fixture, permitindo commit real de constraints diferíveis.

O helper ContractAssertions verifica campos, tipos e restrições dos schemas das respostas exercitadas. Não é validador formal de todo o OpenAPI e não mede cobertura de código. A suíte atual é Feature; não se afirma uma suíte unitária pura separada.

## Demonstração HTTP real E2

| Verificação | Resultado |
|---|---|
| Natureza / transporte | simulation=true; HTTP loopback real |
| Papel de banco | weddell_app |
| Bruto | 100000 centavos |
| Líquido / CBS / IBS | 80000 / 8000 / 12000 centavos |
| Estado final | REFUNDED |
| Razão / auditoria | Oito lançamentos e quatro eventos |
| Saldos após compensação | CLEARING, MERCHANT_PAYABLE, CBS_PAYABLE e IBS_PAYABLE iguais a zero |
| Revogação | Requisição posterior recebe 401 |

As 13 requisições registram health 200; criação de vendedor/documento/pagamento 201; autorização, liquidação e estorno 200; consultas; replay de settle 200 após estorno; GET de estado atual; negação 401 após revogação. O demonstrador captura tokens efêmeros apenas em memória e os revoga; o log final não contém tokens.

## Falhas, correções e regressão

1. Duas falhas de E0 eram do auxiliar de testes: corpo vazio virava JSON [] em vez de {}. A API respondia corretamente 400 antes dos cenários de autenticação/campos obrigatórios. A serialização foi corrigida sem relaxar validações.
2. Uma falha revelou bug da API: normalizar todo campo terminado em _id tornava virtual_account_id minúsculo, invalidando DEMO-ACCOUNT-ARCTIC. A normalização ficou restrita aos UUIDs; fixture válida e expectativa 201 foram mantidas.
3. Segurança identificou CORS padrão permissivo. A configuração foi fechada e a regressão adicional elevou a suíte de 79 para 80 casos.
4. E3 encontrou health 503: o processo filho de artisan serve não carregou pdo_pgsql. O launcher passou a iniciar php -S diretamente com a extensão. A comparação do demonstrador foi corrigida para ignorar somente a ordem das chaves JSONB, preservando valores/tipos; limpeza/revogação ocorre no encerramento. E2 demonstra o resultado final.

Os logs iniciais foram preservados. A coordenação informou estabilidade do código financeiro após E1; ajustes posteriores de instruções/wrappers não são apresentados como nova execução financeira.

## Revisão documental e limites

Os dez links locais do README examinados apontam para arquivos existentes. As dez requisições de docs/weddell-pay.http correspondem a métodos/rotas do contrato. Prefixos DEMO, campos, valores nominais, scenario e cabeçalhos Idempotency-Key estão coerentes. demo:operator, demo:merchant-key e demo:revoke-key correspondem aos comandos registrados.

Subcasos parciais da matriz: X13 (documento inexistente), X17 (UUID inválido/erro 500 genérico injetado), X20 (setup integral em ambiente vazio), X22 (limite de falhas auth/IP) e X26 (combinação cursor de outro vendedor). Não foram aprovados por inferência.

Não houve nesta consolidação nova suíte, pentest, teste de carga, validação formal OpenAPI, teste de UI ou homologação de publicação. A evidência local não autoriza publicação nem representa pagamento/recolhimento real.

## Identificação dos artefatos — SHA-256

| Arquivo | SHA-256 |
|---|---|
| .runtime/evidence/phpunit-first.txt | EA8AAD99348FFC1E7F56E240AA69E73370BEADC3F07D44202832AED741E3EB65 |
| .runtime/evidence/phpunit-final.txt | B525D230CEDE33D7E53848713ACEA93AF9350E28A2E933BA5A07AE1D939EAC12 |
| .runtime/evidence/http-demo-final.txt | F637DC40BCFAA3E5AC6B6B7A9B428207F083E073E87E998EDD5A72FD27374716 |
| src/api/composer.lock | 50D4CF4C489A216AE983F3957AB3CB9EF08140B659E2F42614A43D455B834C7E |
