<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/** The node is temporarily unavailable (connection loss, discovery failure, shutdown). */
final class ServiceUnavailableException extends KurrentException {}
