<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The session was opened offline (the portal was unreachable at login), so
 * nothing may be fetched from or sent to the portal; only the archive is read.
 *
 * A scraping exception so extractors pass it on unchanged, never one that
 * ends the session.
 */
class PortalOfflineException extends PortalScrapingException
{
    public static function offlineSession(): self
    {
        return new self('The campus session is in offline (archive) mode', 503);
    }
}
