# Relatório de segurança da demonstração web — Weddell Pay

**Responsável:** agente seguranca.
**Data do parecer:** 2026-11-07, America/Sao_Paulo.
**Escopo:** demonstração web local das tarefas PAY-016 a PAY-018, complementar ao MVP da API.

**Parecer técnico:** favorável à demonstração web local no snapshot revisado. Não foi identificado achado crítico ou alto conhecido sem correção no código e nos casos inspecionados.

Este parecer não representa aceite humano da entrega ou da API, não altera o estado dessas aprovações e não autoriza publicação, produção ou movimentação financeira. O projeto continua restrito a dados fictícios, contas virtuais e valores nominais de CBS/IBS.

## Método e fontes

O agente Segurança revisou em leitura:

- Contrato web e integração com PaymentService/IdempotencyService.
- WebDemoController, WebDemoService, WebContext e WebDemoSession.
- Rotas, tratamento de erros, configuração de sessão e CORS.
- Templates Blade e os pontos de geração de HTML, chaves de ação e requisições em public/assets/weddell.js.
- Os relatórios sanitizados da coordenação indicados abaixo.

Os testes automatizados e a prova em navegador/HTTP foram executados pela coordenação, não pessoalmente pelo agente Segurança. Este agente não alterou código, não iniciou novos testes amplos, não leu arquivos .env privados e não registrou mutações no BlackBoard.

| Fonte | Resultado observado |
|---|---|
| .runtime/evidence/web-phpunit-first.txt | Primeira suíte web: 16 testes e 1.756 asserções aprovados |
| .runtime/evidence/web-phpunit-final.txt | Regressão combinada: result=passed, 96 testes aprovados, 11.590 asserções, 45.041 ms; exit 0 informado pela coordenação |
| .runtime/evidence/web-browser-final.txt | ok=true, errors=[]; prova real de navegação/HTTP e interface móvel |

A primeira suíte web não foi tratada como prova suficiente de CSRF: testes Laravel em ambiente testing podem dispensar essa verificação. A conclusão sobre CSRF apoia-se no caso real HTTP 419 registrado na prova final de navegador.

## Controles revisados e evidências

| Área | Implementação inspecionada | Evidência e conclusão |
|---|---|---|
| Sessão e segredo | Sessão contém somente merchant_id/credential_id; hash fica no banco e o segredo bruto não é mantido no navegador. Configuração usa file por padrão, encrypt=true, HttpOnly e SameSite=Strict | Relatório browser confirma HttpOnly/Strict e ausência de browser storage. Configuração de criptografia de arquivo foi inspecionada; arquivos privados de sessão não foram decriptados nesta revisão |
| Isolamento | Cada requisição protegida confere credencial merchant ativa, merchant da sessão e revogação; consultas/agregados são filtrados pelo tenant antes de paginar/agrupar | Suíte web cobre sessão revogada, credencial de outro tenant, detalhes/transições cruzados e agregados que excluem registros de terceiros |
| CSRF e origem | Mutações permanecem no grupo web padrão; JSON objeto até 16 KiB; Origin/Sec-Fetch-Site externos são recusados e demonstração dinâmica exige ambiente local/testing e IP loopback | Prova real registra 419 sanitizado. Suíte web cobre origem externa e endereço remoto negados, sem provisionamento indevido |
| Fixação e logout | Entrada reutiliza sessão válida e gira ID/CSRF ao provisionar. Logout revoga somente a credencial vinculada, invalida a sessão e regenera CSRF | Teste web cobre mudança de ID no logout e remoção da sessão; prova browser registra logout com revogação e requisição posterior 401 |
| Criação atômica | Documento e pagamento são inseridos no mesmo callback/savepoint do núcleo idempotente; UUIDs e referências são gerados no servidor | Suíte cobre criação 201, replay, conflito, ausência de documentos órfãos e falha após documento seguida de rollback/retry |
| Idempotência | Chave original é validada antes do prefixo/hash web-create; operation=create_payment/resource_scope=create respeita a constraint existente. Transições reutilizam o núcleo | Suíte cobre replay histórico após estorno e conflitos sem duplicação. JavaScript conserva a chave por ação lógica enquanto a página permanece aberta |
| Exemplos e valores | Seis pagamentos são persistidos por serviços reais; guarda transacional por vendedor e chaves estáveis evitam duplicação. Valores individuais usam centavos inteiros e agregados grandes preservam dígitos | Suíte cobre exemplos repetidos, totais reais por tenant, fronteira de dia America/Sao_Paulo, filtros e paginação. Browser percorreu o ciclo completo com ledger/eventos |
| DOM e templates | Blade usa saída escapada. Os pontos innerHTML revisados escapam valores da API ou usam constantes/números derivados; mensagens e campos de formulário usam saída textual segura | Suíte cobre ausência de reflexão de query HTML nas páginas; prova browser registra busca segura e validação do formulário. Não houve fuzzing exaustivo de XSS |
| Cabeçalhos e downloads | CSP limita scripts/conexões a self, sem script inline; nosniff, frame DENY, no-referrer e private/no-store nas páginas/dados. Downloads usam dois nomes fixos, attachment e nosniff | Prova browser registra página de documentação e os dois downloads. Não há caminho de arquivo controlado pelo cliente nos handlers revisados |
| Abuso e desenvolvimento | Novas sessões limitadas a dez/minuto/IP; requisições protegidas a sessenta/minuto/credencial. Boost desabilitado por padrão e watcher de browser desativado | Limites e configuração foram inspecionados; os resumos finais não documentam ensaio específico de saturação dessas quotas web |

A CSP permite style-src-attr unsafe-inline para atributos cosméticos calculados com números. Isso não libera scripts inline; os pontos revisados não concatenam entrada textual do usuário nesses estilos.

A autorização web usa os identificadores da sessão no servidor. A documentação pode mostrar placeholders de Bearer para explicar a API; nenhum token Bearer real é fornecido em HTML, JavaScript, JSON ou armazenamento do navegador no fluxo revisado.

## Prova final em navegador/HTTP

O relatório final registra estes casos aprovados:

1. Landing HTTP 200 sem overflow horizontal.
2. CSRF real com HTTP 419 e erro sanitizado.
3. Cookie HttpOnly/SameSite=Strict e ausência de browser storage.
4. Validação do simulador no cliente.
5. Criação, autorização, liquidação, estorno, razão e eventos.
6. Filtros/busca de pagamentos sem interpretação indevida da entrada.
7. Documentação e downloads dos dois contratos YAML.
8. Logout com revogação e resposta posterior 401.
9. Interface de 390 px sem overflow, menu, Escape e retorno de foco.

O arquivo informa errors=[] para os casos registrados. Trata-se de relatório resumido da coordenação, não de uma captura HTTP integral ou de uma auditoria independente de navegador.

A regressão de 96 testes reúne os 80 casos anteriores da API e os 16 casos web. Seu resultado aprovado é evidência técnica de regressão; não equivale a aceite humano da API.

## Atualização das dependências e repetição das provas

Após a emissão inicial deste parecer, a coordenação atualizou Symfony http-foundation, http-kernel, routing e yaml de 8.1.10 para 8.1.11. O lockfile atual confirma essas quatro versões e deprecation-contracts/event-dispatcher-contracts em 3.8.1. A referência a contracts nesta atualização limita-se a esses dois pacotes; não implica que todos os pacotes *-contracts tenham essa versão.

A coordenação informou nova execução de composer audit --locked com exit 0 e sem advisories. Essa informação é atribuída ao resultado real reportado pela coordenação; não há um novo arquivo separado de auditoria entre as evidências lidas pelo agente Segurança. Não se trata de execução pessoal deste agente nem de garantia sobre vulnerabilidades ainda desconhecidas.

Depois da atualização, a regressão combinada foi repetida: 96 testes e 11.590 asserções aprovados, duration_ms=45041, exit 0 informado pela coordenação. A prova em navegador também foi repetida, com os mesmos nove checks, errors=[] e exit 0 informado pela coordenação. Seu resumo determinístico conserva o hash anterior; o hash da evidência PHPUnit mudou e foi atualizado abaixo.

O parecer técnico favorável permanece limitado ao estado final identificado por este lockfile e pelas evidências repetidas. O aceite humano continua independente.

## Identificação das evidências e do snapshot

Os SHA-256 foram calculados durante esta revisão, sem ler segredos:

| Artefato | SHA-256 |
|---|---|
| web-browser-final.txt | 588089928D375DFCBC2FD1DDE6E1FEF8CF0631ACE4898D39BAFA701F3D31089B |
| web-phpunit-final.txt | 9439DC60C1F69186F0A254B5FA98F9255EE68D375EA0F51DCEC5C5940C33E88B |
| src/api/composer.lock | CA2C3DE9BB2F260ED49D8F32EABFB9D0119D8497D279CADB9B0DF794B2914B0E |
| src/api/config/session.php | 5D0847951C9A99D975F172E3DD19B64C73E76BFD6B7181BF38B82C68C7EBF9BF |
| src/api/app/Http/Middleware/WebContext.php | DD49DB620161A658E8ABCDE4A31379B9E550EC13A73BC542534A333FB8E2D5C7 |
| src/api/app/Http/Middleware/WebDemoSession.php | BCD2D5665147C29274BA27745ACDBD735AD91557CC5B6B400FCF5CCECD4D1E86 |
| src/api/app/Services/WebDemoService.php | CCE37F3357FD8D8984CD3B23584F5067888605982B53A58A67CD15D7CE634BD8 |
| src/api/public/assets/weddell.js | AB91B517A0CF35BBBE1FBE140DCD6B4AAD2FFBFCA03DA20467A9F525AD376B18 |

Os dois arquivos de evidência estão em .runtime/evidence; o lockfile identifica as dependências após a correção. Não foi informado hash de commit; o parecer limita-se ao snapshot identificado e à revisão de código realizada.

## Limites e condições mantidas

- Operação somente local, com dados fictícios. Não há pagamento real, documento fiscal válido, recolhimento de tributo ou integração oficial no fluxo revisado.
- TLS/exposição externa, proxy público, autenticação de pessoas reais e operação em produção não foram avaliados ou autorizados.
- Criptografia/serialização de sessão foram conferidas na configuração; não houve análise forense de arquivos de sessão nem ensaio completo de expiração por tempo.
- Quotas web, carga sustentada, fuzzing abrangente, penetração externa, restauração de backup e descarte de logs após sete dias reais não tiveram prova específica nesta etapa.
- As chaves de ações do JavaScript ficam em memória: a continuidade de uma tentativa após recarregar/fechar a página não foi garantida por armazenamento persistente no navegador.
- Mudanças em templates/DOM, autenticação, cookies, CORS, CSP ou exposição de rede exigem revisão dos controles afetados.
- Este relatório não certifica segurança absoluta, conformidade tributária, PCI DSS ou LGPD.

**Conclusão técnica:** a demonstração web local está favorável no escopo e nas evidências revisadas. As aprovações humanas e o registro operacional continuam sob coordenação do projeto.
