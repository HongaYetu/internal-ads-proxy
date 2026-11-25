<?php

namespace HongaYetu\InternalAdsProxy;

use Illuminate\Support\Facades\Auth;

/**
 * As mensagens em popup nos **sites** dos serviços (sessão web), sem build de
 * frontend: o package serve o `popup.js` e a directiva `@mensagensPopupHonga`
 * escreve a etiqueta que o carrega.
 *
 * Aqui ficam as três coisas que a directiva, as rotas e o controller têm de
 * ver da mesma maneira: se está ligado, quais são as zonas, e o que a página
 * recebe.
 *
 * Uma **zona** diz onde a janela pode abrir e em nome de que serviço:
 * `['servico' => 'humbi', 'superficie' => 'site', 'caminhos' => ['/'], 'anfitrioes' => []]`.
 */
class MensagensPopupWeb
{
    /** As superfícies que as rotas web aceitam. A `app` fica para as rotas da API. */
    public const SUPERFICIES = ['site', 'painel', 'portal'];

    /*
     * Os valores por omissão vivem também aqui, e não só no config: o
     * `mergeConfigFrom` não junta chaves aninhadas, e um serviço que publicou o
     * `config/ads-proxy.php` antes da 0.11.0 não tem a chave `mensagens.web`.
     * Sem isto, as rotas ficavam sem `auth` nesses serviços.
     */
    public const PREFIXO = 'mensagens-popup';

    public const PREFIXO_DOS_NOMES = 'mensagens-popup.web.';

    public const MIDDLEWARE = ['web', 'auth', 'throttle:60,1,mensagens-popup-web'];

    /** O script é público e igual para todos: sem sessão (para poder ficar em cache) e com contador próprio. */
    public const MIDDLEWARE_DO_SCRIPT = ['throttle:120,1,mensagens-popup-script'];

    protected static ?string $versaoDoScript = null;

    public static function config(string $chave, mixed $omissao = null): mixed
    {
        return config('ads-proxy.mensagens.web.'.$chave, $omissao);
    }

    /** Os dois interruptores: o das mensagens e o das mensagens nos sites. */
    public static function ligada(): bool
    {
        return (bool) config('ads-proxy.mensagens.enabled', true)
            && (bool) self::config('enabled', true);
    }

    /**
     * As zonas configuradas, já arrumadas. Uma zona mal escrita (sem serviço,
     * com uma superfície que a web não aceita) fica de fora em vez de dar erro.
     *
     * @return list<array{servico: string, superficie: string, caminhos: list<string>, anfitrioes: list<string>}>
     */
    public static function zonas(): array
    {
        $zonas = [];

        foreach ((array) self::config('zonas', []) as $zona) {
            if (! is_array($zona)) {
                continue;
            }

            $servico = self::texto($zona['servico'] ?? null);
            $superficie = self::texto($zona['superficie'] ?? null);

            if ($servico === null || ! in_array($superficie, self::SUPERFICIES, true)) {
                continue;
            }

            $caminhos = [];
            foreach ((array) ($zona['caminhos'] ?? ['/']) as $caminho) {
                $caminho = self::texto($caminho);
                if ($caminho !== null) {
                    $caminhos[] = self::semBarraFinal($caminho);
                }
            }

            $anfitrioes = [];
            foreach ((array) ($zona['anfitrioes'] ?? []) as $anfitriao) {
                $anfitriao = self::texto($anfitriao);
                if ($anfitriao !== null) {
                    $anfitrioes[] = mb_strtolower($anfitriao);
                }
            }

            $zonas[] = [
                'servico' => $servico,
                'superficie' => $superficie,
                'caminhos' => array_values(array_unique($caminhos)) ?: ['/'],
                'anfitrioes' => array_values(array_unique($anfitrioes)),
            ];
        }

        return $zonas;
    }

    /**
     * A zona de um pedido web: o serviço e a superfície têm de ser os de uma
     * zona configurada e, se a zona nomear domínios, o pedido tem de vir de um.
     *
     * @return array{servico: string, superficie: string, caminhos: list<string>, anfitrioes: list<string>}|null
     */
    public static function zona(?string $servico, ?string $superficie, ?string $anfitriao): ?array
    {
        if ($servico === null || $superficie === null) {
            return null;
        }

        $anfitriao = mb_strtolower((string) $anfitriao);

        foreach (self::zonas() as $zona) {
            if ($zona['servico'] !== $servico || $zona['superficie'] !== $superficie) {
                continue;
            }

            if ($zona['anfitrioes'] === [] || in_array($anfitriao, $zona['anfitrioes'], true)) {
                return $zona;
            }
        }

        return null;
    }

    public static function caminhoDoScript(): string
    {
        return __DIR__.'/../resources/js/popup.js';
    }

    /** Muda quando o ficheiro muda: vai no `?v=` para o browser largar a cópia antiga. */
    public static function versaoDoScript(): string
    {
        return self::$versaoDoScript ??= substr((string) @md5_file(self::caminhoDoScript()), 0, 12) ?: '0';
    }

    /** O caminho público das rotas web (`/mensagens-popup`), sem domínio. */
    public static function raiz(): string
    {
        $prefixo = trim((string) self::config('route_prefix', self::PREFIXO), '/');

        return rtrim((string) request()->getBaseUrl(), '/').'/'.$prefixo;
    }

    /**
     * O que a directiva `@mensagensPopupHonga` escreve: uma única etiqueta
     * `<script>`, ou nada.
     *
     * Só escreve com as mensagens ligadas, zonas configuradas e alguém
     * autenticado com conta Honga Yetu. Não leva dados da pessoa. E nunca
     * parte a página: qualquer erro aqui dá em nada escrito.
     */
    public static function etiqueta(): string
    {
        try {
            if (! self::ligada()) {
                return '';
            }

            $zonas = self::zonas();

            if ($zonas === [] || ! self::temContaHonga()) {
                return '';
            }

            $raiz = self::raiz();
            $atributos = [
                'src' => $raiz.'/janela?v='.self::versaoDoScript(),
                'data-raiz' => $raiz,
            ];

            $csrf = function_exists('csrf_token') ? csrf_token() : null;

            $html = '<script defer';
            foreach ($atributos as $nome => $valor) {
                $html .= ' '.$nome.'="'.e($valor).'"';
            }
            // Aspas simples por fora: o JSON vai com as aspas, os «<», os «&» e as plicas das cadeias escapados.
            $html .= " data-zonas='".json_encode(
                $zonas,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP,
            )."'";
            if (is_string($csrf) && $csrf !== '') {
                $html .= ' data-csrf="'.e($csrf).'"';
            }

            return $html.'></script>';
        } catch (\Throwable) {
            return '';
        }
    }

    /** Há alguém autenticado no guard configurado, com o id da conta Honga Yetu preenchido. */
    protected static function temContaHonga(): bool
    {
        $guard = self::texto(self::config('guard'));
        $utilizador = Auth::guard($guard)->user();

        if ($utilizador === null) {
            return false;
        }

        $atributo = (string) config('ads-proxy.mensagens.atributo_do_utilizador', 'honga_user_id');
        $valor = data_get($utilizador, $atributo);

        return is_numeric($valor) && (int) $valor > 0;
    }

    protected static function semBarraFinal(string $caminho): string
    {
        $caminho = '/'.ltrim($caminho, '/');

        return rtrim($caminho, '/') ?: '/';
    }

    protected static function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
