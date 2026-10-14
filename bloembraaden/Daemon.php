<?php
declare(strict_types=1);

namespace Bloembraaden;
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
    public const MAX_LOOP_SECONDS = 10;

    public function __construct()
    {
        if (isset($_SERVER['argv'])) {
            $force = 'force' === ($_SERVER['argv'][1] ?? null);
        } else {
            $force = isset($_GET['force']);
        }
        if (false === $force
            && ($last_alive = Help::getDB()->getSystemValue('daemon_last_alive'))
        ) {
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
                    sleep(1);
                }
                $trans->flush();
                Setup::logErrors();
            }
            /* check if we’re still the one and only */
            try {
                if (
                    $this->did !== Help::getDB()->getSystemValue('daemon_did')
                    || Setup::$THEDATE !== date('Y-m-d') // start a new daemon around midnight to use the current logfile
                ) {
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
            $trans->start('Warmup stale (elements) cache');
            foreach ($rows as $key => $row) {
                $stale_slug = (string)$row->slug;
                if (isset($done[$stale_slug])) continue;
                $instance_id = $row->instance_id;
                if (isset($row->in_cache)) { // only warmup rows already in cache
                    $done[$stale_slug] = $instance_id;
                    echo 'Warming up (', $instance_id, ') ', $stale_slug, ': ';
                    if (true === $stove->Warmup($stale_slug, $instance_id)) {
                        echo 'OK';
                        $total_count++;
                    } else {
                        echo 'NO';
                    }
                    echo "\n";
                }
                // delete the slug when we’re done
                $db->deleteFromStale($stale_slug, $instance_id);
                if ($this->runningLate()) {
                    break;
                }
            }
            echo $total_count;
            echo " done\n";
            if (0 === $total_count) ob_clean();
            /* update csp and homepage slug, if necessary */
            $total_count = 0;
            $trans->start('Check instance CSP default-src and homepage slug');
            $rows = $db->fetchInstances();
            foreach ($rows as $index => $row) {
                $instance = new Instance($row);
                //Setup::loadInstanceSettings($instance);
                if ($row->csp_default_src !== ($src = $instance->fetchDefaultSrc())) {
                    $db->updateInstance($row->instance_id, array('csp_default_src' => $src));
                    echo "Update $row->instance_id default-src to $src.\n";
                    $total_count++;
                }
                if ($row->homepage_slug !== ($slug = $db->fetchPageSlug($row->homepage_id))) {
                    $db->updateInstance($row->instance_id, array('homepage_slug' => $slug));
                    echo "Update $row->instance_id slug to $slug.\n";
                    $total_count++;
                }
            }
            if (0 === $total_count) ob_clean();
            /* old cache (least important, most time-consuming) */
            $trans->start('Warmup old (elements) cache');
            $rows = $db->jobOldCacheRows(self::MINUTES_ELEMENT_CACHE_IS_CONSIDERED_OLD, 60 - $total_count);
            $total_count = 0;
            foreach ($rows as $key => $row) {
                echo 'Warming up (', $row->instance_id, ') ', $row->slug, ': ';
                if (true === $stove->Warmup($row->slug, $row->instance_id)) {
                    echo 'OK.';
                    $total_count++;
                } else {
                    echo 'NO.';
                }
                echo "\n";
                if ($this->runningLate()) {
                    break;
                }
            }
            echo $total_count;
            echo " done.\n";
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
