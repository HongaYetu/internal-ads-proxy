<?php

namespace HongaYetu\InternalAdsProxy\Contratos;

use Illuminate\Http\Request;

/**
 * Decide quem recebe as mensagens em popup da central neste serviço.
 *
 * Uma classe do serviço implementa-a e diz o nome em `ads-proxy.mensagens.filtro`; resolve-se pelo
 * contentor do Laravel. Com `false`, a lista vem vazia, o evento responde «ok» sem chamar a central e a
 * directiva `@mensagensPopupHonga` não escreve nada. Serve, por exemplo, para deixar de fora os clientes
 * de um revendedor (marca branca).
 */
interface FiltroDeMensagens
{
    /** @param  mixed  $utilizador  O utilizador autenticado do pedido (nunca `null`). */
    public function aceita(Request $request, mixed $utilizador): bool;
}
