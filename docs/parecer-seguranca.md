# Parecer de segurança — MVP local Weddell Pay

**Tarefa:** PAY-014 | **Agente:** seguranca | **Referência:** PLAN-001 aprovado e tarefas PAY-002 a PAY-015.
**Decisão:** favorável ao aceite técnico do MVP de simulação local, no snapshot e condições abaixo.

Não há achado crítico/alto conhecido sem correção no escopo inspecionado. Este parecer não é certificação, comprovação de conformidade jurídica ou autorização de produção/publicação.

## Escopo e método

O agente Segurança revisou em leitura os documentos de requisitos/contrato, scripts de ambiente, middleware de contexto/autenticação, validação, serializers, controllers, serviços de pagamento/idempotência, rotas HTTP/CLI, configuração CORS e as três migrations PostgreSQL. Conferiu depois os relatórios sanitizados de execução.

As execuções da suíte, da demonstração HTTP, da auditoria Composer e das provas Apache/PostgreSQL foram realizadas pela coordenação. O agente Segurança não reivindica execução pessoal desses comandos. Não foram lidos/impressos pelo agente os arquivos .env privados, nem feitos testes contra sistemas externos.

A análise cobre a API sem UI, em loopback, com tokens demo, contas virtuais e documentos fictícios. CBS/IBS são componentes nominais separados; não há dinheiro real ou protocolo fiscal oficial implementado.

## Ambiente e snapshot

- PHP 8.5.12 e PHPUnit 12.5.43, conforme log final.
- Laravel conforme composer.lock; PostgreSQL 17 local, porta 55432, conforme ambiente informado e provas da coordenação.
- Suíte usa banco dedicado weddell_pay_test; demonstração HTTP usa papel runtime weddell_app.
- API HTTP real em 127.0.0.1:8000; PostgreSQL publicado somente em 127.0.0.1:55432.
- Não foi fornecido hash de commit; o snapshot é identificado pelos arquivos e SHA-256 abaixo. Não interpretar este parecer como avaliação de revisões futuras.

| Artefato | SHA-256 |
|---|---|
| src/api/composer.lock | 50D4CF4C489A216AE983F3957AB3CB9EF08140B659E2F42614A43D455B834C7E |
| docs/openapi.yaml | 7EB4C868B0C58E2993D8DEEBE0BC9529303C65CF5ED17A47D59ECBCC25E43583 |
| migration 000100_create_simulation_schema | B4714D8E6E0B6E48C657A317141C3AD29883A57E2A91D2647671229840C45EF1 |
| migration 000200_strengthen_settlement_claim | 1BDB5F04A06BECB14A7201E87808959DEFCC272BD382BDEBEBA43373BDBCC6D7 |
| migration 000300_freeze_document_identity | 905B0D94A95D0B0382FF12DB04C60DDE7011BDC9F3B36FA68ECC063EE19C9853 |

## Evidências verificadas

### Suíte de contrato, negócio e PostgreSQL

Comando registrado pela coordenação, diretório src/api:

`php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit --testdox`

Resultado: exit 0; 80 testes, 9.835 asserções; 36,387 s.

Arquivo: `.runtime/evidence/phpunit-final.txt`.
SHA-256: B525D230CEDE33D7E53848713ACEA93AF9350E28A2E933BA5A07AE1D939EAC12.

O log inclui T01–T14, autenticação/papéis/isolamento, tipos/limites, allowlist, saúde/erros, 429, CORS, escopo/replay idempotente, concorrência por pagamento/documento, estornos concorrentes, integridade no commit e rollback/retry. O teste de claim não financeiro e o de UUID fiscal imutável passaram.

Histórico preservado: a primeira execução teve 79 testes, 76 aprovados e três falhas. Houve correção da normalização indevida de virtual_account_id, de fixtures que enviavam [] como corpo vazio e inclusão do teste CORS. Não se reclassifica a primeira execução como aprovada.

### HTTP real com a conta da aplicação

Comando registrado pela coordenação, raiz do projeto:

`php -d extension=pdo_pgsql scripts/demo.php`

Arquivo: `.runtime/evidence/http-demo-final.txt`.
SHA-256: F637DC40BCFAA3E5AC6B6B7A9B428207F083E073E87E998EDD5A72FD27374716.

Resultado passed, exit 0: 13 requisições reais em loopback como weddell_app, criação/autorização/liquidação/estorno, replay da resposta histórica e GET do estado atual. Pagamento terminou REFUNDED; oito partidas e quatro eventos; CLEARING, MERCHANT_PAYABLE, CBS_PAYABLE e IBS_PAYABLE terminaram com saldo agregado zero. Revogação por CLI seguida de requisição retornou 401. Relatório não contém tokens.

A primeira tentativa HTTP falhou por ausência do driver no processo filho de artisan serve. O wrapper foi alterado para PHP builtin com pdo_pgsql explícito e raiz public; somente a prova final aprovada sustenta esta conclusão.

### Privilégios do papel runtime

Comando, raiz do projeto:

`php -d extension=pdo_pgsql scripts/check-runtime.php`

Arquivo: `.runtime/evidence/runtime-permissions.txt`.
SHA-256: 32DBFDE160A76C63A57822688C19E699A0975771EFB0070A680ABDC8D387E999.

Resultado passed, exit 0: weddell_app sem superuser/schema CREATE; sem UPDATE/DELETE/TRUNCATE em ledger_entries, payment_events e document_settlements; seis tentativas UPDATE/DELETE WHERE false negadas com 42501, sem alterar dados. Ausência de TRUNCATE foi conferida por catálogo, sem tentativa destrutiva.

Montante fiscal e token_hash não têm UPDATE. Há somente UPDATE(id) nominal do documento para permitir row lock e UPDATE(revoked_at) para revogação, além das atualizações necessárias em payments/idempotency. O UUID nominal não pode ser alterado: proteção da migration 000300 passou na suíte.

### Dependências e proteção da fonte

Relatório sanitizado: `.runtime/evidence/security-infra.json`.
SHA-256: AAD9931697F54604E814E6487DF3C98C52CA07CADF59A59CCB45C620632C3C7B.

Auditoria, diretório src/api:

`php -d extension=pdo_pgsql C:\Users\gabri\composer\composer.phar audit --locked --format=json`

Exit 0, advisories=[], abandoned=[] e filter=[]. Isso significa que a auditoria consultada não identificou avisos para o lockfile naquele momento; não é garantia de ausência de vulnerabilidades.

Prova Apache informada pela coordenação: configuração temporária em 127.0.0.1:18081, DocumentRoot C:/xampp2/htdocs e AllowOverride All equivalente. Validação retornou Syntax OK; sentinela não secreta recebeu 403, conteúdo não exposto. Instância temporária foi encerrada. O Apache global da porta 80 estava inacessível e não foi iniciado.

O agente Segurança também conferiu por git check-ignore/git ls-files que /.env, /src/api/.env e /src/api/.env.testing estão ignorados e não rastreados, sem ler seus conteúdos.

## Correções avaliadas

As correções e responsáveis estão detalhados em registro-riscos.md. Foram verificados:

- Bloqueio Apache na raiz e reset de grants antes de aplicar privilégios mínimos.
- Guards de banco/ambiente nos testes antes de migração/reset.
- Claim restrita a evento SETTLED correspondente e UUID fiscal imutável.
- Permissão nominal para row lock, acompanhada de prova do fluxo com a conta real da aplicação.
- Revogação CLI, atualização restrita a revoked_at e rejeição HTTP posterior.
- CORS explícito sem origens autorizadas.
- Validação de corpo nas páginas ledger/events e preservação da conta virtual DEMO ao normalizar UUIDs.
- Contrato D05 harmonizado: vendedor/operação/recurso/chave. Corpo de replay é histórico; correlação no header identifica a requisição atual.

Nenhum arquivo do backend foi alterado pelo agente Segurança. Conforme coordenação, o núcleo financeiro não mudou depois da suíte final; os wrappers foram conferidos e a demonstração HTTP final passou.

## Limitações e condições do parecer

1. Validade restrita ao MVP local com dados fictícios, sem UI, TLS externo, instituições financeiras/fiscais ou publicação.
2. Retenção está configurada para sete arquivos de log diários; não transcorreram sete dias de observação para comprovar descarte temporal. Restauração de backups não foi testada.
3. A inspeção de código verificou allowlists e logging técnico; não houve varredura exaustiva de logs, captura de egress, fuzzing abrangente, carga sustentada ou pentest externo.
4. O limite por credencial e corpo/chave foram exercitados; não houve caso dedicado para 30 falhas de autenticação/minuto por IP, Bearer igual ao hash armazenado ou literal monetário em notação exponencial.
5. A proteção Apache foi testada em instância temporária equivalente. Revalidar configuração se o Apache global for ativado.
6. TLS não foi avaliado como requisito de HTTP em loopback. Qualquer exposição de rede, UI/cookies/proxy/CORS permitido exige revisão e autorização próprias.
7. MCP local identifica agentes por declaração, sem autenticação própria. Não expor o servidor com base apenas no campo agent.
8. Datas/regras legais citadas na especificação não foram verificadas; nenhuma conformidade tributária, PCI DSS ou LGPD é atestada.

Os itens não executados constam no checklist, sem marcação antecipada. São limites de cobertura, não achados altos/críticos confirmados sem tratamento.

**Resultado final:** parecer favorável ao aceite do MVP local no snapshot revisado. Registro no BlackBoard cabe à coordenação; este arquivo não declara que a publicação foi autorizada.
