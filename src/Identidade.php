<?php

namespace HongaYetu\InternalAdsProxy;

use HongaYetu\InternalAdsProxy\Contratos\FiltroDeMensagens;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Quem é a pessoa de um pedido, do ponto de vista da central: a conta Honga Yetu (`honga_user_id`) ou, para
 * quem não a tem, a conta do próprio serviço (`conta_externa_id` + `conta_nome`). Sai sempre do utilizador
 * autenticado neste servidor, nunca do pedido.
 */
final class Identidade
{
    /** O mesmo que a central aceita. */
    public const REGEX_DA_CONTA_EXTERNA = '/^[A-Za-z0-9_.:-]{1,64}$/';

    public static function hongaUserId(mixed $utilizador, ?string $atributo = null): ?int
    {
        if ($utilizador === null) {
            return null;
        }

        $atributo ??= (string) config('ads-proxy.mensagens.atributo_do_utilizador', 'honga_user_id');
        $valor = data_get($utilizador, $atributo);

        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    /**
     * A conta do utilizador no serviço: o identificador (`getAuthIdentifier()`) e o nome.
     *
     * @return array{conta_externa_id: string, conta_nome?: string}|null
     */
    public static function contaDoServico(mixed $utilizador): ?array
    {
        if (! $utilizador instanceof Authenticatable) {
            return null;
        }

        $id = $utilizador->getAuthIdentifier();

        if (! is_scalar($id) || preg_match(self::REGEX_DA_CONTA_EXTERNA, (string) $id) !== 1) {
            return null;
        }

        $conta = ['conta_externa_id' => (string) $id];

        if ($nome = self::nomeDoUtilizador($utilizador)) {
            $conta['conta_nome'] = $nome;
        }

        return $conta;
    }

    /**
     * A identidade a mandar à central: a conta Honga Yetu e, sem ela, e só com `$contasSemHonga`, a conta
     * do serviço.
     *
     * @return array{honga_user_id: int}|array{conta_externa_id: string, conta_nome?: string}|null
     */
    public static function daConta(mixed $utilizador, bool $contasSemHonga, ?string $atributo = null): ?array
    {
        if ($hongaUserId = self::hongaUserId($utilizador, $atributo)) {
            return ['honga_user_id' => $hongaUserId];
        }

        return $contasSemHonga ? self::contaDoServico($utilizador) : null;
    }

    /** O que as mensagens mandam: o interruptor é `mensagens.contas_sem_honga`. */
    public static function dasMensagens(mixed $utilizador): ?array
    {
        return self::daConta($utilizador, (bool) config('ads-proxy.mensagens.contas_sem_honga', false));
    }

    /**
     * O filtro do serviço (`mensagens.filtro`). Sem filtro, aceita. Um filtro que não se consegue
     * resolver ou que falha recusa (e regista): é o lado seguro para quem o pôs a proteger alguém.
     */
    public static function mensagensAceites(Request $request, mixed $utilizador): bool
    {
        $classe = config('ads-proxy.mensagens.filtro');

        if (! is_string($classe) || $classe === '') {
            return true;
        }

        try {
            $filtro = app($classe);

            if (! $filtro instanceof FiltroDeMensagens) {
                throw new \LogicException($classe.' não implementa '.FiltroDeMensagens::class);
            }

            return $filtro->aceita($request, $utilizador) === true;
        } catch (\Throwable $e) {
            Log::warning('hongayetu.mensagens_popup.filtro_falhou', ['filtro' => $classe, 'erro' => $e->getMessage()]);

            return false;
        }
    }

    protected static function nomeDoUtilizador(Authenticatable $utilizador): ?string
    {
        $configurado = config('ads-proxy.mensagens.atributo_do_nome');
        $atributos = is_string($configurado) && $configurado !== '' ? [$configurado] : ['nome', 'name'];

        foreach ($atributos as $atributo) {
            $valor = data_get($utilizador, $atributo);

            if (is_scalar($valor) && trim((string) $valor) !== '') {
                return mb_substr(trim((string) $valor), 0, 150);
            }
        }

        return null;
    }
}
