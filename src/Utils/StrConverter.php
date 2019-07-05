<?php

declare(strict_types=1);

namespace Somnambulist\ReadModels\Utils;

/**
 * Class StrConverter
 *
 * @package    Somnambulist\ReadModels\Utils
 * @subpackage Somnambulist\ReadModels\Utils\StrConverter
 */
class StrConverter
{
    private function __construct() {}

    /**
     * Converts a string to a stream resource by passing it through a memory file
     *
     * @link https://logansbailey.com/converting-strings-to-streams
     *
     * @param string $string
     *
     * @return bool|resource
     */
    public static function toResource(string $string)
    {
        $stream = fopen('php://memory','r+');

        fwrite($stream, $string);
        rewind($stream);

        return $stream;
    }
}
