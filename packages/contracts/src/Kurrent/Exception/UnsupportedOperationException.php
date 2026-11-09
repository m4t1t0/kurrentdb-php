<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/**
 * The frozen surface point is not implemented by this client version
 * (the amphp client fills the frozen methods in over v0.x releases).
 * Contract-test suites treat this as "not yet", not as failure.
 */
final class UnsupportedOperationException extends KurrentException {}
