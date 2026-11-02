# SPEC — API de Pagamentos com Simulação de Split Payment

**Versão:** 1.0 | **Data:** 2026-10-13 | **Estado:** proposta de implementação
**Natureza:** projeto acadêmico / portfólio, sem movimentação financeira real.

## 1. Objetivo e fundamento
Construir uma API REST para simular o ciclo de uma venda: emissão/associação de documento fiscal fictício, criação do pagamento, autorização, liquidação e segregação de valores de CBS e IBS, com auditoria e possibilidade de estorno.

**Contexto:** a LC 214/2025, alterada pela LC 227/2026, disciplina o split payment nos arts. 31 e seguintes. Há procedimento padrão e simplificado. A implantação é prevista a partir de 2027, sujeita a cronograma e atos técnicos. Em 15/10/2026 começou uma fase controlada de testes de integração com a Plataforma Pública. **Esta API não implementa nem certifica o protocolo oficial.**

## 2. Premissas e limites legais
- A solução é um **simulador**; não captura dinheiro, não emite NF-e real, não recolhe tributos e não se conecta a bancos, PSPs, RFB ou CGIBS.
- Todos os dados fiscais, credenciais e contas são fictícios; proibir CPF/CNPJ real, cartão real e dados bancários de clientes.
- CBS e IBS são **componentes separados**; nunca usar um campo único `tax` para os dois.
- Alíquotas e regras do modo didático são configuráveis por cenário, **não representam alíquotas oficiais de 2027**.
- O MVP usa valores nominais de CBS/IBS declarados em documento fiscal simulado. Um módulo opcional calcula valores com alíquotas artificiais explícitas e base configurada.
- A segregação ocorre na **liquidação**, não necessariamente na simples criação/autorização do pagamento.
- O vínculo entre pagamento e documento fiscal é obrigatório no fluxo padrão do simulador.
- Não afirmar que a segregação equivale ao recolhimento legal efetivo; os lançamentos usam contas virtuais.
- Não tentar reproduzir todos os casos de regime especial, créditos tributários, múltiplos documentos, meios de pagamento ou procedimento simplificado na primeira versão.

## 3. Escopo do MVP
### Incluído
1. Cadastrar vendedores fictícios e identificadores de contas virtuais.
2. Criar documentos fiscais simulados contendo valor total e parcelas CBS/IBS.
3. Criar pagamentos associados a um documento e a um vendedor.
4. Autorizar ou rejeitar pagamentos em gateway simulado.
5. Liquidar transações autorizadas e segregar CBS, IBS e saldo líquido.
6. Consultar pagamentos, lançamentos e histórico de eventos.
7. Estornar integralmente uma liquidação por lançamentos compensatórios.
8. Testar caminhos de sucesso, erro e concorrência; gerar OpenAPI/Swagger.

### Fora do MVP
- Pagamento com Pix, cartões ou boleto reais; armazenamento de PAN, CVV ou tokens bancários.
- Emissão de documentos fiscais válidos, cálculo tributário oficial, determinação de créditos e integração à Plataforma Pública do Split Payment.
- Marketplace com múltiplos recebedores, parcelamento, split comercial, câmbio, repasses externos ou interface web obrigatória.
- Procedimento simplificado legal, taxas de PSP e conciliação bancária real.

## 4. Stack sugerida
- **PHP 8.3+ e Laravel** (usar versões do framework compatíveis com o ambiente validado).
- **PostgreSQL** para persistência relacional e transações ACID.
- **PHPUnit/Pest**, conforme compatibilidade da stack, para testes.
- **OpenAPI/Swagger** para contrato e documentação.
- Docker Compose é opcional; configuração `.env.example` sem segredos.
- Todos os valores monetários em **centavos inteiros (`int64`)**, moeda `BRL`; evitar `float`.

## 5. Modelo de domínio mínimo
### `merchants`
`id (UUID)`, `name`, `virtual_account_id`, `created_at`.

### `fiscal_documents`
`id (UUID)`, `merchant_id`, `reference`, `gross_amount_cents`, `cbs_amount_cents`, `ibs_amount_cents`, `status`, `created_at`.
**Invariantes:** parcelas >= 0; CBS + IBS <= total; vínculo ao mesmo vendedor; referência única no cenário de MVP.

### `payments`
`id (UUID)`, `merchant_id`, `fiscal_document_id`, `amount_cents`, `currency`, `status`, `idempotency_key`, `request_hash`, `failure_code`, `version`, `created_at`, `updated_at`.
**Invariantes:** valor positivo; BRL; total do pagamento igual ao total do documento no MVP; chave idempotente única por operação e comerciante.

### `ledger_entries`
`id (UUID)`, `payment_id`, `event_id`, `account_code`, `direction`, `amount_cents`, `created_at`.
Contas virtuais: `CLEARING`, `MERCHANT_PAYABLE`, `CBS_PAYABLE`, `IBS_PAYABLE`. O razão é de dupla entrada, com débito e crédito iguais por evento; lançamentos confirmados são imutáveis.

### `payment_events`
`id (UUID)`, `payment_id`, `event_type`, `previous_status`, `new_status`, `occurred_at`, `correlation_id`, `metadata_safe`.
Não registrar segredos, números de cartão ou dados pessoais sensíveis.

## 6. Estados e transições
`CREATED -> AUTHORIZED -> SETTLED -> REFUNDED`
- `CREATED -> FAILED`: autorização recusada.
- `AUTHORIZED -> FAILED`: falha terminal de liquidação; falhas transitórias conservam o estado com registro de tentativa.
- `AUTHORIZED -> SETTLED`: única transição que contabiliza o split.
- `SETTLED -> REFUNDED`: estorno integral com evento e lançamentos inversos, nunca apagando os originais.
- `FAILED` e `REFUNDED` são terminais no MVP.
- Repetir uma operação concluída **não pode** gerar novos lançamentos.

## 7. Regras de negócio
**RN-01** — Toda transação tem UUID único e documento fiscal simulado existente.
**RN-02** — Os valores de CBS e IBS são obtidos do documento simulado, sob as premissas do cenário didático.
**RN-03** — Na liquidação: `merchant_net = gross - cbs - ibs`.
**RN-04** — Deve valer `gross = merchant_net + cbs + ibs`, sem diferença de centavos.
**RN-05** — Toda liquidação gera lançamentos balanceados: débito `CLEARING` pelo total; créditos `MERCHANT_PAYABLE`, `CBS_PAYABLE` e `IBS_PAYABLE` por suas respectivas parcelas.
**RN-06** — Um pagamento só pode ser liquidado uma vez; restrições de banco e transação atômica devem impedir duplicidade sob concorrência.
**RN-07** — `Idempotency-Key` igual + payload igual retorna o resultado persistido; mesma chave + payload diferente retorna `409 Conflict`.
**RN-08** — Falhas antes do commit não deixam pagamento liquidado parcialmente; uma nova tentativa segura pode ocorrer.
**RN-09** — Reembolsos são integrais no MVP e geram contrapartidas contábeis; não recalcular tributo com parâmetros posteriores.
**RN-10** — Valores fiscais não podem ser alterados depois da criação de um pagamento associado.
**RN-11** — Cancelar documento vinculado a pagamento liquidado é proibido; rejeições devem informar motivo sem revelar dados internos.
**RN-12** — Cada alteração relevante gera evento auditável e `correlation_id`.

## 8. Contrato HTTP (v1)
| Método | Rota | Finalidade |
|---|---|---|
| `POST` | `/api/v1/merchants` | Cadastrar vendedor fictício |
| `POST` | `/api/v1/fiscal-documents` | Criar documento fiscal fictício |
| `POST` | `/api/v1/payments` | Criar pagamento (`Idempotency-Key` obrigatória) |
| `POST` | `/api/v1/payments/{id}/authorize` | Autorizar/recusar no gateway simulado |
| `POST` | `/api/v1/payments/{id}/settle` | Liquidar e registrar split atomicamente |
| `POST` | `/api/v1/payments/{id}/refund` | Estornar integralmente (idempotente) |
| `GET` | `/api/v1/payments/{id}` | Consultar estado e discriminação do split |
| `GET` | `/api/v1/payments/{id}/ledger` | Consultar lançamentos |
| `GET` | `/api/v1/payments/{id}/events` | Consultar trilha de auditoria |
| `GET` | `/health` | Verificar processo e banco (sem expor segredos) |

- Retornar `201` na criação; `200` em consultas/transições concluídas; `400/422` em payload inválido; `401/403` em autenticação/autorização; `404` não encontrado; `409` em conflito de estado/idempotência; `500` apenas para erro inesperado.
- Exigir API key **de demonstração** em ambiente controlado ou outro mecanismo equivalente; associar permissões ao vendedor e negar acesso cruzado.
- Swagger deve demonstrar payloads, validações, códigos de erro e exemplos com dados inteiramente fictícios.

## 9. Exemplo de contrato didático
Criar documento: `POST /api/v1/fiscal-documents`
```json
{
  "merchant_id": "UUID_FICTICIO",
  "reference": "DEMO-2027-001",
  "gross_amount_cents": 100000,
  "cbs_amount_cents": 8000,
  "ibs_amount_cents": 12000
}
```
Os valores são **arbitrários**, não uma previsão tributária. O identificador retornado será usado na criação do pagamento de R$ 1.000,00.

Resposta ilustrativa após liquidação: `GET /api/v1/payments/{id}`
```json
{
  "id": "UUID_DO_PAGAMENTO",
  "status": "SETTLED",
  "currency": "BRL",
  "gross_amount_cents": 100000,
  "split": {
    "merchant_net_cents": 80000,
    "cbs_cents": 8000,
    "ibs_cents": 12000
  },
  "simulation": true
}
```

## 10. Fluxo transacional
1. Validar requisição, autenticação, vendedor, documento e chave idempotente.
2. Persistir pagamento `CREATED` e evento inicial.
3. Simular autorização (sucesso ou falha determinística configurável para teste).
4. Na liquidação, abrir transação de banco e bloquear o pagamento para escrita.
5. Confirmar `AUTHORIZED`, inexistência de liquidação prévia e consistência dos valores.
6. Calcular líquido, criar lançamentos de débito/crédito e evento `SETTLED`.
7. Confirmar transação; responder com valores segregados. No erro, rollback integral.
8. No estorno, bloquear a transação, validar `SETTLED` e criar lançamentos inversos atomicamente.

## 11. Segurança, resiliência e observabilidade
- Validar formato, tipo, limites de valores, ownership e transições; não confiar em dados do cliente.
- Aplicar `rate limit`, autenticação, autorização por vendedor e princípio do menor privilégio.
- Não versionar `.env`, credenciais ou dados reais; expor apenas mensagens sanitizadas.
- Usar índices únicos para idempotência e prevenção de dupla liquidação; não depender somente de validações na aplicação.
- Registrar `request_id/correlation_id`, estado anterior/novo, erro categorizado, horário UTC e duração.
- Não usar `float` nem alterar lançamentos históricos; apresentar valores em reais apenas na camada de resposta, se necessário.
- Construir seed de dados fictícios e comandos reproduzíveis de instalação/teste.

## 12. Testes e critérios de aceite
| ID | Cenário | Resultado esperado |
|---|---|---|
| T01 | Pagamento R$1.000, CBS R$80, IBS R$120 | Líquido R$800; total preservado |
| T02 | CBS + IBS acima do bruto | `422`; nada liquidado |
| T03 | Pagamento rejeitado | `FAILED`, sem lançamentos de split |
| T04 | Liquidar pagamento não autorizado | `409`; sem lançamentos |
| T05 | Liquidar duas vezes | Uma liquidação apenas |
| T06 | Duas requisições simultâneas de liquidação | Uma liquidação persistida, razão sem duplicatas |
| T07 | Mesmo Idempotency-Key + mesmo payload | Resposta reaproveitada, sem nova operação |
| T08 | Mesmo Idempotency-Key + payload diferente | `409` |
| T09 | Falha do banco antes do commit | Rollback completo; nenhuma contabilização parcial |
| T10 | Estorno integral de transação liquidada | Lançamentos inversos e estado `REFUNDED` |
| T11 | Repetir estorno | Não gera duplicidade |
| T12 | Token do vendedor A consulta pagamento B | `403` ou `404`, sem vazamento |
| T13 | Valores com centavos e casos-limite | Precisão exata em centavos |
| T14 | Verificação de partidas dobradas | Débitos = créditos por evento |

**Aceite do MVP:** T01–T14 aprovados, migrações reproduzíveis, OpenAPI atualizado, README com execução e limites legais, ausência de dados reais/segredos e demonstração completa via HTTP.

## 13. Fases sugeridas
- **Fase 1 — Domínio:** modelos, migrações, valores monetários, validações e seeds.
- **Fase 2 — Pagamentos:** endpoints de criação, autorização, estados e idempotência.
- **Fase 3 — Split:** liquidação atômica, razão contábil e eventos.
- **Fase 4 — Robustez:** concorrência, rollback, estorno, autenticação e autorização.
- **Fase 5 — Apresentação:** suíte de testes, Swagger, README e coleção de requisições de demonstração.

## 14. Regras para agentes de desenvolvimento
- Não implementar funcionalidade fora do MVP sem registrar motivação, impacto e aprovação.
- Antes de alterar código: explicar objetivo, arquivos afetados, riscos e teste que comprova a alteração.
- Depois de alterar: executar testes apropriados, inspecionar resultados e registrar evidências; não declarar sucesso sem execução comprovada.
- Bloquear alterações que comprometam invariantes monetários, idempotência ou imutabilidade contábil.
- Registrar no MCP, caso esteja disponível: `task_id`, agente, contexto, estado (`TODO/IN_PROGRESS/BLOCKED/TESTED/DONE`), decisões, mudanças, comandos, resultados e pendências. Se não houver MCP, usar `docs/decision-log.md` e `docs/test-evidence.md`.
- Se um teste falhar, documentar causa, impacto, correção proposta e novo resultado; não mascarar erro.
- Separar definitivamente `MOCK` de qualquer futura integração oficial. Modificações regulatórias exigem revisão jurídica/técnica e nova versão da Spec.

## 15. Estrutura proposta
```text
split-payment-api/
├── app/
│   ├── Http/Controllers/Api/V1/
│   ├── Http/Requests/
│   ├── Models/
│   ├── Services/Payment/
│   ├── Services/TaxSplit/
│   ├── Services/Ledger/
│   └── Policies/
├── database/{migrations,seeders}/
├── routes/api.php
├── tests/{Feature,Unit}/
├── docs/{openapi.yaml,decision-log.md,test-evidence.md}/
├── .env.example
├── README.md
└── SPEC_API_SPLIT_PAYMENT.md
```

## 16. Evolução posterior (não incluída no aceite)
- Implementar adaptador configurável para ler layouts e manuais oficiais atualizados, **sem presumir acesso** à Plataforma Pública.
- Comparar fluxos padrão e simplificado, após estudo regulatório específico.
- Adicionar múltiplos documentos/parcelas, reversões parciais e conciliação de eventos de PSP simulado.
- Criar painel de auditoria e métricas para demonstrar confiabilidade operacional.

## 17. Referências oficiais para revisão
1. LC 214/2025, arts. 31 e seguintes (texto consolidado): https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp214.htm
2. Legislação da Reforma Tributária — Receita Federal: https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/reforma-tributaria-do-consumo/legislacao/
3. Atos técnicos conjuntos sobre a Plataforma Pública do Split Payment: https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/reforma-tributaria-do-consumo/legislacao/atos-tecnicos-conjuntos
4. Comunicado sobre testes da Plataforma Pública, 30/09/2026: https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/setembro/comunicado-5
5. Transição da reforma — Receita Federal: https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/reforma-tributaria-do-consumo/entenda

**Observação final:** valores de teste e lógica contábil são convenções de simulação. Não utilizar para apuração, retenção ou recolhimento tributário real.
