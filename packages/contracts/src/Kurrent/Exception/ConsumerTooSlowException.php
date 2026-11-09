<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/** A persistent subscription reported the consumer too slow (buffer overflow on the server). */
final class ConsumerTooSlowException extends KurrentException {}
