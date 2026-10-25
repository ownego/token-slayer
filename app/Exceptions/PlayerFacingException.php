<?php

namespace App\Exceptions;

use Exception;

/**
 * A failure whose message is written for the player: a refusal they can act on (a busy
 * lock, a full minion set, a missing draft), as opposed to a bug.
 *
 * The pose sheet editors catch this one class and show the message in the panel; anything
 * else is let through as the error it is. A new exception the player needs to read extends
 * this class and is picked up with no list to edit. An exception whose message was not
 * written for the player must not extend it.
 */
abstract class PlayerFacingException extends Exception {}
