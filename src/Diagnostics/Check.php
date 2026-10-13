<?php

namespace ItsJustVita\VectorTiles\Diagnostics;

final readonly class Check
{
    public function __construct(
        public CheckStatus $status,
        public string $message,
        public ?string $hint = null,
    ) {}

    public static function pass(string $message): self
    {
        return new self(CheckStatus::Pass, $message);
    }

    public static function warn(string $message, ?string $hint = null): self
    {
        return new self(CheckStatus::Warn, $message, $hint);
    }

    public static function fail(string $message, ?string $hint = null): self
    {
        return new self(CheckStatus::Fail, $message, $hint);
    }
}
