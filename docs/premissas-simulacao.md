# Premissas e limites da simulação

Este projeto é acadêmico/de portfólio. Nenhuma operação movimenta dinheiro, emite documento fiscal válido, recolhe tributo ou acessa banco, PSP, RFB, CGIBS ou Plataforma Pública. As referências legais e datas da spec não foram verificadas nesta tarefa e não fundamentam taxas nem calendário implementado.

## Cenário fictício

- Um conjunto isolado de dados de demonstração representa a vida do cenário; reiniciar o processo não reinicia sua história.
- Moeda exclusiva BRL, centavos inteiros positivos no bruto, teto de 10^12 centavos. CBS e IBS são parcelas nominais distintas, não alíquotas oficiais.
- Documento contém valores arbitrários declarados pelo usuário de demonstração; a API valida consistência e não calcula tributos por lei.
- O líquido é bruto menos CBS menos IBS; nenhuma taxa de PSP, split comercial, crédito tributário ou arredondamento tributário entra no MVP.
- Segregação só ocorre em SETTLED. CREATED/AUTHORIZED não têm partidas. GET retorna split=null até a liquidação; após REFUNDED, split é o histórico original, não saldo devido.
- Contas CLEARING, MERCHANT_PAYABLE, CBS_PAYABLE e IBS_PAYABLE são códigos de razão virtual, sem dados bancários.

## Allowlist e dados

Campos adicionais são recusados em todos os requests. Nome do vendedor deve usar DEMO-[A-Z0-9_-]{3,48}; conta deve usar DEMO-ACCOUNT-[A-Z0-9_-]{3,48}; referência fiscal deve usar DEMO-DOC-[A-Z0-9_-]{3,48}. Exemplos: DEMO-ARCTIC, DEMO-ACCOUNT-ARCTIC, DEMO-DOC-001. UUIDs de recursos são produzidos pelo servidor.

Não existem campos para CPF/CNPJ, endereço, email de cliente, PAN, CVV, conta bancária real ou credencial externa. Prefixos/regex constituem convenção de demo, não prova matemática de que texto arbitrário nunca codifique dado pessoal; a interface e a documentação devem instruir somente dados fictícios. Logs não registram payload integral nem Authorization e têm retenção operacional de sete dias; auditoria/razão são permanentes durante o cenário. Corpo JSON limitado a 16 KiB; limite de 60 requisições/minuto por chave autenticada e 30 falhas de autenticação/minuto por IP.

Chaves de demonstração são segredos locais mesmo sem acesso a dinheiro real. Provisionamento por CLI, hash no banco e vínculo por vendedor. Operador cria merchant por HTTP, sem receber token no corpo; emissão da chave daquele merchant ocorre na CLI. A API não oferece endpoint para listar/recuperar segredos.

## Determinismo e falhas

POST payments exige scenario approved ou declined; o valor persistido define a autorização e não pode mudar em authorize. Não existe aleatoriedade financeira nem resultado informado livremente no endpoint de transição.

Somente testes podem configurar failure_mode none/transient/terminal no servidor/fixture do gateway; campo HTTP correspondente é recusado. None é a configuração de execução demonstrativa. Transient gera 503, rollback financeiro e conserva estado/chave reutilizável; terminal na liquidação gera FAILED com código categorizado e sem partidas. Falha de autorização por declined retorna Payment FAILED com HTTP 200.

## Documento, repetição e estorno

Cada pagamento tem um documento do mesmo vendedor e valor igual ao seu bruto. Após qualquer pagamento associado, os valores fiscais são imutáveis. Não existem rotas de edição/cancelamento. Uma falha permite novo pagamento para o mesmo documento ainda não liquidado; cada documento pode ser liquidado somente uma vez durante toda sua vida. Estorno não libera nova liquidação nem apaga a marca permanente.

Estorno é integral, baseado nas partidas originais; nunca recalcula CBS/IBS com parâmetros atuais. Transição já concluída com chave diferente retorna 409 sem novos efeitos. Mesmo Idempotency-Key e comando retorna status/corpo originais; GET retorna estado atual. Chaves e resultados persistem durante toda a vida do cenário, sem expiração temporal do MVP.

## Fora do escopo e evolução

Sem interface obrigatória, Pix/cartão/boleto reais, emissão NF-e, tributação oficial, múltiplos documentos por pagamento, parcelamento, estorno parcial, múltiplos recebedores, procedimento simplificado legal ou integração pública. Cálculo didático por alíquotas, se aprovado depois, será módulo separado com parâmetros/versionamento explícitos.

Qualquer integração externa futura exige adaptador separado do gateway MOCK, documentação atualizada, revisão técnica/jurídica pertinente e nova autorização. O aceite desta versão depende de T01–T14 e verificações de segurança/concorrência reais; documentos de contrato não equivalem a testes executados ou certificação.
