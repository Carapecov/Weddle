# Plano de testes — Weddell Pay

Tarefa: PAY-005. Responsável: qualidade_testes.
Base: Specs/SPEC_API_SPLIT_PAYMENT.md, decisões aprovadas em PLAN-001 e contrato OpenAPI.
Estado deste documento: planejamento de validação. Nenhum caso é considerado executado ou aprovado por estar descrito aqui.

## 1. Objetivo e limites

Validar uma API local de pagamentos e Split Payment tributário inteiramente simulados. Todos os vendedores, documentos, contas e credenciais utilizados são de demonstração. CBS e IBS permanecem separados; não há movimentação financeira, documento fiscal válido, cálculo tributário oficial ou conexão com bancos, PSPs ou órgãos fiscais.

O aceite do MVP cobre a API HTTP e sua persistência. Landing page, dashboard, autenticação de usuários finais e testes visuais pertencem a uma fase posterior.

## 2. Contrato a verificar

- Moeda BRL; valores em centavos inteiros, máximo de 1.000.000.000.000 por montante. Valores de pagamento/bruto devem ser positivos; CBS e IBS podem ser zero; somatório dos tributos não pode superar o bruto.
- O total do pagamento deve coincidir com o documento do mesmo vendedor. Os dados fiscais ficam imutáveis após associação.
- Estados: CREATED -> AUTHORIZED -> SETTLED -> REFUNDED; autorização recusada resulta em FAILED. Estados terminais e conflitos não geram lançamentos.
- O cenário approved/declined é escolhido na criação e produz autorização determinística. Campos e valores permitidos devem seguir o OpenAPI aprovado.
- Apenas a liquidação produz segregação. Uma única liquidação é permitida por documento durante toda sua existência, inclusive entre pagamentos diferentes; estorno não libera o documento.
- Liquidação e estorno são transações atômicas. O estorno preserva os lançamentos originais e cria contrapartidas exatas; parcelas zero são omitidas do razão.
- Idempotency-Key é obrigatória na criação, autorização, liquidação e estorno. Repetir a mesma chave e comando devolve o status HTTP e corpo originalmente persistidos, inclusive depois de outras transições. Conteúdo divergente na mesma chave gera 409. GET retorna o estado atual.
- Falha transitória antes do commit permite retry seguro, sem persistência parcial ou chave de sucesso envenenada.
- Chave distinta em uma transição já concluída retorna 409, sem novo efeito. POST payments exige merchant_id, fiscal_document_id, amount_cents, currency=BRL e scenario=approved|declined; authorize/settle/refund não recebem corpo.
- Rejeitar campos extras e montantes representados como float/string. Identificadores demo seguem o contrato: nome DEMO-[A-Z0-9_-]{3,48}, conta DEMO-ACCOUNT-... e referência DEMO-DOC-....
- split é null antes de SETTLED e permanece disponível após REFUNDED. Respostas incluem failure_code, version e simulation conforme OpenAPI. Consultas de razão/eventos usam limit entre 1 e 100, padrão 50, e cursor opaco.
- Autenticação Bearer de demonstração com papéis tenant/operador e isolamento por vendedor. Permissões exatas de cada rota devem coincidir com o contrato e a revisão de Segurança.

As decisões acima são convenções do simulador, sem pretensão de representar recolhimento legal.

## 3. Camadas e estratégia

| Camada | Responsabilidade | Evidência esperada |
|---|---|---|
| Unitária | Centavos, limites, split, estados e regras determinísticas | Entradas/saídas exatas, sem float e sem serviços externos |
| HTTP/contrato | Validações, códigos, autenticação, ownership, formato e replay | Status, JSON e efeitos observáveis; exemplos compatíveis com OpenAPI |
| Integração PostgreSQL | Restrições, migrações, imutabilidade, transações e razão | Estado antes/depois e consultas de invariantes |
| Concorrência PostgreSQL | Duas operações efetivamente sobrepostas | Processos/conexões independentes e uma liquidação persistida |
| Segurança funcional | Acesso cruzado, credenciais e erros sanitizados | Negação verificável e ausência de dados internos |
| Demonstração | Fluxo HTTP completo com dados fictícios | Requisições reproduzíveis, respostas e limitações documentadas |

SQLite, mocks de repositório e testes sequenciais não comprovam locking, rollback e concorrência no PostgreSQL. Passar em testes unitários não substitui a integração.

## 4. Dados e isolamento

Usar banco PostgreSQL exclusivo de testes, identificado e diferente de qualquer banco de desenvolvimento. A suíte não pode limpar dados de ambiente não identificado como teste. Não versionar credenciais; variáveis e arquivos locais sensíveis ficam fora do Git.

Preparar dois vendedores fictícios A/B e credenciais demo distintas, documentos independentes, pagamentos em cada estado e cenário approved/declined. O caso de referência usa bruto 100000, CBS 8000, IBS 12000 e líquido 80000, todos em centavos. Acrescentar 1 centavo, tributos zero, líquido zero, limite máximo e limite mais um.

Cada caso utiliza identificadores e chaves próprios. Testes concorrentes não podem depender de uma transação externa de fixture invisível às outras conexões: preparar e confirmar os dados antes de liberar os trabalhadores, depois remover somente os dados daquele caso.

## 5. Concorrência e falhas

Para T06, iniciar dois trabalhadores independentes contra o mesmo pagamento autorizado, sincronizando o início e registrando as duas respostas. Verificar uma transição SETTLED, um conjunto de lançamentos e um saldo preservado. Repetir para pagamentos distintos associados ao mesmo documento.

Para T09, provocar falha controlada dentro da transação antes do commit, após uma etapa persistente relevante. Verificar que pagamento, reserva/consumo do documento, evento de liquidação, razão e resultado idempotente de sucesso não ficaram parcialmente gravados. Remover a falha e repetir a mesma operação/chave com sucesso. Evidência de falha inesperada sem ponto de injeção identificável não comprova rollback completo.

Aplicar o mesmo princípio ao estorno e às repetições concorrentes de um comando idempotente. Instrumentação de falha deve ser restrita ao ambiente de teste e não exposta por payload público.

## 6. Critérios por etapa

| Etapa | Entrada | Saída |
|---|---|---|
| Contrato e ambiente | Plano aprovado; OpenAPI e regras consolidados | Rotas, papéis, limites e replay definidos; PHP/Laravel compatíveis; pdo_pgsql e PostgreSQL acessíveis |
| Domínio | Regras monetárias e migrações disponíveis | Invariantes e fronteiras testadas; migrações reproduzíveis; dados fictícios |
| Pagamentos | Domínio e autenticação integrados | Criação/autorização, erros e quatro comandos idempotentes verificados |
| Liquidação/estorno | Razão e transações implementados | T01–T14 e extras de atomicidade, documento único e compensação executados |
| Aceite | Funcionalidades completas e revisão de Segurança | Evidências reproduzíveis, nenhum defeito bloqueador, OpenAPI/README coerentes e demonstração HTTP |

Na consulta de planejamento, pdo_pgsql estava ausente e a disponibilidade do serviço PostgreSQL não estava demonstrada. Esses pontos são dependências de ambiente a revalidar, não resultados de testes.

## 7. Evidências e execução

Comandos serão fixados após o bootstrap em src/api e a configuração real da suíte PHPUnit. A execução deve informar diretório, comando literal, versão de PHP/framework/PostgreSQL, revisão do código e código de saída. Nenhum comando ilustrativo deve ser apresentado como executado.

Para cada caso da matriz registrar: ID, preparação, operação, resultado esperado/obtido, status (não executado, aprovado, falhou ou bloqueado), evidência sanitizada e defeito relacionado. JUnit/logs podem complementar o relato, sem tokens ou connection strings.

O relatório final deve distinguir testes unitários, HTTP e PostgreSQL, incluindo testes ignorados/bloqueados. Teste bloqueado ou não executado não conta como aprovado. Corrigir um defeito exige executar novamente o caso e a regressão afetada.

## 8. Responsabilidades e aceite

QA mantém esta matriz, implementa testes autorizados e registra resultados reais. Arquitetura/API resolve contrato, regras e defeitos de backend. Segurança fornece cenários de abuso e revisa credenciais, permissões e exposição de dados. Frontend participa quando houver interface aprovada.

Aceitar o MVP somente com T01–T14 e os extras obrigatórios desta matriz executados sem defeitos bloqueadores, migrações e execução reproduzíveis, documentação consistente e revisão de Segurança registrada. Riscos residuais precisam ser explícitos e aprovados pelo responsável; não podem ser ocultados por testes ignorados. Publicação continua dependente de autorização humana.
