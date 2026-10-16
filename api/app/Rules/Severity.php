<?php

namespace App\Rules;

enum Severity: string
{
    /** The change is refused. */
    case Block = 'block';

    /** The change is allowed and the warning is shown (and re-shown on publish). */
    case Warn = 'warn';
}
