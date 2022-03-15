<?php
namespace Peat;

interface LoggerInterface
{
    public function log(string $message): void;
}