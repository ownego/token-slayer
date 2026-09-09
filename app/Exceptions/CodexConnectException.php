<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a Codex admin-provisioning upload fails validation — a
 * missing/undecodable identity claim in the uploaded auth.json, or a Step B
 * upload whose chatgpt_account_id doesn't match the target account (the
 * self-graft guard).
 */
class CodexConnectException extends Exception {}
