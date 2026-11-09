# Suíte de QA do Weddell Pay

Os arquivos desta pasta verificam a API simulada e suas invariantes no PostgreSQL real. Não contêm credenciais privadas, não movimentam dinheiro e não acessam serviços financeiros/fiscais externos.

## Segurança de execução

A base PostgresTestCase confirma APP_ENV=testing, conexão pgsql, host 127.0.0.1, porta 55432, configuração de banco weddell_pay_test e SELECT current_database() antes de qualquer migração ou limpeza.

A suíte aplica migrações pendentes e usa TRUNCATE somente nesse banco dedicado, com credencial owner fornecida privadamente pelo ambiente de testes. Não executar contra dados de demonstração/desenvolvimento; não substituir as verificações ou usar SQLite. A configuração phpunit.xml e o arquivo privado .env.testing pertencem à coordenação.

Os casos não usam transação externa de fixture: isso permite observar restrições diferíveis no commit e compartilhar dados confirmados com os processos concorrentes. Portanto, não executar a suíte em paralelo sobre o mesmo banco. Não executar duas instâncias simultâneas.

## Cobertura

| Classe | Casos principais |
|---|---|
| PaymentLifecycleTest | T01–T05, T07–T08, T10–T11, T13–T14; máquina de estados, replay após estorno e documento único vitalício |
| HttpContractTest | T12–T13; Bearer/papéis, schema estrito, limites, campos proibidos, erros, health e rate limit |
| PaginationAndScopeTest | Cursores, limites e escopo vendedor/operação/recurso de idempotência |
| TransactionFailureTest | T09; falha transitória/terminal, rollback após razão e compensação, hooks inativos fora de testing |
| PostgresIntegrityTest | Imutabilidade, constraints monetárias, razão inválida no commit, idempotência incompleta e claim não financeiro |
| PostgresConcurrencyTest | T06; mesma/diferente chave, dois pagamentos do mesmo documento e estornos simultâneos |

O auxiliar ContractAssertions confere respostas com os schemas de docs/openapi.yaml. O worker Support/http-worker.php é iniciado apenas pelos testes concorrentes, recebe configuração/token por stdin e não os imprime.

A prova concorrente usa duas sessões PostgreSQL e dois processos PHP independentes. Uma terceira sessão mantém locks nas fixtures até observar ambos os workers efetivamente esperando por lock no PostgreSQL; então libera a disputa e verifica os efeitos persistidos. Falhas ou ausência de sobreposição reprovam o teste, sem fallback sequencial.

## Execução autorizada pela coordenação

Na raiz do repositório, após ambiente e backend completos:

~~~powershell
.\scripts\php.ps1 vendor/phpunit/phpunit/phpunit
~~~

Execução focal:

~~~powershell
.\scripts\php.ps1 vendor/phpunit/phpunit/phpunit --filter PaymentLifecycleTest
.\scripts\php.ps1 vendor/phpunit/phpunit/phpunit --filter PostgresConcurrencyTest
~~~

Esses comandos são instruções reproduzíveis, não evidências de execução. Registre comando literal, revisão/ambiente, código de saída, resultados e bloqueios no relatório de validação/BlackBoard. Nenhum teste pode ser declarado aprovado apenas pela existência dos arquivos ou pelo lint.
