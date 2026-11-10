<?php

declare(strict_types=1);

namespace Kurrent\Amphp;

/**
 * Protobuf wire encoding for the KurrentDB gRPC protocol — varint plus
 * length-delimited (everything the Streams API uses). Battle-tested in the
 * spike; the committed-stubs workflow will replace its usage, not its logic.
 */
final class Protobuf
{
    public const VARINT = 0;
    public const I64 = 1;
    public const LEN = 2;
    public const I32 = 5;

    public static function tag(int $field, int $wire): string
    {
        return self::varint(($field << 3) | $wire);
    }

    public static function varint(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('negative varint: use int64Bytes() for signed 64-bit fields');
        }
        $out = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            $out .= \chr($byte | ($value !== 0 ? 0x80 : 0));
        } while ($value !== 0);

        return $out;
    }

    public static function msg(int $field, string $bytes): string
    {
        return self::tag($field, self::LEN) . self::varint(\strlen($bytes)) . $bytes;
    }

    public static function bytes(int $field, string $bytes): string
    {
        return self::msg($field, $bytes);
    }

    /**
     * Varint field; pass $force to serialize oneof members even when zero.
     */
    public static function num(int $field, int $value, bool $force = false): string
    {
        if ($value === 0 && !$force) {
            return '';
        }

        return self::tag($field, self::VARINT) . self::varint($value);
    }

    /**
     * Signed 64-bit varint encoded directly from 8 raw big-endian bytes
     * (PHP ints cannot shift past 2^32 safely): 7-bit groups LSB-first,
     * 10 bytes, the 10th carrying only value bit 63.
     */
    public static function int64Bytes(int $field, string $eightBytes): string
    {
        if (\strlen($eightBytes) !== 8) {
            throw new \InvalidArgumentException('int64 field needs exactly 8 bytes');
        }
        $bits = '';
        for ($i = 7; $i >= 0; $i--) {
            $bits .= \strrev(\sprintf('%08b', \ord($eightBytes[$i])));
        }
        $out = '';
        for ($i = 0; $i <= 9; $i++) {
            $chunk = \bindec(\strrev(\str_pad(\substr($bits, $i * 7, 7), 7, '0')));
            $out .= \chr($chunk | ($i === 9 ? 0x00 : 0x80));
        }

        return self::tag($field, self::VARINT) . $out;
    }

    public static function readVarint(string $buf, int &$pos): int
    {
        $result = 0;
        $shift = 0;
        $len = \strlen($buf);
        while (true) {
            if ($pos >= $len) {
                throw new \RuntimeException('truncated varint');
            }
            $byte = \ord($buf[$pos++]);
            $result |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
            if ($shift >= 70) {
                throw new \RuntimeException('varint too long');
            }
        }
    }

    /**
     * Decode a message into a list of [field, wire, value] tuples.
     *
     * @return list<array{0:int,1:int,2:mixed}>
     */
    public static function fields(string $buf): array
    {
        $pos = 0;
        $fields = [];
        $len = \strlen($buf);
        while ($pos < $len) {
            $key = self::readVarint($buf, $pos);
            $field = $key >> 3;
            $wire = $key & 7;
            switch ($wire) {
                case self::VARINT:
                    $fields[] = [$field, $wire, self::readVarint($buf, $pos)];
                    break;
                case self::LEN:
                    $size = self::readVarint($buf, $pos);
                    if ($pos + $size > $len) {
                        throw new \RuntimeException('truncated length-delimited field');
                    }
                    $fields[] = [$field, $wire, \substr($buf, $pos, $size)];
                    $pos += $size;
                    break;
                case self::I64:
                    $fields[] = [$field, $wire, \substr($buf, $pos, 8)];
                    $pos += 8;
                    break;
                case self::I32:
                    $fields[] = [$field, $wire, \substr($buf, $pos, 4)];
                    $pos += 4;
                    break;
                default:
                    throw new \RuntimeException("unsupported wire type $wire");
            }
        }

        return $fields;
    }

    /** Value of the first occurrence of $field, or null. */
    public static function field(array $fields, int $field): mixed
    {
        foreach ($fields as [$f, , $v]) {
            if ($f === $field) {
                return $v;
            }
        }

        return null;
    }

    /** gRPC message framing: compression flag byte (0) + big-endian uint32 length. */
    public static function grpcFrame(string $message): string
    {
        return "\x00" . \pack('N', \strlen($message)) . $message;
    }

    /** Incrementally yields complete gRPC frames from body chunks. */
    public static function grpcFrames(iterable $chunks): \Generator
    {
        $buffer = '';
        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            while (\strlen($buffer) >= 5) {
                $len = \unpack('N', \substr($buffer, 1, 4))[1];
                if (\strlen($buffer) < 5 + $len) {
                    break; // wait for the next chunk
                }
                yield \substr($buffer, 5, $len);
                $buffer = \substr($buffer, 5 + $len);
            }
        }
        if ($buffer !== '') {
            throw new \RuntimeException('truncated gRPC frame (first 64 bytes): ' . \substr(\bin2hex($buffer), 0, 128));
        }
    }
}
