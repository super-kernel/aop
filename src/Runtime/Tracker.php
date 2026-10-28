<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

final class Tracker
{
    /**
     * Holds the original source line of the currently executing statement.
     */
    public static int $currentLine = 0;

    public static function setLine(int $line): void
    {
        self::$currentLine = $line;
    }
}
