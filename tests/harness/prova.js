/**
 * Prova do `resources/js/popup.js` num browser a sério (Chromium headless, pelo Playwright do monólito).
 *
 *   node tests/harness/prova.js
 *
 * Não arranca servidor nenhum: o `index.html` desta pasta e o `popup.js` do package são servidos por
 * intercepção em https://humbi.exemplo/, e o `window.fetch` da página está simulado. As capturas ficam
 * em `tests/harness/capturas/`. Sai com 1 se alguma verificação falhar.
 */
const fs = require('fs');
const path = require('path');

const PLAYWRIGHT = process.env.PLAYWRIGHT_DIR || '/Volumes/HongaWork/ProjectosSites/hongayetu/node_modules/playwright';
const { chromium } = require(PLAYWRIGHT);

const AQUI = __dirname;
const CAPTURAS = path.join(AQUI, 'capturas');
const SITE = 'https://humbi.exemplo';
const HTML = fs.readFileSync(path.join(AQUI, 'index.html'), 'utf8');
const SCRIPT = fs.readFileSync(path.join(AQUI, '..', '..', 'resources', 'js', 'popup.js'), 'utf8');

const svg = (titulo, a, b) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 540" width="960" height="540">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs>
<rect width="960" height="540" fill="url(#g)"/>
<circle cx="760" cy="150" r="120" fill="#fff" opacity=".14"/><circle cx="210" cy="430" r="170" fill="#fff" opacity=".10"/>
<rect x="330" y="230" width="260" height="130" rx="18" fill="#fff" opacity=".92"/><rect x="590" y="270" width="110" height="90" rx="14" fill="#fff" opacity=".8"/>
<circle cx="400" cy="372" r="34" fill="#0f172a"/><circle cx="640" cy="372" r="34" fill="#0f172a"/>
<text x="480" y="150" font-family="Helvetica,Arial,sans-serif" font-size="46" font-weight="700" fill="#fff" text-anchor="middle">${titulo}</text></svg>`;

const falhas = [];
let feitas = 0;
function verificar(condicao, descricao, pormenor) {
    feitas++;
    if (condicao) {
        console.log('  ok  ' + descricao);
    } else {
        falhas.push(descricao);
        console.log('FALHA ' + descricao + (pormenor !== undefined ? '  → ' + JSON.stringify(pormenor) : ''));
    }
}

const espera = (ms) => new Promise((r) => setTimeout(r, ms));

/** Um contexto novo, com a página servida por intercepção e os pedidos do `fetch` simulado guardados cá fora. */
async function abrir(browser, opcoes = {}) {
    const contexto = await browser.newContext({
        viewport: opcoes.viewport || { width: 1440, height: 900 },
        colorScheme: opcoes.esquema || 'light',
        reducedMotion: opcoes.semMovimento ? 'reduce' : 'no-preference',
        deviceScaleFactor: opcoes.telemovel ? 2 : 1,
        isMobile: !!opcoes.telemovel,
        hasTouch: !!opcoes.telemovel,
        locale: 'pt-PT',
    });
    const estado = { pedidos: [], erros: [], separadores: [], hls: opcoes.hls || 'abortar', contexto };

    await contexto.route('**/*', async (rota) => {
        const url = new URL(rota.request().url());
        if (url.origin === SITE || url.host === 'negocio.humbi.exemplo') {
            if (url.pathname === '/mensagens-popup/popup.js') {
                return rota.fulfill({ contentType: 'application/javascript; charset=utf-8', body: SCRIPT });
            }
            if (url.pathname === '/promocoes') {
                return rota.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>Promoções</title><h1>Promoções</h1>' });
            }
            return rota.fulfill({ contentType: 'text/html; charset=utf-8', body: HTML });
        }
        if (url.host === 'cdn.exemplo' && url.protocol === 'https:' && url.pathname.endsWith('.svg')) {
            const capa = url.pathname.includes('capa');
            return rota.fulfill({
                contentType: 'image/svg+xml',
                body: capa ? svg('Conhece a carteira', '#7c3aed', '#db2777') : svg('Entregas no mesmo dia', '#0f766e', '#22c55e'),
            });
        }
        if (url.host === 'cdnjs.cloudflare.com') {
            if (estado.hls === 'simular') {
                return rota.fulfill({
                    contentType: 'application/javascript',
                    body: 'window.Hls=function(){};window.Hls.isSupported=function(){return true};window.Hls.Events={ERROR:"hlsError"};'
                        + 'window.Hls.prototype={on:function(){},loadSource:function(u){window.__hlsFonte=u},attachMedia:function(v){window.__hlsLigado=v.tagName},destroy:function(){window.__hlsDestruido=true}};',
                });
            }
            return rota.abort();
        }
        if (url.host === 'parceiro.exemplo') {
            return rota.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>Parceiro</title>' });
        }
        return rota.abort();
    });

    await contexto.exposeBinding('__registarPedido', (_origem, pedido) => { estado.pedidos.push(pedido); });
    contexto.on('page', (p) => { if (estado.pagina && p !== estado.pagina) { estado.separadores.push(p.url()); p.close().catch(() => {}); } });

    // O Chromium recente toca HLS sozinho; a prova escolhe o caminho em vez de depender da versão.
    if (opcoes.hlsNativo !== undefined) {
        await contexto.addInitScript((nativo) => {
            const original = HTMLMediaElement.prototype.canPlayType;
            HTMLMediaElement.prototype.canPlayType = function (tipo) {
                return tipo === 'application/vnd.apple.mpegurl' ? (nativo ? 'maybe' : '') : original.call(this, tipo);
            };
        }, opcoes.hlsNativo);
    }

    if (opcoes.cookies) {
        await contexto.addCookies(opcoes.cookies);
    }

    const pagina = await contexto.newPage();
    estado.pagina = pagina;
    pagina.on('pageerror', (e) => estado.erros.push(String(e)));
    pagina.on('console', (m) => { if (m.type() === 'error' && !/net::ERR_FAILED|Failed to load resource/.test(m.text())) { estado.erros.push(m.text()); } });

    estado.eventos = (tipo, id) => estado.pedidos.filter((p) => p.metodo === 'POST' && p.corpo && p.corpo.tipo === tipo && (id === undefined || p.url.endsWith('/' + id + '/eventos')));
    estado.listas = () => estado.pedidos.filter((p) => p.metodo === 'GET');
    estado.dialogo = pagina.locator('[role="dialog"]');
    estado.botao = (nome) => estado.dialogo.locator('.rodape').getByRole('button', { name: nome, exact: true });
    estado.captura = async (nome) => {
        // A animação de entrada dura 200 ms.
        await espera(350);
        await pagina.screenshot({ path: path.join(CAPTURAS, nome + '.png') });
        console.log('  captura ' + nome + '.png');
    };
    estado.fechar = () => contexto.close();

    await pagina.goto(SITE + (opcoes.caminho || '/'));
    return estado;
}

const CORPO_BASE = { servico: 'humbi', superficie: 'site' };
const igual = (a, b) => JSON.stringify(Object.keys(a).sort().map((k) => [k, a[k]])) === JSON.stringify(Object.keys(b).sort().map((k) => [k, b[k]]));

async function provaPrincipal(browser) {
    console.log('\n[1] 1440×900 claro: a novidade (2 diapositivos) e depois a obrigatória');
    const s = await abrir(browser);
    await s.dialogo.waitFor({ state: 'visible' });

    const lista = s.listas();
    verificar(lista.length === 1 && lista[0].url === '/mensagens-popup?servico=humbi&superficie=site', 'a lista pede-se uma vez, com o serviço e a superfície da zona', lista.map((p) => p.url));
    verificar(lista[0].credenciais === 'same-origin' && lista[0].cabecalhos.Accept === 'application/json', 'a lista vai com credentials same-origin e Accept JSON', lista[0]);

    verificar(await s.dialogo.getAttribute('aria-modal') === 'true', 'role="dialog" com aria-modal="true"');
    verificar((await s.dialogo.locator('h2').innerText()) === 'Entregas no mesmo dia em Luanda', 'o título do 1.º diapositivo');
    verificar(await s.pagina.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-honga-mensagens-popup')), 'o foco entra na janela ao abrir');
    verificar(s.eventos('vista').length === 0, 'antes de 1 s ainda não há «vista»');

    await espera(1200);
    const vistas = s.eventos('vista', 11);
    verificar(vistas.length === 1 && igual(vistas[0].corpo, { tipo: 'vista', ...CORPO_BASE }), '«vista» 1 s depois de abrir, com o corpo certo', vistas.map((p) => p.corpo));
    verificar(vistas[0] && vistas[0].url === '/mensagens-popup/11/eventos' && vistas[0].cabecalhos['X-CSRF-TOKEN'] === 'csrf-de-prova' && !('X-XSRF-TOKEN' in vistas[0].cabecalhos), 'o evento vai para /mensagens-popup/11/eventos com o X-CSRF-TOKEN da página', vistas[0]);
    verificar(vistas[0] && vistas[0].credenciais === 'same-origin' && vistas[0].keepalive === true, 'o evento vai com credentials same-origin e keepalive');

    await s.dialogo.locator('img.media').evaluate((img) => img.complete && img.naturalWidth > 0 || new Promise((r) => { img.onload = r; }));
    await s.captura('claro-1440-01-novidade-diapositivo-1');

    // O CSS do site não entra na janela, nem o da janela sai.
    const estilos = await s.pagina.evaluate(() => {
        const raiz = document.querySelector('[data-honga-mensagens-popup]').shadowRoot;
        const b = getComputedStyle(raiz.querySelector('.botao'));
        const p = getComputedStyle(raiz.querySelector('.texto p'));
        const site = getComputedStyle(document.getElementById('botao-do-site'));
        return { transformacao: b.textTransform, borda: b.borderTopStyle, fonte: b.fontFamily, corDoTexto: p.color, botaoDoSite: site.textTransform, fundoDoSite: site.backgroundColor };
    });
    verificar(estilos.transformacao === 'none' && estilos.borda !== 'dashed' && !/Comic/.test(estilos.fonte) && estilos.corDoTexto !== 'rgb(185, 28, 28)', 'o CSS do site não entra na janela (Shadow DOM)', estilos);
    verificar(estilos.botaoDoSite === 'uppercase' && estilos.fundoDoSite === 'rgb(255, 105, 180)', 'o CSS da janela não toca no site', estilos);
    verificar(await s.pagina.evaluate(() => document.documentElement.style.overflow) === 'hidden', 'a página por trás não faz scroll enquanto a janela está aberta');

    // O foco fica preso.
    let preso = true;
    for (let i = 0; i < 14; i++) {
        await s.pagina.keyboard.press(i % 5 === 4 ? 'Shift+Tab' : 'Tab');
        preso = preso && await s.pagina.evaluate(() => {
            const a = document.activeElement;
            return !!a && a.hasAttribute('data-honga-mensagens-popup') && !!a.shadowRoot.activeElement;
        });
    }
    verificar(preso, 'o Tab e o Shift+Tab nunca saem da janela (14 toques)');

    // A ligação do texto: regista o clique, abre noutro separador, a janela fica.
    const ligacao = s.dialogo.locator('.texto a');
    verificar(await ligacao.getAttribute('target') === '_blank' && await ligacao.getAttribute('rel') === 'noopener noreferrer', 'a ligação de fora leva target="_blank" e rel="noopener noreferrer"');
    await ligacao.click();
    await espera(400);
    const cliquesNoTexto = s.eventos('clique', 11);
    verificar(cliquesNoTexto.length === 1 && igual(cliquesNoTexto[0].corpo, { tipo: 'clique', botao: 'ligacao_texto', destino: 'https://parceiro.exemplo/zonas', ...CORPO_BASE }), '«clique» ligacao_texto com o destino', cliquesNoTexto.map((p) => p.corpo));
    verificar(await s.dialogo.isVisible() && s.eventos('fechada').length === 0, 'a janela continua aberta depois da ligação do texto');
    verificar(s.separadores.length === 1, 'a ligação abriu num separador novo', s.separadores);

    // Diapositivos.
    verificar(await s.botao('Anterior').count() === 0 && await s.botao('Seguinte').count() === 1, 'no 1.º diapositivo há «Seguinte» e não há «Anterior»');
    verificar(await s.dialogo.locator('.ponto').count() === 2 && await s.dialogo.locator('.ponto[aria-current="step"]').getAttribute('aria-label') === 'Diapositivo 1 de 2', 'o indicador marca o diapositivo 1 de 2');
    await s.botao('Seguinte').click();
    verificar((await s.dialogo.locator('h2').innerText()) === 'Acompanha o estafeta no mapa', '«Seguinte» avança para o 2.º diapositivo');
    verificar(await s.dialogo.locator('.ponto[aria-current="step"]').getAttribute('aria-label') === 'Diapositivo 2 de 2', 'o indicador acompanha');
    const rotulos = await s.dialogo.locator('.rodape .botao').allInnerTexts();
    verificar(JSON.stringify(rotulos) === JSON.stringify(['Não mostrar mais', 'Anterior', 'Ver promoções', 'Abrir a loja', 'Fechar']), 'no último: Não mostrar mais, Anterior, os dois botões da mensagem e Fechar', rotulos);
    verificar(await s.botao('Abrir a loja').evaluate((b) => b.classList.contains('cheio')) && await s.botao('Ver promoções').evaluate((b) => b.classList.contains('neutro')), 'o botão principal vai em destaque');
    await s.captura('claro-1440-02-novidade-diapositivo-2');

    await s.botao('Anterior').click();
    verificar((await s.dialogo.locator('h2').innerText()) === 'Entregas no mesmo dia em Luanda', '«Anterior» volta ao 1.º');
    await s.dialogo.locator('.ponto').nth(1).click();
    verificar((await s.dialogo.locator('h2').innerText()) === 'Acompanha o estafeta no mapa', 'o ponto do indicador também muda de diapositivo');
    verificar(s.eventos('vista').length === 1, 'mudar de diapositivo não repete a «vista»');

    // O botão principal: clique, separador novo, e passa à mensagem seguinte.
    await s.botao('Abrir a loja').click();
    await espera(300);
    const cliques = s.eventos('clique', 11);
    verificar(cliques.length === 2 && igual(cliques[1].corpo, { tipo: 'clique', botao: 'principal', destino: 'https://parceiro.exemplo/loja', ...CORPO_BASE }), '«clique» principal com o destino', cliques.map((p) => p.corpo));
    verificar(s.separadores.length === 2, 'o botão «endereço» com novo separador abriu noutro separador', s.separadores);
    verificar(s.eventos('fechada').length === 0 && s.eventos('dispensada').length === 0, 'um clique num botão não manda «fechada» nem «dispensada»');

    // A obrigatória.
    const inicio = Date.now();
    await s.pagina.locator('[data-honga-mensagens-popup="12"]').waitFor({ state: 'attached' });
    await s.dialogo.waitFor({ state: 'visible' });
    const continuar = s.dialogo.locator('.rodape .botao').last();
    const rotuloInicial = await continuar.innerText();
    verificar(/^Continuar em [23] s$/.test(rotuloInicial) && await continuar.isDisabled(), 'a obrigatória abre com «Continuar em N s» desactivado', rotuloInicial);
    verificar(await s.dialogo.locator('.fechar').count() === 0 && await s.botao('Não mostrar mais').count() === 0, 'a obrigatória não tem «x» nem «Não mostrar mais»');
    await s.captura('claro-1440-03-obrigatoria-contagem');

    await s.pagina.keyboard.press('Escape');
    await s.pagina.mouse.click(8, 8);
    await continuar.click({ force: true }).catch(() => {});
    await espera(250);
    verificar(await s.dialogo.isVisible() && s.eventos('fechada', 12).length === 0, 'durante a contagem nem o Esc, nem o clique fora, nem o botão a fecham');

    await s.dialogo.getByRole('button', { name: 'Continuar', exact: true }).waitFor({ state: 'visible', timeout: 6000 });
    const demorou = Date.now() - inicio;
    verificar(demorou >= 2500 && demorou < 5000 && await continuar.isEnabled(), 'o botão passa a «Continuar» quando a contagem de 3 s acaba (' + demorou + ' ms)');
    await s.pagina.mouse.click(8, 8);
    await espera(250);
    verificar(await s.dialogo.isVisible(), 'mesmo depois da contagem, clicar fora não fecha uma obrigatória');
    await s.captura('claro-1440-04-obrigatoria-continuar');

    await continuar.click();
    await s.pagina.locator('[data-honga-mensagens-popup]').waitFor({ state: 'detached' });
    const fechadas = s.eventos('fechada', 12);
    verificar(fechadas.length === 1 && igual(fechadas[0].corpo, { tipo: 'fechada', ...CORPO_BASE }), '«fechada» da obrigatória ao continuar, com o corpo certo', fechadas.map((p) => p.corpo));
    verificar(s.eventos('vista', 12).length === 1, 'a obrigatória também mandou a sua «vista»');
    verificar(await s.pagina.evaluate(() => document.documentElement.style.overflow) === '', 'o scroll da página volta ao que era');
    verificar(s.listas().length === 1, 'a lista não se voltou a pedir');
    verificar(s.erros.length === 0, 'sem erros na consola', s.erros);
    await s.fechar();
}

async function provaFecharEDispensar(browser) {
    console.log('\n[2] Fechar (Esc, «x», fora, botão) e dispensar');

    let s = await abrir(browser);
    await s.dialogo.waitFor({ state: 'visible' });
    await s.pagina.keyboard.press('Escape');
    await s.pagina.locator('[data-honga-mensagens-popup="12"]').waitFor({ state: 'attached' });
    verificar(s.eventos('fechada', 11).length === 1 && igual(s.eventos('fechada', 11)[0].corpo, { tipo: 'fechada', ...CORPO_BASE }), 'o Esc fecha a novidade e manda «fechada»', s.pedidos.map((p) => p.corpo));
    await espera(1300);
    verificar(s.eventos('vista', 11).length === 0, 'fechada antes de 1 s, a novidade não conta como «vista»');
    await s.dialogo.getByRole('button', { name: 'Continuar', exact: true }).waitFor({ state: 'visible', timeout: 6000 });
    await s.pagina.keyboard.press('Escape');
    await s.pagina.locator('[data-honga-mensagens-popup]').waitFor({ state: 'detached' });
    verificar(s.eventos('fechada', 12).length === 1, 'depois da contagem, o Esc fecha a obrigatória');
    await s.fechar();

    s = await abrir(browser);
    await s.dialogo.waitFor({ state: 'visible' });
    await s.botao('Não mostrar mais').click();
    await espera(200);
    const dispensadas = s.eventos('dispensada', 11);
    verificar(dispensadas.length === 1 && igual(dispensadas[0].corpo, { tipo: 'dispensada', ...CORPO_BASE }) && s.eventos('fechada', 11).length === 0, '«Não mostrar mais» manda «dispensada» (e não «fechada»)', s.pedidos.map((p) => p.corpo));
    await s.fechar();

    s = await abrir(browser, { caminho: '/?cenario=uma' });
    await s.dialogo.waitFor({ state: 'visible' });
    verificar(await s.botao('Não mostrar mais').count() === 0, 'uma mensagem não dispensável não tem «Não mostrar mais»');
    await s.pagina.mouse.click(8, 8);
    await s.pagina.locator('[data-honga-mensagens-popup]').waitFor({ state: 'detached' });
    verificar(s.eventos('fechada', 21).length === 1, 'clicar fora fecha uma mensagem que não é obrigatória');
    await s.fechar();

    s = await abrir(browser, { caminho: '/?cenario=uma' });
    await s.dialogo.waitFor({ state: 'visible' });
    await s.dialogo.locator('.fechar').click();
    await s.pagina.locator('[data-honga-mensagens-popup]').waitFor({ state: 'detached' });
    verificar(s.eventos('fechada', 21).length === 1, 'o «x» fecha');
    verificar(await s.pagina.evaluate(() => document.querySelectorAll('[data-honga-mensagens-popup]').length) === 0, 'a janela sai do documento ao fechar');
    await s.fechar();

    // O botão «página»: navega no próprio site.
    s = await abrir(browser);
    await s.dialogo.waitFor({ state: 'visible' });
    await s.botao('Seguinte').click();
    await Promise.all([s.pagina.waitForURL(SITE + '/promocoes'), s.botao('Ver promoções').click()]);
    const secundarios = s.eventos('clique', 11);
    verificar(secundarios.length === 1 && igual(secundarios[0].corpo, { tipo: 'clique', botao: 'secundario', destino: '/promocoes', ...CORPO_BASE }), '«clique» secundario e o botão «página» navega para /promocoes', secundarios.map((p) => p.corpo));
    await s.fechar();
}

async function provaNavegacao(browser) {
    console.log('\n[3] Só na página inicial da zona; a navegação do Inertia e o botão de recuar');
    const s = await abrir(browser, { caminho: '/outra?cenario=uma' });
    await espera(600);
    verificar(s.listas().length === 0 && await s.dialogo.count() === 0, 'fora da página inicial não pede a lista nem abre');

    await s.pagina.evaluate(() => { history.pushState({}, '', '/?cenario=uma'); document.dispatchEvent(new CustomEvent('inertia:navigate')); });
    await s.dialogo.waitFor({ state: 'visible' });
    verificar(s.listas().length === 1, 'ao chegar à página inicial pelo Inertia, pede a lista e abre');
    await s.pagina.keyboard.press('Escape');
    await s.pagina.locator('[data-honga-mensagens-popup]').waitFor({ state: 'detached' });

    await s.pagina.evaluate(() => { history.pushState({}, '', '/outra?cenario=uma'); document.dispatchEvent(new CustomEvent('inertia:navigate')); });
    await s.pagina.evaluate(() => { history.pushState({}, '', '/?cenario=uma'); document.dispatchEvent(new CustomEvent('inertia:navigate')); });
    await s.pagina.evaluate(() => history.back());
    await s.pagina.evaluate(() => history.forward());
    await espera(700);
    verificar(s.listas().length === 1 && await s.dialogo.count() === 0, 'de volta à página inicial (Inertia e popstate), não pede outra vez nem reabre');
    await s.fechar();

    const t = await abrir(browser, { caminho: '/?cenario=uma&tutorial=1' });
    await espera(600);
    verificar(t.listas().length === 0 && await t.dialogo.count() === 0, 'com ?tutorial no endereço não abre (como no monólito)');
    await t.fechar();

    const z = await abrir(browser, { caminho: '/painel?cenario=uma' });
    await espera(600);
    verificar(z.listas().length === 0, 'a zona com domínios não vale noutro domínio');
    await z.pagina.goto('https://negocio.humbi.exemplo/painel/?cenario=uma');
    await z.dialogo.waitFor({ state: 'visible' });
    verificar(z.listas().length === 1 && z.listas()[0].url === '/mensagens-popup?servico=humbi-negocio&superficie=painel', 'no domínio dela, a segunda zona pede com o seu serviço e a sua superfície (e a barra final não conta)', z.listas().map((p) => p.url));
    await z.fechar();
}

async function provaSeguranca(browser) {
    console.log('\n[4] Conteúdo hostil');
    const s = await abrir(browser, { caminho: '/?cenario=hostil' });
    await s.dialogo.waitFor({ state: 'visible' });
    await espera(300);
    const r = await s.pagina.evaluate(() => {
        const raiz = document.querySelector('[data-honga-mensagens-popup]').shadowRoot;
        const texto = raiz.querySelector('.texto');
        return {
            xss: window.__xss,
            etiquetas: Array.from(new Set(Array.from(texto.querySelectorAll('*')).map((e) => e.tagName))).sort(),
            atributos: Array.from(new Set(Array.from(texto.querySelectorAll('*')).flatMap((e) => Array.from(e.attributes).map((a) => a.name)))).sort(),
            ligacoes: Array.from(texto.querySelectorAll('a')).map((a) => [a.getAttribute('href'), a.getAttribute('target'), a.getAttribute('rel')]),
            titulo: raiz.querySelector('h2').textContent,
            filhosDoTitulo: raiz.querySelector('h2').children.length,
            imagens: raiz.querySelectorAll('img').length,
            temTexto: texto.textContent.includes('cabeçalho desembrulhado') && !texto.textContent.includes('__xss=3') && !texto.textContent.includes('display:none'),
        };
    });
    verificar(r.xss === undefined, 'nenhum script do conteúdo correu', r.xss);
    verificar(JSON.stringify(r.etiquetas) === JSON.stringify(['A', 'B', 'P']), 'no texto só ficam as etiquetas permitidas', r.etiquetas);
    verificar(JSON.stringify(r.atributos) === JSON.stringify(['href', 'rel', 'target']), 'e nenhum atributo além do href (com target e rel nas de fora)', r.atributos);
    verificar(JSON.stringify(r.ligacoes) === JSON.stringify([['/ajuda', null, null], ['https://parceiro.exemplo/fora', '_blank', 'noopener noreferrer'], ['mailto:apoio@exemplo.ao', null, null]]), 'as ligações javascript: e //… desaparecem; ficam a de dentro, a de fora e o mailto:', r.ligacoes);
    verificar(r.filhosDoTitulo === 0 && r.titulo.includes('<img'), 'o título é texto simples', r.titulo);
    verificar(r.imagens === 0, 'uma imagem que não é https não se desenha');
    verificar(r.temTexto, 'o que não é permitido é desembrulhado (fica o texto), e o script e o style somem com o conteúdo');

    await s.botao('Botão mau').click();
    await espera(400);
    verificar(await s.pagina.evaluate(() => window.__xss) === undefined && s.pagina.url() === SITE + '/?cenario=hostil', 'um botão com destino javascript: não faz nada (só regista o clique e fecha)');
    await s.fechar();

    const p = await abrir(browser, { caminho: '/?cenario=hostil' });
    await p.dialogo.waitFor({ state: 'visible' });
    await p.botao('Página de fora').click();
    await espera(500);
    verificar(p.pagina.url() === SITE + '/?cenario=hostil', 'um botão «página» com //outro-site não sai do site');
    verificar(p.erros.length === 0 && s.erros.length === 0, 'sem erros na consola', p.erros.concat(s.erros));
    await p.fechar();
}

async function provaFalhas(browser) {
    console.log('\n[5] Falhas: silêncio total');
    for (const cenario of ['falha', 'erro', 'lixo']) {
        const s = await abrir(browser, { caminho: '/?cenario=' + cenario });
        await espera(700);
        verificar(s.listas().length === 1 && await s.dialogo.count() === 0 && s.erros.length === 0, 'cenário «' + cenario + '»: nada aparece e nada parte', s.erros);
        await s.fechar();
    }
}

async function provaVideo(browser) {
    console.log('\n[6] Vídeo');
    let s = await abrir(browser, { caminho: '/?cenario=video', hls: 'abortar', hlsNativo: true });
    await s.dialogo.waitFor({ state: 'visible' });
    verificar(await s.dialogo.locator('video').getAttribute('src') === 'https://cdn.exemplo/video/mestre.m3u8' && await s.pagina.evaluate(() => window.Hls) === undefined, 'HLS com suporte nativo: vai directo no <video>, sem o hls.js');
    await s.dialogo.locator('video').evaluate((el) => el.dispatchEvent(new Event('error')));
    await s.dialogo.locator('img.media').waitFor({ state: 'visible' });
    verificar(await s.dialogo.locator('video').count() === 0, 'se o vídeo falhar a tocar, fica só a capa');
    await s.fechar();

    s = await abrir(browser, { caminho: '/?cenario=video', hls: 'abortar', hlsNativo: false });
    await s.dialogo.waitFor({ state: 'visible' });
    await s.dialogo.locator('img.media').waitFor({ state: 'visible' });
    verificar(await s.dialogo.locator('video').count() === 0 && await s.dialogo.locator('img.media').getAttribute('src') === 'https://cdn.exemplo/capa.svg', 'HLS sem suporte nativo e sem o hls.js (bloqueado): fica só a capa e o texto');
    verificar((await s.dialogo.locator('.texto').innerText()).includes('Dois minutos'), 'o texto continua lá');
    await s.captura('claro-1440-05-video-so-a-capa');
    verificar(s.erros.length === 0, 'sem erros na consola', s.erros);
    await s.fechar();

    s = await abrir(browser, { caminho: '/?cenario=video', hls: 'simular', hlsNativo: false });
    await s.dialogo.waitFor({ state: 'visible' });
    await s.pagina.waitForFunction(() => window.__hlsFonte);
    const v = s.dialogo.locator('video');
    verificar(await s.pagina.evaluate(() => window.__hlsFonte) === 'https://cdn.exemplo/video/mestre.m3u8' && await s.pagina.evaluate(() => window.__hlsLigado) === 'VIDEO', 'HLS sem suporte nativo: o hls.js vem do cdnjs a pedido e liga-se ao <video>');
    verificar(await v.getAttribute('poster') === 'https://cdn.exemplo/capa.svg' && await v.getAttribute('controls') !== null && await v.getAttribute('playsinline') !== null, 'o <video> leva controls, playsinline e a capa');

    // O progresso: no máximo de 10 em 10 segundos; o fim manda o progresso final e «video_fim».
    await v.evaluate((el) => {
        let t = 0;
        Object.defineProperty(el, 'currentTime', { get: () => t, set: (x) => { t = x; }, configurable: true });
        Object.defineProperty(el, 'duration', { get: () => 42, configurable: true });
        for (const s of [3, 9, 10, 14, 19.9, 20, 25]) { t = s; el.dispatchEvent(new Event('timeupdate')); }
        t = 42; el.dispatchEvent(new Event('ended'));
    });
    await espera(200);
    const progressos = s.eventos('video_progresso', 41).map((p) => p.corpo.segundos);
    verificar(JSON.stringify(progressos) === JSON.stringify([10, 20, 42]), '«video_progresso» aos 10 s, aos 20 s e no fim', progressos);
    verificar(s.eventos('video_fim', 41).length === 1 && igual(s.eventos('video_fim', 41)[0].corpo, { tipo: 'video_fim', ...CORPO_BASE }), '«video_fim» no fim');
    await s.captura('claro-1440-06-video-hls');

    await s.botao('Seguinte').click();
    verificar(await s.pagina.evaluate(() => window.__hlsDestruido) === true, 'ao mudar de diapositivo, o leitor do anterior é desligado');
    verificar(await s.dialogo.locator('video').getAttribute('src') === 'https://cdn.exemplo/video/filme.mp4', 'o MP4 vai directo no <video>');
    await s.fechar();
}

async function provaTemas(browser) {
    console.log('\n[7] 1440×900 escuro');
    let s = await abrir(browser, { esquema: 'dark' });
    await s.dialogo.waitFor({ state: 'visible' });
    const tema = () => s.pagina.locator('[data-honga-mensagens-popup]').getAttribute('data-tema');
    const fundo = () => s.dialogo.evaluate((c) => getComputedStyle(c).backgroundColor);
    verificar(await tema() === 'escuro' && await fundo() === 'rgb(24, 24, 27)', 'com prefers-color-scheme: dark, a janela é escura');
    await s.dialogo.locator('img.media').evaluate((img) => img.complete && img.naturalWidth > 0 || new Promise((r) => { img.onload = r; }));
    await s.captura('escuro-1440-01-novidade-diapositivo-1');
    await s.botao('Seguinte').click();
    await s.captura('escuro-1440-02-novidade-diapositivo-2');
    await s.botao('Fechar').click();
    await s.pagina.locator('[data-honga-mensagens-popup="12"]').waitFor({ state: 'attached' });
    await s.captura('escuro-1440-03-obrigatoria-contagem');
    await s.fechar();

    s = await abrir(browser, { esquema: 'light', caminho: '/?classe=dark' });
    await s.dialogo.waitFor({ state: 'visible' });
    verificar(await tema() === 'escuro', 'com o sistema claro mas <html class="dark">, a janela é escura');
    await s.pagina.evaluate(() => document.documentElement.classList.remove('dark'));
    await espera(100);
    verificar(await tema() === 'claro' && await fundo() === 'rgb(255, 255, 255)', 'e acompanha a troca de tema do site com a janela aberta');
    await s.fechar();

    s = await abrir(browser, { esquema: 'dark', caminho: '/?classe=light' });
    await s.dialogo.waitFor({ state: 'visible' });
    verificar(await tema() === 'claro', 'com o sistema escuro mas <html class="light">, a janela é clara');
    await s.fechar();

    s = await abrir(browser, { semMovimento: true });
    await s.dialogo.waitFor({ state: 'visible' });
    verificar(await s.dialogo.evaluate((c) => getComputedStyle(c).animationName) === 'none', 'com prefers-reduced-motion, a janela entra sem animação');
    await s.fechar();
}

async function provaTelemovel(browser) {
    console.log('\n[8] 390×844 (telemóvel)');
    const medir = (s) => s.pagina.evaluate(() => {
        const raiz = document.querySelector('[data-honga-mensagens-popup]').shadowRoot;
        const c = raiz.querySelector('.cartao').getBoundingClientRect();
        const alvos = Array.from(raiz.querySelectorAll('button')).map((b) => { const r = b.getBoundingClientRect(); return [b.textContent || b.getAttribute('aria-label'), Math.round(r.width), Math.round(r.height), Math.round(r.left), Math.round(r.right)]; });
        const corpo = raiz.querySelector('.corpo');
        return {
            esquerda: Math.round(c.left), direita: Math.round(c.right), topo: Math.round(c.top), fundo: Math.round(c.bottom), alvos,
            larguraDoDocumento: document.documentElement.scrollWidth, larguraDoCorpo: corpo.scrollWidth - corpo.clientWidth,
        };
    });
    const conferir = (m, onde) => {
        verificar(m.esquerda === 16 && m.direita === 374, onde + ': o cartão ocupa a largura com 16 px de margem', [m.esquerda, m.direita]);
        verificar(m.topo >= 16 && m.fundo <= 844 - 16, onde + ': o cartão cabe na altura do ecrã', [m.topo, m.fundo]);
        verificar(m.alvos.every((a) => a[2] >= 44), onde + ': todos os alvos de toque têm pelo menos 44 px de altura', m.alvos);
        verificar(m.alvos.every((a) => a[3] >= 16 && a[4] <= 374), onde + ': nenhum botão sai do cartão', m.alvos);
        verificar(m.larguraDoDocumento <= 390 && m.larguraDoCorpo <= 0, onde + ': sem scroll horizontal', [m.larguraDoDocumento, m.larguraDoCorpo]);
    };

    let s = await abrir(browser, { viewport: { width: 390, height: 844 }, telemovel: true });
    await s.dialogo.waitFor({ state: 'visible' });
    await s.dialogo.locator('img.media').evaluate((img) => img.complete && img.naturalWidth > 0 || new Promise((r) => { img.onload = r; }));
    await s.captura('telemovel-390-01-novidade-diapositivo-1');
    conferir(await medir(s), '1.º diapositivo');
    await s.botao('Seguinte').tap();
    await s.captura('telemovel-390-02-novidade-diapositivo-2');
    conferir(await medir(s), '2.º diapositivo');
    await s.botao('Fechar').tap();
    await s.pagina.locator('[data-honga-mensagens-popup="12"]').waitFor({ state: 'attached' });
    await s.captura('telemovel-390-03-obrigatoria-contagem');
    conferir(await medir(s), 'obrigatória');
    await s.fechar();

    s = await abrir(browser, { viewport: { width: 390, height: 844 }, telemovel: true, esquema: 'dark' });
    await s.dialogo.waitFor({ state: 'visible' });
    await s.botao('Seguinte').tap();
    await s.captura('telemovel-390-04-escuro-diapositivo-2');
    await s.fechar();

    // Um ecrã baixo: o corpo faz scroll e o rodapé fica à vista.
    s = await abrir(browser, { viewport: { width: 390, height: 480 }, telemovel: true });
    await s.dialogo.waitFor({ state: 'visible' });
    await espera(400);
    const baixo = await s.pagina.evaluate(() => {
        const raiz = document.querySelector('[data-honga-mensagens-popup]').shadowRoot;
        const c = raiz.querySelector('.cartao').getBoundingClientRect();
        const r = raiz.querySelector('.rodape').getBoundingClientRect();
        const corpo = raiz.querySelector('.corpo');
        return { fundo: c.bottom, rodape: r.bottom, rola: corpo.scrollHeight > corpo.clientHeight };
    });
    verificar(baixo.fundo <= 480 - 16 && baixo.rodape <= 480 - 16 && baixo.rola, 'num ecrã baixo (390×480) o rodapé fica fixo à vista e o corpo é que faz scroll', baixo);
    await s.captura('telemovel-390x480-05-ecra-baixo');
    await s.fechar();
}

async function provaCsrf(browser) {
    console.log('\n[9] CSRF com o cookie XSRF-TOKEN');
    const s = await abrir(browser, { caminho: '/?cenario=uma', cookies: [{ name: 'XSRF-TOKEN', value: 'do%3Dcookie', url: SITE }] });
    await s.dialogo.waitFor({ state: 'visible' });
    await s.pagina.keyboard.press('Escape');
    await espera(200);
    const c = (s.eventos('fechada', 21)[0] || {}).cabecalhos || {};
    verificar(c['X-XSRF-TOKEN'] === 'do=cookie' && !('X-CSRF-TOKEN' in c), 'havendo cookie XSRF-TOKEN, vai o X-XSRF-TOKEN (descodificado) e não o da página', c);
    await s.fechar();
}

(async () => {
    fs.mkdirSync(CAPTURAS, { recursive: true });
    const so = process.argv[2];
    const browser = await chromium.launch({ headless: true });
    try {
        const provas = { principal: provaPrincipal, fechar: provaFecharEDispensar, navegacao: provaNavegacao, seguranca: provaSeguranca, falhas: provaFalhas, video: provaVideo, temas: provaTemas, telemovel: provaTelemovel, csrf: provaCsrf };
        for (const nome of Object.keys(provas)) {
            if (!so || so === nome) {
                await provas[nome](browser);
            }
        }
    } catch (e) {
        falhas.push('a prova parou: ' + (e && e.stack ? e.stack : e));
        console.log('FALHA a prova parou: ' + (e && e.stack ? e.stack : e));
    } finally {
        await browser.close();
    }
    console.log('\n' + (feitas - falhas.length) + ' de ' + feitas + ' verificações certas' + (falhas.length ? '; ' + falhas.length + ' falhas:' : '.'));
    falhas.forEach((f) => console.log(' - ' + f));
    process.exit(falhas.length ? 1 : 0);
})();
