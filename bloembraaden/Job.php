<?php

namespace Peat;
//
if (extension_loaded('newrelic')) {
    newrelic_name_transaction('Job start');
    newrelic_background_job(true);
}
/**
 * File called by cron job to perform maintenance tasks
 * @since 0.5.7
 */
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
    public function flush(bool $with_log = true) {
        $this->message .= ob_get_clean();
        if ($with_log) {
            error_log($this->message, 3, Setup::$LOGFILE);
        }
        echo $this->message;
    }
}
$trans = new jobTransaction();

require __DIR__ . '/../Require.php';
// to test, run it as follows:
// # php-cgi /var/www/peatcms.com/site/bloembraaden/Job.php param=value (e.g. interval=temp)
if (!isset($_GET['interval'])) {
    die('interval needed');
}
$start_timer = microtime(true);
$db = new DB;
$interval = $_GET['interval'];
Define('ADMIN', true); // todo remove this once we have it properly setup, necessary for order class now
ob_start();
echo "\r\n" . date('Y-m-d H:i:s') . " JOB $interval:\r\n";
if ('1' === $interval) { // interval should be '1'
    $trans->start('mail order confirmations');
    // @since 0.5.16: mail order confirmation to client
    // @since 0.9.0: added creation of invoice and sending payment confirmation to client
    $mailgun_custom_domain = '/|\\';
    $mailer = null;
    $rows = $db->jobGetUnconfirmedOrders();
    foreach ($rows as $index => $row) {
        if ($row->mailgun_custom_domain !== $mailgun_custom_domain) {
            $mailgun_custom_domain = $row->mailgun_custom_domain;
            $mailer = new Mailer($mailgun_custom_domain);
            if ($mailer->hasError()) {
                $out = (object)(array(
                    'success' => false,
                    'status_code' => 0,
                    'message' => $mailer->getLastError()->getMessage(),
                ));
            }
        }
        if (isset($row->order_number)) {
            $instance_id = $row->instance_id;
            if (Setup::$instance_id !== $instance_id) {
                Setup::loadInstanceSettings(new Instance($db->fetchInstanceById($instance_id)));
            }
            // determine what to do with the order
            $order_number = $row->order_number;
            // make sure the dates are in the current timezone, and also use a generic format
            $row->date_created = date('Y-m-d H:i:s', strtotime($row->date_created));
            $row->date_updated = date('Y-m-d H:i:s', strtotime($row->date_updated));
            $order = new Order($row);
            $order_output_object = $order->getOutputFull();
            $order_number_human = $order_output_object->order_number_human;
            if (null === $row->html || '' === trim($row->html)) {
                $temp = new Template(null);
                try {
                    $temp->loadDefaultFor('order'); // throws error if not able to load
                    $html = $temp->renderObject($order_output_object);
                    if (true === $order->updateHTML($html)) {
                        $row->html = $html;
                    } else {
                        Help::addError(new \Exception(sprintf(
                            __('Could not save order html for ‘%1$s’ (%2$s)', 'peatcms'),
                            $order_number,
                            $row->domain
                        )));
                        continue;
                    }
                } catch (\Exception $e) {
                    Help::addError($e);
                    continue;
                }
            }
            // 1) mail order confirmation
            if (false === $row->emailed_order_confirmation) {
                $mailer->clear();
                // https://www.trompetters.nl/__action__/pay/order_number:202184610579
                $order_output_object->payment_link = 'https://' . $row->domain . '/__action__/pay/order_number:' . $row->order_number;
                if (false === $row->confirmation_before_payment) {
                    $out = (object)(array(
                        'success' => false,
                        'status_code' => 0,
                        'message' => '‘send order confirmation before payment’ switched off in settings',
                    ));
                } elseif (!$mailer->hasError()) {
                    $html = $row->html; // the original order html
                    // make a confirmation mail for the client
                    if (null !== ($template_id = $row->template_id_order_confirmation)) {
                        try {
                            $temp = new Template($db->getTemplateRow($template_id, $instance_id));
                            $html = $temp->renderObject($order_output_object);
                        } catch (\Exception $e) {
                            Help::addError($e);
                        }
                    }
                    $mailer->set(array(
                        'to' => $row->user_email,
                        'to_name' => $row->shipping_address_name,
                        'from' => $row->mail_verified_sender,
                        'subject' => sprintf(
                        //#TRANSLATORS this is the order confirmation email subject line, %s is the order number
                            __('Order %s', 'peatcms'),
                            $order_number_human
                        ),
                        'text' => Help::html_to_text($html),
                        'html' => $html,
                    ));
                    $out = $mailer->send();
                }
                var_dump($out);
                $db->updateColumns('_order', array(
                    'emailed_order_confirmation' => true,
                    'emailed_order_confirmation_success' => $out->success,
                    'emailed_order_confirmation_response' => json_encode($out),
                ), $row->order_id);
                continue;
            }
            // 3) mail payment confirmation // Note: (false === $row->emailed_payment_confirmation) is understood
            if (true === $row->payment_confirmed_bool) {
                // 2) create invoice
                $filename = Help::getInvoiceFileName($order_number, $instance_id);
                $invoice_title = sprintf(
                //#TRANSLATORS this is the invoice filename, %s is the order number
                    __('Invoice for order %s', 'peatcms'),
                    $order_number_human
                );
                $payment_confirmation_short_text = sprintf(
                //#TRANSLATORS this is the payment confirmation email subject line, %s is the order number
                    __('Payment confirmation for %s', 'peatcms'),
                    $order_number_human
                );
                if (true === $row->create_invoice && false === file_exists($filename)) {
                    // create the invoice id if there isn’t one yet
                    if (null === ($payment_sequential_number = $row->payment_sequential_number)) {
                        $payment_sequential_number = $db->generatePaymentSequentialNumber($row->instance_id);
                        $db->updateColumns('_order', array(
                            'payment_sequential_number' => $payment_sequential_number,
                        ), $row->order_id);
                        $row->payment_sequential_number = $payment_sequential_number;
                    }
                    $order_output_object->invoice_id = $payment_sequential_number;
                    $order_output_object->title = $invoice_title;
                    $order_output_object->date_invoiced = date('d-m-Y'); // todo make it internationalfähig
                    // first ensure we have a nice template, and it is correctly filled in
                    $temp = new Template(null);
                    try {
                        $temp->loadDefaultFor('invoice'); // throws error if not able to load
                        $html = $temp->renderObject($order_output_object);
                    } catch (\Exception $e) {
                        Help::addError($e);
                        continue;
                    }
                    // call the browserless people to make a pdf from the html
                    $curl = curl_init();
                    if (!$curl) {
                        continue; // peatcms can’t mail without curl anyway so might as well just ignore this
                    }
                    //curl_setopt($curl, CURLOPT_USERAGENT, 'Bloembraaden/VERSION');
                    curl_setopt($curl, CURLOPT_URL, Setup::$PDFMAKER->api_url . '?token=' . Setup::$PDFMAKER->api_key);
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3); // it would hang for 2 minutes even though the answer was already there?
                    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                        'Cache-Control: no-cache',
                        'Content-Type: application/json; charset=utf-8'
                    ));
                    $params = (object)array(
                        'html' => $html,
                        'options' => (object)array(
                            'displayHeaderFooter' => true,
                            'printBackground' => true,
                            'format' => 'A4',
                        ),
                    );
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
                    $result = trim(curl_exec($curl));
                    $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
                    curl_close($curl);
                    if (200 !== $status_code) {
                        Help::addError(new \Exception(sprintf('Pdfmaker error: ‘%s’', substr($result, 0, 300))));
                        break; // it’s not working apparently
                    }
                    if ('%PDF' !== substr($result, 0, 4)) {
                        Help::addError(new \Exception(sprintf('Pdfmaker error: ‘%s’', substr($result, 0, 300))));
                        break; // it’s not working apparently
                    }
                    // save the pdf
                    file_put_contents($filename, $result, LOCK_EX);
                    // you have to be sure there is an invoice, if there is not, continue, because it will not be able to send the invoice
                }
                // the invoice is created (above), so you can now e-mail it (when requested)
                if (false === $row->confirmation_of_payment) {
                    $out = (object)(array(
                        'success' => false,
                        'status_code' => 0,
                        'message' => '‘send payment confirmation’ switched off in settings',
                    ));
                } elseif (false === $mailer->hasError()) {
                    $mailer->clear();
                    if (true === $row->send_invoice_as_pdf && false === $row->create_invoice) {
                        // this combination makes no sense
                        $row->send_invoice_as_pdf = false;
                    }
                    $order_output_object->invoice_id = $row->payment_sequential_number;
                    // make a confirmation mail for the client
                    $html = $payment_confirmation_short_text;
                    if (null !== ($template_id = $row->template_id_payment_confirmation)) {
                        try {
                            $temp = new Template($db->getTemplateRow($template_id, $instance_id));
                            $html = $temp->renderObject($order_output_object);
                        } catch (\Exception $e) {
                            Help::addError($e);
                        }
                    }
                    $mailer->set(array(
                        'to' => $row->user_email,
                        'to_name' => $row->billing_address_name,
                        'from' => $row->mail_verified_sender,
                        'subject' => $payment_confirmation_short_text,
                        'text' => Help::html_to_text($html),
                        'html' => $html,
                    ));
                    if ($row->send_invoice_as_pdf) {
                        $mailer->attach(
                            $invoice_title . '.pdf',
                            'application/pdf',
                            file_get_contents($filename)
                        );
                    }
                    $out = $mailer->send();
                    // send the internal mail to the internal addresses...
                    if ('' !== trim($row->confirmation_copy_to)) {
                        // create internal mail
                        if (null !== ($template_id = $row->template_id_internal_confirmation)) {
                            try {
                                $temp = new Template($db->getTemplateRow($template_id, $instance_id));
                                $html = $temp->renderObject($order_output_object);
                                $mailer->set(array(
                                    'text' => Help::html_to_text($html),
                                    'html' => $html
                                ));
                            } catch (\Exception $e) {
                                Help::addError($e);
                            }
                        }
                        // send to all addresses
                        foreach (explode(',', $row->confirmation_copy_to) as $email_address) {
                            $email_address = trim($email_address);
                            if (false === filter_var($email_address, FILTER_VALIDATE_EMAIL)) continue;
                            $mailer->set(array(
                                'to' => $email_address
                            ));
                            if ($mailer->send()) {
                                echo $email_address . ' ';
                            }
                        }
                    }
                    var_dump($out);
                }
                $db->updateColumns('_order', array(
                    'emailed_payment_confirmation' => true,
                    'emailed_payment_confirmation_success' => $out->success,
                    'emailed_payment_confirmation_response' => json_encode($out),
                ), $row->order_id);
            }
        }
    }
    unset($mailer);
    // @since 0.7.0 update ci_ai column, the column each element has for search purposes
    $trans->start('update ci_ai');
    echo $db->jobSearchUpdateIndexColumn() . PHP_EOL;
    $trans->start('empty expired lockers');
    echo $db->jobEmptyExpiredLockers() . PHP_EOL;
    // handle cache, NOTE you have to do this late / last, because some pages may depend on the ci_ai (that should not be NULL)
    $trans->start('warmup (elements) cache');
    echo 'clear stale cache: ';
    $warmed = $db->jobWarmupStaleCache(60);
    echo $warmed;
    echo ' found' . PHP_EOL;
    echo 'remove duplicates from cache: ';
    echo $db->removeDuplicatesFromCache();
    echo PHP_EOL;
    if (40 > $warmed) {
        echo 'warmup old cache: ';
        echo $db->jobWarmupOldCache(120, 60 - $warmed);
        echo ' found' . PHP_EOL;
    }
    // Refresh Instagram media
    $trans->start('refresh instagram data');
    // @since 0.7.8 find deauthorized instagram accounts to trigger the feed updates, set them to deleted afterwards
    if (($rows = $db->fetchInstagramDeauthorized())) {
        foreach ($rows as $index => $row) {
            $db->invalidateInstagramFeedSpecsByUserId($row->user_id);
            $db->deleteInstagramMediaByUserId($row->user_id);
            $db->updateColumns('_instagram_auth', array('deleted' => true), $row->instagram_auth_id);
        }
    }
    // get the 25 newest media entries or next 25 when still loading for each user and update the associated entries
    //https://graph.instagram.com/{user_id}?fields=media&access_token={token}
    $curl = curl_init(); // start new curl request
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3); // it would hang for 2 minutes even though the answer was already there?
    $instagram_user_ids = array();
    if (($rows = $db->getInstagramUserTokenAndNext())) {
        echo 'register instagram user ids for new media entries' . PHP_EOL;
        foreach ($rows as $index => $row) {
            // remember the tokens to get media info later
            $instagram_user_ids[(string)$row->user_id] = $row->access_token;
            if (false === $row->done && isset($row->next)) {
                curl_setopt($curl, CURLOPT_URL, $row->next);
            } else { // build it yourself
                curl_setopt($curl, CURLOPT_URL,
                    'https://graph.instagram.com/' . $row->user_id .
                    '?fields=media&access_token=' . urlencode($row->access_token)
                );
            }
            $result = curl_exec($curl);
            //$status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
            $media = json_decode($result);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($media->media)) $media = $media->media; // apparently this goes in and out at instagram?!
                if (isset($media->data) and is_array($media->data)) {
                    $ids = $media->data; // check all the media id’s against the database
                    foreach ($ids as $index2 => $obj) {
                        if (isset($obj->id) and $media_id = $obj->id) {
                            echo $media_id;
                            $check = $db->getInstagramMediaByMediaId($media_id, array('user_id'));
                            if (null === $check) {
                                echo ($db->insertRowAndReturnKey('_instagram_media', array(
                                    'media_id' => $media_id,
                                    'user_id' => $row->user_id,
                                    'flag_for_update' => true,
                                ))) ? ': NEW' : ': insert FAILED';
                            } elseif (null === $check->user_id) {
                                echo ($db->updateColumns('_instagram_media', array(
                                    'user_id' => $row->user_id,
                                ), $media_id)) ? ': updated' : ': FAILED';
                            } else {
                                echo ': still good';
                            }
                            echo PHP_EOL;
                        }
                    }
                }
                if (false === $row->done) {
                    // paging -> next is where you will have to resume later, if it’s not there,
                    // remove the next from auth as well, so the next run will start with the newest items
                    if (isset($media->paging) and isset($media->paging->next)) {
                        $db->updateColumns('_instagram_auth', array(
                            'next' => $media->paging->next,
                        ), $row->instagram_auth_id);
                    } else {
                        $db->updateColumns('_instagram_auth', array(
                            'next' => null,
                            'done' => true // register that you have walked through all the next-s
                        ), $row->instagram_auth_id);
                    }
                }
            } else {
                Help::addError(new \JsonException(json_last_error()));
            }
        }
    }
    // now get all media entries that have no content (ie where username = null), to update
    $rows = $db->jobGetInstagramMediaIdsForRefresh(count($instagram_user_ids) * 4); // max 4 every minute per user registered
    echo 'refresh instagram media entries' . PHP_EOL;
    if (0 === count($rows)) { // when there are no new rows, select some old ones just to check if they’re still valid
        // TODO temporarily disabled because of IG rate limiting
        $rows = $db->jobGetInstagramMediaIdsForRefreshByDate(count($instagram_user_ids) * 4);
        echo '(no new ones found, so checking up on some old ones)' . PHP_EOL;
    }
    $updated_user_ids = array();
    // https://graph.instagram.com/17896358038720106?fields=caption,media_type,media_url,permalink,thumbnail_url,timestamp,username&access_token={}
    foreach ($rows as $index => $row) {
        if (false === isset($instagram_user_ids[$row->user_id])) {
            // this row is weird, can be when a user is deauthorized but has just uploaded media
            echo 'No access token for this user';
            if ($db->updateColumns('_instagram_media', array('deleted' => true), $row->media_id)) {
                echo ', removed media entry: ';
                echo $row->media_id;
            }
            echo PHP_EOL;
            continue;
        }
        echo $row->media_id;
        echo ': ';
        curl_setopt($curl, CURLOPT_URL,
            'https://graph.instagram.com/' . $row->media_id .
            '?fields=caption,media_type,media_url,permalink,thumbnail_url,timestamp,username&access_token=' .
            urlencode($instagram_user_ids[$row->user_id])
        );
        $result = curl_exec($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
        if (200 === $status_code) {
            $media = json_decode($result);
            if (json_last_error() === JSON_ERROR_NONE) {
                // update the entry
                if ($db->updateColumns('_instagram_media', array(
                    'caption' => $media->caption ?? '', // (old) instagram posts may have no caption
                    'media_type' => $media->media_type,
                    'permalink' => $media->permalink,
                    'instagram_username' => $media->username,
                    'instagram_timestamp' => $media->timestamp,
                    'media_url' => ($media->thumbnail_url ?? $media->media_url),
                    'flag_for_update' => false,
                ), $row->media_id)) {
                    // remember you updated this user_id, so their feeds can be refreshed
                    $updated_user_ids[(string)$row->user_id] = true;
                    echo 'OK';
                } else {
                    echo 'failed';
                }
            }
        } elseif ($status_code === 400 || $status_code === 403 || $status_code === 404) { // remove this
            // update the entry
            if ($db->updateColumns('_instagram_media', array(
                'deleted' => true,
            ), $row->media_id)) {
                // remember you updated this user_id, so their feeds can be refreshed
                $updated_user_ids[(string)$row->user_id] = true;
                echo 'DELETED';
            } else {
                echo 'DB update failed';
            }
        } else {
            Help::addError(new \Exception('Instagram media update failed with status ' . $status_code));
            echo 'Nothing done, got status ' . $status_code;
        }
        echo PHP_EOL;
    }
    // @since 0.7.4 get all images (media...) that are not yet cached and put them on your own server
    $trans->start('caching instagram media');
    echo 'Caching media_urls for instagram... ' . PHP_EOL;
    $rows = $db->jobGetInstagramMediaUrls(true, 15);
    $logger = new StdOutLogger();
    foreach ($rows as $index => $row) {
        $media_url = $row->media_url;
        $save_path = Setup::$UPLOADS;
        echo $media_url . PHP_EOL;
        curl_setopt($curl, CURLOPT_URL, $media_url);
        $result = curl_exec($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
        if ($status_code === 200) {
            $src = sha1($media_url) . '.jpg';
            //echo $src;
            // save the image and update src
            if (file_put_contents($save_path . $src, $result)) {
                $img = new InstagramImage($row);
                if (true === $img->process($logger)) {
                    //if (true === $db->updateColumns('_instagram_media', array('src' => $src), $row->media_id)) {
                    // remember you updated this user_id, so their feeds can be refreshed
                    $updated_user_ids[(string)$row->user_id] = true;
                    $logger->out();
                    //echo ' DB updated';
                }
            }
        } else { // mark it for update
            echo 'UNAVAILABLE';
            $db->updateColumns('_instagram_media', array('flag_for_update' => true), $row->media_id);
        }
        $result = null;
        echo PHP_EOL;
    }
    curl_close($curl);
    // after updating the media entries, you can update the feeds for users you updated (some) media for
    $trans->start('update instagram feeds');
    // fill them with an appropriate number of entries
    echo 'Updating feeds triggered by media updates... ' . PHP_EOL;
    $updated_feeds = array(); // each feed has to be updated only once here
    foreach ($updated_user_ids as $user_id => $ok) {
        echo 'user ' . $user_id . PHP_EOL;
        $feeds = $db->getInstagramFeedSpecsByUserId($user_id);
        // TODO this code is duplicated below...
        foreach ($feeds as $index => $specs) {
            echo($feed_name = $specs->feed_name);
            if (isset($updated_feeds[$feed_name])) {
                echo ' already done' . PHP_EOL;
                continue;
            }
            $feed = $db->fetchInstagramMediaForFeed($specs->instance_id, $specs->instagram_username, $specs->instagram_hashtag, $specs->quantity, Setup::$CDNROOT);
            echo ($db->updateColumns('_instagram_feed', array(
                'feed' => json_encode($feed),
                'feed_updated' => 'NOW()'
            ), $specs->instagram_feed_id)) ? ': OK' : ': failed';
            $updated_feeds[$feed_name] = true;
            echo PHP_EOL;
        }
    }
    // there may be (changed) feeds that do not yet have an actual feed or need updating anyway
    echo 'Updating feeds that are outdated... ' . PHP_EOL;
    $feeds = $db->getInstagramFeedSpecsOutdated();
    // TODO duplicate code ahead, same as directly above
    foreach ($feeds as $index => $specs) {
        echo($feed_name = $specs->feed_name);
        if (isset($updated_feeds[$feed_name])) {
            echo ' already done' . PHP_EOL;
            continue;
        }
        $feed = $db->fetchInstagramMediaForFeed($specs->instance_id, $specs->instagram_username, $specs->instagram_hashtag, $specs->quantity, Setup::$CDNROOT);
        echo ($db->updateColumns('_instagram_feed', array(
            'feed' => json_encode($feed),
            'feed_updated' => 'NOW()'
        ), $specs->instagram_feed_id)) ? ': OK' : ': failed';
        $updated_feeds[$feed_name] = true;
        echo PHP_EOL;
    }
} elseif ('5' === $interval) { // interval should be 5
    $trans->start('purge deleted');
    echo $db->jobPurgeDeleted($interval) . PHP_EOL;
//} elseif ('process' === $interval) { // comment this line to use automated image processing each 5 minutes
    // process some images that need processing (date_processed = null)
    $upload = Setup::$UPLOADS;
    $logger = new StdOutLogger();
    // instagram images
    $trans->start('process instagram images');
    foreach ($db->jobFetchInstagramImagesForProcessing() as $index => $row) {
        $img = new InstagramImage($row);
        echo $img->getSlug();
        if (true === $img->process($logger)) {
            echo ' SUCCES' . PHP_EOL;
        } else {
            // the src will be set to null by ->process, and so it will be picked up by ->jobGetInstagramMediaUrls
            echo ' FAILED' . PHP_EOL;
        }
        $logger->out();
    }
    echo PHP_EOL;
    // regular images
    $trans->start('process images that need processing');
    foreach ($db->jobFetchImagesForProcessing() as $index => $row) {
        $img = new Image($row);
        Setup::$instance_id = $row->instance_id;
        echo $img->getSlug();
        if (true === $img->process($logger)) {
            echo ' SUCCES' . PHP_EOL;
        } else {
            echo ' FAILED' . PHP_EOL;
        }
        $logger->out();
    }
    echo PHP_EOL;
    // remove some originals that are old (date_processed = long ago)
    $trans->start('remove old files from upload directory');
    foreach ($db->jobFetchImagesForCleanup() as $index => $row) {
        Setup::$instance_id = $row->instance_id;
        echo $row->slug;
        if (file_exists($upload . $row->filename_saved)) {
            if (false === unlink($upload . $row->filename_saved)) {
                echo ' could not be removed' . PHP_EOL;
                continue;
            }
        } else {
            echo ' did not exist' . PHP_EOL;
        }
        if (false === $db->updateColumns('cms_image', array('filename_saved' => null), $row->image_id)) {
            echo ' ERROR UPDATING DB' . PHP_EOL;
            continue;
        }
        echo ' ok' . PHP_EOL;
    }
} elseif ('hourly' === $interval) { // interval should be hourly
    // check all the js and css in the cache, delete old ones
    $trans->start('handle js and css cache');
    foreach (array('js', 'css') as $sub_directory) {
        $dir = new \DirectoryIterator(Setup::$DBCACHE . $sub_directory);
        foreach ($dir as $index => $file_info) {
            if (!$file_info->isDot() && 'gz' === $file_info->getExtension()) {
                // these are compressed js and css files with a timestamp, delete all files
                // with an older timestamp than the latest one for the instance, or of an older version
                $pieces = explode('-', $file_info->getFilename());
                $instance_id = $pieces[0];
                $version = $pieces[1];
                if (version_compare(Setup::$VERSION, $version) !== 0) {
                    unlink($file_info->getPathname());
                    echo 'Deleted ' . $file_info->getFilename() . PHP_EOL;
                    continue;
                }
                $timestamp = explode('.', end($pieces))[0];
                if (($row = $db->fetchInstanceById($instance_id))) {
                    if (strtotime($row->date_published) > $timestamp) {
                        unlink($file_info->getPathname());
                        echo 'Deleted ' . $file_info->getFilename() . PHP_EOL;
                    }
                }
            }
        }
    }
    // refresh the json files for the filters as well
    $trans->start('handle properties filters cache');
    $dir = new \DirectoryIterator(Setup::$DBCACHE . 'filter');
    foreach ($dir as $index => $file_info) {
        if ($file_info->isDir()) {
            if (0 === ($instance_id = (int)$file_info->getFilename())) continue;
            echo 'Filters for instance ' . $instance_id . PHP_EOL;
            $filter_dir = new \DirectoryIterator($file_info->getPath() . '/' . $instance_id);
            foreach ($filter_dir as $index2 => $filter_file_info) {
                if ('serialized' === $filter_file_info->getExtension()) {
                    $filename = $filter_file_info->getFilename();
                    // -11 to remove .serialized extension
                    $path = urldecode(substr($filename, 0, -11));
                    $src = new Search();
                    $src->getRelevantPropertyValuesAndPrices($path, $instance_id, true);
                    echo 'Refreshed ' . $path . PHP_EOL;
                }
            }
        }
    }
} elseif ('daily' === $interval) {
    $trans->start('clean template folder');
    echo $db->jobCleanTemplateFolder() . PHP_EOL;
    // @since 0.8.9
    $trans->start('remove old sessions and shoppinglists');
    echo $db->jobDeleteOldSessions() . ' and ';
    echo $db->jobDeleteOrphanedLists() . PHP_EOL;
    // @since 0.8.x
    //echo "clean client scripts cache folder: ";
    //echo $db->jobCleanClientScriptsFolder() . PHP_EOL;
    // @since 0.7.9 remove orphaned list variants
    $trans->start('remove orphaned shoppinglist rows (variants)');
    echo $db->jobDeleteOrphanedShoppinglistVariants() . PHP_EOL;
    // @since 0.8.9
    $trans->start('remove orphaned session variables');
    echo $db->jobDeleteOrphanedSessionVars() . PHP_EOL;
    // refresh token should be called daily for all long-lived instagram tokens, refresh like 5 days before expiration or something
    $trans->start('refresh instagram access token');
    // @since 0.7.2
    $rows = $db->jobGetInstagramTokensForRefresh(5);
    // TODO move this to instagram class or somewhere more appropriate
    if (count($rows) > 0) {
        $default_expires = Setup::$INSTAGRAM->default_expires;
        // https://developers.facebook.com/docs/instagram-basic-display-api/guides/long-lived-access-tokens
        $curl = curl_init(); // start new curl request
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3); // it would hang for 2 minutes even though the answer was already there?
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        foreach ($rows as $index => $row) {
            curl_setopt($curl, CURLOPT_URL,
                'https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=' .
                urlencode($row->access_token)
            );
            $result = curl_exec($curl);
            $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
            $return_value = json_decode($result);
            if (json_last_error() !== JSON_ERROR_NONE || false === isset($return_value->access_token)) {
                echo sprintf(
                        'Instagram refresh token error, status %1$s, body %2$s',
                        $status_code, var_export($return_value, true)
                    ) . PHP_EOL;
            } else {
                // and update it in the db
                $expires = isset($return_value->expires_in) ?
                    Help::getAsInteger($return_value->expires_in, $default_expires) : $default_expires;
                if ($db->updateColumns('_instagram_auth', array(
                    'access_token' => $return_value->access_token,
                    'access_token_expires' => date("Y-m-d G:i:s.u O", time() + $expires),
                    'access_granted' => true,
                ), $row->instagram_auth_id)) {
                    echo 'OK' . PHP_EOL;
                } else {
                    echo 'Unable to update settings for Instagram authorization' . PHP_EOL;
                }
            }
        }
        $curl = null;
    } else {
        echo 'no tokens to refresh' . PHP_EOL;
    }
    $rows = null;
    // TODO refresh media and remove deleted entries
    // fetch all the media_url statusses (not counting towards absurd rate limitation)
    // if not 200 then mark / flag the media for update (boolean flag_for_update)
} elseif ('temp' === $interval) {
    echo 'Notice: this is a temp job, only for testing' . PHP_EOL;
    // @since 0.8.12 get the images you have, to check if they are still valid according to Instagram
    stream_context_set_default(
        array(
            'http' => array(
                'method' => 'HEAD'
            )
        )
    );
    $rows = $db->jobGetInstagramMediaUrls(false, 50);
    foreach ($rows as $index => $row) {
        $headers = get_headers($row->media_url);
        if (is_array($headers) and isset($headers[0])) {
            if (false === strpos($headers[0], ' 200 OK')) {
                $db->updateColumns('_instagram_media', array('flag_for_update' => true), $row->media_id);
                echo 'flagged for update: ' . $row->media_id . PHP_EOL;
            }
        }
        //var_dump($headers);
    }
    //
    // todo check the pretty parent chains + update them
    // TODO delete original uploads that are no longer in the database
    // load all files in an array, load all database entries in an array
    // loop (?) through and remove the ones only on disk
    // when there are left in the db that are not on disk, you need to log that there’s an error
    // remove smaller photos as well that are no longer in the db (maybe after renaming in the next step)
    // use ‘system’ to keep tabs on where you are so you don’t have to load everything everytime
    // TODO delete and rename images according to entries in the database
    // grab slug, src_small, src_medium and src_height
    // for each size, copy the image, upon success update the entry in the database
    // it has to be uncached as well, or else the image is gone when it’s deleted in the next run...
}
$trans->start('report current job');

echo date("Y-m-d H:i:s") . ' (ended)' . PHP_EOL;
printf("«completed in %s seconds»\r\n", microtime(true) - $start_timer);
$trans->flush();
//
unset($db);
