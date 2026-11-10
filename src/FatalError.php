<?php

declare(strict_types=1);

namespace WatchTower;

/**
 * A fatal PHP error (E_ERROR, E_PARSE, ...) found by error_get_last() at shutdown.
 */
final class FatalError extends \ErrorException {}
