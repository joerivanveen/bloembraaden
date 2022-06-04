<?php

declare(strict_types = 1);

namespace Peat;

class Instagram extends BaseLogic
{
    protected object $config;

    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
        if (isset(Setup::$INSTAGRAM)) {
            $this->config = Setup::$INSTAGRAM;
        } else {
            $this->handleErrorAndStop('No config for Instagram', __('Instagram authorize not configured correctly', 'peatcms'));
        }
        // list of authorized users for this instance
        // list of feeds for this instance, with their media
    }

    /**
     * Handles the authorization process of an instagram user including the setting of the long lived token
     *
     * Authorization can only be gotten for the CURRENT instance, however the feedback from Instagram is always
     * sent to the main instance of this installation (see config), so after storing the token you need to redirect
     * the user back to their own instance where they started the authorization process
     *
     * https://developers.facebook.com/docs/instagram-basic-display-api/guides/getting-access-tokens-and-permissions
     * @param \stdClass $post_data
     * @return string[]|null
     * @since 0.7.2
     */
    public function authorize(\stdClass $post_data)
    {
        // NOTE this function can be called for any instance, not just the one we’re in currently
        if (isset($_GET['error'])) { // error... fail gracefully with a nice Bloembraaden error screen
            $error_str = isset($_GET['error_description']) ? $_GET['error_description'] : $_GET['error'];
            $this->handleErrorAndStop($error_str, $error_str); // TODO maybe redirect the user to the original site?
        } elseif (isset($_GET['code'])) { // this is the first step in the authorization process
            // instagram will provide the original key you set as state back in the request variables
            if (isset($_GET['state']) && ($contents = Help::getDB()->emptyLocker($_GET['state']))) {
                $code = $_GET['code'];
                // go get the short lived token:
                $curl = curl_init();
                if (!$curl) {
                    $this->handleErrorAndStop('cURL is needed to authorize');
                }
                //curl_setopt($curl, CURLOPT_USERAGENT, 'Bloembraaden/VERSION');
                curl_setopt($curl, CURLOPT_URL, 'https://api.instagram.com/oauth/access_token');
                /*curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    'Authorization: Bearer ' . $this->api_key,
                    'Content-Type: application/json'
                ));
                    curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $this->api_key);*/
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3); // it would hang for 2 minutes even though the answer was already there?
                curl_setopt($curl, CURLOPT_POSTFIELDS, \http_build_query((object)array(
                    'client_id' => $this->config->app_id,
                    'client_secret' => $this->config->secret,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->config->redirect_uri,
                    'code' => $code,
                )));
                $result = curl_exec($curl);
                $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
                $return_value = json_decode($result);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->handleErrorAndStop(
                        sprintf('Instagram short lived token error, status %1$s, body %2$s', $status_code, var_export($return_value, true)),
                        sprintf(__('%s authorization error', 'peatcms'), 'Instagram')
                    );
                }
                if (false === isset($return_value->access_token) or false === isset($return_value->user_id)) {
                    $this->handleErrorAndStop(
                        var_export($return_value, true),
                        sprintf(__('%s authorization error', 'peatcms'), 'Instagram')
                    );
                }
                // short lived token response, also posts 'user_id' back
                $access_token_short_lived = $return_value->access_token;
                $user_id = $return_value->user_id;
                // request a longer lived token
                /*curl - i - X GET "https://graph.instagram.com/access_token
    ?grant_type=ig_exchange_token
    &client_secret={instagram-app-secret}
    &access_token={short-lived-access-token}"*/
                /* {
                     "access_token":"{long-lived-user-access-token}",
       "token_type": "bearer",
       "expires_in": 5183944  // Number of seconds until token expires
     }*/
                $curl = curl_init(); // start new curl request
                curl_setopt($curl, CURLOPT_URL,
                    'https://graph.instagram.com/access_token?grant_type=ig_exchange_token&client_secret=' .
                    urlencode($this->config->secret) . '&access_token=' . urlencode($access_token_short_lived)
                );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $result = curl_exec($curl);
                $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
                $return_value = json_decode($result);
                if (json_last_error() !== JSON_ERROR_NONE or false === isset($return_value->access_token)) {
                    $this->handleErrorAndStop(
                        sprintf('Instagram long lived token error, status %1$s, body %2$s', $status_code, var_export($return_value, true)),
                        __('Authorization error', 'peatcms')
                    );
                }
                // save it to the db by creating a new authorization entry
                $access_token = $return_value->access_token;
                $default_expires = $this->config->default_expires;
                $expires = isset($return_value->expires_in) ?
                    Help::getAsInteger($return_value->expires_in, $default_expires) : $default_expires;
                if ($instagram_auth_id = Help::getDB()->insertRowAndReturnKey('_instagram_auth', array(
                    'instance_id' => $contents->instance_id,
                    'user_id' => $user_id,
                    'access_token' => $access_token,
                    'access_token_expires' => date('Y-m-d G:i:s.u O', time() + $expires),
                    'access_granted' => true,
                ))) {
                    // update to set username on this authorization
                    try {
                        $curl = curl_init(); // start new curl request
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3); // it would hang for 2 minutes even though the answer was already there?
                        curl_setopt($curl, CURLOPT_URL,
                            'https://graph.instagram.com/' . $user_id . '?fields=username&access_token=' . urlencode($access_token)
                        );
                        $return_value = json_decode(curl_exec($curl));
                        Help::getDB()->updateColumns('_instagram_auth', array(
                            'instagram_username' => $return_value->username,
                        ), $instagram_auth_id);
                    } catch (\Exception $e) {
                        Help::addError($e);
                    }
                } else {
                    $this->handleErrorAndStop(
                        'Unable to update settings for Instagram authorization', __('Database error', 'peatcms')
                    );
                }
                // return the user to the correct instance
                $instance = Help::getDB()->fetchInstanceById($contents->instance_id);

                return array('redirect_uri' => 'https://' . $instance->domain . '/__admin__/instance/' . $instance->domain);
            } else {
                $this->handleErrorAndStop(
                    'Instagram authorization: no code received or locker was empty',
                    __('Could not finish authorization process', 'peatcms')
                );
            }
        } else { // nothing specific, just redirect to the authorization screen
            // setup a state key so you will know what to change when the user comes back to the redirect url
            if ($key = Help::getDB()->putInLocker(0)) {
                return array('redirect_uri' => 'https://api.instagram.com/oauth/authorize?client_id=' . $this->config->app_id . '&redirect_uri=' .
                    $this->config->redirect_uri . '&scope=user_profile,user_media&response_type=code&state=' . $key);
            } else {
                $this->addMessage(__('Could not start instagram authorization process', 'peatcms'), 'error');

                return null;
            }
        }

        return null; // clearly this has not worked out
    }

    /**
     * Deletes everything for a certain user_id, received in a signed request
     * https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
     * @param \stdClass $post_data
     * @return string
     * @since 0.7.3
     */
    public function delete(\stdClass $post_data): string
    {
        if (false === isset($post_data->signed_request)) {
            $this->handleErrorAndStop('Did not receive signed request for Instagram ‘delete’');
        }
        // NOTE this function can be called for any instance
        $data = $this->parse_signed_request($post_data->signed_request);
        $user_id = $data['user_id'];
        $confirmation_code = $user_id; // build a unique confirmation code for this request
        // set feeds with this user_id (possibly) to update by Job
        $confirmation_code .= '_' . Help::getDB()->invalidateInstagramFeedSpecsByUserId($user_id);
        // delete the media associated with this user_id
        $confirmation_code .= '_' . Help::getDB()->updateColumnsWhere('_instagram_media',
                array('deleted' => true),
                array('user_id' => $user_id)
            );
        // delete _instagram_auth by user_id
        $confirmation_code .= '_' . Help::getDB()->updateColumnsWhere('_instagram_auth',
                array('deleted' => true),
                array('user_id' => $user_id)
            );
        $confirmation_code .= '_' . time();

        return $confirmation_code;
    }

    /**
     *
     * @param $signed_request
     * @return mixed
     * @since 0.7.3
     */
    private function parse_signed_request($signed_request)
    {
        list($encoded_sig, $payload) = explode('.', $signed_request, 2);
        $secret = $this->config->secret; // Use your app secret here
        // decode the data
        $sig = $this->base64_url_decode($encoded_sig);
        $data = json_decode($this->base64_url_decode($payload), true);
        // confirm the signature
        $expected_sig = hash_hmac('sha256', $payload, $secret, $raw = true);
        if ($sig !== $expected_sig) {
            $this->handleErrorAndStop('(Instagram) Bad Signed JSON signature!');
        }

        return $data;
    }

    private function base64_url_decode($input)
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }

    /**
     * Get the cached feed by $feed_name, returns default feed if name not found or empty feed when there are none
     * @param string $feed_name
     * @return \stdClass
     * @since 0.7.2
     */
    public function feed(string $feed_name): \stdClass
    {
        // this is instance_specific and csrf has been checked...
        // a feed can contain a username and a hashtag, it will filter the hashtag (if present) and one username (if present)
        // media is cached in feed column if all is well
        if (!($feed = Help::getDB()->getInstagramFeed($feed_name))) {
            $this->addError(sprintf('Instagram feed ‘%s’ not found', $feed_name));
            // get default feed
            if (($rows = Help::getDB()->getInstagramFeedSpecs())) {
                if (count($rows) > 0 && isset($rows[0]->feed_name)) {
                    $feed = $rows[0];
                }
                $rows = null;
            } else {
                $feed = (object)array('feed_name' => __('No Instagram feed found', 'peatcms'));
            }
        }
        if (isset($feed->feed)) {
            $feed->__media__ = json_decode($feed->feed);
            unset($feed->feed);
        }
        $feed->slug = '__action__/instagram/feed/' . $feed_name;

        return $feed;
    }
}