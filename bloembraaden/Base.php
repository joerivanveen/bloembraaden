<?php
declare(strict_types=1);

namespace Bloembraaden;
class Base
{
    // NOTE we track whether THIS object has a message or error, but we save and retrieve them all globally (through Help)
    private bool $has_error = false;

    public function __construct()
    {
    }

    public function __destruct()
    {
    }

    /**
     * Messages are meant for client, so should be localized and not contain sensitive information
     *
     * @param string $message Localized message that will be displayed to the client
     * @param string $level default 'log', also possible: 'warn', 'error', 'note'
     */
    public function addMessage(string $message, string $level = 'log'): void
    {
        Help::addMessage($message, $level);
    }

    /**
     * @param string $error_message
     */
    public function addError(string $error_message): void
    {
        $domain = Setup::$INSTANCE_DOMAIN ?? Setup::$instance_id;
        if (true === isset($_SERVER['REQUEST_URI'])) $domain .= $_SERVER['REQUEST_URI'];
        Help::addError(new \Exception("$error_message [$domain]"));

        $this->has_error = true;
    }

    /**
     * @return bool true when object has one or more errors, false otherwise
     */
    public function hasError(): bool
    {
        return $this->has_error;
    }

    /**
     * @return \Exception|null the last error that occurred
     */
    public function getLastError(): ?\Exception
    {
        if ($this->has_error === true) {
            return array_values(array_slice(Help::getErrors(), -1))[0];
        }

        return null;
    }

    /**
     * sends 500 header and json output to the client, then dies
     *
     * @param \Exception|string $e The exception that occurred, or error message as string
     * @param string $message_for_frontend default 'A program error occurred.', will be displayed to user / client
     * @since 0.1.0
     */
    public function handleErrorAndStop($e, string $message_for_frontend = 'Bloembraaden fatal error'): void
    {
        // prepare the message to log and the message for frontend
        $s = str_replace(array("\n", "\r", "\t"), '', strip_tags($message_for_frontend));
        $error_message = sprintf(
            "%s\t%s\t%s\t%s\nFATAL: $e\n",
            ($_SERVER['REMOTE_ADDR'] ?? 'INTERNAL'),
            date('Y-m-d H:i:s'),
            ($_SERVER['REQUEST_METHOD'] ?? 'NON-WEB'),
            ($_SERVER['REQUEST_URI'] ?? '')
        );
        try {
            if (0 !== count(($messages = Help::getErrorMessages()))) {
                $error_message .= implode("\n", $messages) . "\n";
            }
        } catch (\Throwable) {
        }
        // log the error
        if (false === error_log($error_message, 3, Setup::$LOGFILE)) {
            $s = "$s (could not be logged)";
        }
        // send to newrelic
        if (true === extension_loaded('newrelic')) {
            newrelic_notice_error($error_message);
        }
        if (Help::$LOGGER instanceof LoggerInterface) {
            Help::$LOGGER->log($s);
        } elseif (ob_get_length()) { // false or 0 when there's no content in it, but when there is you cannot send header
            die($s);
        } else {
            // send error header
            if (false === headers_sent()) {
                $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
                header("$protocol 500 Bloembraaden Fatal Error", true, 500);
            }
            if (true === Help::$OUTPUT_JSON) {
                Help::addMessage($s, 'error');
                echo '{ "__messages__": ', json_encode(Help::getMessages()), ' }';
            } else {
                // TODO customizable error pages
                echo str_replace('{{message}}', $s, file_get_contents(CORE . 'presentation/error.html'));
            }
        }
        die();
    }
}
