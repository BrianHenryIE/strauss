<?php

/**
 * Thrown when parallel file analysis cannot be completed, e.g. a worker process cannot be started, exits early,
 * times out or sends unexpected output.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Parallel;

use RuntimeException;

/**
 * A plain {@see RuntimeException} subclass, thrown by {@see WorkerPool} and {@see WorkerProcessFactory}, so callers
 * can catch parallelisation failures separately from other errors.
 */
class ParallelisationException extends RuntimeException {}
