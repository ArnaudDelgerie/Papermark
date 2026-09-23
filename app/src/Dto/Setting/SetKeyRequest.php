<?php

declare(strict_types=1);

namespace App\Dto\Setting;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /settings/provider/{name}/key. */
final readonly class SetKeyRequest
{
    /**
     * The field is named `_password` on the wire (SEC-01, lot 09): it is the
     * one name Symfony's request collector masks, so the dev profiler never
     * persists the key. The validation message stays attached to it
     * (`setting.key.blank`), wherever the client shows it.
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'setting.key.blank')]
        public string $_password = '',
    ) {
    }

    /**
     * Masked in the profiler's dumps too (SEC-01, lot 09): the request
     * collector masks the field on its own, but the validator's panel dumps
     * the whole DTO — VarDumper honors this method, so the panel shows the
     * masked value instead of the key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['_password' => '******'];
    }
}
