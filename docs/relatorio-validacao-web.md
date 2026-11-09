# Relatório de validação web — Weddell Pay

Data: 2026-11-07. Fuso de referência: America/Sao_Paulo.
Revisão: qualidade_testes. Entrega web das tarefas PAY-016–PAY-018.

## Estado da entrega

**Verificações automatizadas aprovadas. Validação e aceite humano permanecem pendentes.**

A regressão concluiu 96 testes e 11.590 asserções, com saída 0. A prova no Microsoft Edge concluiu nove grupos de verificações com ok=true, errors=[] e saída 0.

O resultado técnico permite apresentar a interface para revisão do usuário. Não registra aceite final da API ou da interface e não autoriza publicação. Todas as operações continuam sendo simulações, sem movimentação financeira ou recolhimento tributário real.

## Fontes verificadas

| Evidência | Resultado |
|---|---|
| [Regressão final](../.runtime/evidence/web-phpunit-final.txt) | 96/96 testes aprovados; 11.590 asserções; 45.041 ms |
| [Navegador final](../.runtime/evidence/web-browser-final.txt) | Nove grupos aprovados; nenhuma ocorrência de pageerror ou console.error registrada |
| [Teste web](../src/api/tests/Feature/WebDemoTest.php) | Dezesseis cenários Feature, integrados à base PostgreSQL protegida |
| [Runner do navegador](../.runtime/browser-tests/final-qa.cjs) | Interações HTTP/DOM em Edge headless |
| [Comandos, ambiente e hashes](test-evidence-web.md) | Procedência e limites da execução |

A coordenação executou as provas. QA leu logs, runner e testes para consolidar este relatório; não executou nova suíte nesta etapa.

## Atualização de dependências revalidada

A coordenação atualizou symfony/http-foundation, symfony/http-kernel, symfony/routing e symfony/yaml de 8.1.10 para 8.1.11 após identificar sete avisos de segurança. Essas quatro versões finais foram conferidas no composer.lock; os demais componentes Symfony não são descritos como atualizados por este parecer.

Após a atualização, a coordenação repetiu a regressão e a prova no Edge: 96 testes / 11.590 asserções em 45,041 segundos, saída 0; nove grupos de browser com ok=true e errors=[], saída 0. Os caminhos finais de evidência agora identificam essa repetição.

A coordenação também informou composer audit com saída 0 e nenhum advisory na execução final. Esse resultado é atribuído à coordenação; os logs de PHPUnit e browser não contêm a saída individual da auditoria. Ele não equivale a garantia de ausência de vulnerabilidades desconhecidas. Validação e aceite humano continuam pendentes.

## Comportamentos demonstrados

| Área | Verificação observada |
|---|---|
| Páginas públicas | Landing e documentação renderizadas; consultas maliciosas não refletidas no HTML; credenciais de fixture ausentes |
| Entrada na demonstração | Sessão cria seis registros persistidos de simulação; reutilizar a entrada não duplica vendedor, credencial ou pagamentos |
| Controle de acesso | /app redireciona sem sessão; endpoints JSON retornam 401; credencial revogada ou incompatível com o vendedor é recusada |
| Isolamento | Detalhes e transições de outro vendedor retornam 404; totais e listagens permanecem limitados ao vendedor da sessão |
| Dashboard | Totais dos exemplos: 927700 centavos no conjunto, 146900 liquidados, 117520 líquidos, CBS 11752 e IBS 17628 |
| Datas | Regressão fixa 03/11/2026 01:30 UTC e espera o dia 02/11 em America/Sao_Paulo; persistência continua em UTC |
| Listagem | Filtros de estado, busca, paginação, entradas inválidas e texto de injeção exercitados |
| Nova simulação | Criação 201, replay, conflito 409, chave ausente 400, campos inválidos 422 e ausência de documentos órfãos |
| Atomicidade | Falha controlada após inserir documento gera rollback; a mesma chave pode ser repetida com sucesso |
| Fluxo de pagamento | Criar, autorizar, liquidar, estornar e consultar detalhes usam o núcleo de pagamentos; replay mantém resultado histórico |
| Exemplos | Botão de exemplos persiste dados uma vez; repetição informa zero novas criações |
| Saída | Credencial revogada no banco e sessão invalidada; acesso posterior retorna 401 |

Os 96 casos incluem a regressão anterior de 80 testes da API e os dezesseis testes web. A validação financeira, concorrência e rollback permanecem incluídos na execução combinada.

## Prova no navegador

Microsoft Edge 156.0.4314.58 headless, controlado por Playwright temporário, em http://127.0.0.1:8000. O contexto desktop usa 1440 × 1050; o móvel, 390 × 844. Ambos usam pt-BR e preferência por movimento reduzido.

Foram observados:

- Landing 200 e ausência de rolagem horizontal indevida na landing/dashboard desktop e no dashboard móvel.
- POST /web/session sem token CSRF válido recusado por HTTP real com 419 e CSRF_MISMATCH sanitizado. A entrada e as ações pela interface, com token, prosseguiram.
- Cookie de sessão HttpOnly e SameSite=Strict. localStorage e sessionStorage estavam vazios no ponto de checagem.
- Formulário apresentou erro para bruto R$ 100,00, CBS R$ 80,00 e IBS R$ 30,00. Após ajuste para CBS R$ 8,00 e IBS R$ 12,00, a criação retornou 201 e o detalhe mostrou R$ 100,00.
- Botões autorizar, liquidar e estornar retornaram 200 e atualizaram o estado visível. O detalhe estornado mostrou oito partidas e quatro eventos.
- Navegação à lista e busca contendo script sem criação de nó executável correspondente no DOM.
- Página /docs com 200 e downloads /docs/openapi e /docs/openapi-web com 200 e tipo YAML.
- Logout retornou à landing; consulta posterior ao overview recebeu 401. A regressão Feature confirma também a revogação persistida da credencial.
- Em 390 px, menu abriu, Escape o fechou e o foco voltou ao botão que abre o menu.

## Capturas para revisão humana

- [Landing desktop](../.runtime/evidence/web-final-landing-desktop.png)
- [Dashboard desktop](../.runtime/evidence/web-final-dashboard-desktop.png)
- [Detalhe após estorno](../.runtime/evidence/web-final-refund-detail.png)
- [Dashboard móvel — 390 px](../.runtime/evidence/web-final-dashboard-mobile.png)

As capturas registram a execução e apoiam a revisão; não representam aprovação visual do usuário.

## Limites e pendências

A prova cobre os fluxos e tamanhos descritos. Não é auditoria formal de acessibilidade, validação formal OpenAPI, pentest, teste de carga ou matriz completa de navegadores/dispositivos. Os downloads foram verificados por resposta HTTP e tipo de conteúdo.

Os testes Feature não são usados como prova de CSRF, pois o framework o desativa no ambiente de testes; a evidência de rejeição 419 veio do servidor real. A passagem da regressão não transforma subcasos parciais da matriz anterior em cobertura completa.

**Pendências: validação funcional/visual e aceite final pelo usuário.** Publicação continua sem autorização. Este relatório não altera código nem registra estado no BlackBoard; a coordenação mantém o histórico do projeto.
