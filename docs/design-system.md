# Design system — Weddell Pay

Responsável: frontend_ux. Tarefa: PAY-006.
Estado: especificação documental do MVP da API; nenhuma tela ou componente foi implementado por esta tarefa.

## Escopo e precedência

O MVP aprovado entrega a API de pagamentos e Split Payment tributário simulados. PAY-016 a PAY-018, relativos à interface web, permanecem sujeitos a aprovação própria. Este documento prepara a linguagem visual e os critérios de futuras telas; não amplia o escopo implementado.

Fontes: [Arctic Fintech](../arctic_fintech_design.md), [especificação funcional](../Specs/SPEC_API_SPLIT_PAYMENT.md), [contrato HTTP](openapi.yaml), [premissas](premissas-simulacao.md) e [jornadas](jornadas-e-integracao.md). A cópia Arctic em Style possui o mesmo conteúdo da fonte da raiz. A referência [Stripe](../Style/DESIGN-stripe.md) orienta hierarquia e organização; a identidade Arctic prevalece nas cores, tipografia e superfícies.

## Identidade e tokens

| Token | Valor | Uso previsto |
|---|---|---|
| --arctic-snow | #F5FBFF | Fundo principal |
| --arctic-ice | #DDFEFF | Seções suaves e detalhes |
| --arctic-blue | #1AAAFA | Destaques gráficos e decorativos |
| --arctic-action | #1262AE | Ações, links e estados ativos |
| --arctic-deep | #102D46 | Títulos e texto principal |
| --weddell-brown | #704626 | Mascote e acentos discretos |
| --white | #FFFFFF | Cartões e tabelas |

Priorizar superfícies claras e leitura de valores. Reservar o azul glacial intenso para destaques cuja combinação de contraste seja verificada; não presumir que todos os tokens servem para texto pequeno. Marrom identifica a foca-de-Weddell, sem representar sucesso ou erro. Usar o mascote pontualmente, sem competir com ações ou números.

Inter ou Manrope para a interface, corpo preferencial de 16 px, pesos 400–500 e títulos 600–700. Usar algarismos tabulares para valores e identificadores alinhados. Código e exemplos HTTP usam fonte monoespaçada, como JetBrains Mono.

Escala de espaçamento: 4, 8, 12, 16, 24, 32 e 48 px. Cartões futuros: fundo branco, borda sutil, raio de 12–20 px e sombra leve. Superfícies transparentes ficam restritas a decoração institucional; tabelas e valores exigem fundo legível.

## Linguagem da simulação e dinheiro

Texto padrão nos fluxos e demonstrações: **Simulação — sem movimentação financeira real**.

- Nomear separadamente bruto, líquido do vendedor, CBS simulada e IBS simulado.
- Valores fiscais são nominais e fictícios. Não apresentar porcentagens como alíquotas oficiais nem o lançamento virtual como recolhimento efetivo.
- Na API, dinheiro usa centavos inteiros em BRL, com teto de 1.000.000.000.000 centavos. Bruto é positivo; CBS/IBS e líquido podem ser zero conforme as invariantes.
- Esse teto fica abaixo do maior inteiro seguro do JavaScript. Ainda assim, cálculos e parsing devem preservar centavos inteiros; formatação visual usa divisão inteira/resto ou recurso decimal apropriado. Não usar arredondamentos binários como regra financeira.
- A API é a autoridade do cálculo e dos estados. A interface futura não recalcula tributos nem produz um resultado financeiro substituto.
- Separar payload de entrada amount_cents da resposta gross_amount_cents. Ambos representam o bruto deste MVP, com nomes definidos pelo contrato.
- Usar exemplo fictício coerente com a API: R$ 1.000,00 bruto, R$ 80,00 CBS, R$ 120,00 IBS e R$ 800,00 líquido. Sem taxas de PSP ou split comercial.
- Antes de SETTLED, split é null. Mostrar “Ainda não liquidado” em vez de uma segregação contabilizada ou de zeros inventados.
- Em REFUNDED, o split recebido é histórico da liquidação original. Identificá-lo como histórico e apresentar o estorno por suas contrapartidas, sem sugerir saldo ainda devido.

## Catálogo documental de componentes futuros

| Componente | Comportamento e critério |
|---|---|
| Aviso de simulação | Texto visível e legível, inclusive junto aos exemplos financeiros |
| Botão principal | Azul de ação, rótulo concreto, foco visível e estados desabilitado/carregando |
| Botão secundário | Superfície branca, borda e texto legíveis; hierarquia abaixo da ação principal |
| Campo | Rótulo persistente, unidade explícita, ajuda e erro associados ao campo |
| Cartão de valor | Rótulo completo, moeda e algarismos tabulares; nada de valores fictícios apresentados como retorno da API |
| Status | Texto e significado, com ícone/cor complementares; diferenciar aprovação de autorização e liquidação |
| Tabela financeira | Cabeçalhos claros, números alinhados e alternativa utilizável em tela estreita |
| Barra de distribuição | CBS, IBS e líquido separados, acompanhados de tabela ou descrição textual equivalente |
| Mensagem de erro | Código/mensagem sanitizados, orientação de recuperação e correlação para suporte |
| Exemplo HTTP/JSON | Dados fictícios, token substituído por placeholder e distinção entre exemplo e resposta executada |
| Paginação | Cursor opaco do servidor; preservar filtros e parar em next_cursor=null |

O catálogo é uma especificação, não uma biblioteca de componentes entregue.

## Estados e recuperação

| Estado | Comunicação prevista |
|---|---|
| Carregando | Informar a ação em curso; impedir envio acidental duplicado sem perder a chave de idempotência |
| Sucesso | Exibir estado retornado; consultar GET quando o resultado for replay e o usuário precisar do estado atual |
| Vazio | Explicar que ainda não há registros; não preencher tabelas ou indicadores com dados inventados |
| Erro de campo | Identificar o campo e manter entradas válidas para correção |
| Sem conexão ou 503 | Explicar a tentativa pendente; reutilizar chave e comando nas operações idempotentes |
| 401 | Solicitar credencial de demonstração válida no cliente autorizado |
| 403 | Informar papel insuficiente sem sugerir acesso privilegiado |
| 404 | Tratar recurso ausente/indisponível sem revelar existência para outro vendedor |
| 409 | Explicar conflito de estado/comando e orientar consulta atual; não repetir com uma chave nova automaticamente |
| 429 | Respeitar Retry-After e evitar repetição contínua |

## Acessibilidade e responsividade futuras

Meta: WCAG 2.2 AA. As verificações abaixo permanecem pendentes até existir interface aprovada e implementada.

- Navegação completa por teclado, ordem lógica, foco visível e sem armadilhas.
- Rótulos persistentes, nomes acessíveis em botões/ícones e mensagens de validação associadas aos campos.
- Medir contraste das combinações reais: 4,5:1 para texto normal, 3:1 para texto grande e 3:1 para elementos gráficos/controles aplicáveis.
- Estados e gráficos compreensíveis sem depender apenas da cor; tabela ou descrição equivalente para distribuição.
- Testar texto ampliado e largura de 320 CSS px sem perda funcional; tabelas podem usar rolagem controlada quando necessária.
- Validar em celular, tablet e desktop com tamanhos, navegador e resultado registrados.
- Respeitar prefers-reduced-motion. Transições usuais entre 150–250 ms; movimento contínuo fora de áreas operacionais.
- Datas da API permanecem UTC ISO 8601. Uma visualização localizada deve informar o fuso e preservar acesso ao horário original na auditoria.
- Leitor de tela deve reconhecer ações, mudança de estado e erros sem exigir interpretação exclusivamente visual.

## Segurança de apresentação

Credenciais de demonstração são segredos locais. Exemplos usam placeholders; não registrar Authorization, tokens ou payloads completos em capturas e logs. A interface de navegador, caso aprovada, dependerá de modelo de sessão/CORS/CSRF revisado por Segurança. API key não constitui por si só um fluxo de login.

Exibir mensagens como texto escapado e apenas os detalhes sanitizados previstos no contrato. Não criar controles de configuração para failure_mode, que é exclusivo dos testes do servidor.

## Critérios de aceite de PAY-006

- Fontes e precedência visual documentadas.
- Vocabulário financeiro, limites monetários, estados e replay alinhados ao contrato.
- Fluxo HTTP e dependências de interface descritos em jornadas-e-integracao.md.
- Separação explícita entre documentação entregue e telas ainda não aprovadas.
- Revisão documental registrada pela coordenação no BlackBoard.

Contraste, responsividade, testes de interface e integração executada exigem evidências futuras; não foram validados por este documento.
