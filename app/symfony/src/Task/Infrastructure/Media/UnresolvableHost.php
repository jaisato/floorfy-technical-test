<?php

declare(strict_types=1);

namespace App\Task\Infrastructure\Media;

/**
 * The host could not be resolved - which is not the same as being refused.
 *
 * Deliberately *not* a PermanentFailure, unlike everything in `BlockedUrl`. A
 * scheme, a port or a private address is a property of the URL and the
 * twentieth attempt reaches the first one's conclusion; a name that did not
 * resolve is a property of the moment. `dns_get_record()` cannot tell NXDOMAIN
 * from a resolver that timed out - both come back empty - so the two have to be
 * treated as one thing, and the choice is which way to be wrong.
 *
 * Called permanent, a resolver outage of a few seconds failed every task whose
 * image was being fetched during it, with no retry, and marked their callbacks
 * abandoned so the recovery sweep would never offer them again: a task lost for
 * good over a blip. Called transient, a host that genuinely does not exist
 * spends its retries and then fails terminally with this same message. The
 * second costs a handful of retries; the first costs the work.
 */
final class UnresolvableHost extends \RuntimeException implements UrlNotFetchable
{
    public static function host(string $host): self
    {
        return new self(\sprintf('No se pudo resolver el host "%s".', $host));
    }
}
