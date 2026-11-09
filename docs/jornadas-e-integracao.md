# Jornadas e integração — Weddell Pay

Responsável: frontend_ux. Tarefa: PAY-006.
Escopo entregue: documentação da integração HTTP do MVP. Landing page, dashboard, simulador web, histórico visual e login não foram implementados e continuam fora da aprovação atual.

## Fontes e contrato

[OpenAPI](openapi.yaml) define campos, schemas, autenticação e respostas. Consulte também [arquitetura](arquitetura.md), [premissas da simulação](premissas-simulacao.md) e [design system](design-system.md).

A integração opera apenas dados fictícios, contas virtuais e pagamentos simulados. Nenhuma chamada autoriza transferência real, emissão fiscal válida ou recolhimento tributário.

O arquivo openapi.yaml pode ser importado em uma ferramenta compatível com OpenAPI/Swagger para explorar o contrato. Importar a especificação não executa a API. Uma rota de Swagger UI hospedada não está declarada neste contrato; execução e provisionamento devem seguir o README da aplicação quando disponíveis. Não incluir Bearer tokens em especificações publicadas, exports ou capturas.

## Preparação e papéis

1. Executar a aplicação e preparar o cenário conforme os procedimentos de ambiente aprovados.
2. Provisionar credencial de operador pela CLI local. O valor bruto é segredo de demonstração e não deve ser versionado.
3. Criar vendedor fictício com o papel de operador por POST /api/v1/merchants. A resposta não contém token.
4. Provisionar a credencial daquele vendedor pela CLI local.
5. Usar a credencial do vendedor em documentos, pagamentos e consultas de seus próprios recursos.

Cabeçalho de autenticação: Authorization: Bearer <TOKEN_DEMO_LOCAL>. Requisições com corpo usam Content-Type: application/json; Accept: application/json é apropriado para os exemplos. Recursos de outro vendedor retornam o mesmo 404 usado para recurso inexistente. O operador não adquire acesso implícito aos pagamentos.

Nomes e identificadores de demonstração obedecem às allowlists do contrato: por exemplo, DEMO-ARCTIC, DEMO-ACCOUNT-ARCTIC e DEMO-DOC-001. Todos os UUIDs usados nas etapas seguintes devem vir das respostas da API. Corpo limitado a 16 KiB; respeitar os limites documentados de requisição.

## Jornada de sucesso via HTTP/Swagger

| Passo | Chamada | Entrada relevante | Resultado contratual |
|---|---|---|---|
| 1 | POST /api/v1/merchants | name e virtual_account_id fictícios; token do operador | 201 e id do vendedor; sem token |
| 2 | POST /api/v1/fiscal-documents | merchant_id, reference, gross_amount_cents, cbs_amount_cents, ibs_amount_cents | 201 e id do documento |
| 3 | POST /api/v1/payments | merchant_id, fiscal_document_id, amount_cents, currency=BRL e scenario=approved; chave de idempotência | 201, CREATED, split=null |
| 4 | POST /api/v1/payments/{id}/authorize | Sem corpo; chave própria da operação | 200, AUTHORIZED, split=null |
| 5 | POST /api/v1/payments/{id}/settle | Sem corpo; chave própria da operação | 200, SETTLED, split com líquido/CBS/IBS |
| 6 | GET /api/v1/payments/{id} | Token do vendedor proprietário | 200 com estado atual |
| 7 | GET /api/v1/payments/{id}/ledger | limit e cursor opcionais | Partidas virtuais da liquidação |
| 8 | GET /api/v1/payments/{id}/events | limit e cursor opcionais | Trilha auditável e correlações |
| 9 | POST /api/v1/payments/{id}/refund | Sem corpo; chave própria da operação | 200, REFUNDED e contrapartidas integrais |

Os passos de cadastro de vendedor e documento não oferecem garantia de idempotência por cabeçalho. A referência fiscal e a conta virtual são únicas no cenário; uma repetição indiscriminada pode produzir 409. Pagamentos e suas três transições têm a semântica idempotente descrita abaixo.

Exemplo fictício do documento, substituindo o UUID pelo vendedor criado:

```json
{
  "merchant_id": "00000000-0000-4000-8000-000000000001",
  "reference": "DEMO-DOC-001",
  "gross_amount_cents": 100000,
  "cbs_amount_cents": 8000,
  "ibs_amount_cents": 12000
}
```

Exemplo de criação de pagamento; os UUIDs abaixo são ilustrativos, não resultados de execução:

```json
{
  "merchant_id": "00000000-0000-4000-8000-000000000001",
  "fiscal_document_id": "00000000-0000-4000-8000-000000000002",
  "amount_cents": 100000,
  "currency": "BRL",
  "scenario": "approved"
}
```

O cenário é obrigatório na criação e permanece imutável. Authorize, settle e refund não recebem parâmetros de resultado, valores ou cenário. Enviar essas transições sem corpo; a descrição contratual também aceita objeto JSON vazio como comando vazio. Campos adicionais são recusados.

A liquidação do exemplo segrega 80000 centavos de líquido, 8000 de CBS e 12000 de IBS. Essa composição é fictícia. O razão debita CLEARING pelo bruto e credita as contas virtuais correspondentes; parcelas zero podem omitir suas partidas sem alterar os componentes da resposta.

## Estados e significado de split

| Estado | Significado no simulador | Apresentação de split |
|---|---|---|
| CREATED | Pagamento criado; autorização ainda não executada | null; ainda não liquidado |
| AUTHORIZED | Autorização simulada aprovada | null; ainda não liquidado |
| FAILED | Recusa de autorização ou falha terminal | null; sem segregação |
| SETTLED | Segregação contabilizada em razão virtual | Componentes originais CBS/IBS/líquido |
| REFUNDED | Liquidação integralmente compensada | Histórico original, não saldo a pagar |

A segregação ocorre somente na liquidação. Após o estorno, as partidas originais continuam presentes e as inversas podem ser consultadas no razão. O split histórico não deve ser apagado na apresentação nem interpretado como obrigação financeira atual.

Uma falha permite novo pagamento para o documento ainda não liquidado. Cada documento só admite uma liquidação ao longo de sua vida; um estorno não libera nova liquidação. Não existem rotas de edição/cancelamento de documento neste MVP.

## Cenário de recusa e falhas

Para demonstrar recusa, criar outro pagamento válido com scenario=declined e executar authorize. O resultado contratual é HTTP 200 com status FAILED e failure_code=AUTHORIZATION_DECLINED. HTTP 200 informa processamento da simulação, não aprovação do pagamento.

A decisão de apresentação deve considerar status e failure_code, além do código HTTP. CREATED e AUTHORIZED não são sinônimos de liquidação.

Falhas transitórias/terminais de liquidação são cenários de testes configurados no servidor. failure_mode não é campo HTTP nem funcionalidade da interface. Um 503 preserva a possibilidade de repetir a operação com a mesma chave; falha terminal produz FAILED sem partidas.

## Idempotência, perda de resposta e replay

As quatro operações de pagamento exigem Idempotency-Key: criação, autorização, liquidação e estorno. Cada ação lógica mantém uma chave estável. Exemplos: demo-payment-001, demo-authorize-001, demo-settle-001 e demo-refund-001. O escopo inclui vendedor, operação e recurso; não tratar a chave isoladamente como identidade global.

- Mesma chave e mesmo comando devolvem status HTTP e corpo originais, sem novo efeito.
- Mesma chave com comando divergente no mesmo escopo retorna 409.
- Chave nova para repetir uma transição já concluída retorna 409, mesmo que a operação anterior tenha sido bem-sucedida.
- Repetir com a chave original é a recuperação correta após perda de resposta; não gerar automaticamente uma nova chave.
- Resultados permanecem durante a vida do cenário. Reiniciar o processo não equivale a limpar as chaves.
- Falhas transitórias não reservam resultado final. Após 503, respeitar Retry-After quando presente e repetir o mesmo comando/chave.
- GET retorna o estado atual. Replay não substitui uma consulta atualizada.

Exemplo: um replay da criação, após liquidação, continua retornando 201/CREATED/split=null, pois essa foi a resposta original. Um replay da liquidação, após estorno, continua retornando seu resultado SETTLED. Para mostrar o estado presente, consultar GET, que retornará REFUNDED. O cabeçalho Idempotency-Replayed sinaliza o replay conforme o contrato.

No Swagger, conservar a chave ao repetir a mesma ação e conferir o ID do recurso. Um exemplo colado na ferramenta é uma entrada para a API, não evidência de que a chamada já foi executada.

## Erros, correlação e consultas paginadas

Erros usam error.code, error.message, error.details e error.correlation_id. O cabeçalho X-Correlation-ID identifica a requisição. Não mostrar SQL, stack trace ou credenciais em diagnósticos.

| HTTP | Conduta de integração |
|---|---|
| 400 | Corrigir JSON, corpo ou cabeçalho conforme o contrato |
| 401 | Usar credencial demo válida, emitida localmente |
| 403 | Verificar o papel autorizado para a ação |
| 404 | Tratar o recurso como indisponível; não inferir proprietário |
| 409 | Ler o estado atual e conferir chave/comando; evitar repetição cega |
| 413 | Reduzir corpo para o limite contratado |
| 415 | Enviar application/json quando houver corpo |
| 422 | Corrigir campos, valores, soma fiscal ou cursor |
| 429 | Respeitar Retry-After |
| 500 | Conservar correlação e consultar estado antes de decidir recuperação |
| 503 | Repetir com a mesma chave após espera recomendada |

Razão e eventos usam limit de 1 a 100, padrão 50, e cursor opaco. A resposta contém data, next_cursor e simulation. Reenviar o next_cursor como recebido até ele ser null. Não interpretar ou fabricar cursor. Cursor inválido/incompatível retorna 422. Essas páginas pertencem a um pagamento; não equivalem a listagem geral de pagamentos.

GET /health é público e retorna apenas saúde do processo/banco, sem recursos financeiros ou detalhes de infraestrutura. Uma consulta de saúde não substitui a validação da jornada de pagamento.

## Interface futura — aprovação separada

PAY-016 a PAY-018 continuam fora do MVP aprovado. Os itens abaixo são dependências de evolução, não telas entregues.

| Área futura | Dependência antes da implementação |
|---|---|
| Landing e documentação visual | Conteúdo e identidade aprovados; exemplos identificados como fictícios |
| Simulador e detalhe | Contrato de pagamentos estável, campos/erros e comportamento de replay |
| Histórico geral | Endpoint de listagem com filtros/paginação ainda não previsto no MVP |
| Dashboard e gráficos | Endpoints/definições de métricas; nenhum indicador inventado no cliente |
| Login e área restrita | Modelo de sessão de navegador aprovado por Segurança/API; API key não define um login |
| Credenciais e configurações | Política própria de gestão de segredos; o MVP não oferece emissão HTTP de tokens |
| Responsividade e acessibilidade | Implementação aprovada e verificações do design-system.md com evidências |

Uma eventual interface seguirá Arctic Fintech e mostrará “Simulação — sem movimentação financeira real”. O navegador não recalculará tributos nem obterá chave administrativa para simular permissões.

## Revisão documental e limites da entrega

A revisão de PAY-006 verificou o alinhamento textual com o contrato disponível: autenticação Bearer/CLI, teto monetário, cenário na criação, ausência de corpo nas transições, split histórico, idempotência e paginação das consultas. A aceitação de objeto vazio nas transições aparece na descrição do contrato e deve permanecer coerente com a implementação.

A ausência de listagem geral, métricas e sessão de navegador é intencional no MVP. Essas dependências devem ser resolvidas em contrato aprovado antes de desenvolver as telas correspondentes.

Não foram executadas requisições da jornada, testes de interface, verificações de contraste ou validação de uma Swagger UI hospedada nesta tarefa. Os exemplos são contratuais. Resultados reais de testes e aceite pertencem às tarefas de implementação, Segurança e QA.
