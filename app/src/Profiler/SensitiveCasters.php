<?php

declare(strict_types=1);

namespace App\Profiler;

use App\Dto\Setting\SetKeyRequest;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Symfony\Component\VarDumper\Cloner\Stub;

/**
 * Keeps the API key out of the profiler's dumps (SEC-01, lot 09). The
 * request collector masks the `_password` field on its own, but other
 * panels dump whole objects: the validator's dumps the deserialized DTO
 * with the key in clear. A caster keyed on the class hides it everywhere
 * VarDumper runs, the profiler's panels included.
 *
 * Registered by Kernel::boot(): the cloner reads its casters statically,
 * before any request is handled.
 */
final class SensitiveCasters
{
    public static function register(): void
    {
        AbstractCloner::addDefaultCasters([
            SetKeyRequest::class => static function (SetKeyRequest $dto, array $a, Stub $stub, bool $isNested): array {
                foreach (array_keys($a) as $key) {
                    if (\str_ends_with((string) $key, '_password')) {
                        unset($a[$key]);
                    }
                }

                return $a + [Caster::PREFIX_VIRTUAL . '_password' => '******'];
            },
        ]);
    }
}
