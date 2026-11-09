<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/** The per-call deadline (grpc-timeout / CallOptions::timeout()) expired. */
final class DeadlineExceededException extends KurrentException {}
