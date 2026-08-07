<?php

namespace cstudiossro\craftcschatbot\helpers;

/**
 * Storage format for embedding vectors.
 *
 * Packed little-endian float32: a 1536-dimension vector takes 6 KB instead of
 * the ~30 KB the same numbers cost as JSON, and unpacking is far cheaper than
 * parsing. The precision lost is well below what cosine similarity between
 * embeddings can distinguish.
 */
class Vector
{
    /**
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...array_map('floatval', array_values($vector)));
    }

    /**
     * Decode a stored vector in either format — packed binary, or the JSON that
     * chunks indexed before the packed format still hold.
     *
     * @return float[]
     */
    public static function unpack(?string $packed, ?string $json = null): array
    {
        if ($packed !== null && $packed !== '') {
            $values = @unpack('g*', $packed);
            if (is_array($values) && $values !== []) {
                return array_values($values);
            }
        }
        if ($json !== null && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded) && $decoded !== []) {
                return array_map('floatval', array_values($decoded));
            }
        }
        return [];
    }
}
