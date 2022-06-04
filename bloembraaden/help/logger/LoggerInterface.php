<?php
declare(strict_types = 1);

namespace Peat;


interface LoggerInterface
{
    public function log(string $message): void;
}