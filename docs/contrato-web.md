# Contrato da demonstração web — PAY-016/PAY-017

A demonstração é local, isolada por vendedor e sem movimentação financeira real. Reutiliza PaymentService, IdempotencyService, o mesmo PostgreSQL e as invariantes do contrato API v1. A API Bearer existente permanece independente. Não há credencial de produção, autenticação de usuário real, integração externa nem publicação autorizada.

## Páginas e abertura

- GET /: Blade web.landing, apresentação e meta csrf-token.
- GET /app: Blade web.app; sessão válida obrigatória, navegador sem sessão retorna à landing.
- GET /docs: Blade web.docs; documentação de demonstração.
- POST /web/session, corpo {} e X-CSRF-TOKEN: retorna 200 {"redirect":"/app"}. Reutiliza sessão válida. Uma nova sessão provisiona vendedor DEMO-WEB-{sufixo}, conta virtual e credencial interna de demonstração, preparando seis pagamentos efetivamente persistidos. Gira o ID da sessão e o token CSRF.
- POST /web/logout, corpo {} e CSRF: revoga somente a credencial vinculada àquela sessão, invalida/gira a sessão e retorna {"redirect":"/"}.

Nenhum Bearer token aparece em HTML, JavaScript, JSON, localStorage ou cookie. A sessão guarda apenas web_demo.merchant_id e web_demo.credential_id. O hash da credencial fica em demo_credentials; não se mantém token bruto recuperável. A sessão padrão usa arquivo criptografado, cookie HttpOnly/SameSite=Strict e host local. SESSION_DRIVER=array continua disponível em testes.

Todas as requisições protegidas consultam novamente credencial role=merchant, não revogada e vinculada ao merchant da sessão. Sessão ausente, expirada ou revogada retorna 401 no JSON; acesso cruzado a UUID retorna 404 antes de replay. Não aceitar merchant_id/document_id do navegador no formulário.

## Consultas

GET /web/overview retorna:

```json
{
  "summary": {
    "total_amount_cents": 927700,
    "settled_amount_cents": 146900,
    "total_payments": 6,
    "authorized_count": 1,
    "failed_count": 1,
    "refunded_count": 1,
    "settled_count": 2,
    "cbs_amount_cents": 11752,
    "ibs_amount_cents": 17628,
    "merchant_net_cents": 117520
  },
  "daily": [{"date":"2026-11-02","amount_cents":927700,"count":6}],
  "recent": []
}
```

Os exemplos daily/recent foram abreviados: a resposta tem sete dias e até seis objetos Payment reais. O agrupamento e o dia corrente usam America/Sao_Paulo, preservando timestamps no banco em UTC. Zeros anteriores representam ausência real de registros, não histórico simulado. O total soma tentativas de pagamento de todos os estados; settled/CBS/IBS/líquido somam apenas SETTLED atual e excluem REFUNDED. Recent contém até seis Payments atuais.

Montantes individuais são JSON integer em centavos, máximo 10^12. Agregados são inteiros JSON até 9007199254740991; acima disso são strings decimais sem sinal/ponto/separador, preservando a exatidão de SUM(bigint) PostgreSQL sem cast int overflow nem float. A interface deve usar dígitos/BigInt para esses totais.

GET /web/payments aceita somente:
- status: ALL (padrão), CREATED, AUTHORIZED, SETTLED, FAILED, REFUNDED;
- search: até 80 caracteres sem controles, busca literal por UUID/referência fiscal do próprio vendedor;
- page: 1..1000000, padrão 1;
- limit: 1..50, padrão 10.

Retorna {"data":[Payment],"meta":{"current_page":1,"last_page":1,"total":6,"per_page":10}}. Ordenação por created_at,id decrescente. Busca usa parâmetros SQL, sem interpolação e sem curingas fornecidos pelo usuário.

GET /web/payments/{id} retorna {"payment":Payment,"document":FiscalDocument,"ledger":[LedgerEntry],"events":[PaymentEvent]}. Ledger/events são arrays diretos, limitados a 100 elementos cada, ordenados por timestamp,id. No MVP um ciclo financeiro tem até oito partidas. Todos os dados pertencem ao vendedor da sessão.

## Formulário e comandos

POST /web/payments exige X-CSRF-TOKEN e Idempotency-Key de 1..128 caracteres ASCII visíveis, validada antes de hash. Corpo estrito:

```json
{"gross_amount_cents":100000,"cbs_amount_cents":8000,"ibs_amount_cents":12000,"scenario":"approved"}
```

Bruto 1..10^12; CBS/IBS 0..10^12; soma fiscal <= bruto. Apenas números inteiros JSON, sem float/string; scenario approved|declined obrigatório. O servidor gera UUID, documento fictício, referência e merchant_id. Referência é determinística a partir do vendedor/chave interna. Documento e pagamento são criados no mesmo callback transacional da idempotência. Não existe documento órfão se o comando falhar.

A operação usa create_payment, resource_scope=create (constraint existente), chave interna web-create:SHA256(header original). O fingerprint inclui os campos canônicos do formulário e a origem web. Mesma chave/comando reapresenta corpo/status 201 originais; comando divergente retorna 409. Location aponta ao detalhe web. Chaves não expiram durante a vida do cenário.

POST /web/payments/{id}/authorize, /settle e /refund recebem somente {} (ou corpo ausente), CSRF e Idempotency-Key. Usam o núcleo e os escopos vendedor/operação/recurso/chave já existentes. Resposta 200 Payment; nova chave para transição já concluída retorna 409 sem efeitos; replay conserva o resultado original. Após comando, a interface deve consultar novamente detalhe/overview para mostrar estado atual.

Split é null antes da liquidação e histórico após estorno. Estorno integral cria contrapartidas, não apaga partidas nem libera segunda liquidação do documento.

## Exemplos persistidos

POST /web/examples com {} e CSRF prepara somente o próprio vendedor e retorna {"seeded":true,"created_count":0} se já preparado. Usa guarda transacional PostgreSQL por vendedor e chaves internas estáveis web-example, sem colisão com formulário. Não reseta alterações do usuário nos exemplos.

| Estado inicial | Bruto | CBS | IBS |
|---|---:|---:|---:|
| CREATED | 284900 | 22792 | 34188 |
| AUTHORIZED | 156000 | 12480 | 18720 |
| SETTLED | 100000 | 8000 | 12000 |
| SETTLED | 46900 | 3752 | 5628 |
| FAILED | 89900 | 7192 | 10788 |
| REFUNDED | 250000 | 20000 | 30000 |

FAILED usa scenario=declined; demais approved. Estados e razão são obtidos pelos serviços reais, com datas atuais. Não fabricar respostas de gateway/ledger no JavaScript.

## Segurança e erros

Somente APP_ENV local/testing e IP loopback podem acessar a demonstração dinâmica. Novos provisionamentos são limitados a dez/minuto/IP; requisições protegidas a sessenta/minuto/credencial. Corpo máximo 16 KiB, campos extras recusados, JSON objeto obrigatório, Origin/Sec-Fetch-Site externos recusados. CSRF usa o grupo web padrão Laravel; não remover middleware. CORS da API continua fechado.

Respostas usam Cache-Control private,no-store, nosniff, frame DENY e CSP self: scripts externos locais, sem JS inline; stylesheets self, somente style-src-attr unsafe-inline para barras numéricas; connect self. Sessão/token/SQL/body bruto não entram nos logs; logs operacionais duram sete dias.

Erros JSON mantêm {"error":{"code","message","details","correlation_id"}}; códigos 400/401/403/404/409/413/415/419/422/429/500/503 conforme caso. 419 usa CSRF_MISMATCH e pede atualizar a página. Replay de erro conserva correlation_id original no corpo; X-Correlation-ID acompanha a requisição atual. Retry-After é fornecido em 429/503.

Hook exclusivo APP_ENV=testing: config weddell.fail_at=web_after_document lança 503 após inserir o documento e antes do pagamento. Comprova rollback de documento/pagamento/reserva idempotente e retry com mesma chave. Não há campo/header/query HTTP para ativá-lo.

OpenAPI complementar: docs/openapi-web.yaml. O contrato v1 original não foi alterado.
