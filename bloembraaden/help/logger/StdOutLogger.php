<?php

namespace Peat;

class StdOutLogger extends Base implements LoggerInterface
{
    private array $messages = array();

    public function log(string $message): void
    {
        $this->messages[] = $message;
    }

    public function get(): array
    {
        return $this->messages;
    }

    public function out(string $separator = "\n"): void
    {
        echo implode($separator, $this->get());
    }
}
