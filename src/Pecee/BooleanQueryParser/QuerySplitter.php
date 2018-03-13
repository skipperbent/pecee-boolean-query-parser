<?php

namespace Pecee\BooleanQueryParser;

class QuerySplitter
{

    protected static $splitters = [
        "\r\n",
        '!=',
        '>=',
        '<=',
        '<>',
        ':=',
        '\\',
        '&&',
        '>',
        '<',
        '|',
        '=',
        '^',
        '(',
        ')',
        "\t",
        "\n",
        "'",
        '"',
        '`',
        ',',
        '@',
        ' ',
        '+',
        '-',
        '*',
        '/',
        ';',
    ];

    protected $tokenSize;
    protected $hashSet;

    public function __construct()
    {
        $this->tokenSize = \strlen(static::$splitters[0]); # should be the largest one
        $this->hashSet = array_flip(static::$splitters);
    }

    public function getMaxLengthOfSplitter(): int
    {
        return $this->tokenSize;
    }

    public function isSplitter(string $token): bool
    {
        return isset($this->hashSet[$token]);
    }
}
