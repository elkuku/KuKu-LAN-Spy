<?php

declare(strict_types=1);

namespace LanSpy\Util;

use RuntimeException;

/** An error to report on stderr; the code is the exit status. */
final class CliError extends RuntimeException {}
