<?php

declare(strict_types=1);

/**
 * The slice of KurrentDB's Streams API the spike needs, hand-encoded from
 * protos/kurrentdb/protocols/v1/streams.proto + shared.proto (Go client
 * v1.4.2). Field numbers here are the wire contract — keep them in lockstep
 * with the .proto while writing, then let protoc-generated stubs replace
 * this whole file when the committed-stub workflow lands.
 */

/** Generate an RFC 4122 v4 UUID: [16 raw bytes, canonical hyphenated string]. */
function uuid_pair(): array
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant
    $string = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

    return [$bytes, $string];
}

/**
 * Signed 64-bit varint encoded directly from 8 raw bytes (big-endian),
 * because PHP ints cannot shift in the >2^32 bit ranges safely. The varint
 * is 10 groups of 7 bits, LSB first; group 9 carries only bit 63.
 */
function pb_int64_bytes(int $field, string $eightBytes): string
{
    if (strlen($eightBytes) !== 8) {
        throw new InvalidArgumentException('int64 field needs exactly 8 bytes');
    }
    // LSB-first bit string of the two's-complement int64 made from big-endian
    // bytes: the last byte's LSB first, each byte's bits kept LSB-first
    $bits = '';
    for ($i = 7; $i >= 0; $i--) {
        $bits .= strrev(sprintf('%08b', ord($eightBytes[$i])));
    }
    // 7-bit chunks, each serialized LSB-first; 10 bytes total, the 10th
    // carrying only value bit 63
    $out = '';
    for ($i = 0; $i <= 9; $i++) {
        $slice = substr($bits, $i * 7, 7);
        $chunk = bindec(strrev(str_pad($slice, 7, '0')));
        $out .= chr($chunk | ($i === 9 ? 0x00 : 0x80));
    }

    return pb_tag($field, PB_VARINT) . $out;
}

/** shared.UUID.Structured { MostSignificantBits=1; LeastSignificantBits=2; } */
function structured_uuid_payload(string $bytes): string
{
    return pb_int64_bytes(1, substr($bytes, 0, 8)) . pb_int64_bytes(2, substr($bytes, 8, 8));
}

/** event_store.client.StreamIdentifier { bytes stream_name = 3; } */
function stream_identifier(string $stream): string
{
    return pb_bytes(3, $stream); // field 3, bytes — not field 1!
}

/** event_store.client.Empty {} */
function pb_empty(): string
{
    return '';
}

/**
 * AppendReq.Options: stream_identifier=1; oneof expected_stream_revision:
 * revision (varint, force) | no_stream=3 | any=4 | stream_exists=5 (Empty).
 * $mode: 'no_stream' | 'any' | 'stream_exists' | ['revision', int]
 */
function append_options(string $stream, array|string $mode = 'any'): string
{
    $fieldByMode = ['no_stream' => 3, 'any' => 4, 'stream_exists' => 5];
    $out = pb_msg(1, stream_identifier($stream));
    if (is_array($mode)) {
        [$name, $revision] = $mode;
        $out .= pb_num(2, $revision, force: true);
    } else {
        $out .= pb_msg($fieldByMode[$mode], pb_empty());
    }

    return $out;
}

/** AppendReq.ProposedMessage.id = shared.UUID{ structured (field 1) = Structured{ most=1, least=2 } }. */
function proposed_message(string $uuidBytes, string $data, array $metadata): string
{
    $meta = '';
    foreach ($metadata as $key => $value) {
        $meta .= pb_msg(2, pb_str(1, $key) . pb_str(2, $value));
    }

    // id: field 1 → shared.UUID (message) whose structured oneof member is field 1 → Structured{most, least}
    return pb_msg(1, pb_msg(1, structured_uuid_payload($uuidBytes))) . $meta . pb_bytes(4, $data);
}

/**
 * ReadReq.Options: oneof stream_option { StreamOptions stream=1; AllOptions all=2; }
 * StreamOptions { StreamIdentifier stream_identifier=1; oneof revision_option:
 * revision=2 | start=3 | end=4 }, ReadDirection read_direction=3,
 * bool resolve_links=4, oneof count_option { uint64 count=5 | SubscriptionOptions subscription=6 },
 * oneof filter_option { FilterOptions filter=7 | Empty no_filter=8 }, ...
 */
function read_options(string $stream, int $count, string $from = 'start'): string
{
    $streamOptions = pb_msg(1, stream_identifier($stream));
    $streamOptions .= match ($from) {
        'start' => pb_msg(3, pb_empty()),
        'end' => pb_msg(4, pb_empty()),
        default => pb_num(2, $from, force: true),
    };

    return pb_msg(1, $streamOptions) // stream = 1 (vs all = 2)
        . pb_num(5, $count, force: true) // count (inside oneof → always serialized)
        . pb_msg(8, pb_empty()) // no_filter
        . pb_msg(9, pb_msg(1, pb_empty())); // uuid_option.content = structured
}

/** Wrap in the outer messages: AppendReq{oneof content{ Options options=1 | ProposedMessage proposed_message=2 }}. */
function append_req_options(string $stream, array|string $mode): string
{
    return pb_msg(1, append_options($stream, $mode));
}

function append_req_message(string $uuidBytes, string $data, array $metadata): string
{
    return pb_msg(2, proposed_message($uuidBytes, $data, $metadata)); // field 2 — NOT the Options field
}

/** ReadReq for `$all`: options.stream_option = AllOptions{all_option{start=2 | end=4 | position=1}}. */
function read_all_options(int $count, string $from = 'start'): string
{
    $allOptions = match ($from) {
        'start' => pb_msg(2, pb_empty()),
        'end' => pb_msg(4, pb_empty()),
        default => pb_msg(1, pb_num(1, $from['commit'], force: true) . pb_num(2, $from['prepare'], force: true)),
    };

    return pb_msg(2, $allOptions) // all (vs stream = 1)
        . pb_num(5, $count, force: true)
        . pb_msg(8, pb_empty()) // no_filter
        . pb_msg(9, pb_msg(1, pb_empty())); // uuid_option.content = structured
}

function read_req(string $stream, int $count, string $from = 'start'): string
{
    return pb_msg(1, read_options($stream, $count, $from));
}

/** ReadReq on `$all` — 'from' is 'start'|'end'|{commit, prepare}. */
function read_all_req(int|string|array $from = 'start', int $count = 100000): string
{
    return pb_msg(1, read_all_options($count, $from));
}

/** gRPC message framing: compression flag byte (0) + big-endian uint32 length. */
function grpc_frame(string $message): string
{
    return "\x00" . pack('N', strlen($message)) . $message;
}

/** Incrementally yields complete gRPC frames from body chunks. */
function grpc_frames(iterable $chunks): \Generator
{
    $buffer = '';
    foreach ($chunks as $chunk) {
        $buffer .= $chunk;
        while (strlen($buffer) >= 5) {
            $len = unpack('N', substr($buffer, 1, 4))[1];
            if (strlen($buffer) < 5 + $len) {
                break; // wait for the next chunk
            }
            yield substr($buffer, 5, $len);
            $buffer = substr($buffer, 5 + $len);
        }
    }
    if ($buffer !== '') {
        throw new RuntimeException('truncated gRPC frame: ' . bin2hex($buffer));
    }
}

/** Parse AppendResp: oneof result { Success success=1 | WrongExpectedVersion wrong_expected_version=2 }. */
function parse_append_response(string $msg): array
{
    $out = ['result' => null, 'current_revision' => null, 'position' => null, 'expected' => null];
    foreach (pb_fields($msg) as [$field, , $value]) {
        $out['result'] ??= match ($field) {
            1 => 'success',
            2 => 'wrong_expected_version',
            default => throw new RuntimeException("unexpected AppendResp field $field"),
        };
        if ($out['result'] === 'success' && $field === 1) {
            foreach (pb_fields($value) as [$f, , $v]) {
                match (true) {
                    $f === 1 => $out['current_revision'] = $v,          // uint64 (0 = stream was empty)
                    $f === 2 => $out['current_revision'] = 'no_stream', // Empty (pre-existing empty stream)
                    $f === 3 => $out['position'] = position_of(pb_fields($v)),
                    default => null,
                };
            }
        }
        if ($out['result'] === 'wrong_expected_version' && $field === 2) {
            foreach (pb_fields($value) as [$f, , $v]) {
                // fields 6-11 are the post-20.6.0 spellings; 1-5 the older ones
                $out['current'] ??= match ($f) {
                    6 => $v, 7 => 'no_stream', 1 => $v, 2 => 'no_stream',
                    default => null,
                };
                $out['expected'] ??= match ($f) {
                    8 => $v, 9 => 'any', 10 => 'stream_exists', 11 => 'no_stream', 3 => $v, 4 => 'any', 5 => 'stream_exists',
                    default => null,
                };
            }
        }
    }

    return $out;
}

/** AppendResp.Position → commit/prepare pair. */
function position_of(array $fields): array
{
    $position = ['commit' => null, 'prepare' => null];
    foreach ($fields as [$field, , $value]) {
        match ($field) {
            1 => $position['commit'] = $value,
            2 => $position['prepare'] = $value,
            default => null,
        };
    }

    return $position;
}

/** Parse a ReadResp; returns ['type' => 'event'|'stream_not_found'|..., ...]. */
function parse_read_response(string $msg): array
{
    foreach (pb_fields($msg) as [$field, , $value]) {
        if ($field === 4) {
            return ['type' => 'stream_not_found'];
        }
        if ($field === 1) { // ReadEvent
            $event = ['type' => 'event'];
            foreach (pb_fields($value) as [$f, , $v]) {
                match ($f) {
                    1 => $event['event'] = parse_recorded_event(pb_fields($v)),
                    3 => $event['commit_position'] = $v,
                    default => null,
                };
            }

            return $event;
        }
    }

    return ['type' => 'unknown'];
}

/** ReadResp.ReadEvent.RecordedEvent. */
function parse_recorded_event(array $fields): array
{
    $event = [
        'event_id' => null,
        'stream_revision' => null,
        'commit_position' => null,
        'prepare_position' => null,
        'data' => null,
        'metadata' => [],
    ];
    foreach ($fields as [$field, , $value]) {
        match ($field) {
            1 => $event['event_id'] = parse_uuid(pb_fields($value)), // shared.UUID (string or structured form)
            3 => $event['stream_revision'] = $value,
            5 => $event['commit_position'] = $value,
            4 => $event['prepare_position'] = $value,
            8 => $event['data'] = $value,
            6 => $event['metadata'] += parse_metadata_entry($value),
            default => null,
        };
    }

    return $event;
}

/** shared.UUID oneof: structured (field 1) | string value (field 2); closed-pair halves are big-endian. */
function parse_uuid(array $fields): string
{
    $structured = pb_field($fields, 1);
    if ($structured !== null) {
        $inner = pb_fields(is_string($structured) ? $structured : '');
        $most = (int) (pb_field($inner, 1) ?? 0);
        $least = (int) (pb_field($inner, 2) ?? 0);
        // reconstruct the 128-bit id: most = high 64 bits, least = low 64 bits, big-endian
        $bytes = pack('N', $most >> 32) . pack('N', $most) . pack('N', $least >> 32) . pack('N', $least);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
    $string = pb_field($fields, 2);
    if (is_string($string)) {
        return $string;
    }

    throw new RuntimeException('UUID with neither structured (1) nor string (2) form');
}

/** A repeated map entry arrives one at a time (key=1, value=2); returns [key => value]. */
function parse_metadata_entry(string $entry): array
{
    $fields = pb_fields($entry);
    $key = pb_field($fields, 1);

    return [$key ?? '' => pb_field($fields, 2) ?? ''];
}
