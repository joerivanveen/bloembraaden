<?php

declare(strict_types=1);

namespace Bloembraaden;

/**
 * This is a static class with some functions used throughout peatcms
 */
class Help
{
    private static DB $db;
    private static array $errors = array();
    private static array $messages = array(); // indexed array of objects {message:..,count:..,level:..}

    public static bool $OUTPUT_JSON = false;
    public static ?LoggerInterface $LOGGER = null;

    /**
     * Construct won't be called inside this class and is uncallable from
     * the outside. This prevents instantiating this class.
     * This is by purpose, because we want a static class.
     */
    private function __construct()
    {
    }

    public static function getDB(): DB
    {
        if (!isset(static::$db)) {
            static::$db = new DB();
        }

        return static::$db;
    }

    /**
     * @param mixed $var the variable to be returned as integer
     * @param int|null $default return value if $var cannot be converted to integer
     * @return int|null the $var converted to integer, or $default when conversion failed
     * @since 0.4.0
     */
    public static function getAsInteger($var, ?int $default = null): ?int
    {
        if (is_numeric($var)) {
            // == will compare if the values are the same (because we know the types aren't anyway)
            if ((float)$var == (int)$var) return (int)$var;
        }

        return $default;
    }

    /**
     * @param mixed $var
     * @param float|null $default
     * @return float|null the $var converted to float, or $default when conversion failed
     * @since 0.5.1
     */
    public static function getAsFloat($var, ?float $default = null): ?float
    {
        if ((string)($float = floatval($var)) === $var) return $float; // return correctly formatted vars immediately
        // get the float correctly from string
        $var = str_replace(Setup::$DECIMAL_SEPARATOR, '.', str_replace(array(Setup::$RADIX, ' '), '', (string)$var));
        // convert to float
        if (is_numeric($var)) {
            return floatval($var);
        }

        return $default;
    }

    /**
     * Returns a float formatted for money (using the user settings of the instance)
     *
     * @param ?float $var
     * @return string the float correctly formatted for display
     * @since 0.5.1
     */
    public static function asMoney(?float $var): string
    {
        if (null === $var) return '';

        return number_format((float)Help::floatForHumans($var), Setup::$DECIMAL_DIGITS, Setup::$DECIMAL_SEPARATOR, Setup::$RADIX);
    }

    /**
     * Floating point numbers have errors that make them ugly and unusable that are not simply fixed by round()ing them
     * Use floatForHumans to return the intended decimal as a string (floatVal it if you want to perform calculations)
     * Decimal separator is a . as is standard, use numberformatting/ str_replace etc. if you want something else
     *
     * @param $float ?float a float that will be formatted to be human readable
     *
     * @return string the number is returned as a correctly formatted string
     *
     * @since    0.5.1
     */
    public static function floatForHumans(?float $float): string
    {
        if (is_null($float) || '' === (string)$float) return '';
        // floating point not accurate... https://stackoverflow.com/questions/4921466/php-rounding-error
        $sunk = strval($float);
        // probably the rounding error (that cannot be fixed with 'round'!) is discarded by strval, but we cleanup anyway
        // TODO check if it's consistently fixed by strval and if so remove the rest of this function
        // whenever there is a series of 0's or 9's, format the number for humans that don't care about computer issues
        if (($index = strpos($sunk, '00000'))) {
            $sunk = substr($sunk, 0, $index);
            if (substr($sunk, -1) === '.') {
                $sunk = substr($sunk, 0, -1);
            }
        }
        if (($index = strpos($sunk, '99999'))) {
            $sunk = substr($sunk, 0, $index);
            if (substr($sunk, -1) === '.') {
                $sunk = (string)((int)$sunk + 1);
            } else {
                $n = (int)(substr($sunk, -1)); // this can never be nine, so you can add 1 safely
                $sunk = substr($sunk, 0, -1) . (string)($n + 1);
            }
        }

        return $sunk;
    }

    public static function removeAccents(string $string): string
    {
        if (!preg_match('/[\x80-\xff]/', $string))
            return $string;
        $chars = array(
            // Decompositions for Latin-1 Supplement
            chr(195) . chr(128) => 'A', chr(195) . chr(129) => 'A',
            chr(195) . chr(130) => 'A', chr(195) . chr(131) => 'A',
            chr(195) . chr(132) => 'A', chr(195) . chr(133) => 'A',
            chr(195) . chr(135) => 'C', chr(195) . chr(136) => 'E',
            chr(195) . chr(137) => 'E', chr(195) . chr(138) => 'E',
            chr(195) . chr(139) => 'E', chr(195) . chr(140) => 'I',
            chr(195) . chr(141) => 'I', chr(195) . chr(142) => 'I',
            chr(195) . chr(143) => 'I', chr(195) . chr(145) => 'N',
            chr(195) . chr(146) => 'O', chr(195) . chr(147) => 'O',
            chr(195) . chr(148) => 'O', chr(195) . chr(149) => 'O',
            chr(195) . chr(150) => 'O', chr(195) . chr(153) => 'U',
            chr(195) . chr(154) => 'U', chr(195) . chr(155) => 'U',
            chr(195) . chr(156) => 'U', chr(195) . chr(157) => 'Y',
            chr(195) . chr(159) => 's', chr(195) . chr(160) => 'a',
            chr(195) . chr(161) => 'a', chr(195) . chr(162) => 'a',
            chr(195) . chr(163) => 'a', chr(195) . chr(164) => 'a',
            chr(195) . chr(165) => 'a', chr(195) . chr(167) => 'c',
            chr(195) . chr(168) => 'e', chr(195) . chr(169) => 'e',
            chr(195) . chr(170) => 'e', chr(195) . chr(171) => 'e',
            chr(195) . chr(172) => 'i', chr(195) . chr(173) => 'i',
            chr(195) . chr(174) => 'i', chr(195) . chr(175) => 'i',
            chr(195) . chr(177) => 'n', chr(195) . chr(178) => 'o',
            chr(195) . chr(179) => 'o', chr(195) . chr(180) => 'o',
            chr(195) . chr(181) => 'o', chr(195) . chr(182) => 'o',
            chr(195) . chr(182) => 'o', chr(195) . chr(185) => 'u',
            chr(195) . chr(186) => 'u', chr(195) . chr(187) => 'u',
            chr(195) . chr(188) => 'u', chr(195) . chr(189) => 'y',
            chr(195) . chr(191) => 'y',
            // Decompositions for Latin Extended-A
            chr(196) . chr(128) => 'A', chr(196) . chr(129) => 'a',
            chr(196) . chr(130) => 'A', chr(196) . chr(131) => 'a',
            chr(196) . chr(132) => 'A', chr(196) . chr(133) => 'a',
            chr(196) . chr(134) => 'C', chr(196) . chr(135) => 'c',
            chr(196) . chr(136) => 'C', chr(196) . chr(137) => 'c',
            chr(196) . chr(138) => 'C', chr(196) . chr(139) => 'c',
            chr(196) . chr(140) => 'C', chr(196) . chr(141) => 'c',
            chr(196) . chr(142) => 'D', chr(196) . chr(143) => 'd',
            chr(196) . chr(144) => 'D', chr(196) . chr(145) => 'd',
            chr(196) . chr(146) => 'E', chr(196) . chr(147) => 'e',
            chr(196) . chr(148) => 'E', chr(196) . chr(149) => 'e',
            chr(196) . chr(150) => 'E', chr(196) . chr(151) => 'e',
            chr(196) . chr(152) => 'E', chr(196) . chr(153) => 'e',
            chr(196) . chr(154) => 'E', chr(196) . chr(155) => 'e',
            chr(196) . chr(156) => 'G', chr(196) . chr(157) => 'g',
            chr(196) . chr(158) => 'G', chr(196) . chr(159) => 'g',
            chr(196) . chr(160) => 'G', chr(196) . chr(161) => 'g',
            chr(196) . chr(162) => 'G', chr(196) . chr(163) => 'g',
            chr(196) . chr(164) => 'H', chr(196) . chr(165) => 'h',
            chr(196) . chr(166) => 'H', chr(196) . chr(167) => 'h',
            chr(196) . chr(168) => 'I', chr(196) . chr(169) => 'i',
            chr(196) . chr(170) => 'I', chr(196) . chr(171) => 'i',
            chr(196) . chr(172) => 'I', chr(196) . chr(173) => 'i',
            chr(196) . chr(174) => 'I', chr(196) . chr(175) => 'i',
            chr(196) . chr(176) => 'I', chr(196) . chr(177) => 'i',
            chr(196) . chr(178) => 'IJ', chr(196) . chr(179) => 'ij',
            chr(196) . chr(180) => 'J', chr(196) . chr(181) => 'j',
            chr(196) . chr(182) => 'K', chr(196) . chr(183) => 'k',
            chr(196) . chr(184) => 'k', chr(196) . chr(185) => 'L',
            chr(196) . chr(186) => 'l', chr(196) . chr(187) => 'L',
            chr(196) . chr(188) => 'l', chr(196) . chr(189) => 'L',
            chr(196) . chr(190) => 'l', chr(196) . chr(191) => 'L',
            chr(197) . chr(128) => 'l', chr(197) . chr(129) => 'L',
            chr(197) . chr(130) => 'l', chr(197) . chr(131) => 'N',
            chr(197) . chr(132) => 'n', chr(197) . chr(133) => 'N',
            chr(197) . chr(134) => 'n', chr(197) . chr(135) => 'N',
            chr(197) . chr(136) => 'n', chr(197) . chr(137) => 'N',
            chr(197) . chr(138) => 'n', chr(197) . chr(139) => 'N',
            chr(197) . chr(140) => 'O', chr(197) . chr(141) => 'o',
            chr(197) . chr(142) => 'O', chr(197) . chr(143) => 'o',
            chr(197) . chr(144) => 'O', chr(197) . chr(145) => 'o',
            chr(197) . chr(146) => 'OE', chr(197) . chr(147) => 'oe',
            chr(197) . chr(148) => 'R', chr(197) . chr(149) => 'r',
            chr(197) . chr(150) => 'R', chr(197) . chr(151) => 'r',
            chr(197) . chr(152) => 'R', chr(197) . chr(153) => 'r',
            chr(197) . chr(154) => 'S', chr(197) . chr(155) => 's',
            chr(197) . chr(156) => 'S', chr(197) . chr(157) => 's',
            chr(197) . chr(158) => 'S', chr(197) . chr(159) => 's',
            chr(197) . chr(160) => 'S', chr(197) . chr(161) => 's',
            chr(197) . chr(162) => 'T', chr(197) . chr(163) => 't',
            chr(197) . chr(164) => 'T', chr(197) . chr(165) => 't',
            chr(197) . chr(166) => 'T', chr(197) . chr(167) => 't',
            chr(197) . chr(168) => 'U', chr(197) . chr(169) => 'u',
            chr(197) . chr(170) => 'U', chr(197) . chr(171) => 'u',
            chr(197) . chr(172) => 'U', chr(197) . chr(173) => 'u',
            chr(197) . chr(174) => 'U', chr(197) . chr(175) => 'u',
            chr(197) . chr(176) => 'U', chr(197) . chr(177) => 'u',
            chr(197) . chr(178) => 'U', chr(197) . chr(179) => 'u',
            chr(197) . chr(180) => 'W', chr(197) . chr(181) => 'w',
            chr(197) . chr(182) => 'Y', chr(197) . chr(183) => 'y',
            chr(197) . chr(184) => 'Y', chr(197) . chr(185) => 'Z',
            chr(197) . chr(186) => 'z', chr(197) . chr(187) => 'Z',
            chr(197) . chr(188) => 'z', chr(197) . chr(189) => 'Z',
            chr(197) . chr(190) => 'z', chr(197) . chr(191) => 's'
        );

        return strtr($string, $chars);
    }

    /**
     * @param string $value
     * @return \DateTime|null
     * @since 0.8.19
     */
    public static function getDate(string $value): ?\DateTime
    {
        // parse the date, it should be YYYY-MM-DD HH:MM:SS.milliseconds+timezone diff compared to UTC
        if (($dt = \DateTime::createFromFormat('Y-m-d G:i:s O', $value))) {
            return $dt;
        } elseif (($dt = \DateTime::createFromFormat('Y-m-d\TG:i:s O', $value))) { // official, used by eg Instagram
            return $dt;
        } elseif (($dt = \DateTime::createFromFormat('Y-m-d G:i:s.u O', $value))) {
            return $dt;
        } elseif (($dt = \DateTime::createFromFormat('Y-m-d G:i:s.u', $value))) {
            return $dt;
        } elseif (($dt = \DateTime::createFromFormat('Y-m-d G:i:s', $value))) {
            return $dt;
        } elseif (($dt = \DateTime::createFromFormat('Y-m-d G:i', $value))) {
            return $dt;
        } elseif (($dt = \DateTime::createFromFormat('Y-m-d', $value))) {
            return $dt;
        }
        //if ($dt === false or array_sum($dt::getLastErrors())) {}
        return null;
    }

    /**
     * @param string $message
     * @param string $level
     * @since 0.4.0, 0.5.9: added $level 'note'
     */
    public static function addMessage(string $message, string $level = 'log'): void
    {
        if (false === in_array($level, array('note', 'warn', 'error', 'log'), true)) $level = 'log'; // default to log
        // NOTE the level is not updated when the count for a message is updated
        $messages = static::$messages;
        foreach ($messages as $index => $obj) {
            if ($obj->message === $message) {
                $obj->count++;
                static::$messages = $messages;

                return;
            }
        }
        $messages[] = (object)array('message' => $message, 'level' => $level, 'count' => 1);
        static::$messages = $messages;
    }

    /**
     * @return bool
     * @since 0.4.0
     */
    public static function hasMessages(): bool
    {
        return count(static::$messages) > 0;
    }

    /**
     * @return array
     * @since 0.4.0
     */
    public static function getMessages(): array
    {
        $messages = array();
        foreach (static::$messages as $index => $obj) {
            $level = $obj->level;
            if (isset($messages[$level])) {
                $messages[$level][] = $obj; // add the message to the array for its level
            } else {
                $messages[$level] = array($obj); // create new array for this level
            }
        }
        static::$messages = array(); // the messages are on their way, clear the array

        return $messages; // return the object with messages per level, as the rest of peatcms expects it now
    }

    /**
     * @return string
     * @since 0.5.1
     */
    public static function getMessagesAsJson(): string
    {
        return json_encode(static::$messages);
    }

    /**
     * @param \Exception $e
     * @since 0.4.0
     */
    public static function addError(\Exception $e): void
    {
        static::$errors[] = $e;
    }

    /**
     * @return array
     * @since 0.4.0
     */
    public static function getErrors(): array
    {
        return static::$errors;
    }

    /**
     * @return array
     * @since 0.4.0
     */
    public static function getErrorMessages(): array
    {
        $arr = [];
        $errors = Help::getErrors();
        foreach ($errors as $key => $e) {
            $arr[] = "{$e->getMessage()}\n{$e->getTraceAsString()}";
        }

        return $arr;
    }

    /**
     * @since 0.6.16
     */
    public static function logErrorMessages(): ?string
    {
        if (count($arr = Help::getErrorMessages()) > 0) {
            ob_start();
            if (isset($_SERVER['REMOTE_ADDR'])) {
                echo "\r\n", $_SERVER['REMOTE_ADDR'], ':', $_SERVER['REMOTE_PORT'];
            } else {
                echo "\r\nNO CLIENT";
            }
            echo "\t", date('Y-m-d H:i:s'), "\t";
            if (isset($_SERVER['REQUEST_METHOD'])) echo $_SERVER['REQUEST_METHOD'], "\t";
            if (isset($_SERVER['REQUEST_URI'])) echo $_SERVER['REQUEST_URI'], "\t";
            echo "LOG\r\n";
            echo implode("\r\n", $arr);

            $message = ob_get_clean();

            error_log($message, 3, Setup::$LOGFILE);

            return $message;
        }
        return null;
    }

    /**
     * @param string $message
     * @param int $level
     * @since 0.4.0
     */
    public static function trigger_error(string $message, int $level = E_USER_NOTICE): void
    {
        if (($caller = debug_backtrace()[1])) {
            $message = "$message in <strong>{$caller['function']}</strong> called from <strong>
                {$caller['file']}</strong> on line <strong>{$caller['line']}</strong>\n<br />error handler";
        }
        trigger_error($message, $level);
    }

    /**
     * NOTE it only returns lowercase characters for now because the uri is converted to lowercase always by the resolver
     *
     * @param int $length mandatory How long a string do you want?
     * @param string $key_space Defaults to alphanumeric characters but you can supply your own string of allowed characters
     * @return string The cryptographically secure random string of specified length is returned
     * @since 0.1.0
     */
    public static function randomString(
        int    $length,
        string $key_space = '0123456789abcdefghijklmnopqrstuvwxyz'
    ): string
    {
        try {
            $pieces = [];
            //$max = mb_strlen($key_space, '8bit') - 1;
            $max = strlen($key_space) - 1;
            for ($i = 0; $i < $length; ++$i) {
                $pieces [] = $key_space[\random_int(0, $max)];
            }

            return implode('', $pieces);
        } catch (\Exception $e) {
            die('no appropriate source for randomization found');
        }
    }

    public static function swapVariables(&$a, &$b)
    {
        $temp = $a;
        $a = $b;
        $b = $temp;
        unset($temp);
    }

    /**
     * @param string $order_number must be raw order number
     * @param int $instance_id defaults to current instance
     * @return string
     */
    public static function getInvoiceFileName(string $order_number, int $instance_id = -1): string
    {
        if (-1 === $instance_id) $instance_id = Setup::$instance_id;

        return Setup::$INVOICE . "/{$instance_id}_$order_number.pdf";
    }

    /*public static function humanFileSize($bytes, int $decimals = 2): string
    {
        $sz = 'BKMGTP';
        $factor = (int)floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
    }*/
    public static function calculateUploadLimit(): int
    {
        $max_upload = floatval(ini_get('upload_max_filesize'));
        $max_post = floatval(ini_get('post_max_size'));
        $memory_limit = floatval(ini_get('memory_limit'));

        return min($max_upload, $max_post, $memory_limit);
    }

    /**
     * @param string $temp_path path to an existing file
     * @return string|null the path the file is saved under, or null when failure
     */
    public static function scanFileAndSaveWhenOk(string $temp_path): ?string
    {
        if (!file_exists($temp_path)) return null;
        // TODO check the file before saving, throw exception when it doesn't work
        // execute maldet / clamav or something on the file
        // https://www.vultr.com/docs/scan-for-malware-and-viruses-on-centos-using-clamav-and-linux-malware-detect
        // if malicious code is found, also block the admin? Or only when multiple times?
        // save the file
        $file_name = Help::randomString(20);
        $new_path = Setup::$UPLOADS . $file_name;
        if (file_exists($new_path)) { // this should never happen
            self::trigger_error("File $new_path already exists", E_USER_ERROR);
        } else {
            copy($temp_path, $new_path);
        }

        return $file_name;
    }

    /**
     * Turns html into e-mail friendly text using some proprietary tags as well
     * @param string $html
     * @return string the cleaned html that can be used as text part for e-mail messages
     * @since 0.6.15
     */
    public static function html_to_text(string $html): string
    {
        $html = str_replace('> <', ">\r\n<", $html);
        $html = str_replace('><', ">\r\n<", $html);
        $html = str_replace('<br/>', "\r\n", $html);
        $html = str_replace("\t", ' ', $html);
        $html = str_replace('<h2', "\r\n.\r\n<h2", $html);
        $html = str_replace('</h2>', "</h2>\r\n", $html);
        $html = str_replace('<p id="remarks"', "\r\n.\r\n.\r\nREMARKS:\r\n" . '<p', $html);
        $html = strip_tags($html);
        $html = str_replace("\r\n ", "\r\n", $html);
        $html = str_replace("\r\n ", "\r\n", $html);
        $html = str_replace("\r\n\r\n", "\r\n", $html);
        $html = str_replace("\r\n\r\n", "\r\n", $html);

        return $html;
    }

    /**
     * @param Instance $instance
     * @param \stdClass $post_data
     * @return bool false if the verification failed, true otherwise (also true if recaptcha is not setup!)
     * @since 0.5.15
     */
    public static function recaptchaVerify(Instance $instance, \stdClass $post_data): bool
    {
        if ($instance->getSetting('recaptcha_site_key') === '') return true;
        if (($recaptcha_secret_key = $instance->getSetting('recaptcha_secret_key')) === '') {
            Help::addError(new \Exception('Recaptcha secret key not filled in'));
            Help::addMessage(__('Recaptcha configuration error', 'peatcms'), 'error');

            return false;
        }
        $recaptcha_pass_score = \floatval($instance->getSetting('recaptcha_pass_score'));
        if ($recaptcha_pass_score === 0.0) {
            Help::addMessage(__('Recaptcha pass score of 0 will not let anything through', 'peatcms'), 'warn');
        }
        if (isset($post_data->{'g-recaptcha-token'}) and ($g_recaptcha_token = $post_data->{'g-recaptcha-token'})) {
            $url = 'https://www.google.com/recaptcha/api/siteverify';
            $fields = [
                'secret' => $recaptcha_secret_key,
                'response' => $g_recaptcha_token,
            ];
            $fields_string = http_build_query($fields);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // it would hang for 2 minutes even though the answer was already there?
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $result = (array)json_decode(curl_exec($ch));
            curl_close($ch);
            if (json_last_error() === JSON_ERROR_NONE) {
                if ($result['success'] === false) {
                    Help::addMessage(var_export($result['error-codes'], true), 'warn');

                    return false;
                } else {
                    if (floatval($result['score']) < $recaptcha_pass_score) {
                        Help::addMessage(sprintf(__('%1$s score %2$s too low', 'peatcms'), 'reCaptcha', $result['score']), 'warn');

                        return false;
                    }
                }
            } else {
                Help::addMessage(sprintf(__('Error reading %s response', 'peatcms'), 'reCaptcha json'), 'warn');

                return false;
            }
        } else { // g-recaptcha-token is missing
            Help::addMessage(__('No recaptcha token received', 'peatcms'), 'error');

            return false;
        }

        return true;
    }

    public static function prepareAdminRowForOutput(\stdClass &$row, string $what, ?string $which = null)
    {
        $row->title = "$what | Bloembraaden";
        $row->template_pointer = (object)array('name' => $what, 'admin' => true);
        $row->type = $what;
        if (null !== $which) {
            $what .= "/$which";
        }
        $row->slug = "__admin__/$what";
        $row->path = $row->slug;
    }

    /**
     * @param string $password The password
     * @return bool|string|null Returns (string) hash, or false on failure (or null when algorithm is invalid)
     */
    public static function passwordHash(string $password): bool|string|null
    {
        return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 128, 'threads' => 1]);
    }

    /**
     * Creates a safe slug from UTF-8 string, which is NOT lowercase yet
     *
     * @param string $string The string you want to be converted to a slug
     * @return string slug-safe UTF-8 string, still contains case
     */
    public static function slugify(string $string): string
    {
        $string = strip_tags($string);
        // preserve underscore and hyphen
        $string = str_replace(array('_', '-'), ' ', $string);
        // only keep Unicode Letter (includes space), Number and Marks
        $string = preg_replace('/[^\p{L}\p{N}\p{M}\s]/u', '', $string);
        // replace spaces with hyphen
        $string = str_replace(' ', '-', $string);
        // lose consecutive hyphens
        $string = preg_replace('|-+|', '-', $string);
        // trim neatly
        return trim($string, '-');
    }

    public static function summarize(int $length, ...$parts): string
    {
        $string = trim(implode(' ', $parts));

        if ($length >= strlen($string)) return $string;

        $string = substr($string, 0, $length - 3);

        return "$string...";
    }

    /**
     * @param array $terms
     * @param array $properties
     * @return string
     * @since 0.8.0
     */
    public static function turnIntoPath(array $terms, array $properties): string
    {
        $callback = function ($a, $b) {
            $a_num = is_numeric($a);
            $b_num = is_numeric($b);
            if (true === $a_num && false === $b_num)
                return 1;
            elseif (false === $a_num && true === $b_num)
                return -1;
            else
                return $a <=> $b;
        };
        usort($terms, $callback);
        ob_start(); // output buffer will hold url
        echo implode('/', $terms);
        if (count($properties) > 0) {
            ksort($properties, SORT_STRING);
            foreach ($properties as $prop => $value) {
                echo '/';
                usort($value, $callback);
                echo $prop;
                echo ':';
                echo implode(',', $value);
                // never end with /, this breaks paging / caching etc.
            }
        }

        return ob_get_clean();
    }

    public static function unpackKeyValueRows(array $rows): array
    {
        $unpacked = array();
        foreach ($rows as $key => $value) {
            $unpacked[] = (object)array(
                'key' => $key,
                'value' => $value,
            );
        }

        return $unpacked;
    }

    public static function upgrade(DB $db)
    {
        $version = $db->getDbVersion();
        if (strlen($version) < 5) {
            echo sprintf('Upgrade failed, version %s from db not valid', $version);

            return;
        }
        echo 'Upgrading from ' . $version . PHP_EOL;
        // read the sql file up until the old version and process everything after that. Remember the new version to check
        if (!file_exists(CORE . 'data/install.sql')) {
            echo 'install.sql not found, nothing to do';
            Help::addError(new \Exception('Install flag switched on, but no instructions found'));

            return;
        }
        // if the version mentioned in config is accurate
        $sql = file_get_contents(CORE . 'data/install.sql');
        // skip to the line -- version $version
        $position_of_current_version_in_sql = strpos($sql, '-- version ' . $version);
        if ($position_of_current_version_in_sql === false) {
            echo sprintf('Current version ‘%s’ not found in install.sql, aborting upgrade', $version);
            Help::addError(new \Exception('Install flag switched on, but failing'));

            return;
        }
        $position_of_next_version_in_sql = strpos($sql, '-- version', $position_of_current_version_in_sql + 10);
        $starting_position_of_upgrade_sql = strpos($sql, 'BEGIN;', $position_of_next_version_in_sql);
        // remember last version
        $version = substr($sql, \strrpos($sql, '-- version '));
        $length = strpos($version, 'BEGIN;');
        $version = trim(substr($version, 10, $length - 10));
        if (\version_compare(Setup::$VERSION, $version) !== 0) {
            echo(sprintf('Can only upgrade to version %s, you must set that exact value in config.json', $version));
            Help::addError(new \Exception('Install flag switched on, but failing'));

            return;
        }
        // skip the current version's sql and start right after that
        // (you can't start with the newly requested version only 'cause maybe you're upgrading multiple versions)
        $sql = substr($sql, $starting_position_of_upgrade_sql);
        //$sql = substr($sql, strpos($sql, '-- version'));
//        if (\strrpos($sql, '-- wipe history')) {
//            Help::wipeHistoryDatabase(new DB());
//        }
        //
        try {
            $db->run($sql);
        } catch (\PDOException $e) {
            $db->resetConnection();
            echo date(DATE_ATOM);
            echo PHP_EOL;
            echo $e;
            Help::addError(new \Exception('Install flag switched on, but failing'));

            return;
        }
        sleep(3); // give the db time to report back the new states
        // sync history
        echo 'Sync history database' . PHP_EOL;
        Help::syncHistoryDatabase($db);
        // now set the version
        $db->run(sprintf('update _system set version = \'%s\';', $version));
        // @since 0.7.7 clear data cache
        $files = glob(Setup::$DBCACHE . '*.info.serialized'); // get all file names
        foreach ($files as $file) { // iterate files
            if (is_file($file)) {
                unlink($file); // delete file
            }
        }
        // done, feedback to user
        echo(sprintf('Successfully upgraded to version %s', $version));
    }

    public static function wipeHistoryDatabase(DB $db)
    {
        try {
            // disconnect all users from the history database
            $sql = 'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = \'peatcms_history\';';
            $db->run($sql);
            // drop it
            $sql = 'DROP DATABASE IF EXISTS peatcms_history;';
            $db->run($sql);
            $sql = 'CREATE DATABASE peatcms_history;';
            $db->run($sql);
            echo 'This update wiped the history database' . PHP_EOL;
        } catch (\Exception $e) {
            echo $e->getMessage();
            echo PHP_EOL;
        }
    }

    public static function syncHistoryDatabase(DB $db)
    {
        // for each table in regular db, check history: if not present, create it from regular db, if present, check the columns and update those
        $tables = $db->getAllTables();
        $db_schema = Setup::getDatabaseSchema('DB_HIST');
        foreach ($tables as $table_index => $table) {
            if (in_array($table->table_name, $db::TABLES_WITHOUT_HISTORY)) continue; // tables without history
            $table_name = $table->table_name;
            $info = $db->getTableInfo($table_name);
            $history_columns = $db->historyTableColumns($table_name);
            if ($history_columns === null) { // create based on table info
                // create table (defaults and unique constraints do not apply in the history table)
                $sql = 'CREATE TABLE "' . $db_schema . '"."' . $table_name . '"
                    (' . PHP_EOL;
                //"history_id" Serial PRIMARY KEY, <- we're not using this anymore @since 0.5.4
                foreach ($info->getColumnNames() as $column_index => $column_name) {
                    $sql .= '"' . $column_name . '" ';
                    $col = $info->getColumnByName($column_name);
                    $sql .= match ($col_type = $col->getType()) {
                        'character' => 'Character Varying(' . $col->getLength() . ') ',
                        'timestamp' => 'Timestamp With Time Zone ',
                        'double' => 'float ',
                        default => "$col_type ",
                    };
                    //if (!is_null($col->getDefault())) { // default not necessary in a new table, and specifically, 'serial' leads to an error
                    //    $sql .= 'DEFAULT ' . $col->getDefault() . ' ';
                    //}
                    if ($col->isNullable() === false) $sql .= 'NOT NULL';
                    $sql .= ',';
                }
                $sql = substr($sql, 0, -1) . ');'; // remove last comma and close the statement
                //$sql .= 'CONSTRAINT "unique_' . $table_name . '_history_id" UNIQUE ("history_id") );';
                echo $sql, PHP_EOL;
                $db->historyRun($sql);
            } else { // compare the columns and add new ones (currently changing of info for columns is not supported...)
                foreach ($info->getColumnNames() as $column_index => $column_name) {
                    if (in_array($column_name, $history_columns)) {
                        // temporary fix for 0.16.0 TODO remove this
                        if ('css_class' === $column_name) {
                            $sql = 'ALTER TABLE "' . $db_schema . '"."' . $table_name . '" ALTER COLUMN "' . $column_name . '" TYPE Character Varying(255);';
                            $db->historyRun($sql);
                        }
                        unset($history_columns[$column_name]); // remove this to indicate it's been checked
                        continue;
                    }
                    $sql = 'ALTER TABLE "' . $db_schema . '"."' . $table_name . '" ADD COLUMN "' . $column_name . '" ';
                    $col = $info->getColumnByName($column_name);
                    $sql .= match ($col_type = $col->getType()) {
                        'character' => 'Character Varying(' . $col->getLength() . ') ',
                        'timestamp' => 'Timestamp With Time Zone ',
                        'double' => 'Double precision ',
                        default => "$col_type ",
                    };
                    if (!is_null($col->getDefault())) {
                        $sql .= 'DEFAULT ' . $col->getDefault() . ' ';
                    }
                    if ($col->isNullable() === false) $sql .= 'NOT NULL ';
                    $sql .= ';';
                    echo $sql, PHP_EOL;
                    $db->historyRun($sql);
                }
                // drop any remaining columns from history
                foreach ($history_columns as $column_name => $idem) {
                    $sql = 'ALTER TABLE "' . $db_schema . '"."' . $table_name . '" DROP COLUMN if exists "' . $column_name . '";';
                    echo $sql . PHP_EOL;
                    $db->historyRun($sql);
                    // maybe a constraint was associated with this column, drop it as well
                    $sql = 'ALTER TABLE "' . $db_schema . '"."' . $table_name . '" DROP CONSTRAINT if exists "unique_' . $table_name . '_' . $column_name . '";';
                    echo $sql . PHP_EOL;
                    $db->historyRun($sql);
                }
            }
            // @since 0.7.5 this also creates / reindexes the indexes on slug columns
            if (substr($table_name, 0, 4) === 'cms_' and $info->getColumnByName('slug')) {
                $index_name = 'index_slug_' . $table_name;
                echo 'Handling index ' . $index_name . PHP_EOL;
                $db->historyRun('CREATE INDEX if not exists "' . $index_name . '" ON "' . $db_schema . '"."' . $table_name . '" USING btree ("slug" Asc NULLS Last)');
                $db->historyRun('REINDEX INDEX ' . $index_name);
            }
        }
    }

    public static function install(DB $db)
    {
        // check the (new) folders @since 0.10.0
        $log_path = substr(Setup::$LOGFILE, 0, strrpos(Setup::$LOGFILE, '/'));
        $db_cache = Setup::$DBCACHE;
        foreach (array(
                     $db_cache,
                     Setup::$INVOICE,
                     $log_path,
                     Setup::$UPLOADS,
                     Setup::$CDNPATH
                 ) as $index => $folder_name) {
            if (!file_exists($folder_name)) {
                Help::addError(new \Exception(sprintf('Please create folder %s on the server', $folder_name)));
            }
            if (!is_writable($folder_name)) {
                Help::addError(new \Exception(sprintf('Folder %s must be writable by the web user', $folder_name)));
            }
            if ($db_cache === $folder_name) {
                // setup the mandatory subfolders
                foreach (array(
                             "{$db_cache}css",
                             "{$db_cache}js",
                             "{$db_cache}filter",
                             "{$db_cache}templates",
                         ) as $index2 => $subfolder_name) {
                    if (false === file_exists($subfolder_name)) {
                        if (false === mkdir($subfolder_name)) {
                            Help::addError(new \Exception(sprintf('Could not create folder %s', $subfolder_name)));
                        }
                    }
                }
            }
        }
        if ($messages = Help::getErrorMessages()) {
            die(implode('<br/>', $messages));
        }
        /**
         * if there is a version in the db, this may be an upgrade request
         */
        try {
            $version = $db->getDbVersion();
        } catch (\Exception $e) {
            Help::addError($e);
            $version = '';
        }
        $do = false;
        if ('' !== $version) {
            $do = version_compare(Setup::$VERSION, $version);
        }
        if ($do === 0) {
            Help::addError(new \Exception('Installed version is current, please switch off the install flag in config'));

            return;
        } elseif ($do < 0) {
            Help::addError(new \Exception('Seems the previously installed version is higher, downgrading is not supported'));

            return;
        }
        if ($do === 1) {
            // setup output stream for feedback and write it to the log
            ob_start();
            Help::upgrade($db);
            echo PHP_EOL, PHP_EOL;
            error_log(ob_get_clean(), 3, Setup::$LOGFILE);
        } else {
            $installable = true;
            /**
             * if this wasn't an upgrade, install fresh, checking requirements
             */
            if (!defined('PHP_VERSION_ID')) {
                $version = explode('.', PHP_VERSION);
                define('PHP_VERSION_ID', ((int)$version[0] * 10000 + (int)$version[1] * 100 + (int)$version[2]));
            }
            if (PHP_VERSION_ID < 80000) {
                die('Bloembraaden needs php version 8.0 or higher.');
            }
            if (is_null(Help::passwordHash('test'))) {
                $installable = false;
                echo 'PASSWORD_ARGON2ID seems missing', PHP_EOL;
            }
            if (false === extension_loaded('exif')) {
                $installable = false;
                echo 'please install and enable exif extension for php', PHP_EOL;
            }
            if (false === extension_loaded('gd')) {
                $installable = false;
                echo 'please install gd extension for php', PHP_EOL;
            }
            if (false === extension_loaded('mbstring')) {
                $installable = false;
                echo 'please install mbstring extension for php', PHP_EOL;
            }
            if (!function_exists('imagewebp')) {
                $installable = false;
                echo 'please enable imagewebp', PHP_EOL;
            }
            if (!function_exists('imagejpeg')) {
                $installable = false;
                echo 'please enable imagejpeg', PHP_EOL;
            }
            // check if a first instance domain is provided
            if (isset($_SERVER['HTTP_HOST'])) {
                $instance_url = $_SERVER['HTTP_HOST'];
            } elseif (isset($_ENV['MAIN_URL'])) {
                $instance_url = $_ENV['MAIN_URL'];
            } else {
                $installable = false;
                echo 'Cannot install without HTTP_HOST header', PHP_EOL;
            }
            if (false === $installable) {
                die('Install failed');
            }
            // check for first admin
            if (isset($_GET['admin_email'], $_GET['admin_password'])) {
                $admin_email = $_GET['admin_email'];
                $admin_password = $_GET['admin_password'];
            } elseif (isset($_ENV['BLOEMBRAADEN_ADMIN_EMAIL'], $_ENV['BLOEMBRAADEN_ADMIN_PASSWORD'])) {
                $admin_email = $_ENV['BLOEMBRAADEN_ADMIN_EMAIL'];
                $admin_password = $_ENV['BLOEMBRAADEN_ADMIN_PASSWORD'];
            } else {
                die('Install failed, please provide first admin through env or querystring. ?admin_email=X&admin_password=Y');
            }
            /**
             * run the entire install file
             */
            $install_file = CORE . '/data/install.sql';
            if (!file_exists($install_file)) {
                die("Expected sql file not found: $install_file");
            }
            $sql = file_get_contents($install_file);
            $db->run($sql);
            /**
             * insert first client
             */
            if (!$client_id = $db->insertClient('Client name')) {
                var_dump(Help::getErrors());
                die('insertClient error');
            }
            /**
             * insert first instance
             */
            $instance_id = $db->insertInstance($instance_url, $instance_url, $client_id);
            if ($instance_id === null) {
                var_dump(Help::getMessages());
                die('Install instance error');
            } else {
                define('INSTANCEID', $instance_id);
            }
            /**
             * insert first page
             */
            if ($page_id = $db->insertElement(new Type('page'), array(
                'title' => 'homepage',
                'slug' => '',
                'template' => 'peatcms',
                'content' => 'My first homepage'
            ))) {
                $db->updateInstance(INSTANCEID, array('homepage_id' => $page_id));
            } else {
                var_dump(Help::getErrors());
                die('insertPage error');
            }
            /**
             * insert first admin
             */
            $hash = Help::passwordHash($admin_password);
            $user_id = $db->insertAdmin($admin_email, $hash, $client_id);
            if ($user_id === null) {
                var_dump(Help::getErrors());
                die('<h1>Install error</h1>');
            }
            // sync history
            echo 'Sync history database<br/>' . PHP_EOL;
            Help::syncHistoryDatabase($db);
            /**
             * register current version
             */
            $version = substr($sql, strrpos($sql, '-- version '));
            $length = strpos($version, 'BEGIN;');
            $version = trim(substr($version, 10, $length - 10));
            $db->run(sprintf('insert into _system (version) values(\'%s\');', $version));
            // done, feedback to user
            echo sprintf('Successfully installed Bloembraaden version %s', $version);
            echo '<br/>', PHP_EOL;
            echo sprintf('Now set version to %s exactly in your config.json and switch off the install flag', $version);
            die();
        }
    }
}
