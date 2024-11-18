<?php
declare(strict_types=1);

namespace Bloembraaden;

class Addresses extends Base
{
    const SUGGESTION_ENDPOINT = 'https://address.api.myparcel.nl/addresses';
    const VALIDATION_ENDPOINT = 'https://address.api.myparcel.nl/validate';

    private string $api_key;

    public function __construct(string $api_key)
    {
        $this->api_key = $api_key;
        parent::__construct();
    }

    public function suggest(string $country_code, string $query): array
    {
        if (3 >= strlen($query)) return array();

        $params = array(
            'countryCode' => $country_code,
            'query' => $query, // todo or use postcode + housenumber
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => self::SUGGESTION_ENDPOINT . '?' . http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Authorization: bearer ' . base64_encode($this->api_key),
                'Content-type: application/json',
            ),
        ));
        $result = curl_exec($curl);
        $err = curl_error($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
        curl_close($curl);
        if ($err) {
            $this->addError($err);
        } elseif (400 < $status_code) {
            $this->addError("MyParcel addresses returned status $status_code / $result");
        } else {
            $return_object = json_decode($result);
            if (0 === json_last_error() && isset($return_object->results)) {
                return $return_object->results;
            } else {
                $this->addError("Did not understand MyParcel addresses response $result");
            }
        }

        return array();
    }
}