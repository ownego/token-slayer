<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when the model price feed cannot be fetched or read. Messages are
 * fixed sentences: nothing from the response body is ever echoed, since the
 * feed is an outside source.
 */
class ModelPriceFeedException extends Exception
{
    /**
     * The request did not complete (timeout, DNS, TLS, aborted download).
     *
     * @var string
     */
    public const string UNREACHABLE = 'The model price feed could not be reached.';

    /**
     * The feed answered with a status other than 200 or 304, a redirect included.
     *
     * @var string
     */
    public const string UNEXPECTED_STATUS = 'The model price feed answered with an unexpected status.';

    /**
     * The body is larger than the configured byte cap.
     *
     * @var string
     */
    public const string TOO_LARGE = 'The model price feed is larger than the allowed size.';

    /**
     * The body is not a JSON object within the depth cap.
     *
     * @var string
     */
    public const string MALFORMED = 'The model price feed is not a valid JSON object.';

    /**
     * Build the exception from one of the fixed messages above.
     *
     * @param  string  $message  one of this class's message constants
     * @param  ?Throwable  $previous  the underlying exception, if any
     * @return void
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
