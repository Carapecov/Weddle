# Checklist de segurança — MVP Weddell Pay

**Tarefas:** PAY-004 (critérios) e PAY-014 (revisão).
**Responsável:** seguranca.
**Estado:** revisão do MVP local concluída com parecer favorável no escopo de `parecer-seguranca.md`.

Marcas abaixo correspondem a verificações com evidência real indicada. Inspeções estáticas e verificações operacionais não executadas são separadas; uma caixa vazia não significa vulnerabilidade confirmada. Não se trata de certificação ou autorização de publicação.

## Evidências e atribuição

Os testes foram executados pela coordenação; o agente Segurança leu os resultados sanitizados e revisou contrato, código e configuração. Não declarar execução pessoal da suíte, do HTTP demo, da auditoria ou das provas Apache/PostgreSQL.

| ID | Evidência local | Resultado |
|---|---|---|
| E1 | `.runtime/evidence/phpunit-final.txt` | 80 testes, 9.835 asserções, 36,387 s, exit 0 |
| E2 | `.runtime/evidence/http-demo-final.txt` | 13 requisições HTTP reais em loopback, papel weddell_app, REFUNDED e revogação verificada |
| E3 | `.runtime/evidence/security-infra.json` | Registro sanitizado da coordenação: auditoria Composer, Apache temporário, ambiente e comandos |
| E4 | `.runtime/evidence/runtime-permissions.txt` | Prova reproduzível como weddell_app; seis tentativas de escrita sem linhas negadas e privilégios conferidos |
| E5 | `.runtime/evidence/phpunit-first.txt` | Histórico: 79 testes, 76 aprovados e 3 falhas, preservado |
| E6 | `git check-ignore -- .env src/api/.env src/api/.env.testing` e `git ls-files -- .env src/api/.env src/api/.env.testing` | Agente Segurança confirmou arquivos ignorados e nenhum desses caminhos rastreado |

Os arquivos privados `.env` não foram lidos nem impressos pelo agente Segurança. E3/E4 foram lidos como relatórios sanitizados.

## Autenticação, papéis e escopo — SEC-01/SEC-02

- [x] Ausência, invalidade e revogação de Bearer produzem 401 JSON nos casos exercitados (E1; E2 inclui revogação por CLI seguida de 401).
- [x] Somente operador cria vendedores; vendedor recebe 403; resposta HTTP de cadastro não emite token (E1).
- [x] Operador não acessa pagamentos de vendedores por privilégio implícito (E1).
- [x] Vendedor A não consulta nem executa authorize/settle/refund em pagamento de B; ledger/events também retornam 404 sem mutação (E1/T12).
- [x] Documento de outro vendedor não pode ser associado ao pagamento de A (E1/T12).
- [x] Escopos idempotentes de vendedores, operações e recursos são independentes; replay histórico não cria operação adicional (E1).
- [x] Provisionamento e revogação locais participaram da demonstração; a credencial revogada deixou de autenticar e o relatório não expõe token (E2).

**Inspeção complementar:** todas as rotas /api/v1 possuem middleware Bearer/role; ownership precede consulta de replay. CLI usa random_bytes(32), banco armazena SHA-256 e nenhuma representação de domínio serializa credenciais. Isso é revisão de mecanismo, não prova estatística de aleatoriedade.

## Entrada, limites e erros — SEC-03/SEC-04

- [x] Campos extras de pagamento e tentativas de injeção de dados de cartão/configuração de falha são rejeitados; comando de transição não aceita corpo não vazio (E1).
- [x] Montantes string/float/boolean/null, negativos, bruto zero, overflow e acima de 10^12 centavos são rejeitados nos datasets exercitados (E1/T13).
- [x] Centavo mínimo, teto, parcelas tributárias zero e líquido zero preservam valores exatos (E1/T13).
- [x] CBS + IBS > bruto, moeda diferente de BRL, total divergente e cenário inválido são rejeitados (E1).
- [x] Identificadores demo e campos obrigatórios são validados; referência duplicada é rejeitada (E1).
- [x] JSON malformado, corpo acima de 16 KiB e mídia não JSON recebem respectivamente 400, 413 e 415 sanitizados, sem persistência indevida (E1).
- [x] Casos de erro exercitados não exibem os marcadores de SQL, stack trace, password/token_hash ou caminhos internos conferidos pelo auxiliar de testes (E1).
- [x] /health é público, retorna 200/503 e formato mínimo com simulation=true; falha de conexão testada não revela detalhes de banco (E1/E2).
- [x] Representações e erros exercitados atendem aos schemas verificados pelo auxiliar de contrato da suíte (E1).

**Inspeção complementar:** Payload faz allowlist e exige is_int antes da validação monetária; regex demo possui modificador D. A revisão de schema não equivale a fuzzing completo.

## Idempotência e integridade — SEC-05/SEC-06

- [x] As quatro mutações de pagamento exigem Idempotency-Key; chaves inválidas e comandos não vazios são rejeitados (E1).
- [x] Mesmo escopo/chave/payload conserva status e corpo originais, inclusive após estorno; ordem de propriedades equivalente não cria novo pagamento (E1/T05/T07/T11).
- [x] Payload divergente no mesmo escopo/chave resulta em 409 (E1/T08).
- [x] D05 é atendida: escopo vendedor/operação/recurso/chave; outro recurso é independente e não recupera resposta anterior (E1).
- [x] Recusa determinística gera FAILED, sem razão contábil; replay preserva seu resultado (E1/T03).
- [x] Estados/transições inválidos não criam novas partidas, inclusive sobre FAILED e REFUNDED (E1/T04).
- [x] Duas sessões PostgreSQL simultâneas não liquidam o mesmo pagamento duas vezes, com chaves iguais ou diferentes (E1/T06).
- [x] Dois pagamentos distintos do mesmo documento não conseguem liquidar ambos (E1).
- [x] Estornos concorrentes ou repetidos geram somente uma compensação; originais são preservados (E1/T10/T11).
- [x] Estorno não libera documento para nova liquidação (E1).
- [x] Falhas antes do commit de liquidação/estorno revertem efeitos e permitem retry seguro com a mesma chave (E1/T09).
- [x] Débitos = créditos por evento e bruto = líquido + CBS + IBS, incluindo fronteiras e parcelas zero (E1/T01/T14).
- [x] Marca de liquidação não pode apontar evento não financeiro; UUID fiscal não muda mesmo sem pagamento associado (E1, migrations 000200/000300).
- [x] Razão, eventos e idempotência confirmados resistem a UPDATE/DELETE nos testes de integridade (E1).
- [x] O papel real da aplicação não tem UPDATE/DELETE/TRUNCATE nos três históricos; seis tentativas UPDATE/DELETE WHERE false são negadas com 42501 sem alterar dados (E4).
- [x] Fluxo real como weddell_app produziu oito partidas, quatro eventos e saldo zero nas quatro contas após estorno (E2).

**Inspeção complementar:** SQL de entrada é parametrizado; table/column usados na paginação são constantes internas. Locks seguem documento → pagamento e resultados idempotentes são persistidos na transação. E4 não executa TRUNCATE: verifica a ausência desse privilégio no catálogo.

## Ambiente, abuso e dependências — SEC-07/SEC-08

- [x] Os três arquivos .env privados estão ignorados e não rastreados pelo Git (E6); exemplos inspecionados contêm placeholders.
- [x] Runtime weddell_app não é superuser nem pode criar objetos no schema; não altera montante fiscal ou hash de credencial (E4).
- [x] Permissões nominais UPDATE(id fiscal) para row lock e UPDATE(revoked_at) para revogação estão concedidas; UUID é protegido por trigger (E1/E4).
- [x] API real usa loopback; configuração serve.ps1 fixa 127.0.0.1:8000 e Compose fixa 127.0.0.1:55432; não houve publicação nesta entrega (E2/E3 e inspeção).
- [x] Limite por credencial gera 429 com Retry-After sem mudança de negócio (E1).
- [x] CORS não concede Access-Control-Allow-Origin ao preflight de origem externa testada (E1); configuração explícita nega origens de navegador.
- [x] Composer audit --locked retornou exit 0, advisories=[], abandoned=[] e filter=[] no lockfile revisado (E3).
- [x] Apache temporário em 127.0.0.1:18081, com AllowOverride All equivalente, negou sentinela não secreta com 403; conteúdo não foi exposto e instância temporária foi encerrada (E3).

**Inspeção complementar:** debug=false no exemplo, logs operacionais fechados em campos técnicos, retenção configurada em sete arquivos diários, sem adaptador financeiro/fiscal externo no fluxo revisto e MCP usado por stdio local. Nenhuma dessas inspeções deve ser chamada de teste externo de intrusão.

## Verificações complementares e limites operacionais

- [ ] Observar exclusão/rotação durante sete dias reais; somente a configuração foi conferida.
- [ ] Exercitar rotação/restauração de backups e política de retenção fora do cenário local.
- [ ] Executar teste dedicado de 30 falhas de autenticação/minuto por IP; limite de 60 por credencial foi exercitado.
- [ ] Executar casos dedicados de Bearer igual ao hash armazenado e de literais monetários em expoente; mecanismos foram inspecionados, sem casos específicos nesta suíte.
- [ ] Fazer inspeção operacional exaustiva de logs para dados pessoais/segredos e captura de egress; código de logging e relatórios sanitizados foram revisados.
- [ ] Repetir a prova no Apache global se esse serviço for ativado: porta 80 estava inacessível. O ensaio equivalente foi em instância temporária.
- [ ] Reavaliar TLS, proxy, host, CORS, sessão/CSRF e armazenamento de credenciais antes de qualquer UI/exposição futura.

TLS não é requisito testado desta operação HTTP em loopback, sem exposição aprovada. Os itens acima delimitam cobertura; não representam achados altos/críticos confirmados no MVP local.

## Parecer e histórico

- [x] Evidências têm ambiente, comandos e identificação do snapshot em parecer-seguranca.md.
- [x] Primeira execução com três falhas permanece registrada; correções foram seguidas de regressão completa aprovada (E1/E5).
- [x] Achados de revisão e correções estão descritos, com responsáveis e evidências, em registro-riscos.md.
- [x] Nenhum achado crítico/alto conhecido permanece sem correção no escopo inspecionado.
- [x] Suíte de QA de aceite/regressão passou; o fluxo HTTP com papel de aplicação também passou.
- [x] Parecer é favorável ao MVP local e distingue revisão de segurança de autorização de publicação.

Não há certificação ou liberação para produção. A decisão e suas evidências estão em `parecer-seguranca.md`.
