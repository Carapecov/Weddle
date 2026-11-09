(() => {
'use strict';
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
const icon = (name, extra = '') => '<svg class="icon ' + extra + '" aria-hidden="true"><use href="#i-' + name + '"></use></svg>';
const csrf = () => $('meta[name="csrf-token"]')?.content || '';
const cents = value => {
    if (typeof value === 'bigint') return value;
    if (typeof value === 'number' && Number.isSafeInteger(value)) return BigInt(value);
    if (typeof value === 'string' && /^-?\d+$/.test(value)) return BigInt(value);
    return 0n;
};
const money = value => {
    const amount = cents(value);
    const absolute = amount < 0n ? -amount : amount;
    return (amount < 0n ? '− ' : '') + 'R$ ' + (absolute / 100n).toLocaleString('pt-BR') + ',' + String(absolute % 100n).padStart(2, '0');
};
const integer = value => Number.isSafeInteger(Number(value)) ? Number(value).toLocaleString('pt-BR') : String(value ?? '0');
const shorten = value => String(value || '').slice(0, 8).toUpperCase();
const dateTime = value => {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Data indisponível' : new Intl.DateTimeFormat('pt-BR', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', timeZone:'America/Sao_Paulo'}).format(date);
};
const statuses = {CREATED:'Criado', AUTHORIZED:'Autorizado', SETTLED:'Liquidado', FAILED:'Recusado / falha', REFUNDED:'Estornado'};
const statusBadge = status => '<span class="status-badge status-' + (Object.hasOwn(statuses, status) ? status.toLowerCase() : 'created') + '">' + escapeHTML(statuses[status] || status) + '</span>';
const state = {pane:'overview', page:1, meta:{current_page:1,last_page:1,total:0}, overview:null, currentDetail:null, detailVersion:0, listVersion:0, keys:new Map(), refreshing:false};
function toast(message, error = false) {
    const region = $('#toast-region');
    if (!region) return;
    const node = document.createElement('div');
    node.className = 'toast' + (error ? ' toast-error' : '');
    node.setAttribute('role', error ? 'alert' : 'status');
    node.innerHTML = icon(error ? 'info' : 'check') + '<span>' + escapeHTML(message) + '</span><button class="icon-button" aria-label="Fechar aviso">' + icon('close') + '</button>';
    $('button', node).addEventListener('click', () => node.remove());
    region.append(node);
    setTimeout(() => node.remove(), error ? 9500 : 5500);
}
class RequestError extends Error {
    constructor(message, status, details = [], code = '') {
        super(message);
        this.status = status;
        this.details = details;
        this.code = code;
    }
}
async function request(path, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 25000);
    try {
        const headers = {'Accept':'application/json', 'X-CSRF-TOKEN':csrf(), ...(options.headers || {})};
        if (options.method && options.method !== 'GET') headers['Content-Type'] = 'application/json';
        const response = await fetch(path, {...options, headers, credentials:'same-origin', signal:controller.signal});
        let payload = {};
        try { payload = await response.json(); } catch (_) { /* Sanitized fallback below. */ }
        if (!response.ok) {
            if (response.status === 401 && document.body.dataset.page === 'app') {
                window.location.assign('/');
                throw new RequestError('Sua sessão foi encerrada. Abra uma nova demonstração.', 401);
            }
            if (response.status === 419) throw new RequestError('Sua sessão expirou. Atualize a página e tente novamente.', 419);
            const message = payload.error?.message || (response.status === 429 ? 'Aguarde alguns instantes antes de tentar novamente.' : 'Não foi possível concluir a operação.');
            throw new RequestError(message, response.status, payload.error?.details || [], payload.error?.code || '');
        }
        return payload;
    } catch (error) {
        if (error instanceof RequestError) throw error;
        throw new RequestError(navigator.onLine ? 'A conexão demorou ou foi interrompida. Você pode repetir a tentativa com segurança.' : 'Você está sem conexão. Tente novamente quando estiver online.', 0);
    } finally {
        clearTimeout(timeout);
    }
}
const post = (path, body = {}, key = null) => request(path, {method:'POST', body:JSON.stringify(body), headers:key ? {'Idempotency-Key':key} : {}});
function keyFor(scope, fingerprint = '') {
    const identifier = scope + ':' + fingerprint;
    if (!state.keys.has(identifier)) state.keys.set(identifier, 'web-' + (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2)));
    return {identifier, key:state.keys.get(identifier)};
}
function setButtonBusy(button, busy, text = 'Aguarde…') {
    if (!button) return;
    if (busy) {
        button.dataset.originalContent = button.innerHTML;
        button.innerHTML = icon('refresh', 'rotation-loading') + escapeHTML(text);
    } else if (button.dataset.originalContent) {
        button.innerHTML = button.dataset.originalContent;
        delete button.dataset.originalContent;
    }
    button.disabled = busy;
}
async function startSession(button) {
    const buttons = $$('[data-start-session]');
    if (buttons.some(item => item.disabled)) return;
    buttons.forEach(item => { item.disabled = true; });
    setButtonBusy(button, true, 'Preparando seu espaço…');
    try {
        const result = await post('/web/session');
        window.location.assign(result.redirect || '/app');
    } catch (error) {
        buttons.forEach(item => { item.disabled = false; });
        setButtonBusy(button, false);
        toast(error.message, true);
    }
}
function showNetwork(message) {
    const banner = $('#network-banner');
    if (!banner) return;
    $('#network-message').textContent = message;
    banner.hidden = false;
}
function hideNetwork() { if ($('#network-banner')) $('#network-banner').hidden = true; }
function renderTable(target, payments, recent = false) {
    if (!target) return;
    if (!payments.length) {
        target.innerHTML = '<div class="empty-state">' + icon('wallet') + '<strong>Nenhum pagamento por aqui.</strong><span>' + (recent ? 'Crie sua primeira simulação para acompanhar o fluxo.' : 'Tente outro filtro ou crie uma nova simulação.') + '</span><br><button class="button button-secondary button-sm" data-new-simulation>' + icon('plus', 'icon-sm') + 'Nova simulação</button></div>';
        return;
    }
    target.innerHTML = '<table class="payment-table"><caption class="sr-only">' + (recent ? 'Pagamentos recentes da sessão' : 'Pagamentos encontrados') + '</caption><thead><tr><th scope="col">Pagamento</th><th scope="col">Valor</th><th scope="col">Status</th><th scope="col">Data e hora · Brasília</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead><tbody>' + payments.map(payment => {
        const identifier = escapeHTML(payment.id);
        const reference = payment.reference || payment.fiscal_document_reference || ('Documento ' + shorten(payment.fiscal_document_id));
        return '<tr><td><button class="payment-id" data-detail="' + identifier + '"><span class="payment-icon">' + icon('wallet') + '</span><span>SIM-' + escapeHTML(shorten(payment.id)) + '<small>' + escapeHTML(reference) + '</small></span></button></td><td class="payment-amount">' + escapeHTML(money(payment.gross_amount_cents)) + '</td><td>' + statusBadge(payment.status) + '</td><td title="' + escapeHTML(payment.created_at) + '">' + escapeHTML(dateTime(payment.created_at)) + '</td><td><button class="icon-button" data-detail="' + identifier + '" aria-label="Ver detalhes do pagamento ' + escapeHTML(shorten(payment.id)) + '">' + icon('chevron') + '</button></td></tr>';
    }).join('') + '</tbody></table>';
}
function renderChart() {
    const data = (state.overview?.daily || []).slice(-7);
    const target = $('#volume-chart');
    if (!target) return;
    if (!data.length) {
        target.innerHTML = '<div class="chart-loading">Ainda não há movimentação no período.</div>';
        $('#chart-total').textContent = money(0);
        return;
    }
    let total = 0n;
    let maximum = 0n;
    data.forEach(day => { const value = cents(day.amount_cents); total += value; if (value > maximum) maximum = value; });
    $('#chart-total').textContent = money(total);
    if (maximum === 0n) maximum = 100n;
    maximum = maximum + maximum / 5n;
    const width = 640, height = 220, left = 77, right = 24, top = 20, bottom = 37;
    const plotWidth = width - left - right, plotHeight = height - top - bottom, baseline = top + plotHeight;
    const pointY = amount => baseline - Number((cents(amount) * 10000n) / maximum) / 10000 * plotHeight;
    const points = data.map((day, index) => ({x:left + (data.length === 1 ? plotWidth / 2 : index * plotWidth / (data.length - 1)), y:pointY(day.amount_cents), day}));
    const line = points.map((point, index) => (index ? 'L ' : 'M ') + point.x.toFixed(2) + ' ' + point.y.toFixed(2)).join(' ');
    const area = 'M ' + points[0].x + ' ' + baseline + ' ' + line.replace(/^M /, 'L ') + ' L ' + points[points.length - 1].x + ' ' + baseline + ' Z';
    const dayLabel = date => { const parsed = new Date(date + 'T12:00:00Z'); return Number.isNaN(parsed.getTime()) ? date : new Intl.DateTimeFormat('pt-BR', {day:'2-digit',month:'2-digit',timeZone:'UTC'}).format(parsed); };
    let markup = '<svg viewBox="0 0 640 220" role="img" aria-label="Volume diário simulado, valores de pagamentos criados"><defs><linearGradient id="volume-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#1AAAFA" stop-opacity=".14"/><stop offset="100%" stop-color="#1AAAFA" stop-opacity=".01"/></linearGradient></defs>';
    for (let index = 0; index <= 4; index++) {
        const y = top + index * plotHeight / 4;
        const value = maximum * BigInt(4 - index) / 4n;
        const label = value >= 100000000n ? 'R$ ' + String(value / 100000000n) + ' mi' : value >= 100000n ? 'R$ ' + String(value / 100000n) + ' mil' : money(value).replace(',00', '');
        markup += '<line x1="' + left + '" x2="' + (width - right) + '" y1="' + y + '" y2="' + y + '" stroke="#ecf2f7" stroke-dasharray="3 4"/><text x="' + (left - 13) + '" y="' + (y + 3) + '" text-anchor="end">' + escapeHTML(label) + '</text>';
    }
    markup += '<path d="' + area + '" fill="url(#volume-fill)"/><path d="' + line + '" fill="none" stroke="#1AAAFA" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>';
    markup += points.map(point => '<circle cx="' + point.x + '" cy="' + point.y + '" r="3.3" fill="#fff" stroke="#1AAAFA" stroke-width="2"><title>' + escapeHTML(dayLabel(point.day.date) + ' · ' + money(point.day.amount_cents) + ' · ' + integer(point.day.count) + ' pagamentos') + '</title></circle><text class="chart-date" x="' + point.x + '" y="' + (height - 12) + '" text-anchor="middle">' + escapeHTML(dayLabel(point.day.date)) + '</text>').join('');
    markup += '</svg>';
    target.innerHTML = markup;
    $('#chart-accessible').textContent = data.map(day => dayLabel(day.date) + ': ' + money(day.amount_cents) + ', ' + integer(day.count) + ' pagamentos').join('; ');
}
function renderOverview(payload) {
    const summary = payload.summary || {};
    state.overview = payload;
    $('#kpi-volume').textContent = money(summary.total_amount_cents);
    $('#kpi-count').textContent = integer(summary.total_payments);
    $('#kpi-settled').textContent = money(summary.settled_amount_cents);
    $('#kpi-taxes').textContent = money(cents(summary.cbs_amount_cents) + cents(summary.ibs_amount_cents));
    $('#kpi-authorized').textContent = integer(summary.authorized_count);
    $('#kpi-settled-count').textContent = integer(summary.settled_count);
    $('#nav-payment-count').textContent = integer(summary.total_payments);
    const net = cents(summary.merchant_net_cents), cbs = cents(summary.cbs_amount_cents), ibs = cents(summary.ibs_amount_cents);
    const total = net + cbs + ibs;
    $('#distribution-total').textContent = money(total);
    $('#distribution-net').textContent = money(net);
    $('#distribution-merchant').textContent = money(net);
    $('#distribution-cbs').textContent = money(cbs);
    $('#distribution-ibs').textContent = money(ibs);
    const netRatio = total > 0n ? Number(net * 10000n / total) / 100 : 0;
    const cbsRatio = total > 0n ? Number(cbs * 10000n / total) / 100 : 0;
    $('#distribution-ring').style.background = total > 0n ? 'conic-gradient(#1262AE 0 ' + netRatio + '%,#1AAAFA ' + netRatio + '% ' + (netRatio + cbsRatio) + '%,#93e3e8 ' + (netRatio + cbsRatio) + '% 100%)' : 'conic-gradient(#e5eef4 0 100%)';
    renderChart();
    renderTable($('#recent-table'), (payload.recent || []).slice(0, 5), true);
    $$('.skeleton-text').forEach(item => item.classList.remove('skeleton-text'));
}
async function loadOverview() {
    const result = await request('/web/overview');
    renderOverview(result);
}
async function loadPayments() {
    const version = ++state.listVersion;
    const params = new URLSearchParams({status:$('#payment-status').value,search:$('#payment-search').value.trim(),page:String(state.page),limit:'10'});
    const payload = await request('/web/payments?' + params.toString());
    if (version !== state.listVersion) return;
    const payments = Array.isArray(payload.data) ? payload.data : [];
    state.meta = payload.meta || {current_page:1,last_page:1,total:payments.length,per_page:10};
    state.page = Number(state.meta.current_page) || 1;
    renderTable($('#payments-table'), payments);
    $('#pagination-label').textContent = integer(state.meta.total) + ' pagamento' + (Number(state.meta.total) === 1 ? '' : 's') + ' encontrado' + (Number(state.meta.total) === 1 ? '' : 's');
    $('#pagination-page').textContent = integer(state.meta.current_page) + ' / ' + integer(state.meta.last_page || 1);
    $('#previous-page').disabled = state.page <= 1;
    $('#next-page').disabled = state.page >= Number(state.meta.last_page || 1);
}
async function refreshAll() {
    if (state.refreshing) return;
    state.refreshing = true;
    $('#refresh-button')?.querySelector('svg')?.classList.add('rotation-loading');
    try {
        await Promise.all([loadOverview(), ['payments','history'].includes(state.pane) ? loadPayments() : Promise.resolve()]);
        hideNetwork();
    } catch (error) { showNetwork(error.message); }
    finally {
        state.refreshing = false;
        $('#refresh-button')?.querySelector('svg')?.classList.remove('rotation-loading');
    }
}
const paneCopy = {
    overview:['Visão geral','Cada operação, cada parcela. Tudo em um só lugar.','SEU LABORATÓRIO DE PAGAMENTOS'],
    payments:['Pagamentos','Crie, acompanhe e explore suas operações simuladas.','CADA OPERAÇÃO, SEM MISTÉRIO'],
    simulator:['Simulador','Uma nova operação. Um novo caminho para entender.','EXPLORE O SPLIT PAYMENT'],
    history:['Histórico','Consulte os estados e os detalhes de cada pagamento.','A HISTÓRIA DO SEU LABORATÓRIO']
};
function setSidebar(shouldOpen, moveFocus = false) {
    const mobile = window.matchMedia('(max-width:700px)').matches;
    const open = mobile && shouldOpen;
    document.body.classList.toggle('sidebar-open', open);
    $('#menu-toggle').setAttribute('aria-expanded', String(open));
    $('#sidebar').inert = mobile && !open;
    if (moveFocus) {
        if (open) $('.app-nav a.active')?.focus(); else $('#menu-toggle').focus();
    }
}
function showPane(name) {
    const pane = Object.hasOwn(paneCopy, name) ? name : 'overview';
    state.pane = pane;
    $('#overview-pane').hidden = pane !== 'overview';
    $('#list-pane').hidden = !['payments','history'].includes(pane);
    $('#simulator-pane').hidden = pane !== 'simulator';
    $('#page-title').innerHTML = paneCopy[pane][0] + '<span class="title-dot">.</span>';
    $('#page-description').textContent = paneCopy[pane][1];
    $('#page-eyebrow').textContent = paneCopy[pane][2];
    $('#breadcrumb-title').textContent = paneCopy[pane][0];
    document.title = paneCopy[pane][0] + ' — Weddell Pay';
    $$('.app-nav [data-pane]').forEach(link => {
        link.classList.toggle('active', link.dataset.pane === pane);
        if (link.dataset.pane === pane) link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current');
    });
    setSidebar(false);
    if (pane === 'simulator') {
        if ($('#simulation-dialog').open) $('#simulation-dialog').close();
        $('#inline-form-slot').append($('#simulation-form'));
    }
    if (['payments','history'].includes(pane)) loadPayments().catch(error => showNetwork(error.message));
}
function openSimulation() {
    const dialog = $('#simulation-dialog');
    if (!dialog) return;
    $('#modal-form-slot').append($('#simulation-form'));
    if (!dialog.open) dialog.showModal();
    setTimeout(() => $('#gross-amount').focus(), 25);
}
function parseMoney(value) {
    let normalized = String(value).trim().replace(/^R\$\s*/, '').replace(/\s/g,'');
    if (!normalized) throw new Error('Informe um valor.');
    let whole = '', fraction = '';
    if (normalized.includes(',')) {
        if (!/^(?:\d{1,3}(?:\.\d{3})+|\d+),\d{1,2}$/.test(normalized)) throw new Error('Use um valor como 1.000,00.');
        const parts = normalized.split(',');
        whole = parts[0].replace(/\./g,'');
        fraction = parts[1];
    } else if (/^\d+\.\d{1,2}$/.test(normalized)) {
        [whole, fraction] = normalized.split('.');
    } else if (/^(?:\d{1,3}(?:\.\d{3})+|\d+)$/.test(normalized)) {
        whole = normalized.replace(/\./g,'');
    } else throw new Error('Informe reais com até duas casas decimais.');
    const result = BigInt(whole) * 100n + BigInt(fraction.padEnd(2,'0') || '0');
    if (result > 1000000000000n) throw new Error('O limite é R$ 10.000.000.000,00 por valor.');
    return result;
}
function previewNet() {
    try {
        const gross = parseMoney($('#gross-amount').value), cbs = parseMoney($('#cbs-amount').value), ibs = parseMoney($('#ibs-amount').value);
        $('#preview-net').textContent = gross >= cbs + ibs ? money(gross - cbs - ibs) : 'Parcelas acima do total';
    } catch (_) { $('#preview-net').textContent = '—'; }
}
function resetFormErrors() {
    ['gross','cbs','ibs'].forEach(field => {
        $('#' + field + '-error').textContent = '';
        $('#' + (field === 'gross' ? 'gross' : field) + '-amount').removeAttribute('aria-invalid');
    });
    $('#simulation-form-error').hidden = true;
}
function formPayload() {
    resetFormErrors();
    let invalid = false;
    const values = {};
    ['gross','cbs','ibs'].forEach(field => {
        try { values[field] = parseMoney($('#' + field + '-amount').value); }
        catch (error) {
            $('#' + field + '-error').textContent = error.message;
            $('#' + field + '-amount').setAttribute('aria-invalid','true');
            invalid = true;
        }
    });
    if (!invalid && values.gross <= 0n) {
        $('#gross-error').textContent = 'O total deve ser maior que zero.';
        $('#gross-amount').setAttribute('aria-invalid','true');
        invalid = true;
    }
    if (!invalid && values.cbs + values.ibs > values.gross) {
        $('#simulation-form-error').textContent = 'A soma de CBS e IBS não pode superar o valor total da operação.';
        $('#simulation-form-error').hidden = false;
        invalid = true;
    }
    if (invalid) {
        $('#simulation-form [aria-invalid="true"]')?.focus();
        return null;
    }
    return {gross_amount_cents:Number(values.gross),cbs_amount_cents:Number(values.cbs),ibs_amount_cents:Number(values.ibs),scenario:$('input[name="scenario"]:checked').value};
}
async function createSimulation(event) {
    event.preventDefault();
    const payload = formPayload();
    if (!payload) return;
    const key = keyFor('create', JSON.stringify(payload));
    const button = $('#create-simulation-button');
    setButtonBusy(button,true,'Criando pagamento…');
    try {
        const result = await post('/web/payments',payload,key.key);
        state.keys.delete(key.identifier);
        if ($('#simulation-dialog').open) $('#simulation-dialog').close();
        $('#simulation-form').reset();
        resetFormErrors();
        previewNet();
        toast('Pagamento criado. Agora você pode explorar a autorização.');
        await refreshAll();
        await openDetail(result.payment?.id || result.id);
    } catch (error) {
        $('#simulation-form-error').textContent = error.message + (error.status === 0 || error.status === 503 ? ' A chave desta tentativa foi preservada.' : '');
        $('#simulation-form-error').hidden = false;
        const names = {gross_amount_cents:'gross',cbs_amount_cents:'cbs',ibs_amount_cents:'ibs'};
        for (const detail of error.details || []) {
            const field = names[detail.field];
            if (field) {
                $('#' + field + '-error').textContent = detail.message;
                $('#' + field + '-amount').setAttribute('aria-invalid','true');
            }
        }
    } finally { setButtonBusy(button,false); }
}
function renderDetail(data) {
    state.currentDetail = data;
    const payment = data.payment;
    const documentData = data.document || {};
    const ledger = Array.isArray(data.ledger) ? data.ledger : data.ledger?.data || [];
    const events = Array.isArray(data.events) ? data.events : data.events?.data || [];
    const action = {CREATED:['authorize','Autorizar pagamento','check'],AUTHORIZED:['settle','Liquidar pagamento','split'],SETTLED:['refund','Estornar integralmente','undo']}[payment.status];
    let markup = '<div class="detail-body"><div class="detail-id">' + escapeHTML(payment.id) + '</div><div class="detail-value">' + escapeHTML(money(payment.gross_amount_cents)) + '</div><div class="detail-intro">' + statusBadge(payment.status) + '<span class="detail-date" title="' + escapeHTML(payment.created_at) + '">' + escapeHTML(dateTime(payment.created_at)) + '</span></div>';
    markup += '<div class="detail-action-row">' + (action ? '<button class="button ' + (action[0] === 'refund' ? 'button-danger' : 'button-primary') + '" data-payment-action="' + action[0] + '" data-payment-id="' + escapeHTML(payment.id) + '">' + icon(action[2], 'icon-sm') + action[1] + '</button>' : '<p class="action-note">' + (payment.status === 'REFUNDED' ? 'Operação estornada. As partidas originais e as compensações permanecem no histórico.' : 'Operação encerrada sem liquidação. O cenário de recusa não gera partidas.') + '</p>') + '<button class="button button-secondary button-sm" data-detail="' + escapeHTML(payment.id) + '">' + icon('refresh','icon-sm') + 'Atualizar</button></div><div id="detail-action-error" class="form-error detail-action-error" hidden role="alert"></div>';
    markup += '<section class="detail-section"><h3>Distribuição da operação</h3>';
    if (payment.split) {
        markup += '<div class="detail-kv"><span><i class="dot dot-net"></i>Líquido do vendedor</span><strong>' + escapeHTML(money(payment.split.merchant_net_cents)) + '</strong></div><div class="detail-kv"><span><i class="dot dot-cbs"></i>CBS simulada</span><strong>' + escapeHTML(money(payment.split.cbs_cents)) + '</strong></div><div class="detail-kv"><span><i class="dot dot-ibs"></i>IBS simulado</span><strong>' + escapeHTML(money(payment.split.ibs_cents)) + '</strong></div>';
        if (payment.status === 'REFUNDED') markup += '<p class="detail-note">Distribuição histórica da liquidação original. Estes valores foram compensados pelo estorno e não representam saldo devido.</p>';
    } else markup += '<div class="notice">' + icon('info') + '<p>Ainda não liquidado. A segregação de CBS, IBS e líquido só é registrada na liquidação.</p></div>';
    markup += '</section><section class="detail-section"><h3>Documento fictício</h3><div class="detail-kv"><span>Referência</span><strong>' + escapeHTML(documentData.reference || shorten(payment.fiscal_document_id)) + '</strong></div><div class="detail-kv"><span>CBS declarada</span><strong>' + escapeHTML(money(documentData.cbs_amount_cents)) + '</strong></div><div class="detail-kv"><span>IBS declarado</span><strong>' + escapeHTML(money(documentData.ibs_amount_cents)) + '</strong></div><div class="detail-kv"><span>Cenário de autorização</span><strong>' + (payment.scenario === 'approved' ? 'Aprovação simulada' : 'Recusa simulada') + '</strong></div></section>';
    markup += '<section class="detail-section"><h3>Razão contábil virtual <span class="online-refresh">· ' + ledger.length + ' partidas</span></h3>';
    if (ledger.length) markup += '<div class="table-wrap"><table class="ledger-table"><thead><tr><th>Conta</th><th>Direção</th><th>Valor</th></tr></thead><tbody>' + ledger.map(entry => '<tr><td>' + escapeHTML(entry.account_code) + (entry.reversal_of_entry_id ? '<br><small>Compensação</small>' : '') + '</td><td class="' + (entry.direction === 'CREDIT' ? 'ledger-credit' : '') + '">' + (entry.direction === 'CREDIT' ? 'Crédito' : 'Débito') + '</td><td>' + escapeHTML(money(entry.amount_cents)) + '</td></tr>').join('') + '</tbody></table></div>';
    else markup += '<p class="detail-note">Nenhuma partida registrada para este pagamento.</p>';
    const eventNames = {CREATED:'Pagamento criado',AUTHORIZED:'Pagamento autorizado',AUTHORIZATION_DECLINED:'Autorização recusada',SETTLED:'Liquidação e split registrados',SETTLEMENT_FAILED:'Falha terminal na liquidação',REFUNDED:'Estorno integral registrado',TRANSIENT_ATTEMPT:'Tentativa com falha transitória'};
    markup += '</section><section class="detail-section"><h3>Histórico de eventos</h3><ol class="timeline">' + events.map(item => '<li><strong>' + escapeHTML(eventNames[item.event_type] || item.event_type) + '</strong><small title="' + escapeHTML(item.occurred_at) + '">' + escapeHTML(dateTime(item.occurred_at)) + '</small><small>Correlação <code>' + escapeHTML(item.correlation_id) + '</code></small></li>').join('') + '</ol></section><div class="notice detail-section">' + icon('shield') + '<p>Simulação — sem movimentação financeira real. CBS e IBS são valores fictícios, sem recolhimento tributário.</p></div></div>';
    $('#detail-content').innerHTML = markup;
}
async function openDetail(id) {
    if (!id) return;
    const detailVersion = ++state.detailVersion;
    const drawer = $('#detail-drawer');
    if (!drawer.open) drawer.showModal();
    $('#detail-title').textContent = 'Pagamento SIM-' + shorten(id);
    $('#detail-content').innerHTML = '<div class="empty-state loading-state">Carregando operação…</div>';
    try {
        const data = await request('/web/payments/' + encodeURIComponent(id));
        if (detailVersion === state.detailVersion) renderDetail(data);
    } catch (error) {
        $('#detail-content').innerHTML = '<div class="empty-state">' + icon('info') + '<strong>Não foi possível abrir a operação.</strong>' + escapeHTML(error.message) + '<br><button class="button button-secondary button-sm" data-detail="' + escapeHTML(id) + '">Tentar novamente</button></div>';
    }
}
async function paymentAction(button) {
    const operation = button.dataset.paymentAction, id = button.dataset.paymentId;
    if (!['authorize','settle','refund'].includes(operation) || !id) return;
    const pending = keyFor(operation + ':' + id);
    setButtonBusy(button,true,'Processando…');
    try {
        await post('/web/payments/' + encodeURIComponent(id) + '/' + operation,{},pending.key);
        state.keys.delete(pending.identifier);
        await openDetail(id);
        await refreshAll();
        const actualStatus = state.currentDetail?.payment?.status;
        toast(actualStatus === 'FAILED' ? 'O cenário recusou a autorização. Nenhuma parcela foi segregada.' : ({authorize:'Pagamento autorizado. A liquidação é o próximo passo.',settle:'Liquidação registrada. CBS, IBS e líquido foram segregados.',refund:'Estorno registrado por partidas compensatórias.'}[operation]));
    } catch (error) {
        if (error.status === 409) {
            await openDetail(id);
            await refreshAll();
            toast(error.message + ' O estado foi atualizado.',true);
        } else {
            const target = $('#detail-action-error');
            if (target) {
                target.textContent = error.message + (error.status === 0 || error.status === 503 ? ' Tente novamente: a chave foi preservada.' : '');
                target.hidden = false;
            } else toast(error.message,true);
            setButtonBusy(button,false);
        }
    }
}
async function copyExample(button) {
    const text = document.getElementById(button.dataset.copyTarget)?.textContent;
    if (!text) return;
    try {
        if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(text);
        else {
            const input = document.createElement('textarea');
            input.value = text; input.style.position = 'fixed'; input.style.opacity = '0';
            document.body.append(input); input.select();
            const copied = document.execCommand('copy'); input.remove();
            if (!copied) throw new Error();
        }
        toast('Exemplo copiado.');
    } catch (_) { toast('Não foi possível copiar. Selecione o texto do exemplo.',true); }
}
document.addEventListener('click', event => {
    const start = event.target.closest('[data-start-session]');
    if (start) { event.preventDefault(); startSession(start); return; }
    const copy = event.target.closest('[data-copy-target]');
    if (copy) { copyExample(copy); return; }
    if (document.body.dataset.page !== 'app') return;
    if (event.target.closest('[data-new-simulation]')) { event.preventDefault(); openSimulation(); return; }
    const paneLink = event.target.closest('[data-pane]');
    if (paneLink) {
        event.preventDefault();
        const pane = paneLink.dataset.pane;
        if (location.hash.slice(1) === pane) showPane(pane); else location.hash = pane;
        return;
    }
    const detailButton = event.target.closest('[data-detail]');
    if (detailButton) { openDetail(detailButton.dataset.detail); return; }
    const actionButton = event.target.closest('[data-payment-action]');
    if (actionButton) { paymentAction(actionButton); return; }
    if (event.target.closest('[data-close-dialog]')) $('#simulation-dialog').close();
    if (event.target.closest('[data-close-drawer]')) $('#detail-drawer').close();
    if (event.target.closest('[data-close-sidebar]')) setSidebar(false, true);
});
if (document.body.dataset.page === 'app') {
    $('#simulation-form').addEventListener('submit',createSimulation);
    ['gross','cbs','ibs'].forEach(field => $('#' + field + '-amount').addEventListener('input',previewNet));
    $('#menu-toggle').addEventListener('click',() => setSidebar(!document.body.classList.contains('sidebar-open'), true));
    $('#simulation-dialog').addEventListener('close',() => {
        if (state.pane === 'simulator') $('#inline-form-slot').append($('#simulation-form'));
    });
    window.matchMedia('(max-width:700px)').addEventListener('change',() => setSidebar(false));
    document.addEventListener('keydown',event => {
        if (!document.body.classList.contains('sidebar-open')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            setSidebar(false, true);
        } else if (event.key === 'Tab') {
            const focusable = $$('a[href],button:not([disabled])', $('#sidebar'));
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    $('#refresh-button').addEventListener('click',refreshAll);
    $('#retry-load').addEventListener('click',refreshAll);
    $('#payment-status').addEventListener('change',() => { state.page = 1; loadPayments().catch(error => showNetwork(error.message)); });
    let searchTimer;
    $('#payment-search').addEventListener('input',() => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { state.page = 1; loadPayments().catch(error => showNetwork(error.message)); },300);
    });
    $('#previous-page').addEventListener('click',() => { if (state.page > 1) { state.page--; loadPayments().catch(error => showNetwork(error.message)); } });
    $('#next-page').addEventListener('click',() => { if (state.page < Number(state.meta.last_page)) { state.page++; loadPayments().catch(error => showNetwork(error.message)); } });
    $('#load-examples').addEventListener('click',async event => {
        const button = event.currentTarget;
        setButtonBusy(button,true,'Preparando…');
        try {
            const result = await post('/web/examples');
            await refreshAll();
            toast(Number(result.created_count) > 0 ? integer(result.created_count) + ' exemplos fictícios adicionados.' : 'Os exemplos desta sessão já estão disponíveis.');
        } catch (error) { toast(error.message,true); }
        finally { setButtonBusy(button,false); }
    });
    $('#logout-button').addEventListener('click',async event => {
        const button = event.currentTarget;
        setButtonBusy(button,true,'Encerrando…');
        try { const result = await post('/web/logout'); window.location.assign(result.redirect || '/'); }
        catch (error) { toast(error.message,true); setButtonBusy(button,false); }
    });
    ['#simulation-dialog','#detail-drawer'].forEach(selector => $(selector).addEventListener('click',event => {
        if (event.target !== event.currentTarget) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) event.currentTarget.close();
    }));
    window.addEventListener('hashchange',() => showPane(location.hash.slice(1)));
    window.addEventListener('online',refreshAll);
    window.addEventListener('offline',() => showNetwork('Você está sem conexão. Os dados exibidos podem estar desatualizados.'));
    showPane(location.hash.slice(1) || 'overview');
    refreshAll();
}
})();