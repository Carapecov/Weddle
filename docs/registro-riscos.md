# Registro de riscos — Weddell Pay

**Tarefas:** PAY-004 e PAY-014 | **Responsável:** seguranca | **Versão:** 1.1.
**Estado:** revisão do MVP local concluída; parecer favorável dentro dos limites registrados.
**Referências:** PLAN-001, SPEC_API_SPLIT_PAYMENT.md, ameacas-e-controles.md, checklist-seguranca.md e parecer-seguranca.md.

As classificações de impacto/probabilidade são estimativas do planejamento, não medições estatísticas. "Tratado no escopo" significa que o controle correspondente foi inspecionado e há evidência real indicada; não garante ausência de vulnerabilidades. Permanecem limitações operacionais, sem achado alto/crítico conhecido não corrigido no escopo local.

## Evidências

- E1: `.runtime/evidence/phpunit-final.txt` — 80 testes, 9.835 asserções, exit 0.
- E2: `.runtime/evidence/http-demo-final.txt` — 13 requisições HTTP reais como weddell_app, REFUNDED, revogação → 401.
- E3: `.runtime/evidence/security-infra.json` — resultados de auditoria Composer e provas Apache/ambiente informados pela coordenação.
- E4: `.runtime/evidence/runtime-permissions.txt` — privilégios e seis negativas reais sem escrita de linhas como weddell_app.

Execuções realizadas pela coordenação, com relatórios sanitizados lidos pelo agente Segurança. A revisão de código/configuração e a conferência documental foram realizadas pelo agente Segurança.

## Riscos e situação após revisão

| ID | Risco | Impacto / probabilidade inicial | Controle e evidência | Responsável | Situação |
|---|---|---|---|---|---|
| R-01 | Acesso cruzado por UUID, merchant_id ou replay | Alto / média | Bearer/papel/ownership antes do replay, 404 sem dados e namespace D05; T12 e testes de escopo E1 | arquitetura_api / QA | Tratado no escopo |
| R-02 | Credencial previsível, exposta ou sem revogação | Alto / média | CLI random_bytes(32), hash SHA-256, nenhuma emissão via HTTP; revogação exercitada com 401 E1/E2; grant só revoked_at E4 | arquitetura_api / seguranca | Tratado; inspeção exaustiva de logs não executada |
| R-03 | Liquidação/estorno duplicado, inclusive entre pagamentos do mesmo documento | Alto / média | Locks, marker vitalício, constraints, concorrência real e estorno repetido E1; contas zeradas E2 | arquitetura_api / QA | Tratado no escopo |
| R-04 | Persistência parcial após falha antes do commit | Alto / média | Transação financeira/idempotência e savepoint; falhas controladas, rollback e retry E1 | arquitetura_api / QA | Tratado no escopo |
| R-05 | Float, overflow, campos arbitrários ou composição fiscal inválida | Alto / média | Inteiros estritos <=10^12, allowlists, constraints, T01/T02/T13/T14 E1 | arquitetura_api / QA | Tratado; literal exponencial não teve caso dedicado |
| R-06 | Dados reais, credenciais ou infraestrutura vazam em respostas/logs | Alto / média | Prefixos demo e schema fechado; erros sanitizados E1, demo sem token E2; logging técnico inspecionado | arquitetura_api / seguranca | Tratado para casos testados; revisão operacional exaustiva de logs pendente |
| R-07 | Papel de aplicação altera histórico confirmado | Alto / baixa a média | Grants mínimos, triggers e negativas UPDATE/DELETE; ausência de TRUNCATE no catálogo E1/E4 | coordenação / arquitetura_api | Tratado no escopo |
| R-08 | Exposição acidental de fonte/API/banco/MCP em webroot | Alto / baixa a média | Loopback, Require all denied na raiz; Apache temporário com sentinela 403 E3; MCP stdio local | coordenação | Tratado na configuração testada; Apache global estava offline |
| R-09 | Abuso de requisições ou contenção de locks | Médio / média | Rate limit por credencial 429, corpo 16 KiB, chave limitada e concorrência sem duplicação E1 | arquitetura_api / QA | Mitigado no MVP; carga sustentada/chaos e limite IP dedicado não exercitados |
| R-10 | Ambiente incompatível ou dependência vulnerável conhecida | Alto / média | PHP 8.5.12, PHPUnit 12.5.43, PostgreSQL local; lockfile e audit exit0 sem advisories/abandoned E1/E3; E2 usa driver real | coordenação / arquitetura_api | Tratado no snapshot; auditoria precisa repetição em atualizações |
| R-11 | Demonstração sugere recolhimento real ou integração oficial | Médio / média | simulation=true, CBS/IBS separados, mock determinístico, sem adaptador financeiro oficial no fluxo revisado E1/E2 e docs | arquitetura_api / frontend_ux / QA | Tratado no escopo; nenhuma conformidade legal atestada |
| R-12 | Retenção/backups expõem informação ou apagam auditoria | Médio / média | Logs diários configurados para sete arquivos; ledger/eventos preservados por triggers/permissões E1/E4 | coordenação | Mitigação configurada; passagem de sete dias e restauração de backup não testadas |

## Achados concretos e correções

| Achado | Impacto no projeto | Correção responsável | Verificação |
|---|---|---|---|
| .env em htdocs sem bloqueio raiz | Possível exposição pelo Apache, distinta do bind do Artisan | Coordenação adicionou .htaccess Require all denied e limitou a forma de servir a aplicação | E3: Apache temporário equivalente retorna 403 para sentinela não secreta; nenhum segredo foi solicitado |
| Grants antigos UPDATE poderiam sobreviver a novo setup | Privilégio residual além do modelo mínimo | Coordenação revoga privilégios gerais antes de conceder os necessários; somente colunas id fiscal/revoked_at foram acrescentadas | E4: histórico protegido, montante e token_hash sem UPDATE |
| Testes podiam herdar banco/ambiente indevido | Risco de migração/reset no banco errado | Coordenação/QA forçam ambiente/host/porta/banco e conferem current_database antes de operações destrutivas | Inspeção dos guards e E1 no banco dedicado; testes destrutivos fora do banco não foram tentados |
| Claim podia apontar CREATED/AUTHORIZED | Documento marcado sem evento financeiro | arquitetura_api: migration 000200 exige SETTLED e correspondência de documento/pagamento/estado | E1: claim não financeiro rejeitado no commit |
| Row lock fiscal exigia privilégio UPDATE | Runtime falhava apesar de suíte owner passar | Coordenação concede UPDATE(id) nominal; arquitetura_api protege UUID incondicionalmente em migration 000300 | E1: UUID imutável sem pagamento; E2: ciclo completo como app; E4: só privilégio nominal, sem alteração de montante |
| CORS default do framework permitia qualquer origem | Divergência do requisito sem navegador aprovado | arquitetura_api configura origens/padrões vazios; QA adiciona preflight externo | E1: sem Access-Control-Allow-Origin |
| Revogação não possuía comando operacional | Credenciais emitidas não tinham procedimento local suportado | arquitetura_api adiciona demo:revoke-key; coordenação concede UPDATE(revoked_at) | E2: token revogado retorna 401; E4: token_hash permanece protegido |
| GET ledger/events ignorava corpo JSON | Divergência de allowlist entre consultas | arquitetura_api aplica Payload::empty às páginas | Inspeção do handler corrigido; caso dedicado de corpo em GET não foi executado |
| Normalização genérica de *_id alterava conta DEMO | Cadastro HTTP válido era rejeitado na primeira suíte | arquitetura_api restringe normalização a merchant_id/fiscal_document_id; QA preserva expectativa 201 | E1/E2: cadastro e ciclo aprovados |
| Helper enviava [] quando queria corpo vazio | Dois testes falhavam por fixture incompatível | QA envia {} sem enfraquecer limites de tipos | E1/E5: regressão após correção |
| Processo filho de artisan serve não herdava driver | Primeira demonstração HTTP retornava 503 | Coordenação usa PHP builtin com pdo_pgsql explícito e raiz public | E2: demonstração HTTP real aprovada |

A observação sobre correlação de replay foi resolvida por documentação: corpo histórico conserva a correlação original, X-Correlation-ID identifica a requisição atual. A semântica D05 aprovada permite a mesma chave em outro recurso; os documentos foram harmonizados após autorização humana.

## Histórico de execução

1. PAY-004 definiu critérios, sem executar testes do produto.
2. Primeira suíte registrada: 79 casos, 76 aprovados e três falhas; arquivo `.runtime/evidence/phpunit-first.txt` preservado.
3. Correções de normalização, helper e CORS, seguidas da suíte final: 80 casos aprovados, 9.835 asserções, exit 0.
4. Primeira prova HTTP teve erro de driver no processo filho. O wrapper foi corrigido; o log inicial `.runtime/evidence/http-demo.txt` foi preservado.
5. Prova final HTTP como aplicação e prova de privilégios passaram; nenhum teste amplo adicional foi executado pelo agente Segurança.
6. Não houve alteração do núcleo financeiro depois da suíte final, conforme coordenação; a atualização dos wrappers foi coberta pela prova HTTP final.

## Limites e condições mantidas

- Usar apenas localmente, com dados fictícios; sem publicação, dinheiro, NF-e válida, tributação oficial ou integrações externas.
- Sem UI: qualquer navegador/sessão/cookie, CORS permitido, proxy ou exposição futura exige revisão específica.
- TLS não foi avaliado como requisito de loopback. Isso não autoriza HTTP em rede externa.
- Apache global na porta 80 estava inacessível. A prova usa instância temporária equivalente e não substitui nova conferência se o serviço global for ativado.
- Rotação em sete dias reais, restauração de backups, carga sustentada, fuzzing abrangente e análise exaustiva de logs/egress não foram realizados.
- O campo agent do BlackBoard continua sendo identificação declarada, não autenticação. O uso permanece local/protegido.
- Referências e datas legais da spec não foram verificadas nesta revisão; não se atesta alíquota, vigência ou conformidade.

Parecer: favorável ao aceite técnico do MVP local no snapshot revisado. Não há achado crítico/alto conhecido sem correção no escopo inspecionado. Publicação e futuras ampliações continuam dependentes de autorização humana.
