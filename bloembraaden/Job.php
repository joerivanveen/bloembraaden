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
            echo "=== cc confirmation copy to ===\n";
            if ('' !== trim($row->confirmation_copy_to)) {
                // create internal mail
                if (null !== ($template_id = $row->template_id_internal_confirmation)) {
                    try {
                        $temp = new Template($template_id, $instance_id);
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
            if (true === isset($row->order_number)) {
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
                            //#translators %1$s: order number, %2$s: instance domain
                                __('Could not save order html for %1$s (%2$s).', 'peatcms'),
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
                    if (0 !== $row->user_id) {
                        echo "Check address for account\n";
                        // get all addresses for this user_id, md5 them
                        $addresses = $db->fetchAddressesByUserId($row->user_id);
                        $by_hash = array();
                        foreach ($addresses as $index => $address) {
                            $hash = md5("$address->address_postal_code$address->address_street$address->address_number$address->address_number_addition$address->address_country_iso2");
                            $by_hash[$hash] = $address;
                        }
                        // todo have the shop addresses appear in the hashes, to not add the ‘collect’ address to the account
                        $addresses = null;
                        // get shipping / billing address -> md5, if not in the addresses md5, add it
                        foreach (array('billing', 'shipping') as $index => $address_type) {
                            $hash = md5($row->{"{$address_type}_address_postal_code"} . $row->{"{$address_type}_address_street"} . $row->{"{$address_type}_address_number"} . $row->{"{$address_type}_address_number_addition"} . $row->{"{$address_type}_address_country_iso2"});
                            if (false === isset($by_hash[$hash])) {
                                $address = array(
                                    'user_id' => $row->user_id,
                                    'instance_id' => $instance_id,
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
                                if ($db->insertRowAndReturnKey('_address', $address)) {
                                    $by_hash[$hash] = $address;
                                    echo 'Added ', $row->{"{$address_type}_address_street"}, ' ', $row->{"{$address_type}_address_number"}, ' ', $row->{"{$address_type}_address_number_addition"}, "\n";
                                }
                            }
                        }
                    }
                    echo "Order confirmation\n";
                    $mailer->clear();
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
                                $temp = new Template($template_id, $instance_id);
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
                    echo 'Payment confirmation', "\n";
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
                                    $temp = new Template($template_id, $instance_id);
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
            }
        }
        unset($mailer);
        $rows = $db->jobGetOrdersForMyParcel();
        if (count($rows) > 0) {
            $trans->start('Orders for MyParcel');
            foreach ($rows as $index => $row) {
                if ('' === ($myparcel_api_key = trim($row->myparcel_api_key))) {
                    $db->updateColumns('_order', array(
                        'myparcel_exported' => true,
                        'myparcel_exported_date' => 'NOW()',
                        'myparcel_exported_response' => 'No api key present.'
                    ), $row->order_id);
                    continue;
                }
                // somewhat duplicate code
                $order_number = $row->order_number;
                if (false === Help::obtainLock("myparcel.order.$order_number")) continue;
                $instance_id = $row->instance_id;
                if (Setup::$instance_id !== $instance_id) {
                    Setup::loadInstanceSettings(new Instance($db->fetchInstanceById($instance_id)));
                }
                echo Setup::$INSTANCE_DOMAIN, "\tORDER: $order_number\n";
                // make order with the row
                $order = new Order($row);
                /**
                 * convert order to myparcel json
                 * afhalen = package_type 3 (letter)
                 * MyParcel expects prices in euro cents
                 */
                $myparcelAmount = function(string $amount):int {
                    return (int)(100 * Help::asFloat($amount));
                };
                $order_out = $order->getOutput();
                $shipping_cc = $order_out->shipping_address_country_iso2;
                $is_pickup = $shipping_cc === 'XX';
                $package_type = 1;
                if ($is_pickup) {
                    $shipping_cc = 'NL';
                    $package_type = 3;
                }
                $order_lines = array();
                foreach ($order_out->__items__ as $index => $line) {
                    $quantity = (int)$line->quantity;
                    if (0 === $quantity) continue;
                    $order_lines[] = (object)array(
                        'quantity' => $quantity,
                        'price' => $myparcelAmount($line->price_ex_vat),
                        'vat' => $myparcelAmount($line->vat_amount),
                        'price_after_vat' => $myparcelAmount($line->price),
                        'product' => (object)array(
                            'sku' => $line->sku,
                            'name' => $line->title
                        ),
                        'instructions' => null,
                        'shippable' => true
                    );
                }
                $order_lines[] = (object)array(
                    'quantity' => 1,
                    'price' => $myparcelAmount($order_out->shipping_costs_ex_vat),
                    'vat' => $myparcelAmount($order_out->shipping_costs_vat_amount),
                    'price_after_vat' => $myparcelAmount($order_out->shipping_costs),
                    'product' => (object)array(
                        'sku' => '',
                        'name' => __('Shipping', 'peatcms')
                    ),
                    'instructions' => null,
                    'shippable' => false
                );
                $myparcel_shipping_name = mb_substr($order_out->shipping_address_name, -40);
                /* #TRANSLATORS: default name is the client name on the shipping label when no name is given */
                if ('' === $myparcel_shipping_name) $myparcel_shipping_name = __('Default name', 'peatcms');
                $myparcel_billing_name = mb_substr($order_out->billing_address_name, -40);
                if ('' === $myparcel_billing_name) $myparcel_billing_name = __('Default name', 'peatcms');
                $order_myparcel_json = json_encode((object)array(
                    'external_identifier' => $order_out->order_number_human,
                    'order_date' => $order_out->date_created,
                    'invoice_address' => (object)array(
                        'cc' => $order_out->billing_address_country_iso2,
                        'street' => mb_substr(implode(' ', array(
                            $order_out->billing_address_street,
                            $order_out->billing_address_number,
                            $order_out->billing_address_number_addition,
                        )), -40),
                        'person' => $myparcel_billing_name,
                        'company' => Help::truncate($order_out->billing_address_company, 50),
                        'email' => $order_out->user_email,
                        'phone' => $order_out->user_phone,
                        'city' => $order_out->billing_address_city,
                        'postal_code' => $order_out->billing_address_postal_code,
                        //'number'=> '31',
                        //'number_suffix'=> 'bis',
                    ),
                    'language' => 'NL',
                    'type' => 'consumer',
                    'price' => $myparcelAmount($order_out->amount_grand_total_ex_vat),
                    //'vat'=>
                    'price_after_vat' => $myparcelAmount($order_out->amount_grand_total),
                    'shipment' => (object)array(
                        'recipient' => (object)array(
                            'cc' => $shipping_cc,
                            'street' => mb_substr(implode(' ', array(
                                $order_out->shipping_address_street,
                                $order_out->shipping_address_number,
                                $order_out->shipping_address_number_addition,
                            )), -40),
                            'person' => $myparcel_shipping_name,
                            'company' => Help::truncate($order_out->shipping_address_company, 50),
                            'email' => $order_out->user_email,
                            'phone' => $order_out->user_phone,
                            'city' => $order_out->shipping_address_city,
                            'postal_code' => $order_out->shipping_address_postal_code,
                            //'number'=> '31',
                            //'number_suffix'=> 'bis',
                        ),
                        'pickup' => null,
                        'options' => (object)array(
                            'package_type' => $package_type,
                        ),
                        'physical_properties' => (object)array(
                            'weight' => 1000,
                        ),
                        'customs_declaration' => null,
                        'carrier' => 1
                    ),
                    'order_lines' => $order_lines,
                ));
                // post the order to myparcel using curl
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.myparcel.nl/fulfilment/orders',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => "{\"data\":{\"orders\":[$order_myparcel_json]}}",
                    CURLOPT_HTTPHEADER => array(
                        'Accept: application/json;charset=utf-8',
                        'Authorization: Bearer ' . base64_encode($myparcel_api_key),
                        'Content-type: application/json;charset=utf-8',
                        'User-Agent: Bloembraaden/' . Setup::$VERSION
                    ),
                ));
                $result = curl_exec($curl);
                // receives an array with the order object with uuid set.
                $err = curl_error($curl);
                $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
                $success = false;
                curl_close($curl);
                if ($err) {
                    Help::addError(new \Exception("MyParcel curl error: $err"));
                } else {
                    $return_object = json_decode($result);
                    if ($status_code >= 400) {
                        if (json_last_error() === 0) {
                            Help::addError(new \Exception('MyParcel error ' . ($return_object->message ?? var_export($return_object, true))));
                        } else {
                            Help::addError(new \Exception("MyParcel status $status_code error (1): $result"));
                        }
                    } elseif (json_last_error() !== 0) {
                        Help::addError(new \Exception("MyParcel status $status_code error (2): $result"));
                    } else { // success
                        // record the response and set true + date for the export when successful
                        try {
                            $order_confirmed = (object)$return_object->data->orders[0];
                        } catch (\Throwable) {
                            Help::addError(new \Exception("MyParcel no order in response for $order_out->order_number_human: " . var_export($return_object, true)));
                        }
                        if (property_exists($order_confirmed, 'external_identifier')
                            && property_exists($order_confirmed, 'uuid')
                        ) {
                            if ($order_out->order_number_human === $order_confirmed->external_identifier) {
                                if (true === $db->updateColumns('_order', array(
                                        'myparcel_exported' => true,
                                        'myparcel_exported_success' => true,
                                        'myparcel_exported_date' => 'NOW()',
                                        'myparcel_exported_uuid' => Help::slugify($order_confirmed->uuid),
                                    ), $row->order_id)) {
                                    echo " ^ OK\n";
                                    $success = true;
                                }
                            } else {
                                Help::addError(new \Exception("MyParcel wrong identifier for $order_out->order_number_human: " . var_export($order_confirmed, true)));
                            }
                        } else {
                            Help::addError(new \Exception("MyParcel no reference or uuid for $order_out->order_number_human: " . var_export($order_confirmed, true)));
                        }
                    }
                    if (false === $success) {
                        if (true === $db->updateColumns('_order', array(
                                'myparcel_exported' => true,
                                'myparcel_exported_error' => true,
                                'myparcel_exported_date' => 'NOW()',
                                'myparcel_exported_response' => json_encode(Help::getErrorMessages()),
                            ), $row->order_id)) {
                            echo " ^ FAIL\n";
                        }
                    }
                }
                // TODO make button to set myparcel_exported to false, so it will be tried again next run
            }
            //return; // JOERI TEMP
        }
        $trans->start('Create missing search index records');
        $limit = 250;
        foreach ($db::TYPES_WITH_CI_AI as $index => $type_name) {
            $rows = $db->fetchElementsMissingCiAi($type_name, $limit);
            foreach ($rows as $row_index => $row) {
                $element = (new Type($type_name))->getElement($row);
                echo $element->getSlug();
                echo $db->updateSearchIndex($element) ? "\tOK" : "\tFAIL";
                echo "\n";
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
        echo $db->jobEmptyExpiredLockers(), "\n";
        // Import images
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
                    echo "Copy $image_src to $save_path\n";
                    if (false === file_exists($save_path)) {
                        $headers = get_headers($image_src, true, $stream_context);
                        if (false === isset($headers[0])) {
                            echo "^ SKIPPED (NO HEADER[0]).\n";
                            continue 2; // can be a timeout, retry later TODO bug when this happens a lot, the import process clogs
                        }
                        $headers_0 = $headers[0];
                        if (str_contains($headers_0, ' 503 ') || str_contains($headers_0, ' 429 ')) {
                            echo "^ HIT RATE LIMIT, paused importing.\n";
                            break 2;
                        }
                        if (isset($headers['Content-Type']) && str_contains($headers_0, ' 200 OK') && 'image/webp' === $headers['Content-Type']) {
                            if (false === copy($image_src, "$static_path$save_path", $stream_context)) {
                                echo "^ ERROR\n";
                                continue 2; // try again later
                            }
                        } else {
                            echo "^ NOT FOUND ($row->instance_id) $row->slug\n";
                        }
                    } else {
                        echo "^ ALREADY EXISTS\n";
                    }
                    $update_data[$src_path] = $save_path;
                    echo 'Copy fallback jpg image';
                    $image_src = substr($image_src, 0, -4) . 'jpg';
                    $save_path = substr($save_path, 0, -4) . 'jpg';
                    if (false === file_exists($save_path)) {
                        $headers = get_headers($image_src, true, $stream_context);
                        if (isset($headers[0], $headers['Content-Type']) && str_contains($headers[0], ' 200 OK') && 'image/jpeg' === $headers['Content-Type']) {
                            if (false === copy($image_src, "$static_path$save_path", $stream_context)) {
                                echo " < ERROR\n";
                                continue 2; // try again later
                            } else {
                                echo " < SUCCESS\n";
                            }
                        } else {
                            echo " < NOT FOUND ($row->instance_id) $row->slug\n";
                        }
                    } else {
                        echo " < ALREADY EXISTS\n";
                    }
                }
                $update_data['filename_saved'] = null;
                $update_data['static_root'] = null;
                $update_data['date_processed'] = 'NOW()';
                if (false === $db->updateColumns('cms_image', $update_data, $row->image_id)) {
                    echo "ERROR could not update database\n";
                }
            }
            if (microtime(true) - $start_timer > 55) break;
        }
        $images = null;
        break;
    case '5': // interval should be 5
        $trans->start('Purge deleted');
        echo $db->jobPurgeDeleted((int)$interval ?: 5), "\n";
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
        // process some images that need processing (date_processed = null)
        $upload = Setup::$UPLOADS;
        $logger = new StdOutLogger();
        // regular images
        $trans->start('Process images that need processing');
        foreach ($db->jobFetchImagesForProcessing() as $index => $row) {
            if ('IMPORT' === $row->filename_saved) continue;
            $img = new Image($row);
            Setup::$instance_id = $row->instance_id;
            echo $img->getSlug();
            if (true === $img->process($logger)) {
                echo ' SUCCESS', "\n";
            } else {
                echo ' FAILED', "\n";
            }
            $logger->out();
        }
        // remove some originals that are old (date_processed = long ago)
        $trans->start('Remove old files from upload directory');
        foreach ($db->jobFetchImagesForCleanup() as $index => $row) {
            if ('IMPORT' === $row->filename_saved) continue;
            Setup::$instance_id = $row->instance_id;
            echo $row->slug;
            if (file_exists("$upload$row->filename_saved")) {
                if (false === unlink("$upload$row->filename_saved")) {
                    echo ' could not be removed', "\n";
                    continue;
                }
            } else {
                echo ' (did not exist)';
            }
            if (false === $db->updateColumns('cms_image', array('filename_saved' => null), $row->image_id)) {
                echo ' ERROR UPDATING DB', "\n";
                continue;
            }
            echo ' ok', "\n";
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
                Setup::loadInstanceSettingsFor($instance_id);
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
                        echo 'Deleted ', $fileinfo->getFilename(), "\n";
                        continue;
                    }
                    $timestamp = explode('.', end($pieces))[0];
                    if (($row = $db->fetchInstanceById((int)$instance_id))) {
                        if (isset($row->date_published) && strtotime($row->date_published) > $timestamp) {
                            unlink($fileinfo->getPathname());
                            echo 'Deleted ', $fileinfo->getFilename(), "\n";
                        }
                    }
                }
            }
        }
        break;
    case 'daily':
        $trans->start('Clean template folder');
        echo $db->jobCleanTemplateFolder(), "\n";
        // @since 0.7.9 & 0.8.9
        $trans->start('Remove old sessions');
        echo $db->jobDeleteOldSessions(), "\n";
        $trans->start('Remove orphaned session variables');
        echo $db->jobDeleteOrphanedSessionVars(), "\n";
        $trans->start('Remove old shoppinglists');
        echo $db->jobDeleteOrphanedLists(), "\n";
        $trans->start('Remove orphaned shoppinglist rows (variants)');
        echo $db->jobDeleteOrphanedShoppinglistVariants(), "\n";
        $trans->start('Remove old _history rows');
        echo $db->jobDeleteOldHistory(300), "\n";
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
        // todo optimize db access with prepared statements outside the loop, if relevant for postgresql
        foreach ($dir as $index => $fileinfo) {
            if ($fileinfo->isDot()) continue;
            $filename = $fileinfo->getFilename();
            echo $index, ': ', $filename;
            if ('' === $fileinfo->getExtension() && 20 === strlen($filename)) {
                if (false === $db->rowExists('cms_image', array(
                        'filename_saved' => $filename,
                    ))
                    && false === $db->rowExists('cms_file', array(
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
            echo "\n";
            usleep(300000); // wait 300 ms
        }
        echo $deleted, ' orphaned files deleted from file system', "\n";
        $trans->start('Clean static folder');
        $deleted = 0;
        $cleanFolder = static function ($folder) use ($db, $trans, &$deleted) {
            $instance = basename($folder);
            $sizes = Image::SIZES;
            $table_name = 'cms_image';
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
                    echo 'too recent', "\n";
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
                echo "\n";
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
        echo $deleted, ' orphaned images deleted', "\n";
        break;
    case 'temp':
        echo 'Notice: this is a temp job, only for testing', "\n";
}
$trans->start('Report current job');
echo date('Y-m-d H:i:s');
echo " (ended)\n";
printf("Job completed in %s seconds\n", number_format(microtime(true) - $start_timer, 2));
$trans->flush();
//
unset($db);
