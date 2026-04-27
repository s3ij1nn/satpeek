<?php

namespace App\Shortlinks\Providers;

use RuntimeException;

/**
 * Raised when an upstream shortener API rejects a request: HTTP failure,
 * `status: error` body, missing/empty shortenedUrl, or absent credentials.
 */
class ShortenerException extends RuntimeException {}
