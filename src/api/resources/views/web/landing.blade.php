<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="description" content="Explore pagamentos e a distribuição de CBS e IBS em um laboratório de simulação. Nenhuma movimentação financeira real.">
<title>Weddell Pay — clareza em cada pagamento</title>
<link rel="icon" href="data:,"><link rel="stylesheet" href="{{ asset('assets/weddell.css') }}?v=web1">
<script src="{{ asset('assets/weddell.js') }}?v=web1" defer></script>
</head>
<body class="landing-page" data-page="landing">
@include('web.icons')
<a class="skip-link" href="#main">Ir para o conteúdo</a>
<header class="site-header">
<a class="brand" href="{{ url('/') }}" aria-label="Weddell Pay, início"><span class="brand-mark" aria-hidden="true">w<span></span></span><span>Weddell<span class="brand-pay">Pay</span><span class="brand-dot">.</span></span></a>
<nav class="site-nav" aria-label="Navegação principal"><a href="#produto">Produto</a><a href="#como-funciona">Como funciona</a><a href="#api">API</a><a href="{{ url('/docs') }}">Documentação <svg class="icon icon-sm"><use href="#i-arrow-up"/></svg></a></nav>
<button class="button button-dark button-sm" data-start-session>Explorar plataforma <svg class="icon icon-sm"><use href="#i-arrow"/></svg></button>
</header>
<main id="main">
<section class="landing-hero section-width" id="produto">
<div class="hero-copy">
<div class="eyebrow"><span class="live-dot"></span> UM NOVO OLHAR PARA PAGAMENTOS</div>
<h1>Clareza em<br>cada parte do<br><span>pagamento.</span></h1>
<p>Entenda o caminho do dinheiro, do início ao split. Um espaço para simular pagamentos e explorar CBS e IBS com transparência.</p>
<div class="hero-actions"><button class="button button-primary button-large" data-start-session>Começar uma simulação <svg class="icon"><use href="#i-arrow"/></svg></button><a class="text-link" href="#como-funciona">Veja como funciona <svg class="icon icon-sm"><use href="#i-chevron"/></svg></a></div>
<div class="hero-note"><svg class="icon"><use href="#i-shield"/></svg><span>Ambiente de demonstração.<br><strong>Sem movimentação financeira real.</strong></span></div>
</div>
<div class="hero-art" aria-label="Mascote foca-de-Weddell ao lado de uma composição ilustrativa de pagamento">
<div class="art-orbit art-orbit-one"></div><div class="art-orbit art-orbit-two"></div>
<div class="art-caption"><span class="live-dot"></span> TRANSPARÊNCIA, POR NATUREZA.</div>
<img class="hero-mascot" src="{{ asset('assets/weddell-mascot.png') }}" alt="Foca-de-Weddell ilustrada em marrom e branco sobre fundo azul glacial" width="1254" height="1254" fetchpriority="high">
<div class="sample-payment"><div class="sample-heading"><span class="sample-icon"><svg class="icon"><use href="#i-split"/></svg></span><span>Um pagamento. Tudo às claras.<small>Exemplo ilustrativo</small></span><span class="sample-check"><svg class="icon icon-sm"><use href="#i-check"/></svg></span></div><div class="sample-total"><span>Valor da operação</span><strong>R$ 1.000<span>,00</span></strong></div><div class="split-bar" aria-hidden="true"><i class="split-net"></i><i class="split-cbs"></i><i class="split-ibs"></i></div><div class="sample-legend"><span><i class="dot dot-net"></i>Líquido<strong>R$ 800,00</strong></span><span><i class="dot dot-cbs"></i>CBS<strong>R$ 80,00</strong></span><span><i class="dot dot-ibs"></i>IBS<strong>R$ 120,00</strong></span></div></div>
<span class="art-footnote">FEITO PARA EXPLORAR. CRIADO PARA ENTENDER.</span>
</div>
</section>
<section class="landing-process section-width" id="como-funciona">
<div class="section-heading"><div><span class="eyebrow">DO PAGAMENTO À DISTRIBUIÇÃO</span><h2>O fluxo completo.<br>Sem perder os detalhes.</h2></div><p>Crie, acompanhe e descubra como cada parcela é segregada. Tudo em um ambiente isolado, com dados fictícios.</p></div>
<div class="process-grid"><article><span class="step-number">01 <svg class="icon"><use href="#i-file"/></svg></span><h3>Prepare a operação</h3><p>Informe o valor e as parcelas nominais de CBS e IBS em um documento simulado.</p></article><article><span class="step-number">02 <svg class="icon"><use href="#i-wallet"/></svg></span><h3>Simule o pagamento</h3><p>Explore autorização, liquidação e recusa. Cada etapa tem um estado claro.</p></article><article><span class="step-number">03 <svg class="icon"><use href="#i-split"/></svg></span><h3>Veja cada parte</h3><p>Consulte o líquido do vendedor, os tributos separados e os registros da operação.</p></article></div>
</section>
<section class="landing-api section-width" id="api"><div class="api-copy"><span class="eyebrow eyebrow-light">CONSTRUÍDA PARA QUEM EXPLORA</span><h2>Simples de usar.<br>Aberta para entender.</h2><p>Uma API REST com valores em centavos, operações idempotentes e uma trilha de eventos para acompanhar cada transição.</p><a href="{{ url('/docs') }}" class="button button-white">Conhecer a API <svg class="icon icon-sm"><use href="#i-arrow"/></svg></a><span class="api-fineprint">CBS e IBS fictícios. Nenhum cálculo tributário oficial.</span></div><div class="code-window"><div class="code-top"><span></span><span></span><span></span><b>POST /api/v1/payments</b></div><pre><code><span class="code-muted">// Exemplo de requisição</span>
{
  <span class="code-key">"merchant_id"</span>: <span class="code-string">"UUID_DO_VENDEDOR"</span>,
  <span class="code-key">"fiscal_document_id"</span>: <span class="code-string">"UUID_DO_DOCUMENTO"</span>,
  <span class="code-key">"amount_cents"</span>: <span class="code-number">100000</span>,
  <span class="code-key">"currency"</span>: <span class="code-string">"BRL"</span>,
  <span class="code-key">"scenario"</span>: <span class="code-string">"approved"</span>
}</code></pre><div class="code-bottom"><span class="live-dot"></span> DOCUMENTAÇÃO OPENAPI DISPONÍVEL</div></div></section>
<section class="landing-bottom section-width"><span class="eyebrow">CURIOSIDADE É UM BOM COMEÇO</span><h2>Seu próximo pagamento<br>pode ser uma descoberta.</h2><button class="button button-primary button-large" data-start-session>Explorar o simulador <svg class="icon"><use href="#i-arrow"/></svg></button><p>A sessão cria exemplos fictícios e isolados para você explorar.</p></section>
</main>
<footer class="site-footer section-width"><a class="brand brand-small" href="{{ url('/') }}"><span class="brand-mark" aria-hidden="true">w<span></span></span><span>Weddell<span class="brand-pay">Pay</span><span class="brand-dot">.</span></span></a><p>Simulação — sem movimentação financeira real.</p><a href="{{ url('/docs') }}">Documentação <svg class="icon icon-sm"><use href="#i-arrow-up"/></svg></a></footer>
<div class="toast-region" id="toast-region" role="status" aria-live="polite"></div>
</body></html>