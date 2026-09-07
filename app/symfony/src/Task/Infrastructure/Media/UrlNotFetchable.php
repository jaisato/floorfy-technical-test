<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

use App\Shared\Domain\Exception\ClientSafe;

/**
 * The guard will not let this URL be fetched.
 *
 * What callers of `PublicUrlGuard` catch, whether the answer is permanent or
 * not: the callback delivery, the request validator and the downloader all ask
 * one question - may I fetch this? - and the difference between a refusal and a
 * resolver that was down belongs to what they do next, not to whether they
 * catch it.
 */
interface UrlNotFetchable extends ClientSafe
{
}
