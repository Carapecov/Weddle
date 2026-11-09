# Arquitetura — API de simulação de Split Payment

Estado: contrato aprovado para implementação do MVP em PLAN-001; detalhamento de PAY-003.
Referência: `Specs/SPEC_API_SPLIT_PAYMENT.md` v1.0. Este documento descreve o simulador, sem movimentação financeira real.

## Escopo e organização

Monólito modular em PHP 8.3+ de 64 bits, Laravel compatível com o ambiente validado e PostgreSQL. A aplicação ficará em `src/api/`; esta tarefa de contrato não cria código nesse diretório. A escolha da versão exata do framework, driver `pdo_pgsql` e instância PostgreSQL pertence a PAY-002. MySQL/SQLite não substituem PostgreSQL para comprovar atomicidade/concorrência.

A API usa `/api/v1`. Todos os identificadores de recursos são UUIDs gerados pelo servidor e todos os exemplos/recursos são fictícios. A interface web não integra o aceite do MVP.

| Módulo | Responsabilidade | Dependências |
|---|---|---|
| Identidade de demonstração | Validar Bearer token, identificar operador/vendedor, limitar requisições | Persistência de hash e rate limiter |
| Vendedores | Criar vendedor e conta virtual fictícios | Papel operador |
| Documentos | Validar valores nominais de CBS/IBS e propriedade | Vendedores |
| Pagamentos | Comandos, estados, versões e idempotência | Documentos, gateway, razão |
| Gateway simulado | Resultado determinístico conforme cenário imutável | Configuração de simulação |
| Segregação | Calcular líquido por subtração de centavos | Valores nominais do documento |
| Razão | Partidas dobradas e contrapartidas imutáveis | Transação e eventos |
| Auditoria | Eventos, correlação e consultas limitadas por vendedor | Persistência |

Controllers finos usam Form Requests, Policies e serviços de aplicação. Serviços conduzem transações; regras de dinheiro/estados não dependem do HTTP. Não criar microserviços, filas, integração bancária nem motor tributário oficial.

## Contrato e identidade

`docs/openapi.yaml` é a fonte do contrato HTTP. Bearer token opaco de demonstração é armazenado somente como hash; valores brutos aparecem apenas na emissão por CLI local. Tokens não são devolvidos por POST de vendedor, não aparecem em eventos/logs e não são versionados. CLI provisiona o operador e chaves de vendedores existentes. Operador pode criar vendedores; operações de documentos e pagamentos exigem chave do respectivo vendedor. Não há autoridade implícita para operador ler pagamentos.

Acesso cruzado a UUID existente retorna o mesmo `404 RESOURCE_NOT_FOUND` usado para recurso ausente, antes de consultar replay. Credencial ausente/inválida: 401; papel inadequado: 403; rate limit: 429. `/health` é público e apresenta apenas status de processo/banco. Um healthcheck com banco indisponível retorna 503 sem detalhes internos.

Todas as respostas JSON incluem `simulation: true` quando forem representações de domínio. Erros têm `error.code`, `message`, `details` e `correlation_id`; nunca stack trace, SQL ou token. A API gera correlação UUID e a devolve em `X-Correlation-ID`. Horários são UTC ISO 8601.

## Máquina de estados

- CREATED -> AUTHORIZED: cenário `approved`.
- CREATED -> FAILED: cenário `declined`, código `AUTHORIZATION_DECLINED`.
- AUTHORIZED -> SETTLED: liquidação financeira simulada atômica.
- AUTHORIZED -> FAILED: somente falha terminal do gateway de teste, sem partidas.
- SETTLED -> REFUNDED: estorno integral por partidas inversas.
- FAILED e REFUNDED são terminais; falha transitória não muda estado.

O cenário `approved|declined` é obrigatório na criação do pagamento, persistido e imutável. Authorize, settle e refund não recebem corpo. Injeção de falhas não é campo HTTP: configuração exclusivamente de testes associa `failure_mode=none|transient|terminal` ao fixture/serviço simulado. Fora do ambiente de testes deve permanecer `none`.

Repetir authorize, settle ou refund já concluído com chave nova retorna 409 sem novos efeitos. Somente replay da mesma chave e comando devolve seu resultado original, inclusive depois de transições posteriores. Um estado incompatível também produz 409. GET sempre representa o estado atual; `split` é null antes da liquidação e preserva a segregação original após o estorno, claramente como histórico.

## Idempotência e transações

Quatro operações exigem `Idempotency-Key`: criar, autorizar, liquidar e estornar pagamento. Chave de 1–128 caracteres ASCII conforme OpenAPI. Escopo único: `merchant_id + operation + resource_scope + key`; criação usa `resource_scope=create`, demais usam UUID do pagamento. Mesma chave pode existir em outro escopo sem colisão.

Canonicalizar o comando após validação: campos desconhecidos são rejeitados; ordenar nomes de propriedades recursivamente, preservar arrays, valores inteiros e enumerações; incluir método, rota normalizada, vendedor efetivo e parâmetros de rota. Corpo ausente nas transições normaliza para objeto vazio. Calcular SHA-256 e armazenar hash. Payload equivalente retorna status HTTP e corpo originais; payload distinto no mesmo escopo/chave retorna 409. Não devolver um estado atualizado durante replay. Chaves/resultados duram toda a vida do conjunto de dados do cenário.

A reserva da chave e o efeito de negócio pertencem à mesma transação. A constraint única serializa requisições com a mesma chave; ao conflito, reler o resultado confirmado. Não capturar violação única dentro de uma transação PostgreSQL já abortada sem rollback/savepoint. Falha transitória faz rollback e não deixa resultado final reservado; a mesma chave pode ser repetida. Validação/401/403/404 anteriores ao comando não reservam chave. Falhas de negócio finais aceitas pelo comando e respostas de sucesso são reapresentáveis. Nunca depender apenas de cache ou mutex em memória.

Na criação: bloquear documento, verificar propriedade/valores e ausência de liquidação anterior, persistir CREATED, evento e resultado idempotente. Novas tentativas com outro pagamento são permitidas depois de FAILED, sem editar o documento.

Na liquidação: bloquear documento e pagamento em ordem consistente; conferir AUTHORIZED e valores; inserir marca única de liquidação por documento, evento SETTLED e partidas balanceadas; atualizar status/versão e resultado idempotente; commit único. Conflito entre pagamentos distintos do mesmo documento retorna 409, preservando o perdedor sem partidas. O bloqueio e as constraints se complementam.

No estorno: seguir a mesma ordem de locks; bloquear pagamento, conferir liquidação original e ausência de estorno; criar evento e partidas inversas sem recalcular tributos; atualizar REFUNDED e resultado idempotente no mesmo commit. Nunca remover a marca de liquidação do documento: nem mesmo estorno permite segunda liquidação para esse documento.

Timeout/deadlock/falha transitória retorna 503 sanitizado e permite retry. Tentativas transitórias podem gerar evento de tentativa após rollback em transação separada, com estado anterior=novo e sem partidas; se o banco estiver totalmente indisponível, registrar correlação em log sanitizado. Versão muda somente em transição de estado. Evidências de rollback verificam ausência de mutação financeira parcial.

## Razão, segurança e observabilidade

Liquidação debita CLEARING pelo bruto e credita MERCHANT_PAYABLE, CBS_PAYABLE e IBS_PAYABLE pelos componentes positivos. Créditos zero são omitidos. Por evento: soma de débitos = soma de créditos. Estorno inverte direção de cada partida original e referencia sua origem.

Role de execução não pode atualizar/excluir partidas e eventos confirmados; migrações usam credencial separada. Um trigger de constraint diferível garante balanceamento dos eventos financeiros no commit; `CHECK` isolado por linha não verifica somas. Serviços também verificam o agregado antes do commit.

Usar allowlists de campos e identificadores fictícios, corpo JSON de no máximo 16 KiB, limite de 60 requisições/minuto por chave autenticada e 30 falhas de autenticação/minuto por IP. Respostas 429/503 podem indicar Retry-After. Logs operacionais têm retenção de sete dias; razão e auditoria não expiram no cenário. Logs registram correlação, operação, duração, estado e categoria de erro sem request bruto nem Authorization. Segurança deve revisar provisionamento, isolamento e permissões do banco.

## Entrega e verificação

Ordem: ambiente PAY-002; contrato/modelo PAY-003; domínio/persistência; autenticação e pagamentos; razão/liquidação/estorno; robustez e documentação. Frontend só consome contrato aprovado em etapa posterior opcional.

Aceite: T01–T14 da spec em PostgreSQL; disputas entre pagamentos do mesmo documento; retry após falha transitória; replay após estado posterior; omissão de partidas zero; documento imutável; chaves isoladas por vendedor/operação/recurso; allowlist e vazamento entre vendedores. Concorrência precisa de conexões/processos independentes e banco real PostgreSQL. Teste somente em SQLite não comprova esses critérios.

Mudança de contrato, limites monetários, cardinalidade por documento ou integração externa requer decisão explícita. Publicação não está autorizada por este documento.

## Privilégio de bloqueio do documento

PostgreSQL exige UPDATE em pelo menos uma coluna para SELECT FOR UPDATE. O usuário runtime recebe apenas UPDATE(id) em fiscal_documents para permitir o bloqueio de linha; um trigger independente proíbe mudar esse UUID mesmo antes de existir pagamento. Não há permissão para atualizar montantes ou outros campos fiscais. A migração incremental 000300 adiciona essa proteção sem reaplicar migrations anteriores.
