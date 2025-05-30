<?php

declare(strict_types=1);

namespace Bloembraaden;


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

    public function out(string $separator = "\n"): self
    {
        echo implode($separator, $this->get());

        return $this;
    }

    public function clear(): self
    {
        $this->messages = array();

        return $this;
    }
}
