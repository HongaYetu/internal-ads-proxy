<?php

namespace HongaYetu\InternalAdsProxy\Http\Controllers;

use HongaYetu\InternalAdsProxy\Contratos\OrigemDoFeedback;
use HongaYetu\InternalAdsProxy\Http\FeedbackClient;
use HongaYetu\InternalAdsProxy\Identidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Proxy do feedback para a central Honga Yetu.
 *
 * A app ou o site do serviço manda o comentário a este servidor; este encaminha-o à central com o token do
 * ConnectedProject e diz quem escreve (a conta Honga Yetu ou a conta do serviço) e de que revendedor é o
 * cliente. Isso sai sempre do servidor, nunca do pedido.
 *
 * Ao contrário das mensagens, **aqui a pessoa tem de saber se não seguiu**: se a central falhar, a resposta
 * é um 503 com o texto a mostrar.
 *
 * As capturas de ecrã: a central recebe endereços `https`, não ficheiros. `capturar()` guarda o ficheiro no
 * disco do serviço e devolve um identificador; `enviar()` troca os identificadores pelos endereços públicos.
 */
class FeedbackController extends Controller
{
    public const TEXTO_DA_FALHA = 'Não foi possível enviar o teu comentário. Tenta de novo.';

    public const PASTA_DAS_CAPTURAS = 'feedback/capturas';

    public const MAXIMO_DE_CAPTURAS = 3;

    /** O nome de um ficheiro guardado por `capturar()`. */
    public const REGEX_DA_CAPTURA = '/^[A-Za-z0-9]{40}\.(jpg|png|webp)$/';

    /** O que a app pode mandar. A conta, a origem e as capturas tratam-se à parte. */
    protected const CAMPOS_DA_APP = [
        'texto', 'tipo', 'estrelas', 'superficie', 'versao_app', 'plataforma', 'ecra', 'aparelho',
        'contacto_tipo', 'contacto',
    ];

    public function __construct(protected FeedbackClient $client) {}

    public function enviar(Request $request): JsonResponse
    {
        return $this->encaminhar($request, (string) $this->config('guard', ''));
    }

    public function enviarWeb(Request $request): JsonResponse
    {
        return $this->encaminhar($request, (string) config('ads-proxy.feedback.web.guard', ''));
    }

    public function capturar(Request $request): JsonResponse
    {
        if (! (bool) $this->config('enabled', true)) {
            return $this->falha();
        }

        $validador = Validator::make($request->all(), [
            'imagem' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ], [
            'imagem.required' => 'Escolhe a imagem da captura de ecrã.',
            'imagem.file' => 'A captura de ecrã não é válida.',
            'imagem.mimes' => 'A captura tem de ser uma imagem JPEG, PNG ou WebP.',
            'imagem.max' => 'A captura passa dos 5 MB. Escolhe uma mais pequena.',
        ]);

        if ($validador->fails()) {
            return response()->json(['estado' => 'erro', 'texto' => $validador->errors()->first()], 422);
        }

        $ficheiro = $request->file('imagem');
        $extensao = match ($ficheiro->guessExtension()) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
        $nome = Str::random(40).'.'.$extensao;

        try {
            $guardada = Storage::disk($this->disco())->putFileAs(self::PASTA_DAS_CAPTURAS, $ficheiro, $nome, 'public');
        } catch (\Throwable $e) {
            $guardada = false;
            $this->logger()->warning('hongayetu.feedback.captura_falhou', ['erro' => $e->getMessage()]);
        }

        if (! $guardada) {
            return $this->falha();
        }

        return response()->json(['estado' => 'ok', 'captura' => $nome]);
    }

    protected function encaminhar(Request $request, string $guard): JsonResponse
    {
        if (! (bool) $this->config('enabled', true)) {
            return $this->falha();
        }

        // A armadilha dos robôs: preenchida, a pessoa «vê» que seguiu e a central nem fica a saber.
        if (filled($request->input('site'))) {
            return response()->json(['estado' => 'ok']);
        }

        $servico = $this->texto($request->input('servico')) ?? $this->texto($this->config('servico_por_omissao'))
            ?? $this->texto(config('ads-proxy.mensagens.servico_por_omissao'));

        if ($servico === null) {
            return response()->json(['estado' => 'erro', 'texto' => 'Indica o serviço.'], 422);
        }

        if (! $this->servicoPermitido($servico)) {
            return response()->json(['estado' => 'erro', 'texto' => 'Este serviço não envia comentários por aqui.'], 422);
        }

        $dados = array_filter(
            array_map(fn ($valor) => is_scalar($valor) ? $valor : null, $request->only(self::CAMPOS_DA_APP)),
            fn ($valor) => $valor !== null && $valor !== '',
        );
        $dados['servico'] = $servico;

        if ($request->has('quer_resposta')) {
            $dados['quer_resposta'] = $request->boolean('quer_resposta');
        }

        $anonimo = $request->has('enviar_como') && (int) $request->input('enviar_como') === 0;

        if ($request->has('enviar_como')) {
            $dados['enviar_como'] = $anonimo ? 0 : 1;
        }

        if ($this->texto($request->input('contacto')) === null) {
            unset($dados['contacto']);
        }

        $utilizador = $anonimo ? null : $this->utilizador($request, $guard);

        if ($utilizador !== null) {
            $dados = [...$dados, ...(Identidade::daConta($utilizador, (bool) $this->config('contas_sem_honga', true)) ?? [])];
        }

        if ($origem = $this->origemDoFeedback($request, $utilizador)) {
            $dados = [...$dados, ...$origem];
        }

        if ($capturas = $this->enderecosDasCapturas((array) $request->input('capturas', []))) {
            $dados['capturas'] = $capturas;
        }

        $resposta = $this->client->enviar($dados);

        if ($resposta !== null && in_array($resposta->status(), [200, 422, 429], true)) {
            return response()->json($resposta->json() ?? ['estado' => $resposta->successful() ? 'ok' : 'erro'], $resposta->status());
        }

        $this->logger()->warning('hongayetu.feedback.falhou', [
            'estado_upstream' => $resposta?->status(),
            'servico' => $servico,
        ]);

        return $this->falha();
    }

    /** Quem está autenticado no guard configurado, se houver; o feedback também aceita visitantes. */
    protected function utilizador(Request $request, string $guard): mixed
    {
        return Identidade::quemPede($request, $guard !== '' ? $guard : null);
    }

    /**
     * Quem é o cliente, por uma classe do serviço (`feedback.origem`). Uma classe mal posta não parte o
     * envio: o comentário segue sem origem e fica um aviso.
     *
     * @return array{origem_tipo?: string, origem_id?: string, origem_nome?: string}|null
     */
    protected function origemDoFeedback(Request $request, mixed $utilizador): ?array
    {
        $classe = $this->config('origem');

        if (! is_string($classe) || $classe === '') {
            return null;
        }

        try {
            $resolvida = app($classe);

            if (! $resolvida instanceof OrigemDoFeedback) {
                throw new \LogicException($classe.' não implementa '.OrigemDoFeedback::class);
            }

            $origem = $resolvida->origem($request, $utilizador);
        } catch (\Throwable $e) {
            $this->logger()->warning('hongayetu.feedback.origem_falhou', ['origem' => $classe, 'erro' => $e->getMessage()]);

            return null;
        }

        if (! is_array($origem)) {
            return null;
        }

        $saida = [];

        foreach (['tipo' => 40, 'id' => 60, 'nome' => 150] as $chave => $limite) {
            $valor = $origem[$chave] ?? null;

            if (is_scalar($valor) && trim((string) $valor) !== '') {
                $saida['origem_'.$chave] = mb_substr(trim((string) $valor), 0, $limite);
            }
        }

        return $saida ?: null;
    }

    /**
     * Troca os identificadores das capturas pelos endereços públicos `https`. O que não existe (ou não é um
     * identificador nosso) é ignorado.
     *
     * @param  list<mixed>  $identificadores
     * @return list<string>
     */
    protected function enderecosDasCapturas(array $identificadores): array
    {
        $disco = Storage::disk($this->disco());
        $enderecos = [];

        foreach (array_slice($identificadores, 0, self::MAXIMO_DE_CAPTURAS) as $id) {
            if (! is_string($id) || preg_match(self::REGEX_DA_CAPTURA, $id) !== 1) {
                continue;
            }

            $caminho = self::PASTA_DAS_CAPTURAS.'/'.$id;

            try {
                if (! $disco->exists($caminho)) {
                    continue;
                }

                $url = (string) $disco->url($caminho);
            } catch (\Throwable) {
                continue;
            }

            if (str_starts_with($url, '/')) {
                $url = url($url);
            }

            $url = preg_replace('#^http://#i', 'https://', $url);

            if (str_starts_with($url, 'https://') && mb_strlen($url) <= 500) {
                $enderecos[] = $url;
            }
        }

        return $enderecos;
    }

    /** Sem `feedback.servicos_permitidos`, vale a lista das mensagens. */
    protected function servicoPermitido(string $servico): bool
    {
        $permitidos = array_filter((array) $this->config('servicos_permitidos', []))
            ?: array_filter((array) config('ads-proxy.mensagens.servicos_permitidos', []));

        return $permitidos === [] || in_array($servico, $permitidos, true);
    }

    protected function disco(): string
    {
        return (string) ($this->config('disco') ?: 'public');
    }

    protected function falha(): JsonResponse
    {
        return response()->json(['estado' => 'erro', 'texto' => self::TEXTO_DA_FALHA], 503);
    }

    protected function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    protected function config(string $chave, mixed $omissao = null): mixed
    {
        return config('ads-proxy.feedback.'.$chave, $omissao);
    }

    protected function logger()
    {
        $canal = config('ads-proxy.log_channel');

        return $canal ? Log::channel($canal) : Log::getFacadeRoot();
    }
}
