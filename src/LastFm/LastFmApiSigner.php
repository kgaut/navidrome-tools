<?php

namespace App\LastFm;

/**
 * Computes the api_sig parameter required by Last.fm's authenticated
 * endpoints (auth.getSession, track.love, track.unlove…). The algorithm,
 * per https://www.last.fm/api/authspec :
 *
 *   1. drop `format` and `callback` from the params,
 *   2. sort the remaining params by key (ASCII),
 *   3. concatenate `key . value` for every param,
 *   4. append the API secret,
 *   5. md5() the result.
 */
final class LastFmApiSigner
{
    /**
     * @param array<string, scalar> $params
     */
    public static function sign(array $params, string $apiSecret): string
    {
        unset($params['format'], $params['callback']);
        ksort($params, SORT_STRING);

        $concat = '';
        foreach ($params as $key => $value) {
            $concat .= $key . $value;
        }

        return md5($concat . $apiSecret);
    }
}
