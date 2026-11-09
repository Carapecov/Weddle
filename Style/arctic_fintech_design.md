# Arctic Fintech — Especificação de Design

**Versão:** 1.0  
**Objetivo:** Definir a identidade visual e a experiência de um site com API de pagamentos simulados, inspirado na Antártida, no gelo e na foca-de-Weddell.  
**Nome provisório:** Weddell Pay.

## 1. Direção visual

- **Estilo:** Arctic Fintech — minimalismo financeiro com identidade glacial.
- **Atributos:** segurança, clareza, tecnologia, organização e confiabilidade.
- **Conceito:** combinar página institucional de caráter visual e envolvente com dashboard financeiro limpo e objetivo.
- **Regra central:** a temática antártica deve diferenciar a marca, sem prejudicar a compreensão dos valores, das transações e das funcionalidades.
- **Mascote:** foca-de-Weddell estilizada, com corpo marrom e detalhes brancos. Usar de forma pontual, não como elemento decorativo em todas as telas.

## 2. Paleta de cores

| Token | Hex | Aplicação |
|---|---|---|
| `--arctic-snow` | `#F5FBFF` | Fundo principal da aplicação |
| `--arctic-ice` | `#DDFEFF` | Seções suaves, contornos e detalhes glaciais |
| `--arctic-blue` | `#1AAAFA` | Destaques da identidade e elementos gráficos |
| `--arctic-action` | `#1262AE` | Botões principais, links e estados ativos |
| `--arctic-deep` | `#102D46` | Títulos, texto principal e cabeçalhos |
| `--weddell-brown` | `#704626` | Mascote, ilustrações e acentos discretos |
| `--white` | `#FFFFFF` | Cartões, tabelas e áreas de conteúdo |

### Regras de aplicação

- Priorizar fundos claros e grandes áreas de respiro visual.
- Concentrar cores mais saturadas em ações e pontos de atenção.
- Usar `#102D46` para textos importantes sobre superfícies claras.
- Evitar usar o marrom como cor de botões financeiros ou estados de sucesso/erro.
- Não depender apenas das cores para comunicar estados: usar rótulos, ícones e descrições.
- Validar contrastes segundo WCAG 2.2 AA, inclusive nos botões e textos pequenos.

## 3. Tipografia

- **Fonte principal:** Inter ou Manrope.
- **Títulos:** pesos 600–700, com aparência moderna e clara.
- **Corpo:** pesos 400–500, preferencialmente 16 px na aplicação.
- **Valores financeiros:** usar algarismos tabulares para alinhamento e consistência.
- **Código e documentação:** fonte monoespaçada, por exemplo JetBrains Mono.

## 4. Elementos de interface

- **Cartões:** fundo branco, cantos arredondados de 12–20 px, borda sutil e sombra leve.
- **Botões principais:** azul `#1262AE`, texto branco e estados visíveis de foco/hover/desabilitado.
- **Botões secundários:** superfícies brancas com borda azul e texto azul profundo.
- **Campos:** rótulos persistentes, mensagens de erro específicas e identificação de campos obrigatórios.
- **Gráficos:** azuis para dados principais; demais cores devem ter significados consistentes e acessíveis.
- **Glassmorphism:** permitido apenas em elementos decorativos ou institucionais; evitar em tabelas e valores críticos.
- **Ícones:** traço consistente, evitando mistura arbitrária de estilos.
- **Bordas e espaçamento:** utilizar uma escala de espaçamento coerente (4, 8, 12, 16, 24, 32 e 48 px).

## 5. Página institucional (landing page)

### Cabeçalho

- Logotipo e nome do produto à esquerda.
- Navegação: Produto, Como funciona, API, Documentação.
- Ações: Entrar e Explorar API.

### Hero

- Título sugerido: **A evolução dos pagamentos começa aqui.**
- Subtítulo: **Simule pagamentos, visualize a distribuição dos valores e explore integrações por API.**
- Ações: **Explorar API** e **Ver documentação**.
- Fundo azul glacial suave e demonstração da interface financeira ao lado do texto, em telas maiores.
- Mascote Weddell em destaque com presença moderada, sem competir com a chamada principal.

### Seções posteriores

1. Explicação objetiva do produto e de seu caráter simulado.
2. Demonstração de uma transação e sua distribuição.
3. Recursos da API: autenticação, requisições, respostas e tratamento de erros.
4. Jornada de integração para desenvolvedores.
5. Perguntas frequentes e rodapé com documentação e informações do projeto.

## 6. Área autenticada (dashboard)

### Navegação

- Visão geral
- Pagamentos simulados
- Simulação de distribuição
- Histórico de transações
- API / Credenciais de teste
- Logs e documentação
- Configurações

### Visão geral

- Cartões de indicadores: volume simulado, quantidade de transações, aprovações e falhas.
- Gráfico de evolução temporal.
- Lista de transações recentes, com data, ID, valor, status e ação de detalhamento.
- Informação visível de que os valores não representam operações financeiras reais.

### Simulador de pagamentos

- Entrada: valor da operação e parâmetros de distribuição.
- Exibição separada dos recebedores, das taxas e dos tributos simulados.
- Detalhes do cálculo, premissas e eventuais arredondamentos.
- Resultado visual em barra segmentada, mais tabela textual acessível.
- Estados de carregamento, sucesso, erro e campos inválidos.
- Opção de inspecionar requisição e resposta JSON de exemplo.

**Exemplo fictício de composição comercial:** R$ 1.000,00 divididos em R$ 720,00 para recebedor, R$ 180,00 de tributo simulado e R$ 100,00 de taxa simulada. Esses valores não representam alíquotas legais nem regras oficiais.

## 7. Distinção conceitual essencial

Não confundir:

- **Split comercial de pagamentos:** distribuição de valores entre participantes de uma transação.
- **Split Payment tributário:** mecanismo de segregação e recolhimento tributário associado à reforma tributária brasileira, sujeito a regras normativas próprias.

Se a API for demonstrar Split Payment tributário, definir separadamente o modelo de regras, a versão normativa adotada, as premissas e as limitações da simulação. Não apresentar fluxos fictícios como integração bancária real ou operação em produção.

## 8. Responsividade e acessibilidade

- **Mobile-first:** começar pelo uso em celular e ampliar para tablet e desktop.
- No celular, empilhar indicadores e permitir rolagem horizontal controlada em tabelas extensas ou substituir linhas por cartões.
- Manter áreas clicáveis confortáveis e navegação por teclado.
- Usar foco visível e descrições textuais em ícones.
- Não transmitir estados apenas por cor.
- Implementar preferência por movimento reduzido (`prefers-reduced-motion`).
- Testar contraste, tamanhos de texto e leitura de números em telas pequenas.

## 9. Animações

- Transições leves de 150–250 ms em interações.
- Entrada discreta de painéis e indicadores.
- Evitar movimento contínuo em áreas operacionais.
- Elementos glaciais como partículas, brilho ou gradientes só em áreas institucionais e com baixa intensidade.

## 10. Referência externa

**Stripe:** https://stripe.com/br

Usar como referência de hierarquia, organização de informações, tipografia, demonstrações da API e apresentação de produtos financeiros. Não copiar marca, imagens ou layout integral. A identidade do projeto permanece azul glacial e Weddell.

## 11. Critérios de aceitação do design

- [ ] Identidade coerente em landing page, dashboard e documentação.
- [ ] Mascote reconhecível e proporcional, com uso moderado.
- [ ] Botões e indicadores com contraste adequado.
- [ ] Valores financeiros facilmente legíveis e alinhados.
- [ ] Fluxo de simulação compreensível sem conhecimento técnico avançado.
- [ ] Separação clara entre operações simuladas e reais.
- [ ] Layout funcional em celular, tablet e desktop.
- [ ] Estados de erro, sucesso, carregamento e vazio documentados.
- [ ] Interfaces acessíveis por teclado e leitor de tela.
- [ ] Distinção explícita entre split comercial e tributário.

## 12. Resumo de implementação

**Landing page:** mais expressiva, azul glacial, uso comedido do mascote e ilustrações.  
**Dashboard:** claro, funcional, com aparência de produto financeiro confiável.  
**Documentação da API:** exemplos de requisição/resposta, navegação direta e tipografia adequada a código.  
**Objetivo da experiência:** permitir que um visitante compreenda rapidamente o produto e que um desenvolvedor consiga testar o fluxo simulado sem ambiguidade.
