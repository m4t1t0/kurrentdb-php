<?php

declare(strict_types=1);

namespace Kurrent;

/** Which server-side filter axis a {@see ReadFilter} narrows by. */
enum ReadFilterKind
{
    /** Filter by stream names (oneof `stream_identifier`). */
    case StreamName;

    /** Filter by event-type prefix (oneof `event_type`). */
    case EventTypePrefix;
}
