<?php

namespace HongaYetu\InternalAdsProxy\Contratos;

use Illuminate\Http\Request;

/**
 * Diz de quem é o cliente que escreve um feedback, quando há um intermediário (um revendedor, por exemplo).
 *
 * Uma classe do serviço implementa-a e diz o nome em `ads-proxy.feedback.origem`; resolve-se pelo
 * contentor. A central usa-o para dizer à equipa de que revendedor é o cliente e para não responder com a
 * marca Honga Yetu.
 */
interface OrigemDoFeedback
{
    /**
     * @param  mixed  $utilizador  O utilizador autenticado, ou `null` num visitante.
     * @return array{tipo?: string, id?: string|int, nome?: string}|null `null` quando não há origem.
     */
    public function origem(Request $request, mixed $utilizador): ?array;
}
