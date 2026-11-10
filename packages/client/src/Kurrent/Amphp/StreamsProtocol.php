<?php

declare(strict_types=1);

namespace Kurrent\Amphp;

use Kurrent\Exception\WrongExpectedVersionException;

/**
 * Hand-built messages for the v1 Streams API
 * (`event_store.client.streams.Streams`), field-for-field from
 * `protos/kurrentdb/protocols/v1/streams.proto` + `shared.proto` (Go client
 * v1.4.2). Wire facts verified in the spike; replace with committed protoc
 * stubs when that workflow lands.
 */
final class StreamsProtocol
{
    /**
     * AppendReq.Options: stream_identifier=1 (field 3 bytes stream_name);
     * oneof expected_stream_revision: revision=2 (force) | no_stream=3 |
     * any=4 | stream_exists=5.
     *
     * @param string|array{0:'revision',1:int} $mode
     */
    public static function appendOptions(string $stream, string|array $mode): string
    {
        $byMode = ['no_stream' => 3, 'any' => 4, 'stream_exists' => 5];
        $out = Protobuf::msg(1, self::streamIdentifier($stream));
        if (\is_array($mode)) {
            [, $revision] = $mode;
            $out .= Protobuf::num(2, $revision, force: true);
        } else {
            $out .= Protobuf::msg($byMode[$mode], '');
        }

        return $out;
    }

    /**
     * AppendReq.ProposedMessage: id=1 (shared.UUID{structured=1; string=2}
     * → id uses the structured form, mirroring the Go client), metadata=2
     * (map entries key/value), custom_metadata=3, data=4. Clients send only
     * `type` + `content-type`; `created` is server-generated.
     */
    public static function proposedMessage(string $eventUuid, string $data, string $eventType, string $contentType): string
    {
        $meta =
            Protobuf::msg(2, Protobuf::bytes(1, 'type') . Protobuf::bytes(2, $eventType))
            . Protobuf::msg(2, Protobuf::bytes(1, 'content-type') . Protobuf::bytes(2, $contentType));

        [$eventBytes] = self::uuidBytesFromString($eventUuid);
        // id (field 1) → shared.UUID (message) → structured oneof member (field 1) → Structured{most=1, least=2}
        $id = Protobuf::msg(1, Protobuf::msg(
            1,
            Protobuf::int64Bytes(1, \substr($eventBytes, 0, 8)) . Protobuf::int64Bytes(2, \substr($eventBytes, 8, 8)),
        ));

        return $id . $meta . Protobuf::bytes(4, $data);
    }

    /** AppendReq{content: options} (Append RPC first frame). */
    public static function appendReqOptions(string $stream, string|array $mode): string
    {
        return Protobuf::msg(1, self::appendOptions($stream, $mode));
    }

    /** AppendReq{content: proposed_message} (Append RPC event frame) — field 2, not 1! */
    public static function appendReqMessage(string $eventUuid, string $data, string $eventType, string $contentType): string
    {
        return Protobuf::msg(2, self::proposedMessage($eventUuid, $data, $eventType, $contentType));
    }

    /**
     * ReadReq.Options: stream_option.stream=1
     * (StreamOptions{stream_identifier=1; revision_option{revision=2|start=3|end=4}}),
     * count=5 (force), no_filter=8, uuid_option=9 (structured). uuidOption is
     * **required** by current servers (26.x verifies presence).
     */
    public static function readReq(string $stream, int $count, null|int|string $from = 'start', bool $backwards = false): string
    {
        $streamOptions = Protobuf::msg(1, self::streamIdentifier($stream));
        $streamOptions .= match ($from) {
            null, 'start' => Protobuf::msg(3, ''),
            'end' => Protobuf::msg(4, ''),
            default => Protobuf::num(2, $from, force: true),
        };

        return Protobuf::msg(1, self::readReqPayload(
            Protobuf::msg(1, $streamOptions), // stream (vs all=2)
            $count,
            $backwards,
        ));
    }

    /**
     * ReadReq on `$all`: all=2 (AllOptions{start=2|end=4|position=1{commit,prepare}})
     * + count + no_filter + uuid_option.
     *
     * @param string|array{0:int,1:int}|null $from 'start'|'end'|Position pair
     */
    /**
     * ReadReq on `$all`: options.all_option = AllOptions
     * (streams.proto:53 — position=1{commit,prepare}; start=2; **end=3**),
     * count + no_filter + uuid_option.
     *
     * NOTE the asymmetry with {@see readReq()}: StreamOptions (single stream)
     * has `end` at field 4, AllOptions has it at field 3 — cited, not
     * guessed (rule 8's whole point).
     *
     * @param string|array{0:int,1:int}|null $from 'start'|'end'|Position pair
     */
    public static function readAllReq(string|array|null $from = 'start', int $count = 100, bool $backwards = false): string
    {
        $allOptions = match (true) {
            $from === null || $from === 'start' => Protobuf::msg(2, ''),
            $from === 'end' => Protobuf::msg(3, ''), // AllOptions.end = field 3 (streams.proto:57)
            default => Protobuf::msg(1, Protobuf::num(1, $from[0], force: true) . Protobuf::num(2, $from[1], force: true)),
        };

        return Protobuf::msg(1, self::readReqPayload(Protobuf::msg(2, $allOptions), $count, $backwards));
    }

    private static function readReqPayload(string $positionOption, int $count, bool $backwards = false): string
    {
        return $positionOption
            . Protobuf::num(3, $backwards ? 1 : 0) // (ReadReq.Options) ReadDirection read_direction=3: Backwards=1, Forwards=0 omitted
            . Protobuf::num(5, $count, force: true)
            . Protobuf::msg(8, '')  // no_filter
            . Protobuf::msg(9, Protobuf::msg(1, '')); // uuid_option: structured
    }

    /** event_store.client.StreamIdentifier { bytes stream_name = 3 } — field 3! */
    private static function streamIdentifier(string $stream): string
    {
        return Protobuf::bytes(3, $stream);
    }

    /** Parse AppendResp: Success=1 (current_revision/position) | WrongExpectedVersion=2 (20.6.0 fields). */    public static function parseAppendResponse(string $msg): array
    {
        $out = ['result' => null, 'current_revision' => null, 'position' => null, 'current' => null, 'expected' => null];
        foreach (Protobuf::fields($msg) as [$field, , $value]) {
            $out['result'] ??= match ($field) {
                1 => 'success',
                2 => 'wrong_expected_version',
                default => throw new \RuntimeException("unexpected AppendResp field $field"),
            };
            if ($out['result'] === 'success' && $field === 1) {
                foreach (Protobuf::fields($value) as [$f, , $v]) {
                    match (true) {
                        $f === 1 => $out['current_revision'] = $v,
                        $f === 2 => $out['current_revision'] = 'no_stream',
                        $f === 3 => $out['position'] = self::positionOf(Protobuf::fields($v)),
                        default => null,
                    };
                }
            }
            if ($out['result'] === 'wrong_expected_version' && $field === 2) {
                foreach (Protobuf::fields($value) as [$f, , $v]) {
                    // 6-11 = post-20.6.0 fields; 1-5 = older spellings
                    $out['current'] ??= match ($f) {
                        6, 1 => $v,
                        7, 2 => 'no_stream',
                        default => null,
                    };
                    $out['expected'] ??= match ($f) {
                        8, 3 => $v,
                        9, 4 => 'any',
                        10, 5 => 'stream_exists',
                        11 => 'no_stream',
                        default => null,
                    };
                }
            }
        }

        return $out;
    }

    /** AppendResp.Position / shared position message → commit/prepare pair. */
    private static function positionOf(array $fields): array
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

    /** Parse one ReadResp: event=1, stream_not_found=4 (others: confirmation etc). */
    public static function parseReadResponse(string $msg): array
    {
        foreach (Protobuf::fields($msg) as [$field, , $value]) {
            if ($field === 4) {
                return ['type' => 'stream_not_found'];
            }
            if ($field === 1) {
                $event = ['type' => 'event'];
                foreach (Protobuf::fields($value) as [$f, , $v]) {
                    match ($f) {
                        1 => $event['event'] = self::parseRecordedEvent(Protobuf::fields($v)),
                        3 => $event['commit_position'] = $v,
                        default => null,
                    };
                }

                return $event;
            }
        }

        return ['type' => 'unknown'];
    }

    /** ReadResp.ReadEvent.RecordedEvent (revision 0 is omitted — proto3 zero scalars; nonzero revisions arrive at field 3). */
    private static function parseRecordedEvent(array $fields): array
    {
        $event = [
            'event_id' => null,
            'event_type' => null,
            'content_type' => null,
            'stream_name' => null,
            'stream_revision' => null,
            'commit_position' => null,
            'prepare_position' => null,
            'data' => null,
            'custom_metadata' => null,
        ];
        foreach ($fields as [$field, , $value]) {
            match ($field) {
                1 => $event['event_id'] = self::parseUuid(Protobuf::fields($value)),
                2 => $event['stream_name'] = self::parseStreamName(Protobuf::fields($value)),
                3 => $event['stream_revision'] = $value, // (ReadResp.ReadEvent.RecordedEvent) uint64 stream_revision=3, zero-based; absent only when 0
                4 => $event['prepare_position'] = $value,
                5 => $event['commit_position'] = $value,
                6 => $event['metadata_entries'][] = $value,
                7 => $event['custom_metadata'] = $value,
                8 => $event['data'] = $value,
                default => null,
            };
        }
        // metadata entries arrive one per field-6 occurrence (map<string,string>)
        $metadataMap = [];
        foreach ((array) ($event['metadata_entries'] ?? []) as $entryPayload) {
            $entry = Protobuf::fields((string) $entryPayload);
            $metadataMap[Protobuf::field($entry, 1) ?? ''] = Protobuf::field($entry, 2) ?? '';
        }
        $event['event_type'] = $metadataMap['type'] ?? null;
        $event['content_type'] = $metadataMap['content-type'] ?? null;
        $event['created_ticks'] = $metadataMap['created'] ?? null;
        // user metadata for the envelope is the RAW custom_metadata bytes (field 7);
        // the system map (type/content-type/created) is transport detail
        $event['metadata'] ??= null;
        unset($event['metadata_entries']);

        return $event;
    }

    /** StreamIdentifier wire payload → name (stream_name sits at field 3, bytes). */
    private static function parseStreamName(array $fields): string
    {
        return (string) (Protobuf::field($fields, 3) ?? '');
    }

    /**
     * shared.UUID oneof: structured=1 (Structured{most=1,least=2} message) |
     * string=2. Reconstruct the 128 bits big-endian (most, then least).
     */
    private static function parseUuid(array $fields): string
    {
        $structured = Protobuf::field($fields, 1);
        if ($structured !== null) {
            $inner = Protobuf::fields((string) $structured);
            $most = (int) (Protobuf::field($inner, 1) ?? 0);
            $least = (int) (Protobuf::field($inner, 2) ?? 0);
            $bytes = \pack('N', $most >> 32) . \pack('N', $most) . \pack('N', $least >> 32) . \pack('N', $least);

            return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
        }
        $string = Protobuf::field($fields, 2);
        if (\is_string($string)) {
            return $string;
        }

        throw new \RuntimeException('UUID with neither structured (1) nor string (2) form');
    }

    /**
     * RFC 4122 string → 16 raw bytes (canonical parse with hyphen layout).
     *
     * @return array{0:string,1:string}
     */
    public static function uuidBytesFromString(string $uuid): array
    {
        $hex = \str_replace('-', '', $uuid);
        if (\strlen($hex) !== 32 || !\ctype_xdigit($hex)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an RFC 4122 UUID string', $uuid));
        }

        return [\hex2bin($hex), $uuid];
    }

    /** Turn the parsed append result into the typed contract exception. */
    public static function wrongExpectedVersion(mixed $current, mixed $expected, string $stream): WrongExpectedVersionException
    {
        return new WrongExpectedVersionException(
            currentRevision: \is_int($current) ? $current : null,
            expectedRevision: \is_int($expected) ? $expected : (\is_string($expected) ? $expected : null),
            message: \sprintf(
                'The stream "%s" changed during append (current=%s, expected=%s).',
                $stream,
                \var_export($current, true),
                \var_export($expected, true),
            ),
        );
    }
}
