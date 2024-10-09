<?php
declare(strict_types=1);

namespace Bloembraaden;

class Instance extends BaseLogic
{
    private array $menus;

    public function __construct(\stdClass $row = null)
    {
        parent::__construct();
        $this->type_name = 'instance';
        if (null === $row) {
            if (
                false === isset($_SERVER['HTTP_HOST'])
                || null === ($host = $_SERVER['HTTP_HOST'])
            ) {
                $this->handleErrorAndStop('Cannot serve pages without HTTP_HOST header');
            } else {
                $this->load($host);
            }
        } else {
            $this->row = $row;
        }
    }

    public function getId(): int
    {
        return (int)$this->row->instance_id;
    }

    public function getHomepageId(): int
    {
        return (int)$this->row->homepage_id;
    }

    public function getClientId(): ?int
    {
        if (isset($this->row->client_id)) {
            return $this->row->client_id;
        }

        return null;
    }

    public function getName(): string
    {
        return (string)$this->row->name;
    }

    public function getDomain(bool $includeProtocol = false): string
    {
        if (true === $includeProtocol) return "https://{$this->row->domain}";
        return $this->row->domain;
    }

    public function getDefaultSrc(): string
    {
        return $this->row->csp_default_src;
    }

    public function fetchDefaultSrc(): string
    {
        return implode(' ', $this->computeDefaultSrc());
    }

    private function computeDefaultSrc(): array {
        $sources = array();
        if ($this->getSetting('turnstile_site_key')) {
            $sources[] = 'https://challenges.cloudflare.com';
        }
        if ($this->getSetting('google_tracking_id') || $this->getSetting('recaptcha_site_key')) {
            $sources[] = 'https://www.google.com';
        }
        // get everything from the embeds...
        $statement = Help::getDB()->queryAllRows('cms_embed', array('embed_code'), $this->getId());
        while (($row = $statement->fetch(5))) {
            if (null === $row->embed_code) continue;
            $parts = explode('https://', $row->embed_code);
            if (isset($parts[1])) {
                $src = explode('/', $parts[1])[0];
                if ('' !== $src && false === in_array("https://$src", $sources)) {
                    $sources[] = "https://$src";
                }
            }
        }

        return $sources;
    }

    public function completeRowForOutput(): void
    {
        // TODO domains and admins should get the same lazy loading construction as menus
        if (false === isset($this->row->__domains__)) {
            $this->row->__domains__ = Help::getDB()->fetchInstanceDomains($this->getId()); // db only returns the rows, customarily
        }
        if (false === isset($this->row->__admins__)) {
            $this->row->__admins__ = Help::getDB()->fetchInstanceAdmins($this->getId()); // db only returns the rows, customarily
        }
        if (false === isset($this->row->__payment_service_providers__)) {
            $this->row->__payment_service_providers__ = Help::getDB()->fetchInstancePsps($this->getId()); // db only returns the rows, customarily
        }
        if (false === isset($this->row->__vat_categories__)) {
            $this->row->__vat_categories__ = Help::getDB()->fetchInstanceVatCategories($this->getId()); // db only returns the rows, customarily
        }
        Help::prepareAdminRowForOutput($this->row, 'instance', $this->getDomain());
    }

    public function getMenus(): array
    {
        if (false === isset($this->menus)) {
            $this->menus = Help::getDB()->fetchInstanceMenus($this->getId());
        }

        return $this->menus;
    }

    /**
     * @return PaymentServiceProvider|null
     * @since 0.6.2
     * @since 0.7.6 return child class of the correct type
     */
    public function getPaymentServiceProvider(): ?PaymentServiceProvider
    {
        if (($psp_id = $this->getSetting('payment_service_provider_id')) > 0) {
            if (($row = Help::getDB()->getPaymentServiceProviderRow($psp_id))) {
                if (class_exists(($class_name = __NAMESPACE__ . '\\' . ucfirst($row->provider_name)))) {
                    return new $class_name($row);
                }
            }
        }

        return null;
    }

    // global stuff accessible from outside
    public function getPresentationInstance(): string
    {
        return (string)$this->row->presentation_instance;
    }

    /**
     * @param string $which the name of the setting you wish to get
     * @param null $default will be returned when the setting is not present
     * @return null|mixed returns the original value (@since 0.7.6)
     * @since 0.4.0
     */
    public function getSetting(string $which, $default = null)
    {
        return $this->row->{$which} ?? $default;
    }

    public function isParked(): bool
    {
        return $this->row->park ?? false;
    }

    /**
     * Loads the instance based on domain accessed
     * When this fails execution is halted, hence no return value is needed
     *
     * @param string $domain
     */
    private function load(string $domain): void
    {
        // load the instance based on the supplied host
        if (!($this->row = Help::getDB()->fetchInstance($domain))) {
            // try to find alternative hosts to provide a 301 redirect
            if (($canonical = Help::getDB()->fetchInstanceCanonicalDomain($domain))) {
                // @since 0.7.1 also supply the originally requested uri...
                header('Location: https://' . $canonical . urldecode($_SERVER['REQUEST_URI']), true, 301);
                die();
            } else {
                // @since 0.10.2 no more error reporting for these kinds of requests
                if (Setup::$VERBOSE) $this->addError("No instance found for domain $domain");
                die('Bloembraaden.io');
            }
        }
    }
}