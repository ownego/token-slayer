<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a reserve token picked for assignment can no longer be handed
 * out: another admin used or discarded it meanwhile, it expired, or it
 * belongs to a different account than the one being provisioned.
 */
class ReserveTokenUnavailableException extends RuntimeException {}
