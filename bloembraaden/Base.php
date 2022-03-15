<?php

namespace Peat;
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
    public function addMessage(string $message, string $level = 'log')
    {
        Help::addMessage($message, $level);
    }

    /**
     * @param \Exception|string $e
     */
    public function addError($e): void
    {
        if ($e instanceof \Exception) {
            Help::addError($e);
        } elseif (is_string($e)) {
            Help::addError(new \Exception($e));
        } else {
            Help::addError(new \Exception(var_export($e, true)));
        }
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
    public function handleErrorAndStop($e, string $message_for_frontend = 'A program error occurred')
    {
        // prepare the message to log and the message for frontend
        $s = str_replace(array("\n", "\r", "\t"), '', strip_tags($message_for_frontend));
        $error_message = PHP_EOL .
            $_SERVER['REMOTE_ADDR'] . ':' . $_SERVER['REMOTE_PORT'] . "\t" .
            date("Y-m-d H:i:s") . "\t" .
            $_SERVER['REQUEST_METHOD'] . "\t" .
            $_SERVER['REQUEST_URI'] . "\nFATAL: ";
        $error_message .= $e;
        try { // TODO these error messages are a bit much, but leaving them out is a bit scarce, what to do?
            $error_message .= PHP_EOL . var_export(Help::getErrorMessages(), true);
        } catch (\Exception $exception) {
        }
        // log the error
        if (error_log($error_message, 3, Setup::$LOGFILE) === false) {
            $s .= ' (could not be logged)';
        }
        // send to newrelic
        if (extension_loaded('newrelic')) {
            newrelic_notice_error($error_message);
        }
        if (ob_get_length()) { // false or 0 when there's no content in it, but when there is you cannot send header
            die($s);
        } else {
            // send error header
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Bloembraaden Fatal Error', true, 500);
            if (defined('OUTPUT_JSON')) {
                echo '{ "error": ' . json_encode($s) . ', "__messages__": ' . json_encode(Help::getMessages()) . ' }';
            } else {
                // TODO customizable error pages
                echo str_replace('{{message}}', $s, file_get_contents(CORE . 'presentation/error.html'));
            }
        }
        die();
    }

    /**
     * Sends a redirect_uri or 307 temporary redirect header, use it for items that are not online
     * @param string|null $slug
     * @since 0.7.6 elements that are not online will generate a user-specifiable error akin to 404
     */
    public function handleNotFoundAndStop(?string $slug): void
    {
        if (null !== $slug) {
            if (Help::slugify($slug) !== $slug) {
                $this->handleErrorAndStop(
                    sprintf('While handling not found: %s is not a slug', $slug),
                    __('Error handling not found page','peatcms')
                );
            }
            if (false === strpos($slug, '/')) $slug = '/' . $slug;
        } else {
            $this->addMessage(__('Not Found', 'peatcms'), 'error');
            $slug = '/';
        }
        // @since 0.8.16 check if the slug is even online...
        if ($_SERVER['REQUEST_URI'] === $slug) {
            $this->handleErrorAndStop(
                sprintf('Not found page %s is calling itself', $slug),
                __('Error handling not found page','peatcms')
            );
        }
        if (defined('OUTPUT_JSON')) {
            echo '{ "error": "Not found", "redirect_uri": ' . json_encode($slug) . ' }';
        } else {
            header($_SERVER['SERVER_PROTOCOL'] . ' 307 Temporary Redirect', true, 307);
            header('Location:' . $slug);
        }
        die();
    }
}
