<?php

declare(strict_types=1);

namespace Bloembraaden;

class Session extends BaseLogic
{
    // TODO the session currently saves user_agent only once, it is never updated, so a long running
    // TODO session can have an outdated user_agent string.
    private int $session_id;
    private ?string $token, $user_agent, $ip_address, $reverse_dns = null;
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
                # Convert back to a normalised string
                $this->ip_address = inet_ntop($binary);
            }
        }
        if (true === isset($_SERVER['HTTP_USER_AGENT']) && true === is_string($_SERVER['HTTP_USER_AGENT'])) {
            $this->user_agent = mb_convert_encoding($_SERVER['HTTP_USER_AGENT'], 'UTF-8', 'UTF-8');
        } else {
            $this->user_agent = 'UNKNOWN';
        }
        // check for cookie and create it if not present
        $this->token = $this->getCookie('BLOEMBRAADEN');
        if (null === $this->token || strlen($this->token) !== 32) {
            $this->token = $this->newSession();
            $this->setCookie('BLOEMBRAADEN', $this->token);
        }
        if (false === $this->load()) {
            // if loading fails, the cookie is wrong apparently, or old maybe, create a new session then
            $this->token = $this->newSession();
            $this->setCookie('BLOEMBRAADEN', $this->token);
            if (false === $this->load(false)) {
                // if that fails, handleErrorAndStop
                $this->handleErrorAndStop($this->getLastError(), __('Could not start session.', 'peatcms'));
            }
        }
        if (null === $this->getValue('csrf_token')) {
            $this->setVar('csrf_token', Help::randomString(9), 0);
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
        $session_id = $this->getId();
        foreach ($updated_vars as $name => $var) {
            if (null === $var) $var = (object)array('delete' => true);
            Help::getDB()->updateSessionVar($session_id, $name, $var);
        }
        // register reverse dns when not already present
        if (null === $this->reverse_dns) {
            $reverse_dns = gethostbyaddr($this->ip_address);
            Help::getDB()->updateColumns('_session', array('reverse_dns' => $reverse_dns), $this->token);
        }
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
            if (true === $this->setCookie('BLOEMBRAADEN', $new_token)) { // the new cookie SHOULD now reach the client
                // @since 0.7.9: merge shoppinglists
                if (isset($columns_to_update['user_id']) && ($user_id = \intval($columns_to_update['user_id'])) > 0) {
                    $affected = Help::getDB()->mergeShoppingLists($this->session_id, $user_id);
                }
                $this->token = $new_token;
                // TODO update csrf token as well here?
            } else {
                $this->handleErrorAndStop(
                    sprintf('setCookie returned false after logging in with %s', var_export($columns_to_update, true)),
                    __('Session lost, token could not be set.', 'peatcms')
                );
            }
        } else {
            $this->handleErrorAndStop(
                sprintf('updateColumns returned false after logging in with %s', var_export($columns_to_update, true)),
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

    /**
     * @param bool $register_access whether you want this action to register as access the session as well
     * @return bool true when successfully loaded, false when failed
     */
    public function load(bool $register_access = true): bool
    {
        // get all the stuff from the database
        if ($session_row = Help::getDB()->fetchSession($this->token)) {
            // get user
            if ($session_row->user_id > 0) {
                $this->user = new User($session_row->user_id);
                // this user is gone:
                if ($this->user->getId() === 0) {
                    $this->user = null; // attention this is not auto-updated in the db for this session
                }
            }
            // get admin
            if ($session_row->admin_id > 0) {
                $this->admin = new Admin($session_row->admin_id);
                // if admin no longer exists
                if ($this->admin->getId() === 0) {
                    $this->admin = null; // attention this is not auto-updated in the db for this session
                } elseif (false === $this->admin->isRelatedInstanceId(Setup::$instance_id)) {
                    $this->admin = null; // attention this is not auto-updated in the db for this session
                }
            }
            // get vars
            if (isset($session_row->vars)) {
                $this->vars = $session_row->vars;
            }
            $this->session_id = $session_row->session_id; // must always be present, this is NOT the token, just an int for identifying internally
            if (true === $register_access) {
                if ($this->ip_address !== $session_row->ip_address) {
                    Help::getDB()->registerSessionAccess($this->token, $this->ip_address);
                    $this->reverse_dns = null;
                } else {
                    Help::getDB()->registerSessionAccess($this->token);
                    $this->reverse_dns = $session_row->reverse_dns;
                }
            }

            return true;
        } else {
            //$this->addError('Session->load(): Could not get session from Database.');

            return false;
        }
    }

    private function getCookie(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }

    private function setCookie(string $name, string $value): bool
    {
        // returns false when there's already been output, true when it successfully runs
        return setCookie($name, $value, array(
            'expires' => time() + 31536000,
            'path' => '/',
            'domain' => '',
            'secure' => true, // or false
            'httponly' => true, // or false
            'samesite' => 'Lax' // None || Lax  || Strict <- strict breaks session after payment and other incoming links
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