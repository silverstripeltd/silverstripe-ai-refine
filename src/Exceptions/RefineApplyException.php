<?php

namespace SilverstripeLtd\AiRefine\Exceptions;

use RuntimeException;

/**
 * Raised when selected suggestions cannot be applied because a target block denies editing.
 */
class RefineApplyException extends RuntimeException
{
}
