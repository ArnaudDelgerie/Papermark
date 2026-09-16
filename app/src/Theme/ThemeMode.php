<?php

declare(strict_types=1);

namespace App\Theme;

enum ThemeMode: string
{
    case Light = 'light';
    case Dark = 'dark';
    case Auto = 'auto';
}
