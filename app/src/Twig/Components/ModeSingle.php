<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Left column, single-file mode: mode selector, Open (file) and the history
 * of opened files. History lives in sessionStorage, entirely managed by the
 * mode-single Stimulus controller — nothing to expose from PHP.
 */
#[AsTwigComponent('mode-single')]
final class ModeSingle
{
}
