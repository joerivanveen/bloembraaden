<?php

declare(strict_types = 1);

namespace Bloembraaden;


class SseLogger extends Base implements LoggerInterface
{
    public function __construct()
    {
        parent::__construct();
        register_shutdown_function([$this, 'close']);
        if (ob_get_length()) { // false or 0 when there's no content in it
            $this->handleErrorAndStop('Cannot setup SSE logger when there is already content in the buffer');
        } else {
            $this->open();
            Help::$LOGGER = $this;
        }
//        if(connection_aborted()){
//            exit();
//        }
    }

    private function open() {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        echo 'data: { "open": true }';
        $this->flush();
    }

    public function log(string $message): void
    {
        echo 'data: { "message":';
        echo json_encode($message);
        echo '}';
        $this->flush();
    }

    public function close()
    {
        echo 'data: { "close": true }';
        echo "\n\n";
        $this->flush();
    }

    private function flush() {
        echo str_pad('',8192);
        echo "\n\n";
        flush();
    }
}