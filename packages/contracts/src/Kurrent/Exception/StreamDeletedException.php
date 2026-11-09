<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/** The stream exists but is deleted (truncated-before); reads report not-found. */
final class StreamDeletedException extends KurrentException {}
