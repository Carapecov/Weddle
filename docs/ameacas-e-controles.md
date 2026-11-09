# Ameaças e controles — Weddell Pay

**Tarefa:** PAY-004 | **Responsável:** seguranca | **Versão:** 1.0
**Escopo:** MVP HTTP local de pagamentos e Split Payment tributário simulados.
**Estado:** baseline de requisitos PAY-004; revisão do MVP local em PAY-014 registrada em parecer-seguranca.md, com evidências e limitações no checklist.

Este documento não atesta segurança, conformidade legal ou certificação. Nenhum controle listado deve ser considerado implementado ou aprovado sem evidência da versão executada.

## 1. Contexto e decisões recebidas

Fontes: `Specs/SPEC_API_SPLIT_PAYMENT.md`, `.agents/Agents/AGENTS.md`, perfil `.agents/Agents/03_agente_seguranca.md` e decisões de PLAN-001 aprovadas pelo usuário, transmitidas pela coordenação.

- Monólito Laravel/PostgreSQL, processamento síncrono e API versionada.
- Gateway exclusivamente mock, com cenário determinístico `approved` ou `declined`.
- Apenas BRL e centavos inteiros; máximo de 1.000.000.000.000 centavos por valor monetário.
- CBS e IBS separados, obtidos do documento fictício, segregados apenas na liquidação.
- Credenciais demo Bearer geradas localmente; banco guarda somente SHA-256 do token.
- Credencial de vendedor com escopo ao próprio vendedor; operador demo separado.
- Provisionamento de credenciais por CLI local, sem endpoint público de emissão.
- Idempotência obrigatória na criação do pagamento, autorização, liquidação e estorno.
- No máximo uma liquidação por documento entre todos os pagamentos; estorno não libera nova liquidação.
- Sem rotas de edição/cancelamento de documento no MVP; razão contábil confirmado imutável.
- Nenhuma conexão com instituições financeiras, sistemas fiscais oficiais ou dinheiro real.
- UI e publicação não integram esta autorização.

## 2. Ativos e fronteiras de confiança

| Fronteira | Ativos | Entradas a controlar |
|---|---|---|
| Cliente HTTP → API | Credencial demo, escopo do vendedor e comandos | Headers, UUIDs, JSON, cenário mock, chave idempotente |
| API → PostgreSQL | Pagamentos, documento, ledger, eventos e idempotência | Consultas parametrizadas, transações, constraints e permissões |
| API → logs | Erros técnicos e correlação | Allowlist de campos e mensagens sanitizadas |
| Operador local → CLI/configuração | Emissão/revogação de chaves e migrações | Acesso ao sistema operacional, variáveis de ambiente e saída do terminal |
| Agentes → MCP BlackBoard | Planejamento, mudanças e evidências | Acesso local/protegido e conteúdo sem segredos |

O identificador `agent` do BlackBoard é informado pelo cliente; não constitui autenticação. O SQLite do BlackBoard é separado do PostgreSQL do produto.

## 3. Autenticação e autorização

### SEC-01 — Credenciais demo

Exigir `Authorization: Bearer <token-demo>` em todas as rotas `/api/v1`. Gerar tokens com CSPRNG e pelo menos 256 bits de entropia; nunca usar senhas, hashes previsíveis ou identificadores como token.

SHA-256 é usado aqui sobre um segredo aleatório de alta entropia, não como mecanismo de armazenamento de senhas. Não aceitar o hash armazenado como credencial. Associar hash a identidade, papel, vendedor quando aplicável e indicador de revogação. Não retornar token/hash em serializers, consultas, eventos ou erros.

A CLI pode exibir uma credencial nova uma única vez para o operador que a solicitou; não a persistir em arquivo versionado, histórico de comandos ou saída de testes. Documentar rotação/revogação; uma credencial revogada deixa de autenticar. Operador não recebe acesso implícito a pagamentos de todos os vendedores.

### SEC-02 — Escopo por vendedor

Identificar o vendedor pelo token autenticado. Quando o contrato aceitar `merchant_id`, exigir coincidência com o escopo autenticado. Verificar ownership de documentos e pagamentos em criação, consultas, ledger, eventos, autorização, liquidação e estorno.

Responder `404` para recurso pertencente a outro vendedor, sem revelar proprietário ou existência. Usar `403` para papel sem permissão na rota, independentemente de um recurso existir. Ausência, formato inválido ou revogação de credencial resultam em `401`.

Somente o operador demo pode cadastrar vendedores. Provisionamento de chaves permanece local. Listagens e contagens futuras também devem aplicar escopo antes de consultar/paginar.

## 4. Validação e minimização de dados

### SEC-03 — Entrada fechada

Adotar allowlist por operação e rejeitar propriedades inesperadas, incluindo campos aninhados. Não permitir mass assignment de `status`, owner, líquido, lançamentos, eventos, credenciais ou campos internos.

- Aceitar valores monetários somente como inteiros JSON, nunca float, notação exponencial ou string numérica.
- Pagamento e total bruto: de 1 até 1.000.000.000.000 centavos; CBS/IBS: de 0 até o mesmo máximo.
- Validar CBS + IBS <= bruto e pagamento = bruto; BRL é a única moeda.
- Verificar UUID, comprimento de campos e enum de cenário (`approved`/`declined`).
- Nomes de demonstração seguem `DEMO-[A-Z0-9_-]{3,48}`; contas virtuais usam prefixo `DEMO-ACCOUNT-` e referências usam `DEMO-DOC-`, com comprimento/sufixo delimitados pelo contrato.
- Não aceitar CPF/CNPJ, PAN/CVV, dados de conta bancária, URLs de callback ou metadados livres.

Formato restrito reduz exposição, mas não comprova que todo texto informado é fictício; exemplos, instruções e testes devem manter essa limitação explícita. Nunca afirmar que uma expressão regular detecta todos os dados pessoais reais.

### SEC-04 — Respostas e saúde

Usar erro padronizado com código estável e correlação, sem SQL, stack trace, caminhos internos, credenciais ou corpo original. Não usar redirecionamento para login HTML em falhas da API.

`/health` pode ser público no ambiente local e retorna apenas estado mínimo e `simulation: true`, com `200` para saudável e `503` para indisponibilidade; não expor host, versão, usuário do banco ou mensagem da exceção. Falha de disponibilidade precisa ser detectável sem revelar detalhes.

## 5. Integridade monetária e concorrência

### SEC-05 — Idempotência com autorização prévia

Autorizar o cliente e o recurso antes de buscar/devolver replay. Conforme a decisão D05 de PLAN-001, o escopo da chave é vendedor + operação + recurso + chave; criação usa recurso create, transições usam o UUID do pagamento. O hash canônico deve vincular método, rota/recurso e payload validado. Canonicalizar ordem de propriedades sem transformar tipos inválidos em válidos.

Mesma chave, escopo e requisição retornam resultado persistido sem repetir eventos/lançamentos; payload diferente no mesmo escopo retorna `409`. Reutilizar a chave em outro pagamento constitui outro escopo e é permitido, sem recuperar resposta do recurso anterior. Uma chave não pode recuperar resposta de outro vendedor/operação/recurso; as constraints de negócio continuam impedindo liquidação/estorno duplicado. Persistir resposta idempotente e efeitos da operação atomicamente.

Recusa determinística de autorização é resultado terminal de negócio e deve ser repetível. Falha transitória com rollback não pode deixar chave concluída ou operação parcialmente persistida; documentar retentativa. Nenhum `401`, `403` ou `404` pode contornar autorização por cache.

### SEC-06 — Ledger e transições

Executar liquidação e estorno em transações PostgreSQL, com bloqueio e restrições únicas que resistam a concorrência. Serializar também a disputa entre pagamentos diferentes do mesmo documento; bloquear apenas cada pagamento não resolve essa disputa.

Somente AUTHORIZED → SETTLED contabiliza o split; SETTLED → REFUNDED cria contrapartidas integrais pelos valores originais. Preservar FAILED/REFUNDED terminais; estorno não remove a evidência de liquidação do documento.

Estado, marcador único de liquidação, eventos, partidas e idempotência devem confirmar ou reverter juntos. Débitos = créditos por evento; bruto = líquido + CBS + IBS.

Impedir UPDATE/DELETE de lançamentos e eventos confirmados pela conexão normal da aplicação, mediante privilégio/controle no banco adequado; separar conexão de migração. Não afirmar imutabilidade assegurada apenas por convenção do ORM.

## 6. Logs, ambiente e abuso

### SEC-07 — Logs e correlação

Permitir somente campos técnicos necessários: timestamp UTC, correlation_id/request_id validado ou gerado, método, rota parametrizada, código de resultado, categoria sanitizada de erro e duração. Identificadores internos necessários podem ser registrados conforme política explícita; não incluir dados pessoais.

Nunca registrar Authorization, chave/hash de token, payload completo, corpo de resposta, bind de SQL, dados fiscais em texto livre ou exceptions com contexto sensível. Não usar conteúdo do cliente como linha de log sem validação. Auditoria de negócio usa metadados fechados e não se confunde com log técnico.

### SEC-08 — Ambiente e disponibilidade

Usar variáveis de ambiente locais, `.env.example` sem valores secretos e `.env` fora do Git. Desabilitar debug em demonstrações compartilhadas. Banco sem exposição pública, conta da aplicação sem superuser, migrações com privilégio separado e instruções reproduzíveis.

Restringir a escuta a loopback por padrão. Não habilitar CORS irrestrito. O MVP Bearer sem cookies não depende de sessão de navegador; se cookies/UI forem adicionados, revisar CSRF, CORS, sessão e armazenamento de credenciais antes da integração.

Aplicar rate limit, limites de payload e timeouts. TLS e autorização humana serão condições de qualquer exposição posterior; esta entrega não autoriza publicação.

### Parâmetros operacionais aprovados para o MVP local

A coordenação aceitou estes parâmetros para inclusão em PAY-003. Sua definição não é evidência de implementação:

| Parâmetro | Proposta |
|---|---|
| Rate limit autenticado | 60 requisições/minuto por identidade de credencial |
| Rate limit de falhas de autenticação | 30 falhas/minuto por IP de origem; confiar apenas em proxy explicitamente configurado |
| Corpo JSON | Máximo 16 KiB |
| Idempotency-Key | 1–128 caracteres ASCII sem controles; sem segredos/dados pessoais |
| Retenção de logs técnicos | 7 dias, com rotação e exclusão local verificável |
| Ledger/eventos de negócio | Não expirar pela política de logs; sem rota de exclusão no MVP |

Responder `429` no excesso e documentar retentativa. Não usar o Bearer em claro como chave/log de rate limit. Ajustes devem ser registrados no contrato e em decisão técnica.

## 7. Verificação e dependências

- PAY-003: contrato HTTP, erros, escopos, limites e canonicalização.
- PAY-007: schema, constraints, transações e privilégios do ledger.
- PAY-008 a PAY-012: aplicação dos controles nos endpoints.
- PAY-005/PAY-013: matriz e execução de testes funcionais, negativos, concorrência e rollback.
- PAY-014: revisão da implementação/configuração/dependências efetivamente entregues, com evidências sanitizadas.
- PAY-015: documentação de uso seguro, credenciais locais, limites e demonstração fictícia.

Uma revisão documental não substitui PAY-014. Critérios detalhados e registro de riscos estão em `checklist-seguranca.md` e `registro-riscos.md`.
