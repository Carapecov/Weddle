# Relatório de validação — Weddell Pay

Tarefas: PAY-013 e PAY-015. Parecer: qualidade_testes. Revisão: 2026-11-02.

## Parecer

**Favorável ao aceite técnico do MVP local nos fluxos executados.** T01–T14 passaram; a suíte final concluiu 80 testes e 9.835 asserções, sem falhas, com saída 0. A demonstração HTTP real com o papel restrito weddell_app concluiu o ciclo e a prova de revogação.

O parecer não substitui a decisão do usuário e não autoriza publicação. Refere-se a um simulador acadêmico sem dinheiro real, documento fiscal válido ou recolhimento tributário.

## Evidências

| Fonte | Resultado |
|---|---|
| [PHPUnit final](../.runtime/evidence/phpunit-final.txt) | 80/80 aprovados; 9.835 asserções; 36,387 s; 56 MB |
| [HTTP real final](../.runtime/evidence/http-demo-final.txt) | 13 requisições; estado REFUNDED; papel weddell_app; revogação 401 |
| [Matriz atualizada](matriz-rastreabilidade.md) | T01–T14 aprovados; extras e subcasos parciais explícitos |
| [Histórico e comandos](test-evidence.md) | Ambiente, falhas/correções, limites e hashes |

A coordenação executou os testes. QA leu os logs completos e revisou documentos, sem repetir a suíte nesta etapa.

## Aceite avaliado

- Valores: 100000 = 80000 + 8000 + 12000 centavos, com CBS/IBS separados; tipos e fronteiras verificados.
- Estados: autorização determinística, transições proibidas bloqueadas, liquidação única e estorno integral.
- Integridade: partidas balanceadas, originais imutáveis, contrapartidas exatas e documento consumido permanentemente.
- Resiliência: rollback antes do commit, retry seguro e replay histórico sem duplicação.
- Concorrência: processos e sessões PostgreSQL independentes com sobreposição observada por locks.
- Acesso: Bearer demo, papéis, isolamento, revogação e erros sanitizados nos cenários executados.
- Interface técnica: schemas de respostas exercitados; coleção HTTP e nomes CLI coerentes.
- Escopo: dados fictícios, identificação de simulação e nenhuma integração financeira/fiscal real.

E2 preservou oito lançamentos e quatro eventos depois do estorno, com saldo zero nas quatro contas virtuais. Replay de settle devolveu seu resultado histórico enquanto GET mostrou REFUNDED.

## Defeitos e regressões

E0 registrou 76 aprovações e três falhas em 79 testes. Duas eram corpos de fixture serializados como array vazio; uma era normalização indevida da conta virtual na API. Os critérios esperados foram preservados. A regressão de CORS foi acrescentada após revisão de Segurança.

A primeira demonstração encontrou health 503 por ausência de driver no processo servidor. Launcher e comparação sem dependência da ordem de chaves JSONB foram corrigidos. O histórico anterior permanece preservado.

Também passaram os negativos PostgreSQL para UUID fiscal imutável e marca permanente não financeira. Não há falha aberta nos 80 casos executados.

## Limitações e condições

X13, X17, X20, X22 e X26 têm subcasos não demonstrados explicitamente: documento inexistente; UUID inválido/exceção 500 genérica; setup completo em ambiente vazio; limitação separada de falhas de autenticação/IP; combinação específica de cursor de outro vendedor. Permanecem parciais, orientando ampliação futura.

Não há medição de cobertura de código ou validação formal OpenAPI. Não foram executados teste de carga, pentest, UI ou homologação de publicação. A evidência local não demonstra prontidão para produção.

Manter dados fictícios e separação entre demonstração e weddell_pay_test. Revisão de Segurança e autorização humana para publicação são etapas próprias. A coordenação registra este parecer e suas evidências no BlackBoard.
