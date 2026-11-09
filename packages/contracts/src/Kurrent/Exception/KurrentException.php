<?php

declare(strict_types=1);

namespace Kurrent\Exception;

/**
 * Catch-all base exception of the contracts package.
 *
 * Mirrors the Python reference's `UnknownError` fallback: any gRPC outcome the
 * typed subset below doesn't cover collapses here, with `getMessage()` (and,
 * where transport exposes it, the gRPC status) intact. Rich
 * `grpc-status-details-bin` decoding into further typed exceptions is a later
 * non-breaking addition.
 */
class KurrentException extends \RuntimeException {}
