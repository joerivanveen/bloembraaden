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
    private static array $values = array();
    private static array $messages = array(); // indexed array of objects {message:..,count:..,level:..}

    public static Session $session;
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
     * @param string $identifier
     * @param $value
     * @return void
     */
    public static function setValue(string $identifier, $value): void
    {
        self::$values[$identifier] = $value;
    }

    /**
     * @param string $identifier
     * @return mixed|null
     */
    public static function getValue(string $identifier): mixed
    {
        if (array_key_exists($identifier, ($values = self::$values))) {
            return $values[$identifier];
        }
        return null;
    }

    /**
     * @param mixed $var the variable to be returned as integer
     * @param int|null $default return value if $var cannot be converted to integer
     * @return int|null the $var converted to integer, or $default when conversion failed
     * @since 0.4.0
     */
    public static function asInteger($var, ?int $default = null): ?int
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
    public static function asFloat(mixed $var, ?float $default = null): ?float
    {
        if ((string)($float = (float)$var) === $var) return $float; // return correctly formatted vars immediately
        // get the float correctly from string
        $var = str_replace(Setup::$DECIMAL_SEPARATOR, '.', str_replace(array(Setup::$RADIX, ' '), '', (string)$var));
        // convert to float
        if (is_numeric($var)) {
            return (float)$var;
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

    public static function strtotime_ms(string $date): int
    {
        $ms = (int)substr(explode('.', $date)[1], 0, 3);
        return 1000 * strtotime($date) + $ms;
    }

    /**
     * Floating point numbers have errors that make them ugly and unusable that are not simply fixed by round()ing them.
     * Use floatForHumans to return the intended decimal as a string.
     * Decimal separator is a . as is standard, use numberformatting/ str_replace etc. if you want something else.
     * @see https://stackoverflow.com/questions/4921466/php-rounding-error
     *
     * @param float|null $float a float that will be formatted to be human-readable
     *
     * @return string the number is returned as a correctly formatted string
     *
     * @since    0.5.1
     */
    public static function floatForHumans(?float $float): string
    {
        if (null === $float) return '';

        return (string)$float; // this conversion eliminates rounding problems like .99999 and .00001
    }

    public static function truncate(string $string, int $length)
    {
        if (strlen($string) <= $length) return $string;
        return substr($string, 0, $length - 3) . '...';
    }

    public static function removeAccents(string $string): string
    {
        if (\false === preg_match('/[\x80-\xff]/', $string))
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
                echo $_SERVER['REMOTE_ADDR'], ':', $_SERVER['REMOTE_PORT'];
            } else {
                echo 'NO CLIENT';
            }
            echo "\t", date('Y-m-d H:i:s'), "\t";
            if (isset($_SERVER['REQUEST_METHOD'])) echo $_SERVER['REQUEST_METHOD'], "\t";
            if (isset($_SERVER['REQUEST_URI'])) echo $_SERVER['REQUEST_URI'], "\t";
            echo "LOG\n";
            echo implode("\n", $arr);
            echo "\n";

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
        $pieces = [];
        $max = strlen($key_space) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $pieces [] = $key_space[\random_int(0, $max)];
        }

        return implode('', $pieces);
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

    public static function getMemorySize(string $memory, string $multiplier = ''): string
    {
        $multiplier = strtolower($multiplier);
        $unit = substr($memory, -1);
        if (is_numeric($unit)) {
            $unit = '';
        } else {
            $unit = strtolower($unit);
            $memory = substr($memory, 0, -1);
        }
        if ($unit === $multiplier) return "$memory$multiplier";

        // convert memory to bytes
        if ($unit === 'g') $memory *= 1024 * 1024 * 1024;
        elseif ($unit === 'm') $memory *= 1024 * 1024;
        elseif ($unit === 'k') $memory *= 1024;

        if ('' === $multiplier) return (string)$memory;

        // convert memory to what you want
        if ($multiplier === 'g') $memory /= (1024 * 1024 * 1024);
        elseif ($multiplier === 'm') $memory /= (1024 * 1024);
        elseif ($multiplier === 'k') $memory /= (1024);

        $memory = ceil($memory);

        return "$memory$multiplier";
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
        $filename = Help::randomString(20);
        $new_path = Setup::$UPLOADS . $filename;
        if (file_exists($new_path)) { // this should never happen
            self::trigger_error("File $new_path already exists", E_USER_ERROR);
        } else {
            copy($temp_path, $new_path);
        }

        return $filename;
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
        $html = str_replace('<p id="remarks"', "\r\n.\r\n.\r\nREMARKS:\r\n<p", $html);
        $html = strip_tags($html);
        $html = str_replace("\r\n ", "\r\n", $html);
        $html = str_replace("\r\n ", "\r\n", $html);
        $html = str_replace("\r\n\r\n", "\r\n", $html);
        $html = str_replace("\r\n\r\n", "\r\n", $html);

        return $html;
    }

    public static function validate_vat(string $country_iso2, string $number): array
    {
        // check availability: https://ec.europa.eu/taxation_customs/vies/#/self-monitoring
        if (2 !== strlen($country_iso2) || strlen($number) < 5) {
            return array('success' => true, 'valid' => false, 'response' => 'Incorrect format');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://ec.europa.eu/taxation_customs/vies/rest-api/ms/$country_iso2/vat/$number");
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = (array)json_decode(curl_exec($ch));
        curl_close($ch);
        if (0 === json_last_error()) {
            $valid = $result['isValid'];
            $success = true;
            if (false === $valid && true === isset($result['userError']) && 'MS_UNAVAILABLE' === $result['userError']) {
                $success = false; // Member state unavailable
                self::addMessage(__('Member state VAT check service unavailable.', 'peatcms'), 'warn');
            }
            return array(
                'response' => $result,
                'valid' => $valid,
                'success' => $success,
            );
        } else {
            Help::addMessage(sprintf(__('Error reading %s response.', 'peatcms'), 'VIES'), 'warn');

            return array('success' => false);
        }
    }

    /**
     * @param Instance $instance
     * @param \stdClass $post_data
     * @return bool
     * @since 0.19.0 Use cloudflare turnstile as recaptcha successor
     */
    public static function turnstileVerify(Instance $instance, \stdClass $post_data): bool
    {
        if ($instance->getSetting('turnstile_site_key') === '') return true;
        if ('' === ($turnstile_secret_key = $instance->getSetting('turnstile_secret_key'))) {
            Help::addError(new \Exception('Turnstile secret key not filled in'));
            Help::addMessage(__('Turnstile configuration error.', 'peatcms'), 'error');

            return false;
        }
        if (true === isset($post_data->{'cf-turnstile-response'})
            && ($cf_turnstile_response = $post_data->{'cf-turnstile-response'})
        ) {
            $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
            $fields = [
                'secret' => $turnstile_secret_key,
                'response' => $cf_turnstile_response,
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
            if (0 === json_last_error()) {
                if (false === $result['success']) {
                    $turnstile_errors = $result['error-codes'];
                    Help::addError(new \Exception('Turnstile error: ' . var_export($turnstile_errors, true)));
                    Help::addMessage(sprintf(__('Turnstile error (%s).', 'peatcms'), $turnstile_errors[0] ?? 'unknown'), 'error');

                    return false;
                }
            } else {
                Help::addMessage(sprintf(__('Error reading %s response.', 'peatcms'), 'turnstile json'), 'warn');

                return false;
            }
        } else { // cf-turnstile-response is missing
            Help::addMessage(__('No turnstile response received.', 'peatcms'), 'error');

            return false;
        }

        return true;
    }

    public static function prepareAdminRowForOutput(\stdClass &$row, string $what, ?string $which = null): void
    {
        $row->title = "$what | Bloembraaden";
        $row->template_pointer = (object)array('name' => $what, 'admin' => true);
        $row->type_name = $what;
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
        $password = hash_hmac('sha256', $password, Setup::$HASHKEY, false);
        return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 15360, 'time_cost' => 128, 'threads' => 1]);
    }

    public static function tokenHash(string $token): bool|string|null
    {
        return hash_hmac('sha256', $token, Setup::$HASHKEY, false);
    }

    /**
     * Creates a safe lowercase slug from a UTF-8 string
     *
     * @param string $string The string you want to be converted to a slug
     * @return string slug-safe UTF-8 string
     */
    public static function slugify(string $string): string
    {
        $string = strip_tags($string);
        // preserve underscore and hyphen
        $string = str_replace(array('_', '-'), ' ', $string);
        // only keep Unicode Letter (includes space), Number and Marks
        $string = preg_replace('/[^\p{L}\p{N}\p{M}\s]/u', '', $string);
        // remove control chars
        $string = preg_replace('/[\p{C}]/u', ' ', $string);
        // replace spaces with hyphen
        $string = str_replace(' ', '-', $string);
        // lose consecutive hyphens
        $string = preg_replace('|-+|', '-', $string);
        // cannot be longer than 127 characters
        $string = substr($string, 0, 127);
        // trim neatly
        $string = trim($string, '-');
        // return lowercase
        return mb_strtolower($string);
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

    /**
     * @param string $url
     * @return string|null returns the $url when it is deemed safe, null otherwise
     */
    public static function safeUrl(string $url): ?string
    {
        $absolute = true;
        if (true === str_starts_with($url, 'https://')) {
            $url = substr($url, 8);
        } elseif (true === str_starts_with($url, 'http://')) {
            $url = substr($url, 7);
        } elseif (true === str_starts_with($url, '//')) {
            $url = substr($url, 2);
        } else {
            $absolute = false;
        }
        $parts = explode('/', $url);
        // sanitize
        foreach ($parts as $index => $part) {
            if (0 === $index && true === $absolute) {
                if ($part === Setup::$INSTANCE_DOMAIN) continue;
                if (1 !== preg_match('/^[a-z0-9\-.:]+$/', $part)) {
                    return null;
                }
            }
            if ($part !== Help::slugify($part)) {
                return null;
            }
        }

        return $url;
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

    public static function obtainLock(string $identifier, bool $persist = false): bool
    {
        $identifier = ".locks.$identifier";
        $filename = Setup::$DBCACHE . rawurlencode($identifier);

        if (true === $persist) {
            $lock = self::$session->getValue($identifier);
        } else {
            $lock = self::getValue($identifier);
        }
        // if you already have it, proceed
        if (true === $lock) {
            return true;
        }
        // if nobody else has it, lock it tight, else return false
        if (false === file_exists($filename)) {
            // TODO have locks work over multiple servers...
            file_put_contents($filename, '', LOCK_EX);
        } else {
            return false;
        }
        // add to current session / request
        if (true === $persist) {
            self::$session->setVar($identifier, true);
        } else {
            self::setValue($identifier, true);
            // if not persisting, release the lock always when the request is done
            register_shutdown_function(static function () use ($filename) {
                if (Help::$LOGGER) {
                    (Help::$LOGGER)->log("Releasing lock $filename");
                }
                @unlink($filename);
            });
        }

        return true;
    }

    public static function releaseLock(string $identifier): void
    {
        $identifier = ".locks.$identifier";
        $filename = Setup::$DBCACHE . rawurlencode($identifier);

        // remove lock
        $lock = self::getValue($identifier) ?: self::$session->getValue($identifier, true);
        if (true === $lock) {
            if (true === file_exists($filename)) {
                // it might already been released early, if this is from shutdown routine...
                unlink($filename);
            }
        } else {
            self::addError(new \Exception("Lock $identifier not found for release"));
        }
    }


    public static function export_instance(int $instance_id, LoggerInterface $logger, bool $include_user_data): void
    {
        if (false === Help::obtainLock("export.$instance_id")) {
            self::handleErrorAndStop("Could not obtain lock exporting instance $instance_id", 'Error: export already running');
        }
        set_time_limit(0);
        $instance_name = Setup::$PRESENTATION_INSTANCE;
        $folder_name = self::import_export_folder();
        $export_file = "$folder_name.export-$instance_name.json";
        if (file_exists($export_file)) {
            $logger->log("Export file $export_file already exists, aborting");
            die();
        }
        $logger->log("Exporting instance $instance_name");
        $db = self::getDB();
        $tables = $db->fetchTablesToExport($include_user_data);
        $version = Setup::$VERSION;
        $date_as_string = date('Y-m-d H:i:s', Setup::getNow());
        $static_root = Setup::$CDNROOT;
        file_put_contents($export_file, "\"Bloembraaden instance\":\"$instance_name\"\n\"Export date\":\"$date_as_string\"\n\"version\":\"$version\"\n\"static_root\":\"$static_root\"\n", LOCK_EX);
        if (true === $include_user_data) {
            file_put_contents($export_file, "\"Include user data\":1\n", FILE_APPEND | LOCK_EX);
        }
        foreach ($tables as $index => $table) {
            $table_name = $table->table_name;
            $logger->log("Exporting $table_name");
            // use the prepared statement to fetch and save row by row, to prevent memory exhaustion
            $statement = $db->queryRowsForExport($table_name, $instance_id);
            file_put_contents($export_file, "\"table\":{\"table_name\":\"$table_name\",\"row_count\":{$statement->rowCount()}}\n", FILE_APPEND | LOCK_EX);
            // write row by row to buffer, save every 20 rows to file
            ob_start();
            $row_number = 0;
            while (($row = $statement->fetch(5))) {
                ++$row_number;
                echo "\"$row_number\":";
                echo json_encode($row);
                echo "\n";
                if (0 === $row_number % 20) {
                    file_put_contents($export_file, ob_get_clean(), FILE_APPEND);
                    ob_start();
                }
            }
            // save remaining to file
            file_put_contents($export_file, ob_get_clean(), FILE_APPEND);
        }
        $logger->log("Exported to $export_file.");
        try {
            chmod($export_file, 0666);
            $logger->log('Set permissions to 666');
        } catch (\Throwable) {
        }
    }

    public static function import_export_folder(): string
    {
        $folder_name = Setup::$DBCACHE . 'import_export/';
        if (false === file_exists($folder_name)) {
            mkdir($folder_name);
        }
        return $folder_name;
    }

    public static function import_into_this_instance(string $filename, LoggerInterface $logger): void
    {
        // todo import files as well...
        if (false === file_exists($filename)) {
            $logger->log('File does not exist, aborting');
            return;
        }
        // what instance id are we performing the import on?
        $instance_id = Setup::$instance_id; // can only import native instance
        if (false === Help::obtainLock("import.$instance_id")) {
            self::handleErrorAndStop("Could not obtain lock importing instance $instance_id", 'Error: import already running');
        }
        set_time_limit(0); // this might take a while
        $static_root = null;
        $update_order_numbers = false;
        $folder_name = self::import_export_folder();
        // remove remnants of previous import
        $files = glob("$folder_name$instance_id.*.json");
        foreach ($files as $index => $file) {
            if (true === is_file($file)) {
                unlink($file); // delete file
            }
        }
        // prepare some vars
        $files = array($filename);
        $db = self::getDB();
        $tables = array_map(function ($value) {
            return $value->table_name;
        }, $db->fetchTablesToExport(true));
        /**
         * ignore _id columns that are false
         * _id columns that are string, translate from that other column
         * other id columns must be filled with translation arrays:
         * [
         *   old id => new id,
         * ]
         * if a table has any id columns (other than its own) that are not yet translated,
         * park the table for later, reload it then to try again.
         */
        $ids = array(
            'homepage_id' => 'page_id',
            'reply_to_id' => 'comment_id',
            'session_id' => false,
            'admin_id' => false,
            'google_tracking_id' => false,
            'umami_website_id' => false,
            'payment_transaction_id' => false,
            'payment_tracking_id' => false,
            'taxonomy_id' => false,
            'template_id_order_confirmation' => 'template_id',
            'template_id_payment_confirmation' => 'template_id',
            'template_id_internal_confirmation' => 'template_id',
        );
        $repeat = -1;
        // read file
        while (($filename = array_shift($files)) && file_exists($filename)) {
            ++$repeat;
            $row_index = 0;
            $logger->log("Process file $filename");
            $handle = @fopen($filename, 'r');
            $string = '';
            $table_name = '';
            if ($handle) {
                $row_treat = 'save'; // skip, wait, save
                $id_column_name = null;
                // fgets read a line until eol, or 'length' bytes of the same line
                while (($buffer = fgets($handle, 4096)) !== false) {
                    $string .= $buffer;
                    $buffer = null;
                    if ("\n" === mb_substr($string, -1)) {
                        $string = trim($string, "\t\r\n\,");
                        $json = (array)json_decode("{{$string}}");
                        if (0 === json_last_error()) {
                            $value = reset($json);
                            $key = key($json);
                            if ('table' === $key) {
                                if ($row_index > 0) {
                                    $logger->log("$row_index rows imported");
                                    $row_index = 0;
                                }
                                $table_name = $value->table_name;
                                $row_count = $value->row_count;
                                $row_treat = 'save';
                                $logger->log("Handle table $table_name");
                                if (false === in_array($table_name, $tables)) {
                                    $logger->log("Table $table_name is not imported");
                                    $string = '';
                                    // following rows must be skipped, until a new ‘table’ is encountered
                                    $row_treat = 'skip';
                                    continue;
                                } elseif ('_order' === $table_name) {
                                    $update_order_numbers = true;
                                }
                                $info = $db->getTableInfo($table_name);
                                if ('_history' !== $table_name) { // history has no id column
                                    $id_column_name = $info->getIdColumn()->getName();
                                    // check the columns, if any _id column is present, it has to be translated
                                    foreach ($info->getColumnNames() as $index => $column_name) {
                                        if ($id_column_name === $column_name) continue;
                                        if ('instance_id' === $column_name) continue;
                                        if ('client_id' === $column_name) continue;
                                        // for the check sub_ _id columns in _x_ tables should be treated as the _id column
                                        if (str_starts_with($column_name, 'sub_')) {
                                            $column_name = substr($column_name, 4);
                                        }
                                        if (isset($ids[$column_name])) {
                                            $column_name_to_check = $ids[$column_name];
                                            if (false === $column_name_to_check) continue;
                                            if (is_string($column_name_to_check)
                                                && false === isset($ids[$column_name_to_check])
                                            ) {
                                                $logger->log("Column $column_name -> $column_name_to_check not filled yet");
                                                $row_treat = 'wait';
                                                break;
                                            }
                                        } elseif (str_ends_with($column_name, '_id')) {
                                            $logger->log("Column $column_name not filled with ids yet");
                                            $row_treat = 'wait';
                                            break;
                                        }
                                    }
                                }
                                if ('save' === $row_treat || 0 === $row_count) {
                                    $repeat = -1;
                                    if ('_instance' !== $table_name) {
                                        if (($affected = $db->deleteForInstance($table_name, $instance_id))) {
                                            $logger->log("Cleared $affected rows from $table_name");
                                        }
                                        $logger->log("Importing table $table_name ($row_count rows)");
                                        // import the table line by line and register the id->id translation
                                        $ids[$id_column_name] = array();
                                    }
                                } else { // 'wait' === $row_treat
                                    $logger->log("Save table $table_name for later");
                                    // register this table for importing later and write the object to disk
                                    $filename = "$folder_name$instance_id.$table_name.json";
                                    if (false === file_exists($filename)) {
                                        file_put_contents($filename, "$string\n", LOCK_EX);
                                    } else {
                                        $row_treat = 'skip'; // no need to save all the rows to this file again
                                    }
                                    $files[] = $filename;
                                }
                                // we’re done with the table row
                                $string = '';
                                continue;
                            }
                            if (is_object($value)) { // value is a row ($key = index...)
                                if ('save' === $row_treat) {
                                    $row = (array)$value;
                                    if (true === isset($id_column_name)) {
                                        $old_id = (int)$row[$id_column_name];
                                    } else {
                                        $old_id = 0; // _history table
                                    }
                                    // todo translate values in the row between versions?
                                    foreach ($row as $col_name => $col_value) {
                                        // $col_trans will be the name of the original id column we need
                                        if (str_starts_with($col_name, 'sub_')) {
                                            $col_trans = substr($col_name, 4);
                                        } else {
                                            $col_trans = $col_name;
                                        }
                                        if ($id_column_name === $col_name) {
                                            // don’t save the primary key
                                            unset($row[$col_name]);
                                        } elseif (true === isset($ids[$col_trans])) {
                                            if (is_string($ids[$col_trans])) {
                                                $col_trans = $ids[$col_trans];
                                                if (false === is_array($ids[$col_trans])) {
                                                    self::handleErrorAndStop("No ids found for column $col_name translated to $col_trans");
                                                }
                                            }
                                            if (true === is_array(($ids_for_col = $ids[$col_trans]))) {
                                                // 0 is possible as a default value for id's
                                                if (0 === $col_value) {
                                                    $row[$col_name] = 0;
                                                } else {
                                                    if (false === isset($ids_for_col[$col_value])) {
                                                        $logger->log("Id $col_value not found for column $col_name");
                                                        continue; // if the id was no longer in the original, no need to import
                                                    }
                                                    $row[$col_name] = $ids_for_col[$col_value];
                                                }
                                            }
                                        } elseif ('instance_id' === $col_name) {
                                            $row['instance_id'] = $instance_id; // we are now this instance id
                                        } elseif ('client_id' === $col_name) {
                                            $row['client_id'] = Help::$session->getInstance()->getClientId();
                                        } elseif (str_starts_with($col_name, 'date_') && '' === $col_value) {
                                            $row[$col_name] = 'NOW()';
                                        }
                                    }
                                    if ('_instance' === $table_name) {
                                        unset($row['instance_id']);
                                        unset($row['domain']); // you cannot use the same domain for another instance, just leave it be
                                        $row['name'] .= ' (IMPORTED)';
                                        if (true === $db->updateColumns('_instance', $row, $instance_id)) {
                                            ++$row_index;
                                            $logger->log("Updated instance $instance_id");
                                        }
                                    } elseif ('_history' === $table_name) {
                                        if (true === $db->insertHistoryEntry((object)$row)) {
                                            ++$row_index;
                                        }
                                    } else {
                                        if ('cms_image' === $table_name) {
                                            $row['filename_saved'] = 'IMPORT'; // to trigger the import job
                                        }
                                        $new_id = $db->insertRowAndReturnKey($table_name, $row, true);
                                        if (null !== $new_id) {
                                            ++$row_index;
                                            $ids[$id_column_name][$old_id] = $new_id;
                                        }
                                    }
                                } elseif ('wait' === $row_treat) {
                                    // save to the existing file
                                    file_put_contents($filename, "$string\n", FILE_APPEND);
                                }
                                //$logger->log(var_export($value, true));
                            } elseif (is_array($value)) {
                                $logger->log("$key: Array, no longer supported");
                            } else {
                                if ('static_root' === $key) {
                                    $static_root = $value;
                                }
                                $logger->log("$key: $value");
                            }
                        } else {
                            $logger->log('Row is not json, skipping');
                        }
                        //$logger->log(var_export($files, true));
                        $string = '';
                    }
                }
                if (false === feof($handle)) {
                    $logger->log('Error: unexpected fgets() fail');
                }
            } else {
                $logger->log('Error: couldn’t get a handle on that file');
            }
            //$logger->log('repeat: ' . $repeat . ' / ' . count($files));
            if ($repeat > count($files)) {
                Help::handleErrorAndStop('Endless loop detected during import', 'Endless loop detected, aborting');
            }
            $logger->log("$row_index rows imported");
        }
        // routine to refresh order numbers
        if (true === $update_order_numbers
            && true === $db->refreshOrderNumbers($instance_id)
        ) {
            $logger->log('Updated order numbers table');
        }
        // update slugs in _history table to have auto-redirect restored
        $logger->log('Update history for redirects');
        $tables_with_slugs = array();
        foreach ($db->getTablesWithSlugs() as $index => $row) {
            $tables_with_slugs[$row->table_name] = $index;
        }
        foreach ($ids as $col_name => $translated_ids) {
            if (false === is_array($translated_ids)) continue;
            // get table name from id:
            $table_name = 'cms_' . substr($col_name, 0, -3);
            if (false === isset($tables_with_slugs[$table_name])) continue;
            // todo loop door de ids in $translated_ids, maar check op overlappingen, en doe die laatst,
            // zodat je niet de net geüpdatete nog een keer update (van 1 naar 3 en van 3 naar 5)
            /**
             * 1 => 3
             * 2 => 4
             * 3 => 5
             * 5 => 2
             * 4 => 8 <- als deze naar 1 gaat kan het dus niet :-P
             */
            while (0 !== ($count = count($translated_ids))) {
                foreach ($translated_ids as $old => $new) {
                    // save for later, when this value exists as old as well
                    if (isset($translated_ids[$new])) continue;
                    // update the history table
                    $db->updateHistoryKey($new, array(
                        'instance_id' => $instance_id,
                        'table_name' => $table_name,
                        'key' => $old,
                    ));
                    // this one is done
                    unset($translated_ids[$old]);
                }
                if ($count === count($translated_ids)) {
                    $logger->log('ERROR: loop detected in history, cannot update');
                    break;
                }
            }
            $logger->log("$table_name done");
        }
        // prepare images for import
        if (null === $static_root) {
            $logger->log('ERROR: static root missing in import file, cannot import images');
        } else {
            $db->updateElementsWhere(
                new Type('image'),
                array('static_root' => $static_root),
                array('instance_id' => $instance_id, 'filename_saved' => 'IMPORT')
            );
            $logger->log('Prepared images for import');
        }
        // cleanup
        $files = glob("$folder_name$instance_id.*.json");
        foreach ($files as $index => $file) {
            if (is_file($file)) {
                unlink($file); // delete file
            }
        }
    }

    /**
     * @param \Exception|string $e
     * @param string $message_for_frontend
     * @return void
     */
    public static function handleErrorAndStop($e, string $message_for_frontend = 'Bloembraaden fatal error'): void
    {
        $boo = new BaseLogic();
        $boo->handleErrorAndStop($e, $message_for_frontend);
    }

    public static function publishTemplates(int $instance_id): bool
    {
        // update the instance with global published value
        if (false === Help::getDB()->updateColumns('_instance', array(
                'date_published' => 'NOW()'
            ), $instance_id)) {
            self::addMessage(__('Could not update date published.', 'peatcms'), 'warn');
        }
        // @since 0.9.4 cache css file on disk when all templates are published, to be included later
        $edit_instance = new Instance(Help::getDB()->fetchInstanceById($instance_id));
        $file_location = Setup::$DBCACHE . "css/$instance_id.css";
        $doc = file_get_contents(CORE . '_front/peat.css'); // reset and some basic stuff
        $doc .= file_get_contents(CORE . "../htdocs/_site/{$edit_instance->getPresentationInstance()}/style.css");
        // minify https://idiallo.com/blog/css-minifier-in-php
        $doc = str_replace(array("\n", "\r", "\t"), '', $doc);
        // strip out the comments:
        $doc = preg_replace("/\/\*.*?\*\//", '', $doc); // .*? means match everything (.) as many times as possible (*) but non-greedy (?)
        // reduce multiple spaces to 1
        $doc = preg_replace("/\s{2,}/", ' ', $doc);
        // remove some spaces that are never necessary, that is, only when not in an explicit string
        $doc = preg_replace("/, (?!')/", ',', $doc);
        $doc = preg_replace("/: (?!')/", ':', $doc);
        $doc = preg_replace("/; (?!')/", ';', $doc);
        // todo, remove unused rules...
        // the curly brace is probably always ok to attach to its surroundings snugly
        $doc = str_replace(' { ', '{', $doc);
        // write plain css to disk, will be included in template and gzipped along with it
        if (false === file_put_contents($file_location, $doc, LOCK_EX)) {
            self::addMessage(sprintf(__('Could not write ‘%s’ to disk.', 'peatcms'), $file_location), 'warn');
        }
        if ('' === file_get_contents($file_location)) {
            unlink($file_location);
            self::handleErrorAndStop('Saving css failed.', __('Saving css failed', 'peatcms'));
        }
        // get all templates for this instance_id, loop through them and publish
        $rows = Help::getDB()->getTemplates($instance_id);
        foreach ($rows as $index => $row) {
            $temp = new Template($row);
            if (false === $temp->publish()) {
                self::addMessage(__('Publishing failed.', 'peatcms'), 'error');
                unset($rows);

                return false;
            }
        }
        // clear template default id cache
        self::getDB()->appCacheSet("templates/defaults.$instance_id", array());
        self::addMessage(__('Publishing done.', 'peatcms'));
        unset($rows);

        return true;
    }

    public static function supplementAddresses(array $rows, array $by_key): void
    {
        $db = self::getDB();
        foreach ($rows as $i => $row) {
            // get shipping / billing address key, if not in the addresses by key, add it
            foreach (array('billing', 'shipping') as $index => $address_type) {
                $address = array(
                    'user_id' => $row->user_id,
                    'instance_id' => $row->instance_id,
                    'address_name' => $row->{"{$address_type}_address_name"},
                    'address_company' => $row->{"{$address_type}_address_company"},
                    'address_postal_code' => $row->{"{$address_type}_address_postal_code"},
                    'address_number' => $row->{"{$address_type}_address_number"},
                    'address_number_addition' => $row->{"{$address_type}_address_number_addition"},
                    'address_street' => $row->{"{$address_type}_address_street"},
                    'address_street_addition' => $row->{"{$address_type}_address_street_addition"},
                    'address_city' => $row->{"{$address_type}_address_city"},
                    'address_country_name' => $row->{"{$address_type}_address_country_name"},
                    'address_country_iso2' => $row->{"{$address_type}_address_country_iso2"},
                    'address_country_iso3' => $row->{"{$address_type}_address_country_iso3"},
                );
                $key = Address::makeKey((object)$address);
                if (false === isset($by_key[$key])) {
                    if ($db->insertRowAndReturnKey('_address', $address)) {
                        $by_key[$key] = $address;
                    }
                }
            }
        }
    }

    /**
     * outputs sitemap in xml format for current instance_id (Setup::$instance_id)
     * @return void
     */
    public static function outputSitemap()
    {
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $domain = Setup::$INSTANCE_DOMAIN;
        $statement = self::getDB()->querySitemap(Setup::$instance_id);
        while (($row = $statement->fetch(5))) {
            echo '<url><loc>';
            echo 'https://', $domain, '/', urlencode($row->slug);
            echo '</loc><lastmod>';
            echo substr($row->date_updated, 0, 10);
            echo '</lastmod></url>';
        }
        $statement = null;
        echo '</urlset>';
    }

    public static function upgrade(DB $db): void
    {
        $version = $db->getDbVersion();
        if (strlen($version) < 5) {
            echo sprintf('Upgrade failed, version %s from db not valid', $version);

            return;
        }
        echo "Upgrading from $version\n";
        // read the sql file up until the old version and process everything after that. Remember the new version to check
        if (!file_exists(CORE . 'data/install.sql')) {
            echo 'install.sql not found, nothing to do';
            Help::addError(new \Exception('Install flag switched on, but no instructions found'));

            return;
        }
        // if the version mentioned in config is accurate
        $sql = file_get_contents(CORE . 'data/install.sql');
        // skip to the line -- version $version
        $position_of_current_version_in_sql = strpos($sql, "-- version $version");
        if ($position_of_current_version_in_sql === false) {
            echo sprintf('Current version %s not found in install.sql, aborting upgrade', $version);
            Help::addError(new \Exception('Install flag switched on, but failing'));

            return;
        }
        $position_of_next_version_in_sql = strpos($sql, '-- version', $position_of_current_version_in_sql + 10);
        $starting_position_of_upgrade_sql = strpos($sql, 'BEGIN;', $position_of_next_version_in_sql);
        // remember last version
        $version = substr($sql, strrpos($sql, '-- version '));
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
        //
        try {
            $db->run($sql);
        } catch (\PDOException $e) {
            $db->resetConnection();
            echo date(DATE_ATOM);
            echo "\n";
            echo $e;
            Help::addError(new \Exception('Install flag switched on, but failing'));

            return;
        }
        sleep(3); // give the db time to report back the new states
        // now set the version
        $db->run(sprintf('update _system set version = \'%s\';', $version));
        // @since 0.7.7 clear data cache
        $files = glob(Setup::$DBCACHE . '*.serialized'); // get all table info files
        foreach ($files as $index => $file) { // iterate files
            if (is_file($file)) {
                unlink($file); // delete file
            }
        }
        // clear opcache
        if (function_exists('opcache_reset')) opcache_reset();
        // done, feedback to user
        echo(sprintf('Successfully upgraded to version %s', $version));
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
            echo "\n\n";
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
            if (null === Help::passwordHash('test')) {
                $installable = false;
                echo 'PASSWORD_ARGON2ID seems missing', "\n";
            }
            if (false === extension_loaded('exif')) {
                $installable = false;
                echo 'please install and enable exif extension for php', "\n";
            }
            if (false === extension_loaded('gd')) {
                $installable = false;
                echo 'please install gd extension for php', "\n";
            }
            if (false === extension_loaded('mbstring')) {
                $installable = false;
                echo 'please install mbstring extension for php', "\n";
            }
            if (!function_exists('imagewebp')) {
                $installable = false;
                echo 'please enable imagewebp', "\n";
            }
            if (!function_exists('imagejpeg')) {
                $installable = false;
                echo 'please enable imagejpeg', "\n";
            }
            if (!function_exists('opcache_get_status') || false === opcache_get_status()) {
                $installable = false;
                echo 'please install and enable opcache', "\n";
            }
            // check if a first instance domain is provided
            if (isset($_SERVER['HTTP_HOST'])) {
                $instance_url = $_SERVER['HTTP_HOST'];
            } elseif (isset($_ENV['MAIN_URL'])) {
                $instance_url = $_ENV['MAIN_URL'];
            } else {
                $installable = false;
                echo 'Cannot install without HTTP_HOST header', "\n";
            }
            if (false === $installable) {
                die('Install failed');
            }
            // check for first admin
            if (isset($_ENV['BLOEMBRAADEN_ADMIN_EMAIL'], $_ENV['BLOEMBRAADEN_ADMIN_PASSWORD'])) {
                $admin_email = $_ENV['BLOEMBRAADEN_ADMIN_EMAIL'];
                $admin_password = $_ENV['BLOEMBRAADEN_ADMIN_PASSWORD'];
            } else {
                die('Install failed, please provide first admin as ENV variables.');
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
            /**
             * register current version
             */
            $version = substr($sql, strrpos($sql, '-- version '));
            $length = strpos($version, 'BEGIN;');
            $version = trim(substr($version, 10, $length - 10));
            $db->run(sprintf('insert into _system (version) values(\'%s\');', $version));
            // done, feedback to user
            echo sprintf('Successfully installed Bloembraaden version %s', $version);
            echo '<br/>', "\n";
            echo sprintf('Now set version to %s exactly in your config.json and switch off the install flag', $version);
            die();
        }
    }
}
