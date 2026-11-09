# Evidências de testes web — Weddell Pay

Revisão: 2026-11-07, America/Sao_Paulo. Responsável: qualidade_testes.

**Resultados automatizados aprovados; validação e aceite humano pendentes.** A coordenação executou os comandos; QA revisou as evidências existentes sem repetir testes ou alterar código/BlackBoard.

## Artefatos e resultado

| ID | Artefato | Resultado observado |
|---|---|---|
| WEB-E1 | [.runtime/evidence/web-phpunit-final.txt](../.runtime/evidence/web-phpunit-final.txt) | result=passed; tests=96; passed=96; assertions=11590; duration_ms=45041 |
| WEB-E2 | [.runtime/evidence/web-browser-final.txt](../.runtime/evidence/web-browser-final.txt) | ok=true; nove grupos em checks; errors=[] |
| WEB-E3 | [.runtime/browser-tests/final-qa.cjs](../.runtime/browser-tests/final-qa.cjs) | Runner de interações que produziu WEB-E2 |
| WEB-E4 | [src/api/tests/Feature/WebDemoTest.php](../src/api/tests/Feature/WebDemoTest.php) | Dezesseis testes web incluídos na regressão |

A coordenação confirmou saída 0 para WEB-E1 e WEB-E2. O arquivo WEB-E1 é um resumo estruturado; não contém um relatório individual de cada asserção. O conjunto executado inclui os 80 testes anteriores e os dezesseis casos web.

Os artefatos .runtime são locais e não versionados. O runner depende do ambiente Playwright temporário preparado pela coordenação; uma cópia do repositório sem esse ambiente não implica reprodução imediata do comando do navegador.

## Ambiente e comandos

- Windows / PowerShell, raiz C:\xampp2\htdocs\Weddle.
- PHP 8.5.12 com pdo_pgsql, conforme ambiente da coordenação.
- Laravel 13.39.0, PHPUnit 12.5.43 e Laravel Boost 2.10.4, conferidos no lock instalado.
- PostgreSQL 17 em 127.0.0.1:55432; testes restritos a weddell_pay_test.
- Servidor real de demonstração em http://127.0.0.1:8000, separado do banco de testes.
- Microsoft Edge 156.0.4314.58 headless, versão confirmada pela coordenação; executablePath do runner aponta para a instalação local do Edge.
- Playwright do ambiente temporário; locale pt-BR e reducedMotion=reduce.

Com diretório de trabalho na raiz, comando confirmado pela coordenação:

~~~powershell
node .runtime\browser-tests\final-qa.cjs 2>&1 | Tee-Object -FilePath '.runtime\evidence\web-browser-final.txt'
~~~

Com diretório de trabalho src/api, comando confirmado pela coordenação:

~~~powershell
php -d extension=pdo_pgsql vendor\phpunit\phpunit\phpunit
~~~

Saída do PHPUnit: 0; 96 testes e 11.590 asserções em 45,041 segundos. Saída do runner: 0; nenhum erro capturado pelo mecanismo pageerror/console.error nas páginas desktop e móvel exercitadas. Não se afirma uma versão do Playwright não informada nem um tempo do browser ausente do log.

## Atualização de dependências e auditoria

A coordenação identificou sete avisos de segurança e atualizou de 8.1.10 para 8.1.11 os componentes symfony/http-foundation, symfony/http-kernel, symfony/routing e symfony/yaml. A revisão conferiu essas quatro versões no composer.lock; não presume que todos os componentes Symfony tenham a mesma versão.

Depois da atualização, foram repetidas as duas provas: PHPUnit 96/96, 11.590 asserções, duration_ms=45041, saída 0; browser com os mesmos nove grupos aprovados, errors=[] e saída 0. WEB-E1 e WEB-E2 apontam para os arquivos finais substituídos pela coordenação. O conteúdo do resumo do browser permaneceu igual, portanto seu hash também permaneceu igual.

A coordenação informou composer audit com saída 0 e nenhum advisory na execução posterior à atualização. Não foi fornecido um arquivo individual de auditoria nestes artefatos; o resultado é registrado com essa procedência, sem alegar inspeção de um log inexistente nesta revisão. O hash do lock atualizado foi acrescentado à identificação dos artefatos.

Essa atualização passou pela regressão e pelas interações descritas. O resultado do audit se limita aos avisos conhecidos reportados naquela execução; validação e aceite humano permanecem pendentes.

## Rastreabilidade da prova no Edge

| Marcador no log WEB-E2 | Ação/assertiva conferida no runner |
|---|---|
| landing:200/no-horizontal-overflow | GET / retorna 200; landing sem overflow horizontal no desktop |
| csrf:real-http-419-sanitized | POST /web/session sem token CSRF válido retorna 419 e error.code=CSRF_MISMATCH |
| session:HttpOnly/SameSite=Strict/no-browser-storage | Cookie observado com HttpOnly e SameSite=Strict; localStorage/sessionStorage vazios |
| simulator:client-validation | Interface mostra erro para CBS + IBS acima do bruto |
| lifecycle:create/authorize/settle/refund/ledger/events | Criação 201; três ações 200; estados visíveis; oito partidas e quatro eventos após estorno |
| payments:filters/search-safe | Navegação à lista, busca contendo script e ausência do nó de script correspondente no DOM |
| docs:page-and-two-yaml-downloads | /docs 200; /docs/openapi e /docs/openapi-web 200 com tipo YAML |
| logout:revoked-and-401 | Botão de saída retorna à landing; overview posterior responde 401 |
| mobile:390px/no-overflow/menu/escape/focus-return | Dashboard 390 × 844 sem overflow; menu abre, Escape fecha e foco retorna ao botão |

O desktop usa 1440 × 1050. A ausência de overflow também foi assertada no dashboard desktop. O teste de busca no navegador não substitui os testes de filtros de estado e SQL do PHPUnit.

O formulário do browser utiliza bruto R$ 100,00. O caso inválido usa CBS R$ 80,00 + IBS R$ 30,00; o caso concluído usa CBS R$ 8,00 + IBS R$ 12,00. O runner confirma o valor de R$ 100,00 no detalhe, o ciclo visível e a contagem final; não se atribuem a ele as assertivas financeiras adicionais que pertencem ao PHPUnit.

O marcador de logout prova a recusa HTTP após a saída. A assertiva de revogação efetiva no banco está em WebDemoTest, complementando a observação externa.

## Cobertura Feature web e regressão

WEB-E4 cobre:

1. Páginas públicas sem reflexão do HTML recebido em query e sem exposição de token/hash/ID de credencial da fixture.
2. Redirecionamento de /app e 401 JSON sem sessão.
3. Entrada com seis registros persistidos, estados distintos e reutilização sem duplicar o vendedor.
4. Credencial revogada recusada e sessão inválida removida.
5. Isolamento de detalhe/transições e vínculo obrigatório entre vendedor e credencial.
6. Overview limitado ao vendedor, com totais conhecidos e sete dias em America/Sao_Paulo.
7. Filtros, busca e paginação limitados ao vendedor.
8. Filtros inválidos 422 e busca de injeção sem alterar a consulta.
9. Criação 201, replay e divergência 409 sem documentos órfãos.
10. Chave ausente 400 e campos inválidos 422 sem persistência.
11. Falha após inserção do documento com rollback e retry da mesma chave.
12. Ciclo pelo adaptador web e replay histórico após estorno.
13. Cenário declined sem lançamentos financeiros.
14. Exemplos persistidos uma única vez.
15. Logout com revogação no banco e invalidação da sessão.
16. Recusa 403 para acesso não local e origem externa.

A base existente confirma testing, pgsql, host 127.0.0.1, porta 55432, nome configurado e current_database() iguais a weddell_pay_test antes de migrar/limpar fixtures. Não usa SQLite.

O caso de datas fixa 03/11/2026 01:30 UTC e espera o bucket local 02/11/2026. É uma entrada determinística, não uma alegação de que essa seja a data da execução. Os seis exemplos somam 927700 centavos; a parcela SETTLED soma 146900, com líquido 117520, CBS 11752 e IBS 17628, verificados pelo PHPUnit.

A regressão combinada inclui os testes anteriores de estados, idempotência, contabilidade, concorrência real e rollback. Os [limites anteriormente registrados](matriz-rastreabilidade.md) não foram apagados nem transformados em aceite humano pela nova execução.

## Capturas existentes

| Arquivo | Contexto |
|---|---|
| [web-final-landing-desktop.png](../.runtime/evidence/web-final-landing-desktop.png) | Landing, 1440 × 1050 |
| [web-final-dashboard-desktop.png](../.runtime/evidence/web-final-dashboard-desktop.png) | Dashboard desktop após entrada |
| [web-final-refund-detail.png](../.runtime/evidence/web-final-refund-detail.png) | Detalhe após estorno |
| [web-final-dashboard-mobile.png](../.runtime/evidence/web-final-dashboard-mobile.png) | Dashboard, 390 × 844 |

Os arquivos foram conferidos como existentes. Não foram produzidas novas capturas nesta revisão. Screenshot não constitui aprovação estética, auditoria WCAG ou verificação de todos os estados possíveis.

## Integridade dos artefatos — SHA-256

| Arquivo | SHA-256 |
|---|---|
| web-phpunit-final.txt | 9439DC60C1F69186F0A254B5FA98F9255EE68D375EA0F51DCEC5C5940C33E88B |
| web-browser-final.txt | 588089928D375DFCBC2FD1DDE6E1FEF8CF0631ACE4898D39BAFA701F3D31089B |
| final-qa.cjs | 5C8A9DDC3250ADC97DE1226E065909AAC857AA5216E529666B4CB8CFE50E3AF4 |
| WebDemoTest.php | DD149019CA83FB0840D613CE38A8361C760B28B4C73E25D628D580C663E4C26E |
| src/api/composer.lock | CA2C3DE9BB2F260ED49D8F32EABFB9D0119D8497D279CADB9B0DF794B2914B0E |

## Limites de interpretação

- CSRF não foi considerado provado pelo ambiente Feature, onde o framework o desativa; a recusa 419 veio de HTTP real.
- Cookies HttpOnly/Strict e armazenamento vazio foram observados; isso não equivale a auditoria criptográfica de todo o mecanismo de sessão.
- Os downloads foram testados por status e tipo YAML, sem validação formal completa OpenAPI.
- Responsividade foi exercitada nos viewports descritos, especialmente o dashboard móvel de 390 px. Não há matriz completa de dispositivos, navegadores, leitores de tela ou conformidade WCAG.
- Não foram realizados nesta revisão novos testes, pentest, teste de carga ou publicação.

**Validação visual/funcional e aceite final pelo usuário continuam pendentes.** As provas referem-se exclusivamente à demonstração local e não representam movimentação financeira ou recolhimento tributário real.
