# PLAN-001 — Planejamento inicial do Weddell Pay para aprovação

- Responsável: `arquitetura_api`
- Estado: `concluida`
- Versão: `7`
- Atualizado: `2026-11-02T23:21:14+00:00`

## Histórico imutável no banco de eventos

### Evento 2 — criacao
- Quando: 2026-11-02T23:02:47+00:00
- Agente: `arquitetura_api`
- Alteração: ATIVIDADE EXCLUSIVA DE PLANEJAMENTO. Consolidar Specs/SPEC_API_SPLIT_PAYMENT.md, arctic_fintech_design.md, referência Style/DESIGN-stripe.md, os quatro perfis .agents/Agents e o MCP BlackBoard. Escopo proposto: API REST de pagamentos e Split Payment tributário inteiramente simulados; sem dinheiro real, NF-e válida, instituições financeiras ou protocolo fiscal oficial. Definir arquitetura, fases, tarefas, responsáveis, dependências, riscos e critérios de aceite. Consultar os quatro agentes apenas para análise. Gate obrigatório: não iniciar implementação, instalação, migrações, código, testes de produto ou publicação antes da aprovação explícita do usuário. PAY-001 é teste de integração anterior e será preservada. Entrega: plano persistido no BlackBoard e apresentado para aprovação.
- Arquivos: (nenhum informado)
- Evidências: (não anexadas)

### Evento 3 — estado
- Quando: 2026-11-02T23:02:47+00:00
- Agente: `arquitetura_api`
- Alteração: planejada → em_andamento. Motivo: Análise e planejamento em curso; implementação expressamente pendente de aprovação humana.
- Arquivos: (nenhum informado)
- Evidências: (não anexadas)

### Evento 21 — alteracao
- Quando: 2026-11-02T23:13:04+00:00
- Agente: `arquitetura_api`
- Alteração: # Weddell Pay — Plano inicial para aprovação (PLAN-001)
Estado: proposta técnica; aprovação humana pendente. Nenhuma implementação autorizada antes da aprovação explícita do usuário.

## Fontes e precedência
Specs/SPEC_API_SPLIT_PAYMENT.md v1.0 define requisitos e T01–T14. arctic_fintech_design.md define identidade visual; cópia Style/arctic_fintech_design.md tem SHA256 idêntico. Style/DESIGN-stripe.md é referência de hierarquia, sem substituir paleta glacial, Inter/Manrope ou dashboard claro. Consultados .agents/Agents/AGENTS.md, README e quatro perfis. MCP_BlackBoard.md, server.py e configuração local definem ferramentas/estados reais; prevalecem sobre a menção desatualizada no README de que o MCP não foi especificado e sobre aliases TODO da spec.

## Consulta operacional
board_list_tasks, board_recent_events e board_read_task executados via MCP stdio real. PAY-001 é teste anterior planejado v1 e foi preservado. PLAN-001 em_andamento apenas durante análise; será encaminhada em_revisao para aprovação humana. Todas as tarefas de desenvolvimento continuam planejadas.

## Requisitos e escopo
MVP: vendedores/contas virtuais fictícios; documentos fiscais simulados com valores nominais CBS/IBS; pagamentos associados ao mesmo vendedor/documento; autorização/rejeição mock determinística; liquidação com segregação contábil; consultas de pagamento/razão/eventos; estorno integral compensatório; autenticação demo/ownership; idempotência; auditoria; OpenAPI e demonstração HTTP. Tudo sinalizado como simulação. Sem captura/transferência/recolhimento real, NF-e válida ou conexão a bancos/PSPs/RFB/CGIBS. Não coletar CPF/CNPJ, cartão, dados bancários ou credenciais reais. Excluídos split comercial/taxas, marketplace, parcelamento, reembolso parcial, cálculo oficial ou por percentual, procedimento simplificado legal e UI obrigatória. Referências legais e cronogramas da spec não foram verificados e não são regras oficiais adotadas. Qualquer evolução regulatória exige pesquisa em fontes oficiais, revisão e aprovação própria.

## Arquitetura inicial
Monólito modular PHP/Laravel com PostgreSQL, API REST /api/v1 e fluxo síncrono. HTTP/validação/policies -> casos de uso/transações -> domínio monetário/estados/segregação/ledger -> persistência. Gateway exclusivamente mock determinístico, sem dependências bancárias/fiscais. Módulos: vendedores/credenciais, documentos, pagamentos, gateway simulado, segregação nominal CBS/IBS, ledger e auditoria. Sem filas ou microserviços no MVP. Aplicação proposta em src/api e contratos/decisões em docs/; versão Laravel e PHPUnit/Pest fixadas após validação da compatibilidade real. A interface futura apenas apresenta valores calculados pela API.

## Dados, dinheiro e atomicidade
Entidades da spec: merchants, fiscal_documents, payments, ledger_entries e payment_events; acrescentar idempotency_records, credenciais demo com hash e registro/marca de settlement único por documento. UUIDs, FK e constraints impedem vínculo entre vendedores. BRL, centavos inteiros int64/BIGINT, pagamento positivo, CBS/IBS >=0 e soma <=bruto. Sem float. Documento imutável após primeiro pagamento e ledger confirmado sem UPDATE/DELETE pela credencial de execução. Contrato PAY-003 fixa teto monetário, proteção de overflow, retenção de idempotência e regra de créditos zero antes de PAY-007. Transporte/limites devem preservar precisão futura em JavaScript.
Estados: CREATED -> AUTHORIZED -> SETTLED -> REFUNDED; CREATED -> FAILED por recusa e AUTHORIZED -> FAILED somente para falha terminal explícita. Falha transitória faz rollback e preserva elegibilidade. FAILED/REFUNDED terminais. Somente SETTLED contabiliza CBS/IBS: bruto=líquido+CBS+IBS. Exemplo fictício R$1000=R$800+R$80+R$120. Débito CLEARING no bruto, créditos MERCHANT_PAYABLE/CBS_PAYABLE/IBS_PAYABLE. Locks na ordem documento/pagamento, constraints de unicidade e transação única para settlement+partidas+evento+estado. Balanceamento é agregado por evento; PAY-007 definirá proteção de banco/transação (constraint diferida quando necessário), pois CHECK por linha não comprova soma. Estorno inverte exatamente partidas originais numa transação, sem apagar nem recalcular valores.

## Decisões propostas para aprovação
D01 API primeiro; interface opcional PAY-016–018 com aprovação própria.
D02 Preservar PostgreSQL, preparar ambiente antes de scaffold; não substituir silenciosamente por MySQL/SQLite.
D03 Permitir novo pagamento após FAILED, mas no máximo uma liquidação bem-sucedida por documento por toda a vida. Registro único por documento cobre pagamentos distintos; estorno não libera nova cobrança. É convenção de simulação proposta para fechar lacuna, não regra tributária oficial.
D04 Sem rotas públicas de edição/cancelamento fiscal no MVP; manter imutabilidade RN10/RN11.
D05 Idempotency-Key obrigatória em criar/autorizar/liquidar/estornar. Escopo vendedor+operação+recurso+chave; hash canônico, status HTTP e resposta persistidos. Replay idêntico retorna resposta original, divergente 409; GET mostra estado atual. Restrições impedem dupla liquidação/estorno mesmo com chaves distintas. Política de retenção e falhas transitórias detalhada no contrato.
D06 Provisionar credenciais demo localmente; vendedor isolado e operador demo separado para criar vendedores. Sem login de navegador no MVP; 404 uniforme para recurso de outro vendedor; health sem detalhes internos.
D07 Fechar teto monetário/overflow/créditos zero no contrato antes de migrações; não adotar alíquotas oficiais ou inferidas.
D08 Swagger/README/coleção HTTP demonstram o MVP; listagens/métricas e sessão web exigem extensão contratada.

## Etapas e dependências
0 Agora: análise, consulta dos quatro agentes, registro e aprovação humana de PLAN-001.
1 Após aprovação: PAY-002 ambiente; PAY-003 contrato/modelo/premissas coordenado com PAY-004 ameaças, PAY-005 matriz QA, PAY-006 experiência. Contrato revisado pelos quatro antes de endpoints.
2 PAY-007 domínio/migrações depende de ambiente/contrato/controles; PAY-008 acesso/demo/vendedores/documentos precede pagamentos.
3 PAY-009 criação/autorização/idempotência -> PAY-010 liquidação/ledger -> PAY-011 estorno; PAY-012 consultas/auditoria acompanha e fecha o ciclo.
4 PAY-013 testes PostgreSQL/concorrência/regressão e PAY-014 revisão Segurança acompanham incrementos e encerram a validação integral.
5 PAY-015 documentação/demonstração/parecer QA; aceite humano e publicação são decisões separadas.
6 Opcional: PAY-016 escopo/contrato web -> PAY-017 extensão API/sessão -> PAY-018 telas/validação. Não liberada pela aprovação do MVP.

## Responsáveis e coordenação
arquitetura-api (MCP arquitetura_api): dono PLAN-001, PAY-002/003/007–012; domínio, API, banco, credenciais, ledger, documentação técnica e apoio à entrega; eventual PAY-017.
frontend-ux (frontend_ux): PAY-006 revisão de contrato/linguagem/experiência agora; eventual PAY-016/018; sem redefinir cálculo.
seguranca: PAY-004 ameaças/controles e PAY-014 revisão de acesso/logs/transações/dependências; revisão das áreas sensíveis e da futura sessão.
qualidade-testes (qualidade_testes): PAY-005 matriz, PAY-013 verificação e PAY-015 documentação/demonstração/parecer; validação visual posterior.
Antes de cada tarefa consultar list_tasks/recent_events/read_task; reler versão antes de mutações e usar expected_version onde suportado. Coordenar contratos/arquivos compartilhados, não editar projeções BlackBoard/tasks manualmente. Autores executam testes pertinentes e registram evidências; Segurança e QA revisam; nenhuma publicação automática.

## Riscos, controles e donos
R01 Alto/probabilidade alta: pdo_pgsql ausente/PostgreSQL não confirmado e compatibilidade Laravel ainda não validada -> PAY-002, API/QA.
R02 Alto/média: dupla liquidação, inclusive pagamentos distintos de um documento -> marca única, locks, constraints, idempotência, teste concorrente PostgreSQL, API/QA.
R03 Alto/média: centavos/overflow, razão desbalanceado ou commit parcial -> BIGINT/teto, proteção por evento, imutabilidade, transação única e falhas injetadas, API/QA.
R04 Alto/média: acesso cruzado/segredos -> policy de ownership, operador separado, hash/allowlists/rate limit/erros sanitizados, Segurança/API/QA.
R05 Alto/média: dados reais/promessa de recolhimento -> fixtures, ausência de campos sensíveis, restrição de payload/metadados e avisos. Não presumir detecção perfeita de dados pessoais em texto livre; limitar campos/conteúdo, Segurança/Frontend/API.
R06 Médio/alta: escopo UI ou visual divergente -> Arctic prevalece e etapa web tem contrato/aprovação próprios, Frontend/API.
R07 Alto/média: replay/estorno/erro transitório mal definidos -> contrato antes do código, gateway determinístico, casos adicionais e regressão, API/QA.
R08 Médio/média: precisão JS futura -> teto seguro ou strings contratadas antes de UI, API/Frontend/QA.
R09 Médio/média: conflitos entre agentes ou MCP exposto/perda de memória -> áreas coordenadas, versões/eventos, MCP local protegido, backup consistente e sem SQLite em rede, coordenação/Segurança.
R10 Alto/média em evolução normativa: referências não validadas -> somente valores nominais fictícios, sem alegar conformidade; revisão oficial e aprovação própria para novas regras, API/Segurança.

## Aceite e evidências
T01–T14 da spec comprovados: exemplo conservado; tributos acima do bruto rejeitados; rejeição sem ledger; liquidação sem autorização bloqueada; repetição/concorrência sem duplicidade; replay/409; rollback; estorno único; isolamento; precisão; partidas balanceadas. Acrescentar concorrência entre pagamentos do mesmo documento, estorno sem liberar recobrança, replay antigo/GET atual, imutabilidade, overflow/zero, falhas terminais/transitórias e vazamento de erros/logs.
Unidade para valores/estados; HTTP/contrato para auth/validações; integração PostgreSQL para constraints/locks/atomicidade; concorrência com conexões/processos independentes e falhas injetadas. SQLite não comprova propriedades do PostgreSQL. Migrações, seeds e instalação reproduzíveis; OpenAPI atualizado; README e coleção HTTP com fluxo completo e limites; configuração sem segredos; nenhum defeito bloqueador. Falha alta/crítica tratada ou risco aceito explicitamente pelo usuário; pareceres Segurança e QA. Evidências: ambiente, versão/commit, comando, resultado e logs sanitizados. Teste planejado não conta como executado. Nesta fase nenhum teste de produto foi executado.

## Ambiente observado sem alterações
PHP CLI 8.5.12 64 bits em C:/xampp2/php/php.exe; Composer 2.10.3; PDO/pdo_mysql presentes; pdo_pgsql/pgsql ausentes. psql não encontrado no PATH, docker.exe encontrado mas daemon não validado. PostgreSQL/conectividade não confirmados. src/ sem arquivos listados. Não houve instalação, scaffold ou migração.

## Aprovação solicitada
Aprovar arquitetura, decisões D01–D08 e backlog. Aprovação libera apenas o MVP PAY-002–015. PAY-016–018 permanecem opcionais com aprovação própria; publicação e futuras integrações reais exigem autorização separada.

## Consulta aos quatro agentes e limites dos pareceres

- Arquitetura/API: leu a spec/perfil e propôs monólito/PostgreSQL, transação/locks, idempotência nas quatro operações, uma liquidação por documento entre tentativas e fechamento de lacunas de cancelamento/credenciais. Compatibilidade e teto monetário ficam em PAY-002/003 antes de implementação.

- Frontend/UX: leu perfil/regras/spec/designs; confirmou Arctic como fonte visual, API sem UI obrigatória, lacunas de listagem/métricas/login e precisão int64 em JavaScript. Revisará contrato agora; telas somente após escopo próprio.

- Segurança: parecer baseado nos documentos fornecidos no contexto e na consolidação da coordenação; a tentativa de leitura adicional por ferramenta foi interrompida. Propôs controles de isolamento, credenciais/operador, transação, allowlists, hashes, revogação/rotação, rate limit, logs e MCP local. Não é aprovação da implementação nem atestado de segurança.

- QA: parecer baseado na spec/perfis disponíveis no contexto e decisões comunicadas; leitura adicional por ferramenta foi interrompida. Mapeou T01–T14 a unidade/HTTP/PostgreSQL/concorrência e exigiu casos extras para documento único, replay, imutabilidade/overflow e rollback. Nenhum teste de produto executado ou aprovado.

- Pareceres são consultas de planejamento; aprovação humana pendente. Coordenação leu diretamente todos os quatro perfis e os documentos citados. Nenhum agente modificou código.


## Backlog e localização dos detalhes
PAY-002 | Validar ambiente e preparar base técnica | arquitetura_api | 1 — Fundação | Dependências: Aprovação explícita de PLAN-001. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-003 | Contratar API, dados e premissas de simulação | arquitetura_api | 1 — Fundação | Dependências: Aprovação de PLAN-001; alinhamento PAY-004, PAY-005 e PAY-006. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-004 | Planejar ameaças, credenciais demo e controles | seguranca | 1 — Fundação | Dependências: Aprovação de PLAN-001; coordenação com PAY-003. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-005 | Definir matriz de testes e critérios de passagem | qualidade_testes | 1 — Fundação | Dependências: Aprovação de PLAN-001; coordenação com PAY-003 e PAY-004. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-006 | Alinhar experiência e necessidades de contrato | frontend_ux | 1 — Fundação | Dependências: Aprovação de PLAN-001; coordenação com PAY-003. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-007 | Modelar domínio, migrações e invariantes | arquitetura_api | 2 — Domínio e acesso | Dependências: PAY-002, PAY-003 e PAY-004; matriz PAY-005. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-008 | Implementar acesso demo, vendedores e documentos fictícios | arquitetura_api | 2 — Domínio e acesso | Dependências: PAY-007 e controles PAY-004. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-009 | Criar e autorizar pagamentos com idempotência | arquitetura_api | 3 — Ciclo de pagamento | Dependências: PAY-008 e contrato PAY-003. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-010 | Liquidar e segregar CBS e IBS atomicamente | arquitetura_api | 3 — Ciclo de pagamento | Dependências: PAY-009 e invariantes PAY-007. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-011 | Estornar integralmente com compensação contábil | arquitetura_api | 3 — Ciclo de pagamento | Dependências: PAY-010. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-012 | Expor consultas, auditoria e saúde | arquitetura_api | 3 — Ciclo de pagamento | Dependências: PAY-009, PAY-010 e PAY-011 para aceite integral. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-013 | Executar integração, concorrência e regressão do MVP | qualidade_testes | 4 — Verificação | Dependências: PAY-005 e incrementos PAY-007 a PAY-012; testes acompanham cada entrega. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-014 | Revisar segurança da implementação e configurações | seguranca | 4 — Verificação | Dependências: PAY-004 e incrementos PAY-007 a PAY-012; revisão final junto a PAY-013. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-015 | Consolidar documentação, demonstração e parecer de aceite | qualidade_testes | 5 — Entrega da API | Dependências: PAY-013 e PAY-014; documentação técnica fornecida por arquitetura_api. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-016 | Especificar extensão opcional para a interface web | frontend_ux | 6 — Opcional, fora do MVP | Dependências: Aceite da API PAY-015 e aprovação explícita do escopo web; PAY-006. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-017 | Implementar extensão contratada de API e sessão web | arquitetura_api | 6 — Opcional, fora do MVP | Dependências: PAY-016 aprovado explicitamente. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
PAY-018 | Construir e validar interface Arctic Fintech | frontend_ux | 6 — Opcional, fora do MVP | Dependências: PAY-016 e PAY-017; autorização explícita do escopo web. Critérios, escopo e artefatos futuros constam da descrição persistida da própria tarefa.
Motivo: Planejamento consolidado a partir dos documentos existentes, da consulta real ao MCP e das análises dos quatro agentes, para decisão do usuário antes de qualquer implementação. Registra apenas trabalho de análise efetuado e backlog futuro explicitamente planejado.
- Arquivos: Nenhum arquivo de código ou especificação modificado. Somente estado BlackBoard via ferramentas MCP (events.sqlite3 e projeções BlackBoard/tasks geradas pelo servidor). Artefatos listados no plano são futuros.
- Evidências: Verificações de planejamento: leitura das especificações/perfis, inventário php -v/php -m/composer --version e consulta MCP. Nenhum teste de produto executado; T01–T14 e casos adicionais permanecem planejados.

### Evento 22 — estado
- Quando: 2026-11-02T23:13:04+00:00
- Agente: `arquitetura_api`
- Alteração: em_andamento → em_revisao. Motivo: Planejamento e 17 tarefas registrados. Aguardando aprovação explícita do usuário para iniciar somente o MVP PAY-002 a PAY-015. PAY-016 a PAY-018 exigem aprovação própria. Nenhuma implementação iniciada.
- Arquivos: (nenhum informado)
- Evidências: Especificações e quatro perfis consultados pela coordenação; pareceres de planejamento dos quatro agentes consolidados. Tarefas planejadas verificadas por board_read_task. Nenhum teste de produto executado.

### Evento 23 — alteracao
- Quando: 2026-11-02T23:21:14+00:00
- Agente: `arquitetura_api`
- Alteração: Usuário: Aprovo a arquitetura e o inicio das tarefas. Aprova arquitetura D01-D08 e início do MVP PAY-002 a PAY-015 apresentado; PAY-016 a PAY-018 permanecem opcionais e publicação depende de autorização separada.
Motivo: Aprovação humana explícita recebida no chat; liberar desenvolvimento do MVP conforme dependências.
- Arquivos: Nenhum arquivo de produto; somente registro de aprovação no BlackBoard.
- Evidências: Mensagem real do usuário; não representa teste ou entrega implementada.

### Evento 24 — estado
- Quando: 2026-11-02T23:21:14+00:00
- Agente: `arquitetura_api`
- Alteração: em_revisao → validada. Motivo: Planejamento aprovado pelo usuário. Encerramento somente do planejamento; MVP em tarefas filhas.
- Arquivos: (nenhum informado)
- Evidências: Usuário: Aprovo a arquitetura e o inicio das tarefas. Aprova arquitetura D01-D08 e início do MVP PAY-002 a PAY-015 apresentado; PAY-016 a PAY-018 permanecem opcionais e publicação depende de autorização separada.

### Evento 25 — estado
- Quando: 2026-11-02T23:21:14+00:00
- Agente: `arquitetura_api`
- Alteração: validada → concluida. Motivo: Planejamento aprovado pelo usuário. Encerramento somente do planejamento; MVP em tarefas filhas.
- Arquivos: (nenhum informado)
- Evidências: Usuário: Aprovo a arquitetura e o inicio das tarefas. Aprova arquitetura D01-D08 e início do MVP PAY-002 a PAY-015 apresentado; PAY-016 a PAY-018 permanecem opcionais e publicação depende de autorização separada.
