# Matriz de rastreabilidade — Weddell Pay

Tarefas: PAY-005 (plano), PAY-013 (suíte) e PAY-015 (evidências). Responsável: qualidade_testes.
Revisão: 2026-11-02.
Referências: Specs/SPEC_API_SPLIT_PAYMENT.md, PLAN-001 e docs/plano-testes.md.
Resultado consolidado: **80 testes / 9.835 asserções aprovados**, código de saída 0. **T01–T14 aprovados**; os subcasos adicionais sem execução explícita permanecem parciais. Nenhuma nova suíte foi executada na consolidação documental.

E1: [PHPUnit final](../.runtime/evidence/phpunit-final.txt). E2: [HTTP real final](../.runtime/evidence/http-demo-final.txt). Comandos, histórico e hashes: [test-evidence.md](test-evidence.md). Logs locais não versionados.

A coluna Camada preserva a estratégia original; a suíte executada é Feature com HTTP kernel e PostgreSQL real. Não se afirma uma suíte unitária pura independente.

## 1. Critérios T01–T14 da especificação

| ID | Requisitos | Cenário/ação | Resultado esperado | Camada | Resultado | Comprovação e limites |
|---|---|---|---|---|---|---|
| T01 | RN-02/03/04/05 | Criar, autorizar e liquidar bruto 100000, CBS 8000, IBS 12000 | Líquido 80000; CBS/IBS separados; soma exata; simulation=true | Unitária, HTTP, PostgreSQL | Aprovado | E1 PaymentLifecycleTest t01_t14; E2 100000=80000+8000+12000 |
| T02 | Invariantes fiscais | Criar documento com CBS + IBS maior que bruto | 422; documento inválido não persistido; nenhuma liquidação | HTTP, PostgreSQL | Aprovado | E1 PaymentLifecycleTest t02; nada persistido |
| T03 | Estados, gateway simulado | Autorizar pagamento com cenário declined | FAILED; motivo sanitizado; nenhum lançamento de split | HTTP, PostgreSQL | Aprovado | E1 PaymentLifecycleTest t03; FAILED sem razão |
| T04 | Estados, RN-06 | Liquidar CREATED, FAILED ou REFUNDED | 409; estado/razão inalterados | HTTP, PostgreSQL | Aprovado | E1 invalidTransitions: nove combinações |
| T05 | RN-06/07 | Repetir liquidação com mesma chave; depois tentar novo comando | Replay original para mesma chave; nenhum conjunto adicional de lançamentos; 409 sem efeito para nova chave | HTTP, PostgreSQL | Aprovado | E1 t05_t07_t11; replay e chave nova 409 |
| T06 | RN-06/08 | Duas liquidações realmente simultâneas | Uma liquidação, um conjunto de lançamentos e total preservado; respostas conforme contrato | PostgreSQL concorrente | Aprovado | E1 PostgresConcurrencyTest: cinco casos com sessões/processos e locks reais |
| T07 | RN-07 | Repetir mesma chave e comando nas quatro operações | Mesmo status HTTP e corpo originais; nenhum efeito duplicado | HTTP, PostgreSQL | Aprovado | E1 quatro operações reapresentam corpo/status históricos; E2 replay settle |
| T08 | RN-07 | Reutilizar chave com comando/payload divergente no mesmo escopo | 409; resposta não revela conteúdo de outro vendedor; nenhum efeito adicional | HTTP, PostgreSQL | Aprovado | E1 t08: payload válido divergente retorna 409 |
| T09 | RN-08 | Falha controlada antes do commit e retry | Rollback completo; estado consistente; retry seguro com a mesma chave | PostgreSQL, HTTP | Aprovado | E1 TransactionFailureTest: falha após razão e retry |
| T10 | RN-09 | Estornar liquidação integralmente | REFUNDED; contrapartidas exatas; originais preservados | HTTP, PostgreSQL | Aprovado | E1 compensações exatas; E2 oito entradas e saldos zero |
| T11 | RN-07/09 | Repetir estorno com mesma chave | Replay original; somente um estorno persistido | HTTP, PostgreSQL | Aprovado | E1 replay de refund e concorrência; compensação única |
| T12 | Segurança/ownership | Credencial A acessa pagamento, razão, eventos ou comandos de B | 403/404 conforme contrato; nenhum vazamento nem mutação | HTTP, PostgreSQL | Aprovado | E1 HttpContractTest t12/papéis |
| T13 | Monetário/int64 | Centavos, mínimo, máximo e entradas inválidas | Precisão inteira exata; rejeição de negativo, fracionário, overflow e valor acima do limite | Unitária, HTTP | Aprovado | E1 datasets de centavos, teto, zero, tipos e overflow |
| T14 | RN-05/09 | Inspecionar cada evento contábil de liquidação/estorno | Débitos = créditos por evento; contas e sinais corretos; parcelas zero omitidas | PostgreSQL | Aprovado | E1 balanço por evento/rejeição SQL de razão inválida; E2 saldos zero |

## 2. Casos adicionais obrigatórios do contrato aprovado

| ID | Regra/risco | Cenário/ação | Resultado esperado | Camada | Resultado | Comprovação e limites |
|---|---|---|---|---|---|---|
| X01 | Documento único vitalício | Liquidar dois pagamentos autorizados do mesmo documento, sequencialmente | Só um pode liquidar; segundo falha sem lançamentos | HTTP, PostgreSQL | Aprovado | E1 documento liquidado uma vez durante toda a existência |
| X02 | Documento único concorrente | Dois pagamentos do mesmo documento tentam liquidar ao mesmo tempo | Restrição/locking impede duas liquidações; invariantes preservadas | PostgreSQL concorrente | Aprovado | E1 dois pagamentos do mesmo documento concorrem |
| X03 | Estorno não libera documento | Estornar o pagamento liquidado e tentar liquidar outro pagamento do mesmo documento | Nova liquidação negada; histórico preservado | HTTP, PostgreSQL | Aprovado | E1 documento permanece consumido após estorno |
| X04 | Replay histórico após evolução | Repetir create/authorize/settle com suas chaves originais depois de REFUNDED | Status e corpos originais; GET continua mostrando REFUNDED | HTTP, PostgreSQL | Aprovado | E1 replay quatro operações após refund; E2 replay+GET atual |
| X05 | Quatro comandos idempotentes | Omitir Idempotency-Key em create/authorize/settle/refund | Erro de validação conforme OpenAPI; nenhum efeito de negócio | HTTP | Aprovado | E1 paymentOperations: ausência de chave nas quatro mutações |
| X06 | Escopo de idempotência | Exercitar isolamento por vendedor, operação e recurso conforme namespace/fingerprint do contrato | Ausência de colisão indevida ou leitura cruzada; divergência detectada no escopo correto | HTTP, PostgreSQL | Aprovado | E1 PaginationAndScopeTest: vendedor/operação/recurso |
| X07 | Concorrência idempotente | Dois comandos iguais com a mesma chave, executados sobrepostos | Um efeito; respostas/retry conforme contrato; sem erro interno inesperado | PostgreSQL concorrente | Aprovado | E1 concorrência mesma chave settle/refund |
| X08 | Idempotência e rollback | Falha transitória antes do commit seguida de retry com a mesma chave | Não persiste sucesso parcial ou bloqueio permanente; retry produz exatamente um efeito | PostgreSQL | Aprovado | E1 falha transitória/rollback sem chave envenenada |
| X09 | Imutabilidade fiscal | Tentar alterar valor/vendedor/documento depois de pagamento associado | Operação indisponível ou negada; valores originais preservados; não criar rota de edição só para o teste | HTTP, domínio, PostgreSQL | Aprovado nos casos executados | E1 congelamento de CBS e UUID; sem afirmar teste de cada coluna |
| X10 | Imutabilidade contábil | Após liquidação/estorno, tentar alterar ou excluir lançamentos pelo caminho suportado e testar a proteção persistente prevista | Originais preservados; compensação somente por novos lançamentos | Domínio, PostgreSQL | Aprovado | E1 histórico imutável e contrapartidas preservando originais |
| X11 | Fronteiras monetárias | Bruto 0, 1 e 1e12; acima de 1e12; strings, booleanos, floats, negativos e inteiros enormes | Apenas tipos/intervalos do OpenAPI aceitos; soma sem overflow nem float | Unitária, HTTP | Aprovado | E1 datasets monetários e constraint SQL |
| X12 | Parcelas zero | CBS=0, IBS=0, um tributo zero e líquido=0 | Totais exatos; omissão somente dos lançamentos de valor zero | Unitária, HTTP, PostgreSQL | Aprovado | E1 zero tributo/líquido; nenhuma partida zero |
| X13 | Vínculo fiscal | Documento inexistente, vendedor divergente, bruto diferente e referência duplicada | Rejeição no código definido pelo contrato; nenhuma associação inválida | HTTP, PostgreSQL | Parcial | Ownership, bruto divergente e referência duplicada aprovados; documento inexistente sem caso explícito |
| X14 | Cenário determinístico | Criar approved/declined e repetir autorização; cenário inválido ou tentativa de alterá-lo | Resultado determinístico; entradas/alterações não permitidas rejeitadas | HTTP, domínio | Aprovado | E1 approved/declined e cenário inválido/alteração por comando rejeitados |
| X15 | Máquina de estados | Autorizar novamente, refund antes de SETTLED e ações sobre terminais | Mesma chave devolve original; chave distinta sobre transição concluída retorna 409 sem efeitos | HTTP, PostgreSQL | Aprovado | E1 nove estados inválidos, replay e nova chave 409 |
| X16 | Bearer e papéis | Token ausente/inválido; tenant tenta ação exclusiva de operador; credenciais separadas | 401/403 conforme contrato; nenhuma elevação ou vazamento | HTTP | Aprovado | E1 Bearer ausente/inválido/revogado e papéis; E2 revogação 401 |
| X17 | Erros e logs | JSON malformado, UUID inválido, recurso inexistente, exceção e banco indisponível | Erros sanitizados; sem stack trace, SQL, token ou dados de conexão na resposta | HTTP, revisão de logs | Parcial | JSON/tipo/tamanho, recurso cruzado e banco indisponível sanitizados; UUID inválido e exceção 500 genérica sem injeção explícita |
| X18 | Auditoria | Executar transições válidas, rejeitadas e replay | Eventos de negócio sem duplicidade; correlação e horários UTC; metadados seguros | HTTP, PostgreSQL | Aprovado nos fluxos executados | E1 schemas/eventos/replay/imutabilidade; E2 quatro eventos finais |
| X19 | Contrato e saúde | Conferir rotas/status/payloads com OpenAPI; health com banco disponível/indisponível | Contrato coerente e health sem detalhes sensíveis | HTTP | Aprovado nos exemplos executados | E1 helper local de schemas/health; dez rotas da coleção revisadas; sem validador formal OpenAPI |
| X20 | Migrações e dados fictícios | Instalar banco de teste do zero e executar dados demo reproduzíveis | Estrutura/restrições presentes; nenhum segredo/dado real em arquivos ou exemplos | PostgreSQL, revisão | Parcial | Bootstrap/migrações e constraints demonstrados; execução integral setup.ps1 em ambiente vazio não repetida |
| X21 | Falha e concorrência de estorno | Interromper estorno antes do commit; repetir; executar estornos sobrepostos | Nenhum estorno parcial; compensação única e documento continua consumido | PostgreSQL concorrente | Aprovado | E1 refund_after_ledger rollback/retry e concorrência de refund |
| X22 | Limitação de requisições | Exceder limite aprovado no ambiente controlado | 429 conforme contrato; nenhuma exposição ou efeito de negócio indevido | HTTP | Parcial | 60 requisições por credencial/Retry-After aprovados; 30 falhas de autenticação por IP não exercitadas explicitamente |
| X23 | Identificação da simulação | Inspecionar respostas e exemplos de operações | simulation=true e documentação inequívoca; CBS/IBS separados; sem promessa de recolhimento real | HTTP, revisão | Aprovado | E1 schemas simulation=true; E2/documentação inequívocos |
| X24 | Schema estrito | Omitir campo obrigatório de pagamento, enviar campo extra, corpo em comando sem body ou nome/conta/referência fora dos prefixos demo | Rejeição conforme OpenAPI, sem persistência; montantes string/float rejeitados | HTTP | Aprovado nos casos executados | E1 campos/tipos estritos, cenário obrigatório, prefixos e corpo de comando |
| X25 | Representação do pagamento | Consultar CREATED/AUTHORIZED/SETTLED/REFUNDED | split=null antes da liquidação, valores preservados após estorno; failure_code/version/simulation coerentes | HTTP | Aprovado | E1 split null antes da liquidação e preservado após estorno; E2 GET atual |
| X26 | Paginação de razão/eventos | limit ausente, 1, 100, 0 e 101; cursor válido/inválido e de outro vendedor | Padrão 50; limites e cursor opaco validados; sem saltos/duplicatas indevidos ou vazamento | HTTP, PostgreSQL | Parcial | Limites/cursor inválido/vínculo recurso e coleção aprovados; combinação explícita de cursor de outro vendedor não exercitada |

## 3. Regressões adicionais

| ID | Cenário | Resultado | Comprovação |
|---|---|---|---|
| X27 | Preflight externo sem Access-Control-Allow-Origin | Aprovado | E1 HttpContractTest external_origin_preflight |
| X28 | UUID fiscal imutável antes de qualquer pagamento | Aprovado; 23514 e ID original preservado | E1 PostgresIntegrityTest document_uuid_is_immutable |
| X29 | Marca permanente não pode apontar para CREATED não financeiro | Aprovado; commit 23514 | E1 PostgresIntegrityTest a_permanent_document_claim |
| X30 | Replay de erro 409 após mudança de estado | Aprovado; corpo original/cabeçalho atual/GET atual | E1 PaymentLifecycleTest cached_business_error |

## 4. Registro de execução e saída

A execução de cada caso deve apontar para uma implementação de teste ou procedimento reproduzível e registrar:

| Campo | Conteúdo exigido |
|---|---|
| Caso e status | ID e não executado/aprovado/falhou/bloqueado |
| Ambiente | Revisão do código, PHP/framework/PostgreSQL e configuração sanitizada |
| Execução | Comando literal, diretório, preparação e código de saída |
| Resultado | Esperado versus obtido, com asserções e efeitos persistidos |
| Evidência | Log/JUnit/consulta sanitizados e defeito relacionado, se houver |

Os resultados referem-se somente à execução identificada em test-evidence.md. Casos parciais não foram convertidos em aprovados pela passagem da suíte. PostgreSQL indisponível deixa casos dependentes bloqueados. QA recomenda aceite técnico do MVP local em T01–T14 e nos fluxos efetivamente exercitados; publicação não está autorizada.

