<?php
declare(strict_types=1);

namespace Bloembraaden;
/**
 * File called by cron job to perform maintenance tasks
 * @since 0.5.7
 */
require __DIR__ . '/Require.php';
$trans = new jobTransaction();
// to test from cli / as a cron job, run it as follows:
// # /path/to/php /path/to/bloembraaden/Job.php interval_value (eg: 5)
$interval = $_GET['interval'] ?? $argv[1];
if (!$interval) {
    die('interval needed');
}
// backwards compatibility:
if (str_starts_with($interval, 'interval=')) {
    $interval = str_replace('interval=', '', $interval);
}
// the work starts here
$start_timer = microtime(true);
$db = new DB();
define('ADMIN', true); // todo remove this once we have it properly setup, necessary for order class now
ob_start();
echo "\n", date('Y-m-d H:i:s'), " JOB $interval:\n";
switch ($interval) {
    case '1': // interval should be '1'
        $trans->start('Mail order confirmations');
        // @since 0.5.16: mail order confirmation to client
        // @since 0.9.0: added creation of invoice and sending payment confirmation to client
        $mailgun_custom_domain = '___';
        $mailer = null;
        /**
         * @param Mailer $mailer
         * @param \stdClass $row
         * @param int $instance_id
         * @param \stdClass $order_output_object
         * @return void
         */
        $cc_confirmation_copy_to = static function (
            Mailer    $mailer,
            \stdClass $row,
            int       $instance_id,
            \stdClass $order_output_object
        ) use ($db) {
            // send the internal mail to the internal addresses...
            if (true === $mailer->hasError()) return;
            if (null === $mailer->get('to')) return; // apparently, no mail was sent :-P
            echo '=== cc confirmation copy to ===', PHP_EOL;
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
                } else {
                    $text = var_export($order_output_object, true);
                    $mailer->set(array(
                        'text' => $text,
                        'html' => $text
                    ));
                }
                // make order easy to answer to
                $mailer->set(array(
                    'reply_to' => $row->user_email
                ));
                // send to all addresses
                foreach (explode(',', $row->confirmation_copy_to) as $email_address) {
                    $email_address = trim($email_address);
                    if (false === filter_var($email_address, FILTER_VALIDATE_EMAIL)) continue;
                    $mailer->set(array(
                        'to' => $email_address
                    ));
                    var_dump($mailer->send());
                    if ($mailer->hasError()) {
                        Help::addError($mailer->getLastError());
                        break;
                    }
                }
            }
        };
        $rows = $db->jobGetUnconfirmedOrders();
        foreach ($rows as $index => $row) {
            if ($row->mailgun_custom_domain !== $mailgun_custom_domain) {
                $mailgun_custom_domain = $row->mailgun_custom_domain;
                $mailer = new Mailer($mailgun_custom_domain);
                if ($mailer->hasError()) {
//                $out = (object)(array(
//                    'success' => false,
//                    'status_code' => 0,
//                    'message' => $mailer->getLastError()->getMessage(),
//                ));
                    Help::addError($mailer->getLastError());
                }
            }
            if (isset($row->order_number)) {
                $order_number = $row->order_number;
                if (false === Help::obtainLock("mailjob.order.$order_number")) continue;
                $instance_id = $row->instance_id;
                if (Setup::$instance_id !== $instance_id) {
                    Setup::loadInstanceSettings(new Instance($db->fetchInstanceById($instance_id)));
                }
                // determine what to do with the order
                echo Setup::$INSTANCE_DOMAIN, "\tORDER: $order_number\n";
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
                                __('Could not save order html for %1$s (%2$s)', 'peatcms'),
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
                    echo 'Order confirmation', PHP_EOL;
                    $mailer->clear();
                    // https://www.trompetters.nl/__action__/pay/order_number:202184610579
                    $order_output_object->payment_link = "https://$row->domain/__action__/pay/order_number:$row->order_number";
                    if (false === $row->confirmation_before_payment) {
                        $out = (object)(array(
                            'success' => false,
                            'status_code' => 0,
                            'message' => 'send order confirmation before payment switched off in settings',
                        ));
                    } elseif (false === $mailer->hasError()) {
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
                    } else {
                        $out = (object)(array(
                            'success' => false,
                            'status_code' => 0,
                            'message' => 'Mailer has error',
                        ));
                    }
                    var_dump($out);
                    $db->updateColumns('_order', array(
                        'emailed_order_confirmation' => true,
                        'emailed_order_confirmation_success' => $out->success,
                        'emailed_order_confirmation_response' => json_encode($out),
                    ), $row->order_id);
                    // carbon copy the web shop owner
                    $cc_confirmation_copy_to($mailer, $row, $instance_id, $order_output_object);
                    continue;
                }
                // 3) mail payment confirmation
                if (true === $row->payment_confirmed_bool) {
                    echo 'Payment confirmation', PHP_EOL;
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
                        if (false === $curl) {
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
                        $result = curl_exec($curl);
                        if (false === $result) {
                            Help::addError(new \Exception(sprintf('Curl error invoice for order %s: ‘%s’', $order_number, curl_error($curl))));
                            curl_close($curl);
                            break; // it’s not working apparently
                        }
                        $result = trim($result);
                        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
                        curl_close($curl);
                        if (200 !== $status_code || '%PDF' !== substr($result, 0, 4)) {
                            Help::addError(new \Exception(sprintf('Pdf error invoice for order %s: ‘%s’', $order_number, substr($result, 0, 300))));
                            break; // it’s not working apparently
                        }
                        // save the pdf
                        file_put_contents($filename, $result, LOCK_EX);
                        // you have to be sure there is an invoice, if there is not, continue, because it will not be able to send the invoice
                    }
                    if (false === $row->emailed_payment_confirmation) {
                        // the invoice is created (above), so you can now e-mail it (when requested)
                        if (false === $row->confirmation_of_payment) {
                            $out = (object)(array(
                                'success' => false,
                                'status_code' => 0,
                                'message' => 'send payment confirmation switched off in settings',
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
                                    "$invoice_title.pdf",
                                    'application/pdf',
                                    file_get_contents($filename)
                                );
                            }
                            $out = $mailer->send();
                        } else {
                            $out = (object)array('success' => false, 'reason' => 'mailer has error');
                        }
                        var_dump($out);
                        $db->updateColumns('_order', array(
                            'emailed_payment_confirmation' => true,
                            'emailed_payment_confirmation_success' => $out->success,
                            'emailed_payment_confirmation_response' => json_encode($out),
                        ), $row->order_id);
                        // carbon copy the web shop owner
                        $cc_confirmation_copy_to($mailer, $row, $instance_id, $order_output_object);
                    }
                }
            } // todo: log mail into _history?
        }
        unset($mailer);
        $trans->start('Create missing search index records');
        $limit = 250;
        foreach ($db::TYPES_WITH_CI_AI as $index => $type_name) {
            $rows = $db->fetchElementsMissingCiAi($type_name, $limit);
            foreach ($rows as $row_index => $row) {
                $element = (new Type($type_name))->getElement($row);
                echo $element->getSlug();
                echo $db->updateSearchIndex($element) ? "\tOK" : "\tFAIL";
                echo PHP_EOL;
                $limit--;
                if (0 === $limit) {
                    echo "MAX reached\n";
                    break 2;
                }
            }
        }
        $trans->start('Delete orphaned search index records');
        echo $db->jobDeleteOrphanedCiAi();
        echo "\n";
        $trans->start('Empty expired lockers');
        echo $db->jobEmptyExpiredLockers(), PHP_EOL;
        // Import images, takes precedence over Instagram data refreshment
        $images = $db->queryImagesForImport();
        if ($images->rowCount() > 0) {
            $trans->start('Import images');
            // make sure you accept webp images to be able to copy them
            $stream_context = stream_context_create(array(
                'http' => array(
                    'method' => 'GET',
                    'header' => "Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*\r\n"
                )
            ));
            $static_path = Setup::$CDNPATH;
            while (microtime(true) - $start_timer < 60 && ($row = $images->fetch(5))) {
                $instance_id = $row->instance_id;
                $static_root = $row->static_root;
                $update_data = array();
                if (false === file_exists("$static_path$instance_id")) mkdir("$static_path$instance_id");
                if (false === str_ends_with($static_root, '/')) $static_root = "$static_root/";
                foreach (Image::SIZES as $size => $pixels) {
                    $save_path = "$instance_id/$size/";
                    $src_path = "src_$size";
                    $image_src = trim($row->{$src_path} ?? '');
                    if ('' === $image_src) {
                        continue; // we do not have an url to get, so skip it
                    }
                    $image_src = "$static_root$image_src";
                    // todo remember the paths so you dont need to check every time
                    if (false === file_exists("$static_path$save_path")) mkdir("$static_path$save_path");
                    $save_path = $save_path . basename($image_src);
                    echo "Copy $image_src to $save_path";
                    if (false === file_exists($save_path)) {
                        $headers = get_headers($image_src, true, $stream_context);
                        if (false === isset($headers[0])) {
                            echo ' SKIPPED (NO HEADER[0])', PHP_EOL;
                            continue 2; // weird, retry later TODO bug when this happens a lot, the import process clogs
                        }
                        $headers_0 = $headers[0];
                        if (str_contains($headers_0, ' 503 ') || str_contains($headers_0, ' 429 ')) {
                            echo ' HIT RATE LIMIT, paused importing.', PHP_EOL;
                            break 2;
                        }
                        if (isset($headers['Content-Type']) && str_contains($headers_0, ' 200 OK') && 'image/webp' === $headers['Content-Type']) {
                            if (false === copy($image_src, "$static_path$save_path", $stream_context)) {
                                echo ' ERROR', PHP_EOL;
                                continue 2; // try again later
                            }
                        } else {
                            echo ' NOT FOUND', PHP_EOL, var_export($headers, true), PHP_EOL;
                            continue 2; // try again later
                        }
                    } else {
                        echo ' ALREADY EXISTS';
                    }
                    $update_data[$src_path] = $save_path;
                    echo PHP_EOL, 'Copy fallback jpg image:';
                    $image_src = substr($image_src, 0, -4) . 'jpg';
                    $save_path = substr($save_path, 0, -4) . 'jpg';
                    if (false === file_exists($save_path)) {
                        $headers = get_headers($image_src, true, $stream_context);
                        if (isset($headers[0], $headers['Content-Type']) && str_contains($headers[0], ' 200 OK') && 'image/jpeg' === $headers['Content-Type']) {
                            if (false === copy($image_src, "$static_path$save_path", $stream_context)) {
                                echo ' ERROR', PHP_EOL;
                                continue 2; // try again later
                            } else {
                                echo ' SUCCESS';
                            }
                        } else {
                            echo ' NOT FOUND', PHP_EOL, var_export($headers, true), PHP_EOL;
                            continue 2; // try again later
                        }
                    } else {
                        echo ' ALREADY EXISTS';
                    }
                    echo PHP_EOL;
                }
                $update_data['filename_saved'] = null;
                $update_data['static_root'] = null;
                $update_data['date_processed'] = 'NOW()';
                if (false === $db->updateColumns('cms_image', $update_data, $row->image_id)) {
                    echo 'ERROR could not update database', PHP_EOL;
                }
            }
            if (microtime(true) - $start_timer > 55) break;
        }
        $images = null;
        // Refresh Instagram media
        $trans->start('Refresh instagram data');
        // @since 0.7.8 find deauthorized instagram accounts to trigger the feed updates, set them to deleted afterwards
        if (null !== ($rows = $db->fetchInstagramDeauthorized())) {
            foreach ($rows as $index => $row) {
                $user_id = (int)$row->user_id;
                $db->invalidateInstagramFeedSpecsByUserId($user_id);
                $db->deleteInstagramMediaByUserId($user_id);
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
            echo 'register instagram user ids for new media entries', PHP_EOL;
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
                if (false === is_string($result)) {
                    Help::addError(new \Exception(sprintf('curl returned \'%s\' for instagram user %s',
                        var_export($result, true),
                        $row->user_id)));
                    continue;
                }
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
                    Help::addError(new \Exception(sprintf('json error %s for instagram user %s, result: %s',
                        json_last_error(),
                        $row->user_id,
                        var_export($result, true))));
                }
            }
        }
        // now get all media entries that have no content (ie where username = null), to update
        $rows = $db->jobGetInstagramMediaIdsForRefresh(count($instagram_user_ids) * 4); // max 4 every minute per user registered
        echo 'refresh instagram media entries', PHP_EOL;
        if (0 === count($rows)) { // when there are no new rows, select some old ones just to check if they’re still valid
            $rows = $db->jobGetInstagramMediaIdsForRefreshByDate(count($instagram_user_ids) * 4);
            echo '(no new ones found, so checking up on some old ones)', PHP_EOL;
        }
        $updated_user_ids = array();
        // https://graph.instagram.com/17896358038720106?fields=caption,media_type,media_url,permalink,thumbnail_url,timestamp,username&access_token={}
        foreach ($rows as $index => $row) {
            if (false === isset($instagram_user_ids[$row->user_id])) {
                // this row is weird, can be when a user is deauthorized but has just uploaded media
                echo 'No access token for this user';
                if ($db->updateColumns('_instagram_media', array('deleted' => true), $row->media_id)) {
                    echo ', removed media entry: ', $row->media_id;
                }
                echo PHP_EOL;
                continue;
            }
            echo $row->media_id, ': ';
            curl_setopt($curl, CURLOPT_URL,
                'https://graph.instagram.com/' . $row->media_id .
                '?fields=caption,media_type,media_url,permalink,thumbnail_url,timestamp,username&access_token=' .
                urlencode($instagram_user_ids[$row->user_id])
            );
            $result = curl_exec($curl);
            $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
            if (200 === $status_code) {
                $media = json_decode($result);
                if (json_last_error() === JSON_ERROR_NONE && isset($media->media_url)) {
                    $media_url = ($media->thumbnail_url ?? $media->media_url);
                    $update_data = array(
                        'caption' => $media->caption ?? '', // (old) instagram posts may have no caption
                        'media_type' => $media->media_type,
                        'permalink' => $media->permalink,
                        'instagram_username' => $media->username,
                        'instagram_timestamp' => $media->timestamp,
                        'media_url' => $media_url,
                        'flag_for_update' => false,
                    );
                    // @since 0.19.0 we are not reprocessing images anymore, since you cannot change them on instagram,
                    // and the url changes are only due to load balancing and such
//                    if ($media_url !== $row->media_url) {
//                        $update_data['src'] = null; // have it processed again
//                    }
                    // update the entry
                    if ($db->updateColumns('_instagram_media', $update_data, $row->media_id)) {
                        // remember you updated this user_id, so their feeds can be refreshed
                        $updated_user_ids[(string)$row->user_id] = true;
                        echo 'OK';
                    } else {
                        Help::addError(new \Exception("Insta update failed for {$media->permalink}"));
                        echo 'failed';
                    }
                } else {
                    echo '(user_id ', $row->user_id, '}): ', $result, ' ';
                    // this happens when this user_id is a collaborator on the post, it is returned empty
                    $update_data = array(
                        'instagram_username' => 'COLLABORATOR',
                        'flag_for_update' => false,
                    );
                    if ($db->updateColumns('_instagram_media', $update_data, $row->media_id)) {
                        echo 'COLLAB';
                    } else {
                        Help::addError(new \Exception("Insta update failed for {$row->media_id}"));
                        echo 'failed';
                    }
                }
            } elseif ($status_code === 400 || $status_code === 403 || $status_code === 404) { // remove this media entry
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
                Help::addError(new \Exception("Instagram media update failed with status $status_code"));
                echo 'Nothing done, got status ', $status_code;
            }
            echo PHP_EOL;
        }
        // @since 0.7.4 get all images (media...) that are not yet cached and put them on your own server
        $trans->start('Caching instagram media');
        echo 'Caching media_urls for instagram... ', PHP_EOL;
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
                $size = sha1($media_url) . '.jpg';
                // save the image and update src
                if (file_put_contents($save_path . $size, $result)) {
                    $row->src = $size;
                    $img = new InstagramImage($row);
                    if (true === $img->process($logger)) {
                        if (true === $db->updateColumns('_instagram_media', array('src' => $size), $row->media_id)) {
                            echo ' DB updated';
                        }
                        // remember you updated this user_id, so their feeds can be refreshed
                        $updated_user_ids[(string)$row->user_id] = true;
                        echo PHP_EOL;
                        $logger->out();
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
        $trans->start('Update instagram feeds');
        // fill them with an appropriate number of entries
        echo 'Updating feeds triggered by media updates...', PHP_EOL;
        $updated_feeds = array(); // each feed has to be updated only once here
        $update_feeds = static function ($feeds) use ($updated_feeds, $db) {
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
        };
        foreach ($updated_user_ids as $user_id => $ok) {
            echo 'user ', $user_id, PHP_EOL;
            $feeds = $db->getInstagramFeedSpecsByUserId($user_id);
            $update_feeds($feeds);
        }
        // there may be (changed) feeds that do not yet have an actual feed or need updating anyway
        echo 'Updating feeds that are outdated...', PHP_EOL;
        $feeds = $db->getInstagramFeedSpecsOutdated();
        $update_feeds($feeds);
        break;
    case '5': // interval should be 5
        $trans->start('Purge deleted');
        echo $db->jobPurgeDeleted((int)$interval ?: 5), PHP_EOL;
        // delete cross table entries with orphaned id’s
        $trans->start('Clear cross (_x_) tables');
        $tables = array_map(static function ($row) {
            return $row->table_name;
        }, $db->jobFetchCrossTables());
        foreach ($tables as $index => $table_name) {
            // get all the id's
            $info = $db->getTableInfo($table_name);
            $cols = $info->getColumnNames();
            $id_column_name = $info->getIdColumn()->getName();
            $foreign_id_cols = array();
            foreach ($cols as $col_index => $col_name) {
                if ($id_column_name === $col_name) continue;
                $id_name = $col_name;
                // replace 'sub_' at the beginning to get the original id column
                $pos = strpos($col_name, 'sub_');
                if ($pos !== false) {
                    $id_name = substr($col_name, 4);
                }
                if (str_ends_with($col_name, '_id')) {
                    $foreign_id_cols[] = array('original' => $col_name, 'real' => $id_name);
                }
            }
            $statement = $db->queryAllRows($table_name);
            while (($row = $statement->fetch(5))) {
                foreach ($foreign_id_cols as $id_col_index => $col) {
                    // find out if an id exists for this real id_column with value of the original x-table column
                    if (false === $db->idExists($col['real'], $row->{$col['original']})) {
                        $db->deleteRowImmediately($table_name, $row->id);
                        echo "Deleted from $table_name because {$col['real']} {$row->{$col['original']}} does not exist\n";
                        continue 2;
                    }
                }
            }
        }
        // fill in reverse dns-es
        $trans->start('Reverse dns for sessions');
        $sessions = $db->fetchSessionsWithoutReverseDns();
        foreach ($sessions as $key => $session) {
            $reverse_dns = gethostbyaddr($session->ip_address);
            $db->updateSession($session->token, array('reverse_dns' => $reverse_dns));
        }
        // process some images that need processing (date_processed = null)
        $upload = Setup::$UPLOADS;
        $logger = new StdOutLogger();
        // instagram images
        $trans->start('Process instagram images');
        foreach ($db->jobFetchInstagramImagesForProcessing() as $index => $row) {
            $img = new InstagramImage($row);
            echo $img->getSlug();
            if (true === $img->process($logger)) {
                echo ' SUCCES', PHP_EOL;
            } else {
                // the src will be set to null by ->process, and so it will be picked up by ->jobGetInstagramMediaUrls
                echo ' FAILED', PHP_EOL;
            }
            $logger->out();
        }
        // regular images
        $trans->start('Process images that need processing');
        foreach ($db->jobFetchImagesForProcessing() as $index => $row) {
            if ('IMPORT' === $row->filename_saved) continue;
            $img = new Image($row);
            Setup::$instance_id = $row->instance_id;
            echo $img->getSlug();
            if (true === $img->process($logger)) {
                echo ' SUCCESS', PHP_EOL;
            } else {
                echo ' FAILED', PHP_EOL;
            }
            $logger->out();
        }
        // remove some originals that are old (date_processed = long ago)
        $trans->start('Remove old files from upload directory');
        foreach ($db->jobFetchImagesForCleanup() as $index => $row) {
            if('IMPORT' === $row->filename_saved) continue;
            Setup::$instance_id = $row->instance_id;
            echo $row->slug;
            if (file_exists("$upload$row->filename_saved")) {
                if (false === unlink("$upload$row->filename_saved")) {
                    echo ' could not be removed', PHP_EOL;
                    continue;
                }
            } else {
                echo ' (did not exist)';
            }
            if (false === $db->updateColumns('cms_image', array('filename_saved' => null), $row->image_id)) {
                echo ' ERROR UPDATING DB', PHP_EOL;
                continue;
            }
            echo ' ok', PHP_EOL;
        }
        // refresh the json files for the filters as well
        $trans->start('Handle properties filters cache');
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
                        if ($age < 300) continue; // filter may be 5 minutes old
                        $filename = $filter_file_info->getFilename();
                        $filename_for_cache = "$instance_id/$filename";
                        if (microtime(true) - $start_timer > 55) {
                            echo "Stopped for time, filter age being $age seconds\n";
                            // remember we left off here, to resume next run
                            $db->setSystemValue('cache_pointer_filter_filename', $filename_for_cache);
                            break 3;
                        }
                        if ($filename_for_cache === $cache_pointer_filter_filename) $cache_pointer_filter_filename = null;
                        if (null !== $cache_pointer_filter_filename) continue;
                        // -11 to remove .serialized extension
                        $path = urldecode(substr($filename, 0, -11));
                        $src = new Search();
                        $src->getRelevantPropertyValuesAndPrices($path, $instance_id, true);
                        //echo "Refreshed $path\n";
                    }
                }
                $db->setSystemValue('cache_pointer_filter_filename', null); // register we’re done
            }
        }
        echo "done... \n";
        if (null === $filename_for_cache) ob_clean();
        break;
    case 'hourly': // interval should be hourly
        // check all the js and css in the cache, delete old ones
        $trans->start('Handle js and css cache');
        foreach (array('js', 'css') as $sub_directory) {
            $dir = new \DirectoryIterator(Setup::$DBCACHE . $sub_directory);
            foreach ($dir as $index => $fileinfo) {
                if (!$fileinfo->isDot() && 'gz' === $fileinfo->getExtension()) {
                    // these are compressed js and css files with a timestamp, delete all files
                    // with an older timestamp than the latest one for the instance, or of an older version
                    $pieces = explode('-', $fileinfo->getFilename());
                    $instance_id = $pieces[0];
                    $version = $pieces[1];
                    if (version_compare(Setup::$VERSION, $version) !== 0) {
                        unlink($fileinfo->getPathname());
                        echo 'Deleted ', $fileinfo->getFilename(), PHP_EOL;
                        continue;
                    }
                    $timestamp = explode('.', end($pieces))[0];
                    if (($row = $db->fetchInstanceById((int)$instance_id))) {
                        if (isset($row->date_published) && strtotime($row->date_published) > $timestamp) {
                            unlink($fileinfo->getPathname());
                            echo 'Deleted ', $fileinfo->getFilename(), PHP_EOL;
                        }
                    }
                }
            }
        }
        break;
    case 'daily':
        $trans->start('Clean template folder');
        echo $db->jobCleanTemplateFolder(), PHP_EOL;
        // @since 0.7.9 & 0.8.9
        $trans->start('Remove old sessions');
        echo $db->jobDeleteOldSessions(), PHP_EOL;
        $trans->start('Remove orphaned session variables');
        echo $db->jobDeleteOrphanedSessionVars(), PHP_EOL;
        $trans->start('Remove old shoppinglists');
        echo $db->jobDeleteOrphanedLists(), PHP_EOL;
        $trans->start('Remove orphaned shoppinglist rows (variants)');
        echo $db->jobDeleteOrphanedShoppinglistVariants(), PHP_EOL;
        $trans->start('Remove old _history rows');
        echo $db->jobDeleteOldHistory(300), PHP_EOL;
        // refresh token should be called daily for all long-lived instagram tokens, refresh like 5 days before expiration or something
        $trans->start('Refresh instagram access token');
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
                $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
                $return_value = json_decode($result);
                if (json_last_error() !== JSON_ERROR_NONE || false === isset($return_value->access_token)) {
                    echo sprintf(
                        'Instagram refresh token error, status %1$s, body %2$s',
                        $status_code, var_export($return_value, true)
                    ), PHP_EOL;
                } else {
                    // and update it in the db
                    $expires = isset($return_value->expires_in) ?
                        Help::getAsInteger($return_value->expires_in, $default_expires) : $default_expires;
                    if ($db->updateColumns('_instagram_auth', array(
                        'access_token' => $return_value->access_token,
                        'access_token_expires' => date('Y-m-d G:i:s.u O', time() + $expires),
                        'access_granted' => true,
                    ), $row->instagram_auth_id)) {
                        echo 'OK', PHP_EOL;
                    } else {
                        echo 'Unable to update settings for Instagram authorization', PHP_EOL;
                    }
                }
            }
            $curl = null;
        } else {
            echo 'no tokens to refresh', PHP_EOL;
        }
        $rows = null;
        // duplicate code, to finish the current job
        $trans->start('Report current job');
        echo date('Y-m-d H:i:s');
        echo " (ended)\n";
        printf("Job completed in %s seconds\n", number_format(microtime(true) - $start_timer, 2));
        $trans->flush();
        /**
         * start the folder cleaning process
         */
        set_time_limit(0); // this might take a while
        $trans->start('Clean uploads folder');
        $dir = new \DirectoryIterator(Setup::$UPLOADS);
        $deleted = 0;
        foreach ($dir as $index => $fileinfo) {
            if ($fileinfo->isDot()) continue;
            $filename = $fileinfo->getFilename();
            echo $index, ': ', $filename;
            if ('jpg' === $fileinfo->getExtension()) {
                if (false === $db->rowExists('_instagram_media', array(
                        'src' => $filename,
                    ))
                ) {
                    echo ' deleted ', $fileinfo->getRealPath();
                    unlink($fileinfo->getRealPath());
                    ++$deleted;
                }
            } elseif ('' === $fileinfo->getExtension() && 20 === strlen($filename)) {
                if (false === $db->rowExists('cms_image', array(
                        'filename_saved' => $filename,
                    ))
                ) {
                    echo ' deleted ', $fileinfo->getRealPath();
                    unlink($fileinfo->getRealPath());
                    ++$deleted;
                }
            }
            if (0 === $index % 1000) {
                $trans->flush();
                $trans->start('Clean uploads folder (continued)');
            }
            echo PHP_EOL;
            usleep(300000); // wait 300 ms
        }
        echo $deleted, ' orphaned files deleted from file system', PHP_EOL;
        $trans->start('Clean static folder');
        $deleted = 0;
        $cleanFolder = static function ($folder) use ($db, $trans, &$deleted) {
            $instance = basename($folder);
            if ('0' === $instance) { // instagram images
                $sizes = InstagramImage::SIZES;
                $table_name = '_instagram_media';
            } else { // regular images
                $sizes = Image::SIZES;
                $table_name = 'cms_image';
            }
            $size = key($sizes);
            $a_week_ago = time() - 604800;
            if (false === file_exists("$folder/$size")) return;
            $dir = new \DirectoryIterator("$folder/$size");
            foreach ($dir as $index => $fileinfo) {
                if ($fileinfo->isDot()) continue;
                if ('webp' !== $fileinfo->getExtension()) continue;
                $filename = $fileinfo->getFilename();
                echo $index, ': ', "$instance/$size/$filename";
                echo str_repeat(' ', max(1, 80 - mb_strlen($filename)));
                if ($fileinfo->getCTime() > $a_week_ago) {
                    echo 'too recent', PHP_EOL;
                    continue;
                }
                if (false === $db->rowExists($table_name, array(
                        "src_$size" => "$instance/$size/$filename",
                    ))
                ) {
                    echo 'DELETED';
                    foreach ($sizes as $size_name => $pixels) {
                        $path = "$folder/$size_name/$filename";
                        if (true === file_exists($path)) unlink($path);
                        // unlink the fallback jpg, when it exists
                        $path = substr($path, 0, -4) . 'jpg';
                        if (true === file_exists($path)) unlink($path);
                        // report
                        echo ' ', $size_name;
                    }
                    ++$deleted;
                }
                echo PHP_EOL;
                if (0 === $index % 1000) {
                    $trans->flush();
                    $trans->start('Clean static folder (continued)');
                }
                usleep(300000); // wait 300 ms
            }
        };
        $dir = new \DirectoryIterator(Setup::$CDNPATH);
        foreach ($dir as $index => $fileinfo) {
            if ($fileinfo->isDot()) continue;
            $cleanFolder($fileinfo->getRealPath());
        }
        echo $deleted, ' orphaned images deleted', PHP_EOL;
        break;
    case 'temp':
        echo 'Notice: this is a temp job, only for testing', PHP_EOL;
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
                if (false === str_contains($headers[0], ' 200 OK')) {
                    $db->updateColumns('_instagram_media', array('flag_for_update' => true), $row->media_id);
                    echo 'flagged for update: ', $row->media_id, PHP_EOL;
                }
            }
            //var_dump($headers);
        }
}
$trans->start('Report current job');
echo date('Y-m-d H:i:s');
echo " (ended)\n";
printf("Job completed in %s seconds\n", number_format(microtime(true) - $start_timer, 2));
$trans->flush();
//
unset($db);
