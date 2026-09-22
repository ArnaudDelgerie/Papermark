<?php

declare(strict_types=1);

namespace App\Dto\Setting;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /settings/provider/{name}/key. */
final readonly class SetKeyRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'setting.key.blank')]
        public string $key = '',
    ) {
    }
}
