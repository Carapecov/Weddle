# Modelo de dados e invariantes

MVP de simulação, PostgreSQL, UUIDs do servidor, UTC, BRL e centavos inteiros. Teto de cada montante: 1.000.000.000.000 centavos (10 bilhões de reais fictícios), inferior ao limite inteiro exato de JavaScript e representável em PHP de 64 bits. Sem float/decimal implícito. Números JSON devem ser inteiros; formatos fracionários, strings monetárias e notação não inteira são rejeitados.

## Entidades

| Tabela | Campos principais | Constraints e índices |
|---|---|---|
| merchants | id UUID PK, name, virtual_account_id, created_at | Conta virtual única; allowlist DEMO |
| demo_credentials | id UUID PK, merchant_id nullable FK, role operator/merchant, token_hash, revoked_at, created_at | Hash único; merchant obrigatório só para role merchant; nunca token bruto |
| fiscal_documents | id UUID PK, merchant_id FK, reference, gross_amount_cents BIGINT, cbs_amount_cents BIGINT, ibs_amount_cents BIGINT, status, created_at | reference única global no conjunto do cenário; UNIQUE(id,merchant_id) |
| payments | id UUID PK, merchant_id FK, fiscal_document_id, amount_cents BIGINT, currency, scenario, status, failure_code nullable, version BIGINT, created_at, updated_at | FK(fiscal_document_id,merchant_id) -> fiscal_documents(id,merchant_id); índice merchant_id/status/created_at; UNIQUE(id,merchant_id) |
| payment_events | id UUID PK, payment_id FK, event_type, previous_status nullable, new_status, occurred_at, correlation_id UUID, metadata_safe JSONB | Índice payment_id/occurred_at/id; unicidade financeira parcial por payment_id/event_type |
| document_settlements | fiscal_document_id UUID PK FK, payment_id UUID UNIQUE FK, settlement_event_id UUID UNIQUE FK, created_at | Uma marca por documento por toda a vida; pagamento/evento/documento devem corresponder |
| ledger_entries | id UUID PK, payment_id FK, event_id FK, account_code, direction, amount_cents BIGINT, reversal_of_entry_id nullable FK, created_at | UNIQUE(reversal_of_entry_id) para não nulos; índice payment_id/created_at/id; eventos/partidas do mesmo pagamento |
| idempotency_records | id UUID PK, merchant_id FK, operation, resource_scope, key, request_hash, http_status, response_body JSONB, response_headers JSONB, completed_at | UNIQUE(merchant_id,operation,resource_scope,key); SHA-256; somente resposta final confirmada |

Campos de chave/hash idempotentes anteriormente sugeridos em payments ficam normalizados em idempotency_records para suportar todas as quatro operações, sem repetir coluna de criação em cada transição. Operation pertence a create_payment/authorize_payment/settle_payment/refund_payment; resource_scope é create ou UUID canônico. Não usar UNIQUE(key) global.

## Domínio monetário

- Documento: gross entre 1 e 10^12; CBS e IBS entre 0 e 10^12; CBS + IBS <= gross. Usar verificações de limites antes de somar/subtrair.
- Pagamento: amount entre 1 e 10^12, currency=BRL e amount=gross do documento; mesmo merchant. Total e componentes ficam congelados após o primeiro pagamento, mesmo FAILED.
- Uma liquidação usa os valores nominais congelados. net = gross - cbs - ibs >= 0.
- Entrada de razão: amount entre 1 e 10^12; direction DEBIT/CREDIT; account_code em CLEARING/MERCHANT_PAYABLE/CBS_PAYABLE/IBS_PAYABLE. Não persistir partidas de zero.
- Uma liquidação pode ter de duas a quatro partidas; três componentes zero simultâneos são impossíveis com bruto positivo. Débitos/créditos balanceiam por evento.
- Valores e vínculos cruzando tabelas precisam de serviço transacional e proteção de banco adequada (FK composta/trigger), não de CHECK com subconsulta.

## Estados e unicidade

Fiscal_documents.status é ISSUED no MVP; não há rota de edição/cancelamento. O banco deve impedir mudança de valores/merchant/reference quando existe pagamento associado. Não excluir documentos, pagamentos ou evidências históricas pelo usuário de execução.

Payments.status: CREATED, AUTHORIZED, SETTLED, FAILED, REFUNDED. Version inicia em 1 e incrementa em mudança de estado. Failure_code é AUTHORIZATION_DECLINED ou SETTLEMENT_TERMINAL_FAILURE quando FAILED; nos demais estados é null.

Payment_events.event_type: CREATED, AUTHORIZED, AUTHORIZATION_DECLINED, SETTLED, SETTLEMENT_FAILED, REFUNDED, TRANSIENT_ATTEMPT. Um evento financeiro SETTLED e um REFUNDED por pagamento, protegidos por índices únicos parciais. Eventos de tentativa mantêm o estado e não alteram version. Correlation_id é gerado pelo servidor; metadata_safe tem allowlist (ex.: failure_code/operation), sem request, tokens ou dados pessoais.

A unicidade de SETTLED por pagamento não impede dois pagamentos liquidarem o mesmo documento. Por isso document_settlements tem fiscal_document_id como chave primária e preserva a marca depois do estorno. Tentativas novas após FAILED são válidas; documentos já liquidados não aceitam novos pagamentos nem nova liquidação. Disputas de pagamentos anteriores à primeira liquidação são resolvidas com lock do documento e constraint.

Ledger_entries.payment_id deve coincidir com o payment_id do evento, usando chave composta ou trigger. Partida de estorno deve referenciar partida de liquidação do mesmo pagamento, com mesma conta/valor e direção inversa. Uma origem só pode ser invertida uma vez. Imutabilidade e balanceamento no commit exigem permissões e trigger diferível; ambos complementam validação de aplicação.

## Exemplo contábil

Liquidação de 100000 centavos, CBS=8000, IBS=12000:

| Conta | Débito | Crédito |
|---|---:|---:|
| CLEARING | 100000 | 0 |
| MERCHANT_PAYABLE | 0 | 80000 |
| CBS_PAYABLE | 0 | 8000 |
| IBS_PAYABLE | 0 | 12000 |

Estorno troca DEBIT/CREDIT em quatro novas partidas, preservando as anteriores. Saldo agregado de cada conta para o pagamento torna-se zero; o pagamento permanece REFUNDED e a marca do documento continua presente.

## Transação e consultas

Ordem estável de locks: documento, pagamento, registros financeiros dependentes. Inserção/reserva de idempotência deve respeitar uma ordem uniforme por operação e nunca abrir ciclo entre locks; retry de deadlock tem limite e não conserva resposta final transitória.

Snapshot idempotente deve guardar o corpo e status originais, incluindo a versão daquele momento e Location quando aplicável. Replay regenera cabeçalhos de transporte/correlação sem reconstruir a representação a partir do estado atual. Hash do payload e chave têm tamanho limitado; a leitura do registro é sempre filtrada por vendedor.

Ledger/events usam ordenação estável (created_at/occurred_at, id), cursor opaco e limit 1..100, padrão 50. Cursor inválido ou incompatível com recurso retorna 422. Nenhum endpoint lista recursos de todos os vendedores.

Migrações devem ser reproduzíveis em PostgreSQL; testar índices parciais, triggers, FKs e rollback com conexões independentes. Credencial de migração é distinta da credencial de execução; snapshots e backups seguem a natureza fictícia dos dados. Remoção integral de cenário é atividade administrativa local explícita, fora das rotas do MVP.
