<?php

namespace HongaYetu\InternalAdsProxy;

/**
 * O agente de quem segue a ligação de um anúncio é de um robô (crawler, browser sem cabeça, cliente de linha
 * de comandos)?
 *
 * Os padrões são os da central (`ads.publisher.bots_ua` no monólito, a mesma regra do `DeteccaoDeRobos` de
 * lá), em `ads-proxy.robos`. A central já não conta os cliques deles, mas cada um custava um pedido a ela:
 * o Reflectionbot seguia os `GET …/click/{token}` dos sites (54 mil cliques recusados em 15 dias). Com isto,
 * o proxy responde-lhes 404 sem sair desta máquina.
 *
 * Um agente vazio não é um robô aqui: o browser de uma pessoa manda sempre um.
 */
class DeteccaoDeRobos
{
    /** Os padrões da central, para quando o `config/ads-proxy.php` publicado não tem a chave. */
    public const PADROES = ['bot', 'crawler', 'spider', 'headless', 'phantomjs', 'selenium', 'curl/', 'wget/', 'python-requests', 'scrapy'];

    public static function eRobo(?string $agente): bool
    {
        $agente = strtolower(trim((string) $agente));

        if ($agente === '') {
            return false;
        }

        $padroes = config('ads-proxy.robos');

        foreach (is_array($padroes) ? $padroes : self::PADROES as $padrao) {
            $padrao = strtolower(trim((string) $padrao));

            if ($padrao !== '' && str_contains($agente, $padrao)) {
                return true;
            }
        }

        return false;
    }
}
