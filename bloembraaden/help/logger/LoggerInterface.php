<?php
declare(strict_types = 1);

namespace Bloembraaden;


interface LoggerInterface
{
    public function log(string $message): void;
}