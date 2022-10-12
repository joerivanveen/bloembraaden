<?php

declare(strict_types = 1);

namespace Peat;

/**
 * Class Mailer
 * @package Peat
 */
class Mailer extends Base
{
    private array $fields = array(), $attachments = array();
    private string $api_url, $active_provider, $api_key;
    private int $success_status = 200; // which I would consider normal but Sendgrid thinks differently
    private $curl;
    // Mailer uses mailgun api via curl
    // https://documentation.mailgun.com/en/latest/user_manual.html?highlight=attachment#sending-via-api
    // or sendgrid
    // https://sendgrid.com/docs/for-developers/sending-email/curl-examples/#sending-a-basic-email-to-multiple-recipients
    // or mailchimp formerly mandrill...
    // https://mailchimp.com/developer/transactional/api/messages/
    /**
     * Mailer constructor.
     * @param string|null $custom_domain
     */
    public function __construct(?string $custom_domain)
    {
        parent::__construct();
        try {
            $mail = Setup::$MAIL;
            $this->active_provider = $mail->active;
            $this->api_key = $mail->{$mail->active}->api_key;
            $this->api_url = $mail->{$mail->active}->api_url;
        } catch (\Exception $e) {
            $this->handleErrorAndStop($e, __('Mail configuration error', 'peatcms'));
        }
        if ($this->active_provider === 'mailgun' && false === strpos(($custom_domain = trim($custom_domain)), '.')) {
            $this->addError(sprintf('%s is not a valid custom domain for Mailer', $custom_domain));
        }
        // setup one curl to use for the instance of the mailer, is fastest
        $curl = curl_init();
        if (!$curl) {
            $this->handleErrorAndStop('cURL is needed to send mail');
        }
        //curl_setopt($curl, CURLOPT_USERAGENT, 'Bloembraaden/VERSION');
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3); // it would hang for 2 minutes even though the answer was already there?
        if ($this->active_provider === 'mailchimp') {
            // mailchimp / mandrill expects a json encoded array holding the required fields
            curl_setopt($curl, CURLOPT_URL, $this->api_url . 'messages/send.json');
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
            ));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        } elseif ($this->active_provider === 'mailgun') {
            curl_setopt($curl, CURLOPT_URL, $this->api_url . $custom_domain . '/messages');
            curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $this->api_key);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        } elseif ($this->active_provider === 'sendgrid') {
            // sendgrid uses 202 for successful queueing, 200 actually means the message is not going to be delivered
            $this->success_status = 202;
            // add mail/send to the api_url as per the specs
            curl_setopt($curl, CURLOPT_URL, $this->api_url . 'mail/send');
            // set sendgrid authorization header https://stackoverflow.com/a/48896992
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json'
            ));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        } else {
            $this->handleErrorAndStop('No active mail provider found.', __('Mail configuration error', 'peatcms'));
        }
        $this->curl = $curl;
        // @since 0.6.17 changed __destruct to __shutdown for stability reasons
        register_shutdown_function(array(&$this, '__shutdown'));
    }

    public function __shutdown()
    {
        parent::__destruct();
        if (isset($this->curl)) curl_close($this->curl);
    }

    public function set(array $fields): array
    {
        $this->fields = array_merge($this->fields, $fields);

        return $this->fields;
    }

    /**
     * @param string $filename the name you want the file to appear to have in the attachment
     * @param string $mimetype official mimetype e.g. ‘application/pdf’, use a correct mimetype this is not checked
     * @param string $contents just a regular utf-8 string (e.g. read from a file) that ‘is’ the attachment
     * @return bool success
     * @since 0.9.0
     */
    public function attach(string $filename, string $mimetype, string $contents): bool
    {
        try {
            $this->attachments[] = array(
                'type' => $mimetype,
                'name' => $filename,
                'content' => base64_encode($contents)
            );

            return true;
        } catch(\Exception $e) {
            $this->addError($e);
        }
        return false;
    }

    public function send(): ?\stdClass
    {
        // validate / check if we have all the parameters we need and enrich them if possible
        foreach (['from', 'to', 'subject', 'text'] as $index => $field_name) {
            if (false === isset($this->fields[$field_name])) {
                $this->addError(sprintf('Mailer->send(): field ‘%s’ is missing', $field_name));

                return (object)array('success' => false);
            }
        }
        // mailchimp formerly mandrill
        // https://mandrillapp.com/api/docs/messages.JSON.html
        if ($this->active_provider === 'mailchimp') {
            // build the mailchimp-specific object to send as json (formerly mandrill)
            // mailchimp uses a to-name, so check if it's in the fields or make a default
            $to_name = $this->fields['to_name'] ?? $this->fields['to'];
            // it also splits the from in e-mail and name, so split that
            $from_email = $this->fields['from'];
            $from_name = $from_email;
            if (($pos = strpos($from_email, '<'))) {
                $from_name = trim(substr($from_name, 0, $pos));
                $from_email = trim(substr($from_email, $pos + 1, strlen($from_email) - $pos - 2));
            }
            $post_data = new \stdClass;
            $post_data->key = $this->api_key;
            $post_data->message = new \stdClass;
            $post_data->message->html = $this->fields['html'];
            $post_data->message->text = $this->fields['text'];
            $post_data->message->subject = $this->fields['subject'];
            $post_data->message->from_email = $from_email;
            $post_data->message->from_name = $from_name;
            $post_data->message->to = array(
                (object)array(
                    'email' => $this->fields['to'],
                    'name' => $to_name,
                    'type' => 'to',
                )
            );
            $post_data->message->headers = (object)array('Reply-To' => $this->fields['reply_to'] ?? $from_email);
            if ($this->attachments) {
                $post_data->message->attachments = $this->attachments;
            }
            if (isset($this->fields['bcc'])) {
                if (false !== filter_var($this->fields['bcc'], FILTER_VALIDATE_EMAIL)) {
                    $post_data->message->bcc_address = $this->fields['bcc'];
                }
            }
            $params = json_encode($post_data);
            //var_dump($params);
            //die('opa dumpt mailchimp params');
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $params);
        } elseif ($this->active_provider === 'mailgun') {
            $fields_string = \http_build_query($this->fields);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $fields_string);
        } elseif ($this->active_provider === 'sendgrid') {
            // build the sendgrid-specific object to send as json
            $post_data = new \stdClass;
            $post_data->personalizations = array((object)array('to' => array((object)array('email' => $this->fields['to']))));
            $post_data->from = (object)array('email' => $this->fields['from']);
            $post_data->subject = $this->fields['subject'];
            $post_data->content = array((object)array('type' => 'text/plain', 'value' => $this->fields['text']));
            if (isset($this->fields['html'])) {
                $post_data->content[] = (object)array('type' => 'text/html', 'value' => $this->fields['html']);
            }
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($post_data));
            $post_data = null;
        }
        $result = curl_exec($this->curl);
        //
        $status_code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE); //get status code
        $return_value = is_string($result) ? json_decode($result) : $result;
        if (json_last_error() === JSON_ERROR_NONE) {
            // mailchimp / mandrill sends the object in an array...
            if ($this->active_provider === 'mailchimp') {
                if (is_array($return_value) && count($return_value) > 0) {
                    $return_value = (object)$return_value[0];
                } else {
                    $return_value = (object)array('return_value' => $result);
                }
            }
            $return_value->status_code = $status_code;
        } else {
            $return_value = (object)array('status_code' => $status_code, 'return_value' => $result);
        }
        $return_value->success = ($status_code === $this->success_status);

        return $return_value;
    }

    public function clear() {
        $this->fields = array();
        $this->attachments = array();
    }
}