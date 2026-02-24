<?php
declare(strict_types=1);

namespace Bloembraaden;

class Addresses extends Base
{
    public const SUGGESTION_ENDPOINT = 'https://address.api.myparcel.nl/addresses';
    public const VALIDATION_ENDPOINT = 'https://address.api.myparcel.nl/validate';

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

        list($result, $error, $status_code) = $this->get(self::SUGGESTION_ENDPOINT, $params);
//        // LOG FOR MYPARCEL
//        $log_path = substr(Setup::$LOGFILE, 0, strrpos(Setup::$LOGFILE, '/'));
//        $string = $result;
//        if ($string){
//            $string = '';
//            foreach (json_decode($result)->results as $address) {
//                $string .= "\n" . var_export($address, true);
//            }
//        }
//        file_put_contents("$log_path/joeri.log", self::SUGGESTION_ENDPOINT . ", params:\n" . var_export($params, true) . "\nresult:$string\n---\n", FILE_APPEND);

        if ($error) {
            $this->addError($error);
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

    public function validate(string $country_code, string $postal_code, string $house_number, string $street, string $city): ?bool
    {
        $params = array(
            'countryCode' => $country_code, // required
            'postalCode' => $postal_code, // required
            'houseNumber' => $house_number,
            'street' => $street,
            'city' => $city,
        );

        // do not validate when items are missing
        if (array_filter($params, function ($item) {
            return '' === trim($item);
        })) {
            return null;
        }

        list($result, $error, $status_code) = $this->get(self::VALIDATION_ENDPOINT, $params);
        // LOG FOR MYPARCEL
//        $log_path = substr(Setup::$LOGFILE, 0, strrpos(Setup::$LOGFILE, '/'));
//        file_put_contents("$log_path/joeri.log", self::VALIDATION_ENDPOINT . ", params:\n" . var_export($params, true) . "\n\tresult: " . var_export($result, true) . "\n---\n",FILE_APPEND);
        if ($error) {
            $this->addError($error);
        } elseif (400 < $status_code) {
            $this->addError("MyParcel addresses returned status $status_code / $result");
        } else {
            $return_object = json_decode($result);
            if (0 === json_last_error() && isset($return_object->valid)) {
                return $return_object->valid;
            }
        }

        return null; // we don’t know
    }

    private function get(string $endpoint, array $params): array
    {
        if ('' === trim($this->api_key)) {
            return array('', 'No api key', 403);
        }

        $curl = curl_init();
        $query = http_build_query($params);
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$endpoint?$query",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Authorization: bearer ' . base64_encode($this->api_key),
                'Content-type: application/json',
            ),
        ));
        $result = curl_exec($curl);
        $error = curl_error($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); //get status code
        curl_close($curl);

        return array($result, $error, $status_code);
    }
}