<?php
declare(strict_types=1);

namespace Bloembraaden;

use PDO, PDOException, Exception, stdClass;

if (class_exists('Setup')) {
    return new Setup();
}

class Setup
{
    public static bool $INSTALL, $VERBOSE, $NEWRELIC_RECORDS_BACKEND = false;
    public static bool $NOT_IN_STOCK_CAN_BE_ORDERED;
    public static int $instance_id, $DECIMAL_DIGITS;
    public static string $DECIMAL_SEPARATOR, $RADIX, $timezone, $THEDATE;
    public static string $VERSION, $UPLOADS, $INVOICE, $LOGFILE, $DBCACHE, $CDNROOT, $CDNPATH;
    public static string $PRESENTATION_INSTANCE, $INSTANCE_DOMAIN, $MAX_MEMORY_LIMIT, $FRAME_ANCESTORS;
    public static array $translations;
    public static stdClass $MAIL, $INSTAGRAM, $POSTCODE, $PDFMAKER;
    private static int $seconds_delta;
    private static ?PDO $DB_MAIN_CONN = null, $DB_HIST_CONN = null;
    private static stdClass $DB_MAIN, $DB_HIST;
    public const AVAILABLE_TIMEZONES = array(
        'Europe/London',
        'Europe/Amsterdam',
        'Europe/Brussels',
        'Europe/Paris',
        'Europe/Berlin',
    );
    private const DB_INIT_TRIES = 5;

    public function __construct()
    {
        self::loadConfig();
        // setup some cleaning for when execution ends
        register_shutdown_function(
            function () {
                // leave the connections in a good state when the script ends to be reused by postgres
                self::abandonDatabaseConnection(self::$DB_MAIN_CONN);
                self::abandonDatabaseConnection(self::$DB_HIST_CONN);
                // also log any serious errors
                self::logErrors();
            });
    }

    public static function logErrors(): void
    {
        if (($e = error_get_last())) {
            $message = "{$e['message']} ({$e['file']}:{$e['line']})";
            Help::addError(new \Exception($message, $e['type']));
            if (Help::$LOGGER instanceof LoggerInterface) Help::$LOGGER->log(sprintf(__('An error occurred in %s.', 'peatcms'), $e['file']));
        }

        $error_messages = Help::logErrorMessages();
        // newrelic reporting
        if (extension_loaded('newrelic')) {
            if (isset($error_messages)) newrelic_notice_error($error_messages);
            newrelic_add_custom_parameter('bloembraaden_instance', self::$INSTANCE_DOMAIN ?? 'unknown');
            newrelic_add_custom_parameter('bloembraaden_output_json', Help::$OUTPUT_JSON);
        }
    }

    /**
     * The db server is the single source of truth for Bloembraaden regarding the timestamp
     * @return int the timestamp (in seconds) that you can compare to any date_ value coming from the db server
     */
    public static function getNow(): int
    {
        if (false === isset(self::$seconds_delta)) {
            self::$seconds_delta = time() - strtotime(self::getMainDatabaseConnection()->query('SELECT NOW();')->fetchColumn(0));
        }

        return time() + self::$seconds_delta;
    }

    public static function getMainDatabaseConnection(): PDO
    {
        return self::$DB_MAIN_CONN ?? (self::$DB_MAIN_CONN = self::initializeDatabaseConnection(self::$DB_MAIN));
    }

    public static function getHistoryDatabaseConnection(): PDO
    {
        return self::$DB_HIST_CONN ?? (self::$DB_HIST_CONN = self::initializeDatabaseConnection(self::$DB_HIST));
    }

    /**
     * @noinspection PhpInconsistentReturnPointsInspection we know we don’t have to return after handleErrorAndStop...
     */
    private static function initializeDatabaseConnection(stdClass $db_properties, int $tries = 0): PDO
    {
        try {
            return new PDO(sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $db_properties->host,
                $db_properties->port,
                $db_properties->name
            ), $db_properties->user, $db_properties->pass, array(
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_EMULATE_PREPARES => true, // to use pool from pgbouncer which has no prepared statements
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => .5, // in seconds
            ));
        } catch (PDOException $e) {
            if (self::DB_INIT_TRIES > $tries) {
                sleep(1);
                return self::initializeDatabaseConnection($db_properties, ++$tries);
            } else {
                Help::$OUTPUT_JSON = file_get_contents('php://input') || 0 < count($_POST);
                Help::handleErrorAndStop($e, sprintf(__('No connection to database %s (tries: %d)', 'peatcms'), $db_properties->name, $tries));
            }
        }
    }

    private static function abandonDatabaseConnection(?PDO $connection): void
    {
        if (null !== $connection) {
            if (true === $connection->inTransaction()) {
                try {
                    $connection->rollBack();
                } catch (\Exception $e) {
                    Help::addError($e);
                    $connection = null;
                }
            }
            //$connection = null; // trying out persistent now <- transaction time reduced by 30%
        }
    }

    private static function loadConfig(): void
    {
        $current_date = date('Y-m-d');
        $config = json_decode(file_get_contents(CORE . '../config.json'));
        self::$VERSION = $config->version;
        self::$UPLOADS = $config->uploads;
        self::$INVOICE = $config->invoice;
        self::$DBCACHE = $config->dbcache;
        self::$CDNROOT = $config->cdnroot;
        self::$CDNPATH = $config->cdnpath;
        self::$THEDATE = $current_date;
        self::$LOGFILE = "$config->logfile$current_date.log";
        self::$VERBOSE = $config->VERBOSE;
        self::$INSTALL = $config->install;
        self::$DB_MAIN = $config->DB_MAIN;
        self::$DB_HIST = $config->DB_HISTORY;
        self::$MAIL = $config->MAIL;
        self::$INSTAGRAM = $config->integrations->instagram;
        self::$POSTCODE = $config->integrations->postcode;
        self::$PDFMAKER = $config->integrations->pdfmaker;
        self::$NEWRELIC_RECORDS_BACKEND = $config->newrelic_records_backend ?? false;
        self::$MAX_MEMORY_LIMIT = Help::getMemorySize($config->max_memory_limit ?? '1g');
        self::$FRAME_ANCESTORS = $config->frame_ancestors ?? '\'none\'';
        $config = null;
    }

    public static function loadInstanceSettingsFor(int $instance_id): void
    {
        if (self::$instance_id === $instance_id) return;
        if (($row = Help::getDB()->fetchInstanceById($instance_id))) {
            self::loadInstanceSettings(new Instance($row));
        }
        $row = null;
    }

    public static function loadInstanceSettings(Instance $I): void
    {
        self::$instance_id = $I->getId(); // this is necessary for DB to output the correct pages and products etc.
        self::$DECIMAL_SEPARATOR = (string)$I->getSetting('decimal_separator');
        self::$RADIX = (self::$DECIMAL_SEPARATOR === '.') ? ',' : '.';
        self::$DECIMAL_DIGITS = (int)$I->getSetting('decimal_digits');
        self::$NOT_IN_STOCK_CAN_BE_ORDERED = (bool)$I->getSetting('not_in_stock_can_be_ordered');
        self::$PRESENTATION_INSTANCE = $I->getPresentationInstance();
        self::$INSTANCE_DOMAIN = $I->getDomain();
        // set timezone for the session
        // PAY ATTENTION the strings must be a valid timezone in PHP as well as in Postgresql
        self::$timezone = $I->getSetting('timezone') ?? 'Europe/Amsterdam';
        if (!in_array(self::$timezone, self::AVAILABLE_TIMEZONES)) {
            Help::addError(new Exception(sprintf('Not a timezone `%s`', self::$timezone)));
            Help::addMessage('Config error, unrecognized timezone', 'warn');
            self::$timezone = 'Europe/Amsterdam'; // this is a correct timezone and for now the default
        }
        if (false === self::getMainDatabaseConnection()->exec(sprintf('SET timezone TO \'%s\';', self::$timezone))) {
            Help::addError(new Exception('failed to set timezone'));
        } else {
            date_default_timezone_set(self::$timezone);
        }
        // load translations
        self::loadTranslations(new \MoParser());
        $I = null;
    }

    public static function loadTranslations(\MoParser $mo_parser): void
    {
        self::$translations = $mo_parser->loadTranslationData(self::$PRESENTATION_INSTANCE, 'XX')['XX'];
    }
}

return new Setup();