<?php
declare(strict_types=1);

namespace Peat;
require __DIR__ . '/Require.php';
set_time_limit(0);
//
new Daemon();
// NOTE you can force a new daemon from cli by adding force as parameter (current daemon will self-destruct)
// php path/to/bloembraaden/Daemon.php force
//
class Daemon
{
    private string $did;
    // TODO make configurable using config file
    public const MINUTES_ELEMENT_CACHE_IS_CONSIDERED_OLD = 120;
    public const MINUTES_FILTER_CACHE_IS_CONSIDERED_OLD = 5;
    public const MAX_LOOP_SECONDS = 15;

    public function __construct()
    {
        if (false === isset($_GET['force'])) {
            $last_alive = Help::getDB()->getSystemValue('daemon_last_alive');
            $this->log("DAEMON last alive $last_alive");
            if (strtotime($last_alive) > Setup::getNow() - 40) {
                die("DAEMON still running\n");
            }
        }
        $this->log('Starting DAEMON');
        $this->did = Help::randomString(10);
        Help::getDB()->setSystemValue('daemon_did', $this->did);
        $this->doTheHustle(); //start infinite loop
    }

    public function doTheHustle(): void
    {
        $trans = new jobTransaction();
        $db = Help::getDB();
        while (true) {
            // check if we’re still the one and only
            try {
                if ($this->did !== Help::getDB()->getSystemValue('daemon_did')) {
                    $this->log("DAEMON $this->did stopped itself");
                    die();
                }
            } catch (\Exception $e) { // maybe there is no database connection anymore
                Help::addError($e);
                $this->log("DAEMON $this->did stopped itself");
                die();
            }
            Help::getDB()->setSystemValue('daemon_last_alive', 'NOW()');
            // start the actual work
            $total_count = 0;
            $stove = new Warmup();
            $done = array();
            $start_timer = microtime(true);
            $rows = $db->jobStaleCacheRows(200);
            $trans->start('warmup stale (elements) cache');
            foreach ($rows as $key => $row) {
                $stale_slug = (string)$row->slug;
                if (isset($done[$stale_slug])) continue;
                // todo make switching instance id a method so you can handle it better
                $instance_id = $row->instance_id;
                if (isset($row->in_cache)) { // only warmup rows already in cache
                    $done[$stale_slug] = $instance_id;
                    $stove->Warmup($stale_slug, $instance_id);
                    $total_count++;
                }
                // delete the slug when we’re done
                $db->deleteFromStale($stale_slug, $instance_id);
            }
            echo $total_count;
            echo " done\n";
            echo 'remove duplicates from cache: ';
            echo $db->removeDuplicatesFromCache();
            echo PHP_EOL;
            if (0 === $total_count) ob_clean();
            $trans->start('warmup old (elements) cache');
            $rows = $db->jobOldCacheRows(self::MINUTES_ELEMENT_CACHE_IS_CONSIDERED_OLD, 60 - $total_count);
            $total_count = 0;
            foreach ($rows as $key => $row) {
                echo 'Warming up ';
                echo $row->slug;
                echo ': ';
                echo ($stove->Warmup($row->slug, $row->instance_id)) ? 'OK' : 'NO';
                echo PHP_EOL;
                $total_count += 1;
            }
            echo $total_count;
            echo " done\n";
            echo 'remove duplicates from search index: ';
            echo $db->removeDuplicatesFromCiAi();
            echo PHP_EOL;
            if (0 === $total_count) ob_clean();
            // refresh the json files for the filters as well TODO maybe move to a more appropriate place (job)
            $trans->start('handle properties filters cache');
            $dir = new \DirectoryIterator(Setup::$DBCACHE . 'filter');
            // get the cache pointer to resume where we left off, if present
            $cache_pointer_filter_filename = $db->getSystemValue('cache_pointer_filter_filename');
            $filename_for_cache = null;
            foreach ($dir as $index => $file_info) {
                if ($file_info->isDir()) {
                    if (0 === ($instance_id = (int)$file_info->getFilename())) continue;
                    echo "Filters for instance $instance_id\n";
                    $filter_dir = new \DirectoryIterator($file_info->getPath() . '/' . $instance_id);
                    foreach ($filter_dir as $index2 => $filter_file_info) {
                        if ('serialized' === $filter_file_info->getExtension()) {
                            $age = strtotime(date('Y-m-d H:i:s')) - $filter_file_info->getMTime();
                            if ($age < 60 * self::MINUTES_FILTER_CACHE_IS_CONSIDERED_OLD) continue;
                            $filename = $filter_file_info->getFilename();
                            $filename_for_cache = "$instance_id/$filename";
                            if (microtime(true) - $start_timer > self::MAX_LOOP_SECONDS) {
                                echo "Stopped for time, filter age being $age seconds";
                                echo PHP_EOL;
                                // remember we left off here, to resume next run
                                $db->setSystemValue('cache_pointer_filter_filename', $filename_for_cache);
                                break 2;
                            }
                            if ($filename_for_cache === $cache_pointer_filter_filename) $cache_pointer_filter_filename = null;
                            if (null !== $cache_pointer_filter_filename) continue;
                            // -11 to remove .serialized extension
                            $path = urldecode(substr($filename, 0, -11));
                            $src = new Search();
                            $src->getRelevantPropertyValuesAndPrices($path, $instance_id, true);
                            echo "Refreshed $path\n";
                        }
                    }
                }
            }
            if (null !== $cache_pointer_filter_filename && null === $filename_for_cache) {
                $db->setSystemValue('cache_pointer_filter_filename', null); // register we’re done
            }
            echo "done... \n";
            if (null === $filename_for_cache) ob_clean();
            /* */
            $total_time = microtime(true) - $start_timer;
            printf("DAEMON completed in %s seconds (%s)\n", number_format($total_time, 2), date('Y-m-d H:i:s'));
            $trans->flush();
            Setup::logErrors();
            /* */
            if ($total_time < self::MAX_LOOP_SECONDS) \sleep(1);
        }
    }

    private function log(string $message): void
    {
        error_log("$message\n", 3, Setup::$LOGFILE);
    }
}