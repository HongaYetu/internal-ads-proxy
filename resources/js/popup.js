/*!
 * As mensagens em popup da Honga Yetu nos sites dos serviços.
 * hongayetu/internal-ads-proxy · JavaScript puro (ES2019), sem dependências nem build.
 *
 * Quem o carrega é a directiva Blade `@mensagensPopupHonga`:
 *   <script defer src="/mensagens-popup/popup.js?v=…" data-raiz="/mensagens-popup"
 *           data-zonas='[{"servico":"humbi","superficie":"site","caminhos":["/"],"anfitrioes":[]}]'
 *           data-csrf="…"></script>
 *
 * - Só pede a lista quando a página (domínio + caminho) é a página inicial de uma zona; reage à
 *   navegação do Inertia e ao botão de recuar. Uma vez por zona e por carregamento do script.
 * - Mostra uma mensagem de cada vez, dentro de um Shadow DOM: o CSS do site não lhe toca, nem o dela
 *   ao site.
 * - Nunca parte a página: qualquer falha (rede, resposta estranha, erro aqui dentro) dá em silêncio.
 *
 * O comportamento segue o `AnfitriaoDeMensagens` do monólito (resources/js/Logigate/ui/mensagens).
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof document === 'undefined' || window.__hongaMensagensPopup) {
        return;
    }
    window.__hongaMensagensPopup = true;

    var MS_PARA_VISTA = 1000;
    var MS_DA_SAIDA = 170;
    var MS_ENTRE_MENSAGENS = 350;
    var SEGUNDOS_ENTRE_PROGRESSOS = 10;
    var HLS_JS = 'https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.17/hls.min.js';
    var SUPERFICIES = ['site', 'painel', 'portal'];

    var etiqueta = document.currentScript || document.querySelector('script[data-zonas][data-raiz]');
    if (!etiqueta) {
        return;
    }

    var raiz = String(etiqueta.getAttribute('data-raiz') || '').replace(/\/+$/, '');
    var csrf = etiqueta.getAttribute('data-csrf') || '';
    var zonas = lerZonas(etiqueta.getAttribute('data-zonas'));
    if (!raiz || zonas.length === 0) {
        return;
    }

    /* ------------------------------------------------------------------ zonas */

    function semBarraFinal(caminho) {
        return String(caminho || '').replace(/\/+$/, '') || '/';
    }

    function lerZonas(json) {
        var lista;
        try {
            lista = JSON.parse(json || '[]');
        } catch (e) {
            return [];
        }
        if (!Array.isArray(lista)) {
            return [];
        }
        return lista
            .filter(function (z) {
                return z && typeof z.servico === 'string' && z.servico && SUPERFICIES.indexOf(z.superficie) !== -1;
            })
            .map(function (z) {
                var caminhos = Array.isArray(z.caminhos) && z.caminhos.length ? z.caminhos : ['/'];
                var anfitrioes = Array.isArray(z.anfitrioes) ? z.anfitrioes : [];
                return {
                    servico: z.servico,
                    superficie: z.superficie,
                    chave: z.servico + '|' + z.superficie,
                    caminhos: caminhos.map(semBarraFinal),
                    anfitrioes: anfitrioes.map(function (a) {
                        return String(a).toLowerCase();
                    }),
                };
            });
    }

    /** A zona de que a página actual é a página inicial, ou `null`. Com `?tutorial` no endereço, nenhuma. */
    function zonaActual() {
        var anfitriao = String(window.location.hostname || '').toLowerCase();
        var caminho = semBarraFinal(window.location.pathname);
        if (/[?&]tutorial(=|&|$)/.test(window.location.search || '')) {
            return null;
        }
        for (var i = 0; i < zonas.length; i++) {
            var z = zonas[i];
            var noDominio = z.anfitrioes.length === 0 || z.anfitrioes.indexOf(anfitriao) !== -1;
            if (noDominio && z.caminhos.indexOf(caminho) !== -1) {
                return z;
            }
        }
        return null;
    }

    /* --------------------------------------------------------- texto seguro */

    var PERMITIDAS = { P: 1, BR: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, UL: 1, OL: 1, LI: 1, A: 1 };
    var DESCARTADAS = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, TEMPLATE: 1, NOSCRIPT: 1 };

    /** Um caminho relativo do próprio sítio (`/…`, nunca `//…`), ou `null`. */
    function caminhoInterno(url) {
        var c = String(url == null ? '' : url).trim();
        return c.charAt(0) === '/' && c.slice(0, 2) !== '//' && c.indexOf('\\') === -1 && c.length <= 500 ? c : null;
    }

    /** O endereço permitido de uma ligação (`http(s)`, `mailto:`, `tel:` ou caminho relativo), ou `null`. */
    function enderecoSeguro(url) {
        // eslint-disable-next-line no-control-regex
        var limpo = String(url == null ? '' : url).replace(/[\u0000- \u007F]+/g, '');
        if (!limpo || limpo.length > 500) {
            return null;
        }
        if (/^(https?:\/\/|mailto:|tel:)/i.test(limpo)) {
            return limpo;
        }
        return caminhoInterno(limpo);
    }

    /** Imagens, vídeos e capas: só `https`. */
    function soHttps(url) {
        var limpo = typeof url === 'string' ? url.trim() : '';
        return /^https:\/\//i.test(limpo) && limpo.length <= 2000 ? limpo : null;
    }

    function limparFilhos(no) {
        Array.prototype.slice.call(no.childNodes).forEach(function (filho) {
            if (filho.nodeType === 3) {
                return;
            }
            if (filho.nodeType !== 1) {
                no.removeChild(filho);
                return;
            }
            var nome = String(filho.tagName).toUpperCase();
            if (DESCARTADAS[nome] === 1) {
                no.removeChild(filho);
                return;
            }
            limparFilhos(filho);
            var desembrulhar = function () {
                while (filho.firstChild) {
                    no.insertBefore(filho.firstChild, filho);
                }
                no.removeChild(filho);
            };
            if (PERMITIDAS[nome] !== 1) {
                desembrulhar();
                return;
            }
            var href = nome === 'A' ? enderecoSeguro(filho.getAttribute('href')) : null;
            if (nome === 'A' && href === null) {
                desembrulhar();
                return;
            }
            Array.prototype.slice.call(filho.attributes).forEach(function (a) {
                filho.removeAttribute(a.name);
            });
            if (href !== null) {
                filho.setAttribute('href', href);
                if (/^https?:\/\//i.test(href)) {
                    filho.setAttribute('target', '_blank');
                    filho.setAttribute('rel', 'noopener noreferrer');
                }
            }
        });
    }

    /**
     * O HTML de um diapositivo, limpo outra vez (o servidor já o limpou): devolve um fragmento com os
     * nós já seguros, para não se escrever HTML em lado nenhum.
     */
    function textoSeguro(html) {
        var fragmento = document.createDocumentFragment();
        var texto = String(html == null ? '' : html).trim();
        if (!texto || typeof DOMParser === 'undefined') {
            return fragmento;
        }
        var doc = new DOMParser().parseFromString('<div id="raiz">' + texto + '</div>', 'text/html');
        var el = doc.getElementById('raiz');
        if (!el) {
            return fragmento;
        }
        limparFilhos(el);
        while (el.firstChild) {
            fragmento.appendChild(document.importNode(el.firstChild, true));
            el.removeChild(el.firstChild);
        }
        return fragmento;
    }

    /* -------------------------------------------------------------- pedidos */

    function cookie(nome) {
        var partes = ('; ' + (document.cookie || '')).split('; ' + nome + '=');
        if (partes.length < 2) {
            return '';
        }
        try {
            return decodeURIComponent(partes.pop().split(';').shift());
        } catch (e) {
            return '';
        }
    }

    function pedir(url, opcoes) {
        try {
            if (typeof window.fetch !== 'function') {
                return Promise.reject(new Error('sem fetch'));
            }
            return window.fetch(url, opcoes);
        } catch (e) {
            return Promise.reject(e);
        }
    }

    /**
     * Um evento de uma mensagem. Não se espera pela resposta e uma falha fica calada.
     *
     * O Laravel lê o `X-CSRF-TOKEN` antes do `X-XSRF-TOKEN`; o do cookie é sempre o mais recente (o da
     * página envelhece quando a sessão se renova sem recarregar), por isso, havendo cookie, vai só ele.
     */
    function enviar(zona, id, tipo, dados) {
        var corpo = { tipo: tipo, servico: zona.servico, superficie: zona.superficie };
        Object.keys(dados || {}).forEach(function (k) {
            corpo[k] = dados[k];
        });
        var cabecalhos = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        var xsrf = cookie('XSRF-TOKEN');
        if (xsrf) {
            cabecalhos['X-XSRF-TOKEN'] = xsrf;
        } else if (csrf) {
            cabecalhos['X-CSRF-TOKEN'] = csrf;
        }
        pedir(raiz + '/' + encodeURIComponent(id) + '/eventos', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: cabecalhos,
            body: JSON.stringify(corpo),
        }).catch(function () {});
    }

    /* ----------------------------------------------------------------- fila */

    var zonasPedidas = {};
    var jaMostradas = {};
    /** @type {{mensagem: object, zona: object}[]} */
    var fila = [];
    var janela = null;

    function mensagemValida(m) {
        return (
            m &&
            (typeof m.id === 'number' || (typeof m.id === 'string' && /^\d+$/.test(m.id))) &&
            Array.isArray(m.diapositivos) &&
            m.diapositivos.length > 0
        );
    }

    function avaliar() {
        var zona = zonaActual();
        if (!zona) {
            return;
        }
        if (zonasPedidas[zona.chave] !== true) {
            zonasPedidas[zona.chave] = true;
            pedirLista(zona);
            return;
        }
        tentarAbrir();
    }

    function pedirLista(zona) {
        var url =
            raiz + '?servico=' + encodeURIComponent(zona.servico) + '&superficie=' + encodeURIComponent(zona.superficie);
        pedir(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (r) {
                return r && r.ok ? r.json() : null;
            })
            .then(function (dados) {
                if (!dados || dados.estado !== 'ok' || !Array.isArray(dados.mensagens)) {
                    return;
                }
                dados.mensagens.forEach(function (m) {
                    if (mensagemValida(m) && jaMostradas[m.id] !== true) {
                        jaMostradas[m.id] = true;
                        fila.push({ mensagem: m, zona: zona });
                    }
                });
                tentarAbrir();
            })
            .catch(function () {});
    }

    /** Abre a primeira da fila, se não houver nenhuma aberta e a página ainda for a inicial da zona dela. */
    function tentarAbrir() {
        if (janela || fila.length === 0) {
            return;
        }
        var zona = zonaActual();
        if (!zona || zona.chave !== fila[0].zona.chave) {
            return;
        }
        try {
            janela = abrirJanela(fila[0].mensagem, fila[0].zona, function () {
                janela = null;
                fila.shift();
                window.setTimeout(seguro(tentarAbrir), MS_ENTRE_MENSAGENS);
            });
        } catch (e) {
            janela = null;
            fila.shift();
        }
    }

    function seguro(fn) {
        return function () {
            try {
                return fn.apply(this, arguments);
            } catch (e) {
                return undefined;
            }
        };
    }

    /* --------------------------------------------------------------- estilo */

    var CSS = [
        ':host{all:initial;position:fixed;inset:0;z-index:2147483000;display:block;',
        'font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;',
        'font-size:16px;line-height:1.5;-webkit-text-size-adjust:100%;',
        '--destaque:var(--mensagens-popup-destaque,#18181b);--sobre-destaque:var(--mensagens-popup-sobre-destaque,#fff);',
        '--fundo:#fff;--texto:#18181b;--suave:#52525b;--linha:#e4e4e7;--neutro:#f4f4f5;--neutro-forte:#e4e4e7;',
        '--ligacao:#2563eb;--ponto:#d4d4d8;--ponto-activo:#27272a;--foco:#a1a1aa;--veu:rgba(9,9,11,.55);',
        '--sombra:0 24px 60px -12px rgba(0,0,0,.35),0 0 0 1px rgba(0,0,0,.05)}',
        ':host([data-tema="escuro"]){',
        '--destaque:var(--mensagens-popup-destaque-escuro,var(--mensagens-popup-destaque,#f4f4f5));',
        '--sobre-destaque:var(--mensagens-popup-sobre-destaque-escuro,var(--mensagens-popup-sobre-destaque,#18181b));',
        '--fundo:#18181b;--texto:#fafafa;--suave:#a1a1aa;--linha:#27272a;--neutro:#27272a;--neutro-forte:#3f3f46;',
        '--ligacao:#60a5fa;--ponto:#52525b;--ponto-activo:#f4f4f5;--foco:#71717a;--veu:rgba(0,0,0,.7);',
        '--sombra:0 24px 60px -12px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.08)}',
        '*,*::before,*::after{box-sizing:border-box}',
        '.veu{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:16px;',
        'background:var(--veu);overflow:hidden;animation:veu .18s ease-out both}',
        '.cartao{position:relative;display:flex;flex-direction:column;width:100%;max-width:520px;',
        'max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);background:var(--fundo);color:var(--texto);',
        'border-radius:16px;box-shadow:var(--sombra);outline:none;overflow:hidden;',
        'animation:cartao .2s cubic-bezier(.2,.8,.2,1) both}',
        ':host([data-saida]) .veu{animation:veu-sai .16s ease-in both}',
        ':host([data-saida]) .cartao{animation:cartao-sai .16s ease-in both}',
        '@keyframes veu{from{opacity:0}to{opacity:1}}',
        '@keyframes veu-sai{from{opacity:1}to{opacity:0}}',
        '@keyframes cartao{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}',
        '@keyframes cartao-sai{from{opacity:1;transform:none}to{opacity:0;transform:translateY(6px) scale(.985)}}',
        '.topo{display:flex;align-items:flex-start;gap:8px;padding:18px 12px 0 22px;flex:none}',
        '.topo.sem-titulo{padding-top:8px}',
        '.titulo{flex:1 1 auto;min-width:0;margin:0;padding:6px 0 4px;font-size:18px;line-height:1.35;font-weight:650;',
        'letter-spacing:-.01em;overflow-wrap:anywhere}',
        '.fechar{flex:none;margin-left:auto;width:44px;height:44px;display:inline-flex;align-items:center;',
        'justify-content:center;border:0;border-radius:10px;background:transparent;color:var(--suave);cursor:pointer;',
        'font:inherit;-webkit-tap-highlight-color:transparent}',
        '.fechar:hover{background:var(--neutro);color:var(--texto)}',
        '.fechar svg{width:18px;height:18px;display:block}',
        '.corpo{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;',
        'padding:10px 22px 18px;display:flex;flex-direction:column;gap:14px}',
        '.topo.sem-titulo+.corpo{padding-top:4px}',
        '.media{display:block;width:100%;border-radius:12px;background:var(--neutro)}',
        'img.media{max-height:288px;object-fit:cover}',
        'video.media{aspect-ratio:16/9;background:#000}',
        '.texto{font-size:15px;line-height:1.6;color:var(--suave);overflow-wrap:anywhere}',
        '.texto p{margin:0}.texto p+p{margin-top:10px}',
        '.texto ul,.texto ol{margin:8px 0 0;padding-left:22px}.texto li+li{margin-top:2px}',
        '.texto strong,.texto b{color:var(--texto);font-weight:600}',
        '.texto a{color:var(--ligacao);text-decoration:underline;text-underline-offset:2px}',
        '.pontos{display:flex;align-items:center;justify-content:center;gap:2px;flex:none;margin:-8px 0 -16px}',
        '.ponto{width:28px;height:44px;display:inline-flex;align-items:center;justify-content:center;border:0;padding:0;',
        'background:transparent;border-radius:10px;cursor:pointer;-webkit-tap-highlight-color:transparent}',
        '.ponto span{display:block;width:6px;height:6px;border-radius:999px;background:var(--ponto);',
        'transition:width .18s ease,background-color .18s ease}',
        '.ponto[aria-current="step"] span{width:20px;background:var(--ponto-activo)}',
        // `wrap-reverse`: quando as acções não cabem ao lado do «Não mostrar mais», ficam elas por cima.
        '.rodape{flex:none;display:flex;flex-wrap:wrap-reverse;align-items:center;gap:8px;padding:14px 22px 18px;',
        'border-top:1px solid var(--linha)}',
        '.accoes{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:8px;margin-left:auto}',
        '.botao{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:0 16px;',
        'border:1px solid transparent;border-radius:10px;font:inherit;font-size:15px;font-weight:550;line-height:1.2;',
        'cursor:pointer;text-align:center;white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis;',
        'font-variant-numeric:tabular-nums;-webkit-tap-highlight-color:transparent;',
        'transition:background-color .12s ease,opacity .12s ease}',
        '.botao svg{width:16px;height:16px;flex:none}',
        '.cheio{background:var(--destaque);color:var(--sobre-destaque)}.cheio:hover{opacity:.88}',
        '.neutro{background:var(--neutro);color:var(--texto)}.neutro:hover{background:var(--neutro-forte)}',
        '.fantasma{background:transparent;color:var(--suave);padding:0 10px}',
        '.fantasma:hover{background:var(--neutro);color:var(--texto)}',
        '.botao:disabled{opacity:.55;cursor:default}.cheio:disabled:hover{opacity:.55}',
        '.neutro:disabled:hover{background:var(--neutro)}',
        '.botao:focus-visible,.fechar:focus-visible,.ponto:focus-visible,.texto a:focus-visible{',
        'outline:2px solid var(--foco);outline-offset:2px}',
        '@media (max-width:520px){',
        '.topo{padding:14px 8px 0 18px}.corpo{padding:8px 18px 16px}',
        '.rodape{flex-direction:column-reverse;align-items:stretch;padding:12px 18px 16px}',
        '.accoes{margin-left:0;width:100%}.accoes .botao{flex:1 1 auto}',
        '.fantasma{width:100%}}',
        '@media (prefers-reduced-motion:reduce){.veu,.cartao,:host([data-saida]) .veu,:host([data-saida]) .cartao{',
        'animation:none}.ponto span,.botao{transition:none}}',
    ].join('');

    var ICONES = {
        fechar: 'M6 6l12 12M18 6L6 18',
        anterior: 'M19 12H5M11 6l-6 6 6 6',
        seguinte: 'M5 12h14M13 6l6 6-6 6',
    };

    function icone(nome) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        var caminho = document.createElementNS(ns, 'path');
        caminho.setAttribute('d', ICONES[nome]);
        svg.appendChild(caminho);
        return svg;
    }

    function el(nome, atributos, filhos) {
        var no = document.createElement(nome);
        Object.keys(atributos || {}).forEach(function (k) {
            var v = atributos[k];
            if (v === null || v === undefined || v === false) {
                return;
            }
            if (k === 'className') {
                no.className = v;
            } else {
                no.setAttribute(k, v === true ? '' : String(v));
            }
        });
        (filhos || []).forEach(function (f) {
            if (f === null || f === undefined || f === false) {
                return;
            }
            no.appendChild(typeof f === 'string' ? document.createTextNode(f) : f);
        });
        return no;
    }

    function botao(variante, rotulo, aoClicar, extras) {
        var b = el('button', { type: 'button', className: 'botao ' + variante }, []);
        if (extras && extras.antes) {
            b.appendChild(icone(extras.antes));
        }
        b.appendChild(el('span', {}, [rotulo]));
        if (extras && extras.depois) {
            b.appendChild(icone(extras.depois));
        }
        b.addEventListener('click', seguro(aoClicar));
        return b;
    }

    function temaEscuro() {
        var classes = document.documentElement.classList;
        if (classes.contains('dark')) {
            return true;
        }
        if (classes.contains('light')) {
            return false;
        }
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    /* ----------------------------------------------------------------- vídeo */

    var hlsACarregar = null;

    function carregarHls() {
        if (window.Hls) {
            return Promise.resolve(window.Hls);
        }
        if (!hlsACarregar) {
            hlsACarregar = new Promise(function (resolver, rejeitar) {
                var s = document.createElement('script');
                s.src = HLS_JS;
                s.async = true;
                s.crossOrigin = 'anonymous';
                s.onload = function () {
                    if (window.Hls) {
                        resolver(window.Hls);
                    } else {
                        rejeitar(new Error('hls.js'));
                    }
                };
                s.onerror = function () {
                    hlsACarregar = null;
                    rejeitar(new Error('hls.js'));
                };
                document.head.appendChild(s);
            });
        }
        return hlsACarregar;
    }

    /**
     * O vídeo de um diapositivo. Devolve `{no, destruir}`, ou `null` se o endereço não servir.
     * Se o HLS não tocar (nem nativo, nem com o hls.js), fica só a capa.
     */
    function criarVideo(video, aoProgresso, aoFim) {
        var url = video && (video.tipo === 'hls' || video.tipo === 'mp4') ? soHttps(video.url) : null;
        var capa = video ? soHttps(video.capa) : null;
        if (!url) {
            return capa ? { no: el('img', { className: 'media', src: capa, alt: '' }), destruir: function () {} } : null;
        }

        var caixa = el('div', {}, []);
        var v = el('video', { className: 'media', controls: true, playsinline: true, preload: 'none', poster: capa }, []);
        var hls = null;
        var vivo = true;
        var ultimo = 0;

        var soACapa = function () {
            if (!vivo) {
                return;
            }
            while (caixa.firstChild) {
                caixa.removeChild(caixa.firstChild);
            }
            if (capa) {
                caixa.appendChild(el('img', { className: 'media', src: capa, alt: '' }));
            }
        };

        v.addEventListener(
            'timeupdate',
            seguro(function () {
                var s = Math.floor(v.currentTime);
                if (s - ultimo >= SEGUNDOS_ENTRE_PROGRESSOS) {
                    ultimo = s;
                    aoProgresso(s);
                }
            })
        );
        v.addEventListener(
            'pause',
            seguro(function () {
                var s = Math.floor(v.currentTime);
                if (!v.ended && s > ultimo) {
                    ultimo = s;
                    aoProgresso(s);
                }
            })
        );
        v.addEventListener(
            'ended',
            seguro(function () {
                aoProgresso(Math.floor(v.duration || v.currentTime || 0));
                aoFim();
            })
        );

        caixa.appendChild(v);

        // Um vídeo que o browser não consegue tocar (o próprio, sem o hls.js) dá lugar à capa.
        var aoFalhar = seguro(function () {
            if (!hls) {
                soACapa();
            }
        });

        if (video.tipo === 'mp4') {
            v.addEventListener('error', aoFalhar);
            v.src = url;
        } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
            v.addEventListener('error', aoFalhar);
            v.src = url;
        } else {
            carregarHls()
                .then(function (Hls) {
                    if (!vivo) {
                        return;
                    }
                    if (!Hls.isSupported()) {
                        soACapa();
                        return;
                    }
                    hls = new Hls();
                    hls.on(Hls.Events.ERROR, function (_evento, dados) {
                        if (dados && dados.fatal) {
                            try {
                                hls.destroy();
                            } catch (e) {
                                /* nada */
                            }
                            hls = null;
                            soACapa();
                        }
                    });
                    hls.loadSource(url);
                    hls.attachMedia(v);
                })
                .catch(soACapa);
        }

        return {
            no: caixa,
            destruir: function () {
                vivo = false;
                try {
                    v.pause();
                } catch (e) {
                    /* nada */
                }
                if (hls) {
                    try {
                        hls.destroy();
                    } catch (e) {
                        /* nada */
                    }
                    hls = null;
                }
            },
        };
    }

    /* ---------------------------------------------------------------- janela */

    var numeroDaJanela = 0;

    function abrirJanela(mensagem, zona, aoSair) {
        var total = mensagem.diapositivos.length;
        var obrigatoria = mensagem.obrigatoria === true;
        var restante = obrigatoria ? Math.max(0, Math.floor(Number(mensagem.obrigatoria_segundos) || 0)) : 0;
        var indice = 0;
        var fechada = false;
        var videoActual = null;
        var botaoDeFechar = null;
        var idDoTitulo = 'honga-mensagem-titulo-' + ++numeroDaJanela;
        var focoAnterior = document.activeElement;
        var transbordoAnterior = document.documentElement.style.overflow;

        var anfitriao = document.createElement('div');
        anfitriao.setAttribute('data-honga-mensagens-popup', String(mensagem.id));
        // No próprio elemento e com `important`: uma regra larga do site (`body > div { … }`) ganhava ao `:host`.
        [
            ['position', 'fixed'],
            ['inset', '0'],
            ['z-index', '2147483000'],
            ['display', 'block'],
            ['margin', '0'],
            ['padding', '0'],
            ['border', '0'],
            ['transform', 'none'],
            ['opacity', '1'],
            ['visibility', 'visible'],
            ['pointer-events', 'auto'],
        ].forEach(function (par) {
            anfitriao.style.setProperty(par[0], par[1], 'important');
        });
        var sombra = anfitriao.attachShadow({ mode: 'open' });

        var folhaPosta = false;
        try {
            if ('adoptedStyleSheets' in sombra && typeof CSSStyleSheet === 'function') {
                var folha = new CSSStyleSheet();
                folha.replaceSync(CSS);
                sombra.adoptedStyleSheets = [folha];
                folhaPosta = true;
            }
        } catch (e) {
            folhaPosta = false;
        }
        if (!folhaPosta) {
            var estilo = document.createElement('style');
            estilo.textContent = CSS;
            sombra.appendChild(estilo);
        }

        var cartao = el('div', { className: 'cartao', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' }, []);
        var veu = el('div', { className: 'veu' }, [cartao]);
        sombra.appendChild(veu);

        var bloqueada = function () {
            return obrigatoria && restante > 0;
        };

        var ev = function (tipo, dados) {
            enviar(zona, mensagem.id, tipo, dados);
        };

        var aplicarTema = seguro(function () {
            anfitriao.setAttribute('data-tema', temaEscuro() ? 'escuro' : 'claro');
        });

        var rotuloDeFechar = function () {
            return bloqueada() ? 'Continuar em ' + restante + ' s' : obrigatoria ? 'Continuar' : 'Fechar';
        };

        var actualizarFechar = function () {
            if (botaoDeFechar) {
                botaoDeFechar.disabled = bloqueada();
                botaoDeFechar.lastChild.textContent = rotuloDeFechar();
            }
        };

        var sair = function () {
            if (fechada) {
                return;
            }
            fechada = true;
            window.clearTimeout(temporizadorDaVista);
            window.clearInterval(contagem);
            document.removeEventListener('keydown', aoTeclar, true);
            if (observador) {
                observador.disconnect();
            }
            if (media && media.removeEventListener) {
                media.removeEventListener('change', aplicarTema);
            }
            if (videoActual) {
                videoActual.destruir();
                videoActual = null;
            }
            anfitriao.setAttribute('data-saida', '');
            window.setTimeout(function () {
                try {
                    document.documentElement.style.overflow = transbordoAnterior;
                    if (anfitriao.parentNode) {
                        anfitriao.parentNode.removeChild(anfitriao);
                    }
                    if (focoAnterior && typeof focoAnterior.focus === 'function' && document.contains(focoAnterior)) {
                        focoAnterior.focus({ preventScroll: true });
                    }
                } catch (e) {
                    /* nada */
                }
                aoSair();
            }, MS_DA_SAIDA);
        };

        var fechar = function () {
            if (bloqueada() || fechada) {
                return;
            }
            ev('fechada');
            sair();
        };

        var dispensar = function () {
            if (fechada) {
                return;
            }
            ev('dispensada');
            sair();
        };

        var clicarBotao = function (b) {
            if (fechada) {
                return;
            }
            var destino = String(b.destino == null ? '' : b.destino);
            ev('clique', { botao: b.principal ? 'principal' : 'secundario', destino: destino });
            if (b.tipo === 'pagina') {
                var c = caminhoInterno(destino);
                if (c) {
                    window.location.assign(c);
                }
            } else if (enderecoSeguro(destino) && !caminhoInterno(destino)) {
                if (b.novo_separador) {
                    window.open(destino, '_blank', 'noopener,noreferrer');
                } else {
                    window.location.assign(destino);
                }
            }
            sair();
        };

        // Uma ligação dentro do texto: regista-se o clique; um caminho do próprio site navega e fecha, as
        // outras seguem o `target` que o texto já traz (separador novo para as de fora) e a janela fica.
        var clicarNoTexto = function (e) {
            var alvo = e.target && e.target.closest ? e.target.closest('a') : null;
            var href = alvo ? alvo.getAttribute('href') : null;
            if (!href || fechada) {
                return;
            }
            ev('clique', { botao: 'ligacao_texto', destino: href });
            if (caminhoInterno(href)) {
                e.preventDefault();
                window.location.assign(href);
                sair();
            }
        };

        var irPara = function (i) {
            indice = Math.max(0, Math.min(total - 1, i));
            desenhar();
            cartao.focus({ preventScroll: true });
        };

        var desenhar = function () {
            var d = mensagem.diapositivos[indice] || {};
            var ultimo = indice >= total - 1;
            var titulo = typeof d.titulo === 'string' ? d.titulo.trim() : '';
            var botoes = (Array.isArray(d.botoes) ? d.botoes : [])
                .filter(function (b) {
                    return b && typeof b.rotulo === 'string' && b.rotulo.trim() !== '';
                })
                .slice(0, 2);
            var principal = botoes.filter(function (b) {
                return b.principal;
            })[0];
            var secundario = botoes.filter(function (b) {
                return !b.principal;
            })[0];

            if (videoActual) {
                videoActual.destruir();
                videoActual = null;
            }
            while (cartao.firstChild) {
                cartao.removeChild(cartao.firstChild);
            }

            if (titulo) {
                cartao.setAttribute('aria-labelledby', idDoTitulo);
                cartao.removeAttribute('aria-label');
            } else {
                cartao.removeAttribute('aria-labelledby');
                cartao.setAttribute('aria-label', 'Mensagem');
            }

            // Topo: o título e, fora das obrigatórias, o «x».
            var x = null;
            if (!obrigatoria) {
                x = el('button', { type: 'button', className: 'fechar', 'aria-label': 'Fechar' }, [icone('fechar')]);
                x.addEventListener('click', seguro(fechar));
            }
            if (titulo || x) {
                cartao.appendChild(
                    el('div', { className: 'topo' + (titulo ? '' : ' sem-titulo') }, [
                        titulo ? el('h2', { className: 'titulo', id: idDoTitulo }, [titulo]) : null,
                        x,
                    ])
                );
            }

            // Corpo: o vídeo ou a imagem, o texto e o indicador dos diapositivos.
            var corpo = el('div', { className: 'corpo' }, []);
            var imagem = soHttps(d.imagem);
            if (d.video) {
                videoActual = criarVideo(
                    d.video,
                    function (segundos) {
                        ev('video_progresso', { segundos: segundos });
                    },
                    function () {
                        ev('video_fim');
                    }
                );
                if (videoActual) {
                    corpo.appendChild(videoActual.no);
                }
            } else if (imagem) {
                var img = el('img', { className: 'media', src: imagem, alt: '' }, []);
                img.addEventListener('error', function () {
                    if (img.parentNode) {
                        img.parentNode.removeChild(img);
                    }
                });
                corpo.appendChild(img);
            }
            if (d.texto) {
                var texto = el('div', { className: 'texto' }, []);
                texto.appendChild(textoSeguro(d.texto));
                if (texto.childNodes.length > 0) {
                    texto.addEventListener('click', seguro(clicarNoTexto));
                    corpo.appendChild(texto);
                }
            }
            if (total > 1) {
                var pontos = el('div', { className: 'pontos', role: 'group', 'aria-label': 'Diapositivos' }, []);
                mensagem.diapositivos.forEach(function (_d, i) {
                    var p = el(
                        'button',
                        {
                            type: 'button',
                            className: 'ponto',
                            'aria-label': 'Diapositivo ' + (i + 1) + ' de ' + total,
                            'aria-current': i === indice ? 'step' : null,
                        },
                        [el('span', {}, [])]
                    );
                    p.addEventListener(
                        'click',
                        seguro(function () {
                            irPara(i);
                        })
                    );
                    pontos.appendChild(p);
                });
                corpo.appendChild(pontos);
            }
            cartao.appendChild(corpo);

            // Rodapé: «Não mostrar mais» de um lado, as acções juntas do outro.
            var accoes = el('div', { className: 'accoes' }, []);
            if (indice > 0) {
                accoes.appendChild(
                    botao(
                        'neutro',
                        'Anterior',
                        function () {
                            irPara(indice - 1);
                        },
                        { antes: 'anterior' }
                    )
                );
            }
            if (secundario) {
                accoes.appendChild(
                    botao('neutro', secundario.rotulo.trim(), function () {
                        clicarBotao(secundario);
                    })
                );
            }
            if (principal) {
                accoes.appendChild(
                    botao('cheio', principal.rotulo.trim(), function () {
                        clicarBotao(principal);
                    })
                );
            }
            botaoDeFechar = null;
            if (!ultimo) {
                accoes.appendChild(
                    botao(
                        botoes.length > 0 ? 'neutro' : 'cheio',
                        'Seguinte',
                        function () {
                            irPara(indice + 1);
                        },
                        { depois: 'seguinte' }
                    )
                );
            } else {
                botaoDeFechar = botao(botoes.length > 0 ? 'neutro' : 'cheio', rotuloDeFechar(), fechar);
                botaoDeFechar.disabled = bloqueada();
                accoes.appendChild(botaoDeFechar);
            }
            cartao.appendChild(
                el('div', { className: 'rodape' }, [
                    mensagem.dispensavel === true ? botao('fantasma', 'Não mostrar mais', dispensar) : null,
                    accoes,
                ])
            );
        };

        var focaveis = function () {
            return Array.prototype.slice
                .call(cartao.querySelectorAll('button, a[href], video[controls], [tabindex]:not([tabindex="-1"])'))
                .filter(function (n) {
                    return !n.disabled && n.getClientRects().length > 0;
                });
        };

        // O Esc fecha (menos durante a contagem de uma obrigatória) e o Tab não sai da janela.
        var aoTeclar = seguro(function (e) {
            if (fechada) {
                return;
            }
            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                e.stopPropagation();
                fechar();
                return;
            }
            if (e.key !== 'Tab') {
                return;
            }
            var lista = focaveis();
            if (lista.length === 0) {
                e.preventDefault();
                cartao.focus({ preventScroll: true });
                return;
            }
            var activo = sombra.activeElement;
            var posicao = lista.indexOf(activo);
            var dentro = document.activeElement === anfitriao && posicao !== -1;
            if (e.shiftKey && (!dentro || posicao === 0)) {
                e.preventDefault();
                lista[lista.length - 1].focus();
            } else if (!e.shiftKey && (!dentro || posicao === lista.length - 1)) {
                e.preventDefault();
                lista[0].focus();
            }
        });

        // Clicar fora fecha, menos nas obrigatórias.
        veu.addEventListener(
            'mousedown',
            seguro(function (e) {
                if (e.target === veu && !obrigatoria) {
                    fechar();
                }
            })
        );

        aplicarTema();
        desenhar();
        (document.body || document.documentElement).appendChild(anfitriao);
        document.documentElement.style.overflow = 'hidden';
        document.addEventListener('keydown', aoTeclar, true);
        cartao.focus({ preventScroll: true });

        var observador = null;
        if (typeof MutationObserver === 'function') {
            observador = new MutationObserver(aplicarTema);
            observador.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        }
        var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        if (media && media.addEventListener) {
            media.addEventListener('change', aplicarTema);
        }

        // «Vista» só depois de 1 s visível.
        var temporizadorDaVista = window.setTimeout(function () {
            ev('vista');
        }, MS_PARA_VISTA);

        // A contagem das obrigatórias.
        var contagem = 0;
        if (restante > 0) {
            contagem = window.setInterval(
                seguro(function () {
                    restante = Math.max(0, restante - 1);
                    actualizarFechar();
                    if (restante === 0) {
                        window.clearInterval(contagem);
                    }
                }),
                1000
            );
        }

        return { sair: sair };
    }

    /* --------------------------------------------------------------- arranque */

    var avaliarSeguro = seguro(avaliar);

    // O Inertia avisa em cada visita; o botão de recuar muda o endereço antes de a página trocar.
    document.addEventListener('inertia:navigate', function () {
        window.setTimeout(avaliarSeguro, 0);
    });
    window.addEventListener('popstate', function () {
        window.setTimeout(avaliarSeguro, 0);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', avaliarSeguro);
    } else {
        avaliarSeguro();
    }
})();
