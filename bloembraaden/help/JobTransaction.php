<?php

declare(strict_types=1);

namespace Peat;

class jobTransaction
{
    private bool $useNewRelic = false;
    private string $newRelicApp = '';
    private string $message = '';

    public function __construct()
    {
        if (extension_loaded('newrelic')) {
            $this->useNewRelic = true;
            $this->newRelicApp = ini_get('newrelic.appname');
        }
    }

    public function start(string $name): void
    {
        $this->message .= ob_get_clean();
        if (true === $this->useNewRelic) {
            newrelic_end_transaction(); // stop recording the current transaction
            newrelic_start_transaction($this->newRelicApp); // start recording a new transaction
            newrelic_background_job(true);
            newrelic_name_transaction($name);
        }
        ob_start(); // ob_get_clean also STOPS output buffering :-P
        echo '=== ';
        echo $name;
        echo ' ===';
        echo PHP_EOL;
    }

    public function flush(bool $with_log = true)
    {
        $this->message .= ob_get_clean();
        if (true === $this->useNewRelic) {
            newrelic_end_transaction(); // stop recording the current transaction
        }
        if ($with_log) {
            error_log($this->message, 3, Setup::$LOGFILE);
        }
        echo $this->message;

        $this->message = '';
    }
}
