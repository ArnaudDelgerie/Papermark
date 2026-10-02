<?php

declare(strict_types=1);

namespace App\Profiler;

use Symfony\Component\Serializer\DataCollector\SerializerDataCollector;

/**
 * The serializer's profiler panel dumps what came in — the request body
 * included, whose `_password` field carries the API key in clear (SEC-01,
 * lot 09). The collector keeps no hook to mask it, so this subclass redacts
 * the field on the way in; the panel stays, the key does not.
 *
 * Swapped in for `serializer.data_collector` by the Kernel's build pass.
 */
final class RedactingSerializerDataCollector extends SerializerDataCollector
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $caller
     */
    public function collectDenormalize(string $traceId, mixed $data, string $type, ?string $format, array $context, float $time, array $caller, string $name): void
    {
        parent::collectDenormalize($traceId, self::redact($data), $type, $format, $context, $time, $caller, $name);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $caller
     */
    public function collectDeserialize(string $traceId, mixed $data, string $type, string $format, array $context, float $time, array $caller, string $name): void
    {
        parent::collectDeserialize($traceId, self::redact($data), $type, $format, $context, $time, $caller, $name);
    }

    /**
     * Masks the profiler's own sensitive field name, at any depth. A
     * string body (JSON, form-encoded) has the field's value masked in
     * place; everything else in it stays readable.
     */
    private static function redact(mixed $data): mixed
    {
        if (\is_string($data)) {
            $data = (string) preg_replace('/(_password"\s*:\s*")[^"]*(")/', '$1******$2', $data);

            return preg_replace('/(_password=)[^&]*/', '$1******', $data);
        }

        if (!\is_array($data)) {
            return $data;
        }

        $redacted = [];
        foreach ($data as $key => $value) {
            $redacted[$key] = '_password' === $key ? '******' : self::redact($value);
        }

        return $redacted;
    }
}
