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
    private float $start_timer;
    // TODO make configurable using config file
    public const MINUTES_ELEMENT_CACHE_IS_CONSIDERED_OLD = 120;
    public const MINUTES_FILTER_CACHE_IS_CONSIDERED_OLD = 5;
    public const MAX_LOOP_SECONDS = 10;

    public function __construct()
    {
        if (isset($_SERVER['argv'])) {
            $force = 'force' === ($_SERVER['argv'][1] ?? null);
        } else {
            $force = isset($_GET['force']);
        }
        if (false === $force) {
            $last_alive = Help::getDB()->getSystemValue('daemon_last_alive');
            $this->log("DAEMON last alive $last_alive");
            if (strtotime($last_alive) > Setup::getNow() - (3 * self::MAX_LOOP_SECONDS)) {
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
            /* report if necessary */
            if (true === isset($this->start_timer)) {
                $total_time = microtime(true) - $this->start_timer;
                if ($total_time > self::MAX_LOOP_SECONDS) {
                    printf("DAEMON completed in %s seconds (%s)\n", number_format($total_time, 2), date('Y-m-d H:i:s'));
                } else {
                    /* sleep if you're really fast */
                    \sleep(1);
                }
                $trans->flush();
                Setup::logErrors();
            }
            /* check if we’re still the one and only */
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
            /* start the actual work */
            $total_count = 0;
            $stove = new Warmup();
            $done = array();
            $this->start_timer = microtime(true);
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
                if ($this->runningLate()) {
                    break;
                }
            }
            echo $total_count;
            echo " done\n";
            echo 'remove duplicates from cache: ';
            echo $db->removeDuplicatesFromCache();
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
                            if ($this->runningLate()) {
                                echo "Stopped for time, filter age being $age seconds";
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
                    $db->setSystemValue('cache_pointer_filter_filename', null); // register we’re done
                }
            }
            echo "done... \n";
            if (null === $filename_for_cache) ob_clean();
            if ($this->runningLate()) continue;
            $trans->start('parent chains');
            // when some serie has its brand_id updated
            $rows = $db->jobIncorrectChainForProduct();
            $total_count = count($rows);
            foreach ($rows as $index => $row) {
                Setup::loadInstanceSettingsFor($row->instance_id);
                if ($serie = $db->fetchElementRow(new Type('serie'), $row->serie_id)) {
                    if (($keys = $db->updateElementsWhere(
                        new type('product'),
                        array('brand_id' => $serie->brand_id),
                        array('serie_id' => $serie->serie_id))
                    )) {
                        $affected = count($keys);
                        echo "Updated $affected products with serie $serie->slug\n";
                    } else {
                        echo "Did not update any products for serie $serie->slug\n";
                    }
                } else {
                    echo "Serie $row->serie_id not found\n";
                }
                if ($this->runningLate()) {
                    $rows = null;
                    continue 2;
                }
            }
            // when some product has its serie_id updated, or its serie had its brand_id updated previously
            $rows = $db->jobIncorrectChainForVariant();
            $total_count += count($rows);
            $product = null;
            foreach ($rows as $index => $row) {
                Setup::loadInstanceSettingsFor($row->instance_id);
                if (null === $product || $product->product_id !== $row->product_id) {
                    if (!($product = $db->fetchElementRow(new Type('product'), $row->product_id))) {
                        echo "Product $row->product_id not found\n";
                        continue;
                    }
                }
                if (($keys = $db->updateElementsWhere(
                    new type('variant'),
                    array('brand_id' => $product->brand_id, 'serie_id' => $product->serie_id),
                    array('product_id' => $product->product_id))
                )) {
                    $affected = count($keys);
                    echo "Updated $affected variants with product $product->slug\n";
                } else {
                    echo "Did not update any variants for product $product->slug\n";
                }
                if ($this->runningLate()) {
                    $rows = null;
                    continue 2;
                }
            }
            if (0 === $total_count) ob_clean();
            /* old cache (least important, most time consuming) */
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
                if ($this->runningLate()) {
                    break;
                }
            }
            echo $total_count;
            echo " done\n";
            echo 'remove duplicates from search index: ';
            echo $db->removeDuplicatesFromCiAi();
            echo PHP_EOL;
            if (0 === $total_count) ob_clean();
        }
    }

    private function log(string $message): void
    {
        error_log("$message\n", 3, Setup::$LOGFILE);
    }

    private function runningLate(): bool
    {
        return microtime(true) - $this->start_timer > self::MAX_LOOP_SECONDS;
    }
}
