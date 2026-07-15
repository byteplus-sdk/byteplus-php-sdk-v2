<?php

namespace Byteplus\Common\Auth\Providers;

use Byteplus\Common\ApiException;

/**
 * @internal
 *
 * Sentinel exception raised when an OAuth /token endpoint returns
 * HTTP 400 with {@code error: "invalid_grant"}. Used by the console-login
 * credential provider to trigger its disk-reload fallback (re-read the cache
 * once in case a concurrent {@code bp login} process rotated the token).
 *
 * This class is not part of the public credential provider API and is
 * caught at the provider boundary; users only ever see {@link ApiException}.
 */
class InvalidGrantApiException extends ApiException
{
}
