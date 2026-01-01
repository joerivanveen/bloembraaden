<?php

declare(strict_types=1);

namespace Bloembraaden;

class Session extends BaseLogic
{
    private int $session_id;
    private ?string $token, $user_agent, $ip_address;
    private Instance $instance;
    private ?User $user;
    private ?Admin $admin;
    private array $vars = array(), $vars_updated = array();

    public function __construct(Instance $I)
    {
        parent::__construct();
        $this->type_name = 'session';
        //
        $this->admin = null;
        $this->user = null;
        // get instance
        $this->instance = $I;
        unset($I);
        // remember client data
        if (
            false === isset($_SERVER['REMOTE_ADDR'])
            || null === ($remote_addr = $_SERVER['REMOTE_ADDR'])
        ) {
            $this->handleErrorAndStop('Cannot serve pages to people without ip address');
        } else {
            $binary = inet_pton($remote_addr);
            if ($binary === false) {
                $this->handleErrorAndStop('Cannot serve pages to people without ip address');
            } else {
                // Convert back to a normalised string
                $this->ip_address = inet_ntop($binary);
            }
        }
        if (true === isset($_SERVER['HTTP_USER_AGENT']) && true === is_string($_SERVER['HTTP_USER_AGENT'])) {
            $this->user_agent = mb_convert_encoding($_SERVER['HTTP_USER_AGENT'], 'UTF-8', 'UTF-8');
        } else {
            $this->user_agent = 'UNKNOWN';
        }
        // check for cookie and create it if not present
        $this->token = $this->getSessionCookie();
        if (null === $this->token || strlen($this->token) !== 32) {
            $this->token = $this->newSession();
            $this->setSessionCookie($this->token);
        }
        if (false === $this->load()) {
            // if loading fails, the cookie is wrong apparently, or old maybe, create a new session then
            $this->token = $this->newSession();
            $this->setSessionCookie($this->token);
            if (false === $this->load()) {
                // if that fails, handleErrorAndStop
                $this->handleErrorAndStop($this->getLastError(), __('Could not start session.', 'peatcms'));
            }
        }
        if (null === $this->getValue('csrf_token')) {
            $this->setVar('csrf_token', Help::randomString(9), 0);
        }
        if (null === $this->getValue('umami_identifier')) {
            $this->setVar('umami_identifier', Help::randomString(12), 0);
        }
        // get lingering messages (if any)
        if (null !== ($messages_as_json = $this->getValue('peatcms_messages', true))) {
            if ($messages = json_decode($messages_as_json)) {
                foreach ($messages as $index => $message) {
                    $this->addMessage(
                        $message->message,
                        $message->level
                    );
                }
            }
        }
        // @since 0.6.17 moved __destruct to register_shutdown_function (way more stable)
        register_shutdown_function(array($this, '__shutdown'));
    }

    /**
     * Remember messages and save updated vars to database for next request
     */
    public function __shutdown(): void
    {
        // save lingering messages for later use
        if (Help::hasMessages()) {
            $this->setVar('peatcms_messages', Help::getMessagesAsJson(), 0);
        }
        // update session vars in database
        $updated_vars = $this->getUpdatedVars();
        $own_variables = array();
        if (0 < count($updated_vars)) {
            $own_variables['date_updated'] = 'NOW()';
        }
        $session_id = $this->getId();
        foreach ($updated_vars as $name => $var) {
            if (null === $var) $var = (object)array('delete' => true);
            Help::getDB()->updateSessionVar($session_id, $name, $var);
        }
        $updated_vars = null; // free memory
        // update own vars (from row) in database
        if ($this->user_agent !== $this->row->user_agent) {
            $own_variables['user_agent'] = $this->user_agent;
        }
        if ($this->ip_address !== $this->row->ip_address) {
            $own_variables['ip_address'] = $this->ip_address;
            $own_variables['reverse_dns'] = null; // to be filled by job
        }
        Help::getDB()->registerSessionAccess($this->token, $own_variables);
    }


    /**
     * by deleting a session someone can effectively logout
     * @return bool success
     * @since 0.6.11
     */
    public function delete(): bool
    {
        // set the session to deleted in the db
        if (true === Help::getDB()->updateColumns('_session', array('deleted' => true), $this->token)) {
            // @since 0.7.9 remove remnants of the session from this instance
            $this->admin = null;
            $this->user = null;

            return $this->refreshAfterLogin(array('user_id' => 0));
        }

        return false;
    }

    public function login(string $email, string $pass, bool $as_admin): bool
    {
        if (($row = Help::getDB()->fetchForLogin($email, $as_admin))) {
            if (true === password_verify($pass, $row->hash)) {
                if (false === $as_admin) {
                    if (($this->user = new User($row->id))) {
                        return $this->refreshAfterLogin(array('user_id' => $row->id));
                    }
                } elseif (($this->admin = new Admin($row->id))) {
                    return $this->refreshAfterLogin(array('admin_id' => $row->id));
                }
                $this->addError("Email $email checks out in password_verify(), but no user is found.");
                $this->addMessage(__('Name / pass checks out, but no user found.', 'peatcms'), 'error');
            }
        }
        $this->addMessage(__('Name / pass combination unknown.', 'peatcms'), 'warn');
        // delay based on user input against timing attacks
        usleep(abs(crc32("pseudorandomstr1ng$email$pass") % 1000));

        return false;
    }

    public function isAdmin(): bool
    {
        return ($this->admin instanceof Admin);
    }

    public function refreshAfterLogin(array $columns_to_update): bool
    {
        // $columns_to_update holds the user_id or admin_id that you must update in the _session table
        $new_token = $this->generateToken();
        $columns_to_update['token'] = $new_token; // also update the token
        if (true === Help::getDB()->updateColumns('_session', $columns_to_update, $this->token)) {
            if (true === $this->setSessionCookie($new_token)) { // the new cookie SHOULD now reach the client
                // @since 0.7.9: merge shoppinglists
                if (true === isset($columns_to_update['user_id'])
                    && 0 < ($user_id = (int) $columns_to_update['user_id'])
                ) {
                    $affected = Help::getDB()->mergeShoppingLists($this->session_id, $user_id);
                }
                $this->token = $new_token;
                // TODO update csrf token as well here?
            } else {
                $this->handleErrorAndStop(
                    sprintf('setCookie returned false after logging in with %s.', var_export($columns_to_update, true)),
                    __('Session lost, token could not be set.', 'peatcms')
                );
            }
        } else {
            $this->handleErrorAndStop(
                sprintf('updateColumns returned false after logging in with %s.', var_export($columns_to_update, true)),
                __('Session lost, token could not be set.', 'peatcms')
            );
        }

        return true;
    }

    /**
     * @param string $name the name of the variable you want to get
     * @param bool $with_remove default false, when true removes the variable from session immediately
     * @return \stdClass|null the original session variable including the times
     * @since 0.1.0, @since 0.5.12 it returns the whole var including the times
     */
    public function getVar(string $name, bool $with_remove = false): ?\stdClass
    {
        if (isset($this->vars[$name])) {
            $var = $this->vars[$name]; // mixed
            if (true === $with_remove) $this->delVar($name);

            return $var;
        } else {
            return null;
        }
    }

    /**
     * @param string $name the name of the variable you want to get the value for
     * @param bool $with_remove default false, when true removes the variable from session immediately
     * @return mixed|null the value you put into it in the first place
     * @since 0.5.12
     */
    public function getValue(string $name, bool $with_remove = false): mixed
    {
        if (($var = $this->getVar($name, $with_remove))) {
            return $var->value;
        }
        return null;
    }

    /**
     * @return array all the session variables in a named array
     */
    public function getVars(): array
    {
        return $this->vars;
    }

    public function getValues(): array
    {
        $values = array();
        foreach ($this->vars as $key => $var) {
            $values[$key] = $var->value;
        }
        return $values;
    }

    /**
     * @return array named array holding session variables that were updated during this request
     * @since 0.6.1
     */
    public function getUpdatedVars(): array
    {
        $return_array = array();
        foreach ($this->vars_updated as $index => $name) {
            $return_array[$name] = $this->getVar($name);
        }

        return $return_array;
    }

    /**
     * NOTE the times parameter works as follows, while on the server you can update and the times will stay the same
     * but javascript will add 1 to times before storing client-side and sending the value, this works both ways, it will
     * not update if it receives an older value, and also here you shouldn’t update if you receive an older value
     *
     * @param string $name the name of the variable, overwrites existing value if present silently
     * @param mixed $value can be of any type, will be jsonencoded by DB class for persistent storage
     * @param int $times @since 0.5.12 default 0: when updating without $times or with 0 the current value is maintained
     */
    public function setVar(string $name, mixed $value, int $times = 0): void
    {
        if (true === isset($this->vars[$name]) && ($var = $this->vars[$name])) {
            if ($var->value === $value) return;
            if (0 === $times) {
                $times = $var->times;
            } elseif ($var->times > $times) {
                return; // don’t update if the current value is newer
            }
        }
        $this->vars[$name] = (object)array('value' => $value, 'times' => $times);
        // @since 0.6.1 remember updated vars
        $this->vars_updated[] = $name;
    }

    /**
     * @param string $name
     * @return void
     */
    public function delVar(string $name): void
    {
        unset($this->vars[$name]);
        // remember to delete this on shutdown
        $this->vars_updated[] = $name;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getAdmin(): ?Admin
    {
        return $this->admin;
    }

    public function getIpAddress(): string
    {
        return $this->ip_address;
    }

    public function getUserAgent(): string
    {
        return $this->user_agent;
    }

    public function getInstance(): Instance
    {
        return $this->instance;
    }

    public function getId(): int
    {
        return $this->session_id;
    }

    public function getUpdatedTimestamp()
    {
        return Help::strtotime_ms($this->row->date_updated);
    }

    /**
     * @return bool true when successfully loaded, false when failed
     */
    public function load(): bool
    {
        // get all the stuff from the database
        if (($this->row = $row = Help::getDB()->fetchSession($this->token))) {
            // get user
            if ($row->user_id > 0) {
                $this->user = new User($row->user_id);
                // this user is gone:
                if ($this->user->getId() === 0) {
                    return false; // will log out automatically
                }
            }
            // get admin
            if ($row->admin_id > 0) {
                $this->admin = new Admin($row->admin_id); // throws error and stops when admin does not exist
                if (false === $this->admin->isRelatedInstanceId(Setup::$instance_id)) {
                    return false; // will log out automatically
                }
            }
            // get vars
            if (isset($row->vars)) {
                $this->vars = $row->vars;
            }
            $this->session_id = $row->session_id; // must always be present, this is NOT the token, just an int for identifying internally

            return true;
        } else {
            //$this->addError('Session->load(): Could not get session from Database.');

            return false;
        }
    }

    private function getSessionCookie(): ?string
    {
        return $_COOKIE['BLOEMBRAADEN'] ?? null;
    }

    private function setSessionCookie(string $value): bool
    {
        // returns false when there's already been output, true when it successfully runs
        return setCookie('BLOEMBRAADEN', $value, array(
            'expires' => time() + 31536000,
            'path' => '/',
            'domain' => '',
            'secure' => true, // or false
            'httponly' => true, // or false
            'samesite' => 'None' // None || Lax  || Strict <- potentially breaks session after payment and other incoming links
        ));
    }

    /**
     * Creates session in database, returns the token on success, halts execution on failure
     *
     * @return string the session token
     */
    private function newSession(): string
    {
        // get fresh token, insert a new session row in database, keep trying until success (meaning the token is unique)
        if (null === ($token = Help::getDB()->insertSession($this->generateToken(), $this->ip_address, $this->user_agent))) {
            if (null === ($token = Help::getDB()->insertSession($this->generateToken(), $this->ip_address, $this->user_agent))) {
                // when failed twice there is something very fishy going on
                $this->handleErrorAndStop(Help::getDB()->getLastError(),
                    __('Unable to create unique session id.', 'peatcms'));
            }
        }

        return $token;
    }

    private function generateToken(): string
    {
        // generate a 256 bit (32 character) long cryptographically secure random string
        return Help::randomString(32);
    }
}