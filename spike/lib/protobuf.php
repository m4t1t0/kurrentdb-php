<?php

declare(strict_types=1);

/**
 * Protobuf wire encoding — the 60 lines of spec that make gRPC legible.
 * Spike-quality: supports only the wire types KurrentDB's Streams API uses
 * (varint + length-delimited). Learned from docs.google.com/protocol-buffers
 * encoding spec; do not use elsewhere without reading order/milling edge cases.
 */

const PB_VARINT = 0; // wire type 0
const PB_I64 = 1;    // wire type 1 (fixed64)
const PB_LEN = 2;    // wire type 2 (length-delimited)
const PB_I32 = 5;    // wire type 5 (fixed32)

function pb_tag(int $field, int $wire): string
{
    return pb_varint(($field << 3) | $wire);
}

function pb_varint(int $value): string
{
    if ($value < 0) {
        // int64 negatives are encoded as 10-byte two's complement varints;
        // unnecessary for the spike (uint64 positions fit in PHP int on 64-bit).
        throw new InvalidArgumentException('negative varint not supported in spike');
    }
    $out = '';
    do {
        $byte = $value & 0x7f;
        $value >>= 7;
        $out .= chr($byte | ($value !== 0 ? 0x80 : 0));
    } while ($value !== 0);

    return $out;
}

/** Length-delimited field carrying a submessage. */
function pb_msg(int $field, string $bytes): string
{
    return pb_tag($field, PB_LEN) . pb_varint(strlen($bytes)) . $bytes;
}

function pb_str(int $field, string $string): string
{
    return pb_msg($field, $string);
}

function pb_bytes(int $field, string $bytes): string
{
    return pb_msg($field, $bytes);
}

/** Varint field. Pass $force=true for oneof members (oneofs are serialized even when zero). */
function pb_num(int $field, int $value, bool $force = false): string
{
    if ($value === 0 && !$force) {
        return ''; // proto3 zero-value scalars are omitted outside oneofs
    }

    return pb_tag($field, PB_VARINT) . pb_varint($value);
}

/** Read one varint at $pos (by reference). */
function pb_read_varint(string $buf, int &$pos): int
{
    $result = 0;
    $shift = 0;
    $len = strlen($buf);
    while (true) {
        if ($pos >= $len) {
            throw new RuntimeException('truncated varint');
        }
        $byte = ord($buf[$pos++]);
        $result |= ($byte & 0x7f) << $shift;
        if (($byte & 0x80) === 0) {
            return $result;
        }
        $shift += 7;
        if ($shift >= 70) {
            throw new RuntimeException('varint too long');
        }
    }
}

/**
 * Decode a message into a list of [field, wire, value] tuples
 * (value varies by wire type: int for varint, string for LEN).
 */
function pb_fields(string $buf): array
{
    $pos = 0;
    $fields = [];
    $len = strlen($buf);
    while ($pos < $len) {
        $key = pb_read_varint($buf, $pos);
        $field = $key >> 3;
        $wire = $key & 7;
        switch ($wire) {
            case PB_VARINT:
                $fields[] = [$field, $wire, pb_read_varint($buf, $pos)];
                break;
            case PB_LEN:
                $size = pb_read_varint($buf, $pos);
                if ($pos + $size > $len) {
                    throw new RuntimeException('truncated length-delimited field');
                }
                $fields[] = [$field, $wire, substr($buf, $pos, $size)];
                $pos += $size;
                break;
            case PB_I64:
                $fields[] = [$field, $wire, substr($buf, $pos, 8)];
                $pos += 8;
                break;
            case PB_I32:
                $fields[] = [$field, $wire, substr($buf, $pos, 4)];
                $pos += 4;
                break;
            default:
                throw new RuntimeException("unsupported wire type $wire");
        }
    }

    return $fields;
}

/** Relaxed take: value of the first occurrence of $field, or null. */
function pb_field(array $fields, int $field): mixed
{
    foreach ($fields as [$f, , $v]) {
        if ($f === $field) {
            return $v;
        }
    }

    return null;
}
