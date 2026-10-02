<?php

declare(strict_types=1);

namespace App\Trait;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * For a string-backed enum implementing TranslatableInterface: its values and
 * labels, in the order of its cases, without repeating the translation key
 * each enum's own trans() already knows.
 */
trait EnumLabelsTrait
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function labels(TranslatorInterface $translator): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->trans($translator);
        }

        return $labels;
    }
}
