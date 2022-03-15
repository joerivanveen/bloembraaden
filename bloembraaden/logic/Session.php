<?php

namespace Peat;
class Session extends BaseLogic
{
    // TODO the session currently saves user_agent only once, it is never updated, so a long running
    // TODO session can have an outdated user_agent string.
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
        $this->ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $this->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        // check for cookie and create it if not present
        $this->token = $this->getCookie('BLOEMBRAADEN');
        if (!$this->token || strlen($this->token) !== 32) {
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
        if (!$this->getValue('csrf_token')) $this->setVar('csrf_token', Help::randomString(9), 0);
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
        register_shutdown_function(array(&$this, '__shutdown'));
    }

    /**
     * __destruct this class on shutdown
     */
    public function __shutdown()
    {
        // save lingering messages to db for later use
        if (Help::hasMessages()) {
            $this->setVar('peatcms_messages', Help::getMessagesAsJson(), 0);
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
        if (true === $this->getDB()->updateColumns('_session', array('deleted' => true), $this->token)) {
            // @since 0.7.9 remove remnants of the session from this instance
            $this->admin = null;
            $this->user = null;

            return $this->refreshAfterLogin(array('user_id' => 0));
        }

        return false;
    }

    public function login(string $email, string $pass, bool $as_admin): bool
    {
        // failed login should always take exactly 2 seconds
        $start = round(microtime(true) * 1000);
        if (($row = $this->getDB()->fetchForLogin($email, $as_admin))) {
            if (password_verify($pass, $row->hash)) {
                if (false === $as_admin) {
                    if ($this->user = new User($row->id)) {
                        return $this->refreshAfterLogin(array('user_id' => $row->id));
                    }
                } else {
                    if ($this->admin = new Admin($row->id)) {
                        return $this->refreshAfterLogin(array('admin_id' => $row->id));
                    }
                }
                $this->addError(sprintf('Email %s checks out in password_verify(), but no user is found.', $email));
                $this->addMessage(__('Name / pass checks out, but no user found.', 'peatcms'), 'error');
            }
        }
        $this->addMessage(__('Name / pass combination unknown.', 'peatcms'), 'warn');
        // failed login should always take 2 seconds
        $time_taken = round(microtime(true) * 1000) - $start;
        usleep(2000000 - $time_taken * 1000);

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
        if ($this->getDB()->updateSession($this->token, $columns_to_update)) {
            if ($this->setCookie('BLOEMBRAADEN', $new_token)) { // the new cookie SHOULD now reach the client
                // @since 0.7.9: merge shoppinglists
                if (isset($columns_to_update['user_id']) && ($user_id = \intval($columns_to_update['user_id'])) > 0) {
                    $affected = $this->getDB()->mergeShoppingLists($this->session_id, $user_id);
                }
                $this->token = $new_token;
                // TODO update csrf token as well here?
            } else {
                $this->handleErrorAndStop(
                    sprintf('setCookie returned false after logging in with %s', var_export($columns_to_update, true)),
                    __('Session lost, token could not be set.', 'peatcms')
                );
            }
        }

        return true;
    }

    /**
     * @param string $name the name of the variable you want to get
     * @param bool $with_remove default false, use true if you want to remove the variable from session immediately
     * @return mixed|null the value you put into it in the first place
     * @since 0.1.0, @since 0.5.12 it returns the whole var including the times
     */
    public function getVar(string $name, bool $with_remove = false)
    {
        if (isset($this->vars[$name])) {
            $var = $this->vars[$name]; // mixed
            if ($with_remove) $this->delVar($name);

            return $var;
        } else {
            return null;
        }
    }

    /**
     * @param string $name the name of the variable you want to get
     * @param bool $with_remove default false, use true if you want to remove the variable from session immediately
     * @return mixed|null the value you put into it in the first place
     * @since 0.5.12
     */
    public function getValue(string $name, bool $with_remove = false)
    {
        if (isset($this->vars[$name])) {
            $var = $this->vars[$name]->value; // mixed
            if ($with_remove) $this->delVar($name);

            return $var;
        } else {
            return null;
        }
    }

    /**
     * @return array all the session variables in a named array
     */
    public function getVars(): array
    {
        return $this->vars;
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
    public function setVar(string $name, $value, int $times = 0)
    {
        if (isset($this->vars[$name]) && ($var = $this->vars[$name])) {
            if ($var->value === $value) return;
            if ($times === 0) {
                $times = $var->times;
            } elseif ($var->times > $times) {
                return; // don’t update if the current value is newer
            }
        }
        // @since 0.5.13 update immediately since __destruct has proven not to work
        $this->vars[$name] = $this->getDB()->updateSessionVar($this->getId(), $name, (object)array('value' => $value, 'times' => $times));
        // @since 0.6.1 remember updated vars to update on the client as well
        $this->vars_updated[] = $name;
    }

    public function delVar($name)
    {
        if (! isset($this->vars[$name])) return;
        // @since 0.5.13 update immediately since __destruct has proven not to work
        $this->vars[$name]->delete = true;
        if (null === $this->getDB()->updateSessionVar($this->getId(), $name, $this->vars[$name])) {
            unset($this->vars[$name]);
        }
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getAdmin(): ?Admin
    {
        return $this->admin;
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
        if ($s = $this->getDB()->fetchSession($this->token)) {
            // get user
            if ($s->user_id > 0) {
                $this->user = new User($s->user_id);
                // this user is gone:
                if ($this->user->getId() === 0) {
                    $this->user = null; // attention this is not auto-updated in the db for this session
                }
            }
            // get admin
            if ($s->admin_id > 0) {
                $this->admin = new Admin($s->admin_id);
                // if admin no longer exists
                if ($this->admin->getId() === 0) {
                    $this->admin = null; // attention this is not auto-updated in the db for this session
                } elseif (false === $this->admin->isRelatedInstanceId(Setup::$instance_id)) {
                    $this->admin = null; // attention this is not auto-updated in the db for this session
                }
            }
            // get vars
            if (isset($s->vars)) {
                $this->vars = $s->vars;
            }
            $this->session_id = $s->session_id; // must always be present, this is NOT the token, just an int for identifying internally
            if (true === $register_access) {
                if ($this->ip_address !== $s->ip_address) {
                    $this->getDB()->registerSessionAccess($this->token, $this->ip_address);
                } else {
                    $this->getDB()->registerSessionAccess($this->token);
                }
            }

            return true;
        } else {
            $this->addError('Session->load(): Could not get session from Database.');

            return false;
        }
    }

    private function getCookie(string $name): ?string
    {
        if (isset($_COOKIE[$name])) {
            return $_COOKIE[$name];
        }

        return null;
    }

    private function setCookie(string $name, string $value): bool
    {
        // returns false when there's already been output, true when it successfully runs
        return setCookie($name, $value, array(
            'expires' => time() + 31536000,
            'path' => '/',
            'domain' => '',
            'secure' => true,     // or false
            'httponly' => true,    // or false
            'samesite' => 'Lax' // None || Lax  || Strict <- strict breaks session after payment and other incoming links... BAD
        ));
        //time() + 31536000, '/', '', true, true);
    }

    /**
     * Creates session in database, returns the token on success, halts execution on failure
     *
     * @return string the session token
     */
    private function newSession(): string
    {
        // get fresh token, insert a new session row in database, keep trying until success (meaning the token is unique)
        if (!($token = $this->getDB()->insertSession($this->generateToken(), $this->ip_address, $this->user_agent))) {
            if (!($token = $this->getDB()->insertSession($this->generateToken(), $this->ip_address, $this->user_agent))) {
                // when failed twice there is something very fishy going on
                $this->handleErrorAndStop($this->getDB()->getLastError(),
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