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

    protected function obtainLock(string $identifier): bool
    {
        $locks = (array)Help::$session->getValue('locks');
        // if you already have it in your session, proceed
        if (in_array($identifier, $locks)) {
            return true;
        }
        // if nobody else has it, lock it tight, else return false
        $file_name = Setup::$DBCACHE . '.' . rawurlencode($identifier) . '.lock';
        if (false === file_exists($file_name)) {
            file_put_contents($file_name, '', LOCK_EX);
        } else {
            return false;
        }
        // add to session
        $locks[] = $identifier;
        Help::$session->setVar('locks', $locks);
        return true;
    }

    protected function releaseLock(string $identifier): void
    {
        // remove from session
        $locks = (array)Help::$session->getValue('locks');
        if (in_array($identifier, $locks)) {
            unset($locks[$identifier]);
            // unlock it, but if you did not have it there, record an error
            $file_name = Setup::$DBCACHE . '.' . rawurlencode($identifier) . '.lock';
            if (false === file_exists($file_name)) {
                $this->addError("Could not release lock $identifier");
            } else {
                unlink($file_name);
            }
            Help::$session->setVar('locks', $locks);
        } else {
            $this->addError("Lock $identifier not found in session to release");
        }
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
            "\n%s\t%s\t%s\t%s\nFATAL: $e\n",
            ($_SERVER['REMOTE_ADDR'] ?? 'INTERNAL'),
            date('Y-m-d H:i:s'),
            ($_SERVER['REQUEST_METHOD'] ?? 'NON-WEB'),
            ($_SERVER['REQUEST_URI'] ?? '')
        );
        try { // TODO these error messages are a bit much, but leaving them out is a bit scarce, what to do?
            $error_message .= var_export(Help::getErrorMessages(), true) . PHP_EOL;
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
                echo '{ "error": ', json_encode($s), ', "__messages__": ', json_encode(Help::getMessages()), ' }';
            } else {
                // TODO customizable error pages
                echo str_replace('{{message}}', $s, file_get_contents(CORE . 'presentation/error.html'));
            }
        }
        die();
    }
}
