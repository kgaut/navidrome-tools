<?php

namespace App\LastFm;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Last.fm refused the API key itself (issue #309): invalid key (10) or
 * suspended key (26). Since 1 December 2026, a key stops working unless its
 * owner subscribes to Last.fm Pro — that's the expected way to land here.
 *
 * Unlike a rate limit or an outage, retrying cannot help: the exception is
 * unrecoverable for Messenger (no retry, so one failed run and one
 * notification instead of four), and its message is written for the user,
 * since it ends up in run history, notifications, flash messages and the
 * console as is.
 */
class LastFmKeyRejectedException extends LastFmApiException implements UnrecoverableExceptionInterface
{
    /** Last.fm error codes meaning « this key cannot be used ». */
    public const CODES = [10, 26];

    public function __construct(int $errorCode, string $errorMessage)
    {
        parent::__construct($errorCode, $errorMessage);
        $this->message = sprintf(
            'Last.fm a refusé la clé d\'API (erreur %d : %s). Depuis le 1er décembre 2026, une clé d\'API Last.fm '
            . 'exige un abonnement Last.fm Pro de son propriétaire : vérifiez LASTFM_API_KEY et l\'abonnement du compte.',
            $errorCode,
            $errorMessage,
        );
    }

    public static function isKeyRejectedCode(int $code): bool
    {
        return in_array($code, self::CODES, true);
    }
}
