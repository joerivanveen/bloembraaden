<?php

namespace Peat;
// if there's one entry in route, just look for that exact match anywhere in slugs
// if there's two this can be a property name/value (rather unlikely) or brand/series (or anything else really)
// or two or more: brand/series/product or brand/series/variant or brand/series/variant/productcode
// or it can be a filter by property: brand/name/value or even brand/series/name/value etc. etc.
// or even multiple properties name/value/name/value etc. (all items made of wood that are red for instance)
// there are a couple of standard commands that differ from normal handling:
// - __admin__
// - __action__
// - __shoppinglist__
// - __order__
// - __user__
class Resolver extends BaseLogic
{
    private ?\stdClass $post_data;
    private array $terms, $properties, $instructions = array();
    private string $path, $render_in_tag;
    private int $variant_page;

    public function __construct(string $request_uri, int $instance_id)
    {
        parent::__construct();
        if (Setup::$instance_id !== $instance_id) {
            $row = Help::getDB()->fetchInstanceById($instance_id);
            //Setup::$instance_id = $instance_id;
            Setup::loadInstanceSettings(new Instance($row));
        }
        $GLOBALS['slugs'] = new \stdClass;
        // urldecode means get utf-8 characters of requested path + querystring
        // remove uppercase through db
        // TODO: if we are certain slugs / urls are always (correct) lowercase, we can remove the conversion from DB methods
        $request_uri = Help::getDB()->toLower(strip_tags(urldecode($request_uri)));
        // deconstruct path + querystring
        $uri = (array)explode('?', $request_uri);
        $src = array();
        if (count($uri) > 1) $src = explode('&', $uri[1]);
        $uri = $uri[0];
        if ($uri === '/') { // homepage is requested, get the slug so it can be retrieved from cache further down
            $uri = array(Help::getDB()->fetchHomeSlug($instance_id));
        } else {
            $uri = array_values(array_filter(explode('/', $uri), function ($value) {
                if ('' === $value) {
                    return false;
                } elseif (substr($value, 0, 2) === '__') {
                    $this->instructions[str_replace('__', '', $value)] = true;

                    return false;
                } elseif (substr($value, 0, 12) === 'variant_page') {
                    if (!isset($this->variant_page)) {
                        $variant_page = (int)substr($value, 12);
                        $this->variant_page = ($variant_page < 1) ? 1 : $variant_page;
                    }

                    return false;
                }

                return true;
            }));
        }
        // 0.5.3 / if you change this, check if the templates can still be saved
        $this->post_data = json_decode(file_get_contents('php://input'));
        if (json_last_error() === JSON_ERROR_NONE) {
            $output_json = true; // $this->post_data->json; <- if you receive json you can bet it wants json back
        } else {
            $this->post_data = (object)json_decode(json_encode($_POST)); // assume form data
            if (isset($_POST['json']) and $_POST['json'] === '1') {
                $output_json = true;
            } else {
                $output_json = false;
            }
        }
        // since 0.5.10: possibility to render returned element in a different tag than its slug
        if (isset($this->post_data->render_in_tag)) $this->render_in_tag = $this->post_data->render_in_tag;
        // remember for everyone, only needed when output is imminent:
        if (true === $output_json and false === defined('OUTPUT_JSON')) Define('OUTPUT_JSON', true);
        // special case action, may exist alongside other instructions, doesn't necessarily depend on uri[0]
        if (isset($this->instructions['action'])) {
            if (count($uri) > 0) {
                $this->instructions['action'] = htmlentities($uri[0]); // always escape user generated input
            } else {
                $this->instructions['action'] = 'ok';
            }
        } elseif (isset($this->post_data->action)) {
            $this->instructions['action'] = htmlentities($this->post_data->action);
        }
        // for fileupload...
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $this->post_data->csrf_token = urldecode($_SERVER['HTTP_X_CSRF_TOKEN']);
        // cleanup terms and properties
        $this->properties = array();
        // $src holds the properties (filters), and possibly other things from the query string,
        // normalize it
        foreach ($src as $key => $value) {
            $values = explode('=', $value);
            if (isset($values[1])) $this->addProperty($values[0], explode(',', $values[1]));
        }
        // remove properties (filters) from path and add them to query
        foreach ($uri as $index => $value) {
            if (strpos($value, ':') === false) continue;
            $values = explode(':', $value);
            $this->addProperty($values[0], explode(',', $values[1]));
            unset($uri[$index]);
        }
        $this->terms = array_values($uri); // $terms are the (now lowercase) uri parts separated by / (forward slash)
    }

    public function __destruct()
    {
        parent::__destruct();
        unset($this->post_data);
    }

    public function getPostData()
    {
        return $this->post_data;
    }

    public function getAction(): ?string
    {
        if (isset($this->instructions['action']))
            return $this->instructions['action'];

        return null;
    }

    public function hasInstructions(): bool
    {
        return (isset($this->instructions) && count($this->instructions) > 0);
    }

    public function hasInstruction(string $instruction): bool
    {
        return isset($this->instructions[$instruction]);
    }

    public function getVariantPage(): int
    {
        return $this->variant_page ?? 1;
    }

    private function addProperty($name, array $values): void
    {
        if (false === isset($this->properties[$name])) $this->properties[$name] = array();
        foreach ($values as $key => $value) {
            $this->properties[$name][] = $value;
        }
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getTerms(): array
    {
        return $this->terms;
    }

    public function getRenderInTag(): ?string
    {
        return $this->render_in_tag ?? null;
    }

    public function getElement(?bool &$from_history, ?Session $session = null, ?bool $no_cache = false): BaseLogic
    {
        $from_history = false;
        //if (isset($this->element)) return $this->element; // caching is unneeded, only called once per request
        // TODO it is a bad sign that session has to be sent to this method
        // TODO optimize this, probably move it to its own method
        // run over the instructions (skipped for regular urls)
        foreach ($this->instructions as $instruction => $value) {
            if (null === $session) $this->handleErrorAndStop('Resolver->getElement() cannot get instructions-element with session = null');
            // btw, instructions may not be cached
            switch ($instruction) {
                case 'admin':
                    if ($session->isAdmin()) {
                        if ($element = $this->getAdminElement($session, $this->getTerms())) {
                            return $element;
                        }
                    } else {
                        return new BaseElement((object)array(
                            'title' => 'Admin login',
                            'template_pointer' => (object)array('name' => 'login', 'admin' => true),
                            'type' => 'page',
                            'slug' => '__admin__',
                        ));
                    }
                    break;
                case 'shoppinglist':
                    return new Shoppinglist($this->getTerms()[0], $session);
                case 'user':
                    if (null === ($user = $session->getUser())) {
                        return new BaseElement((object)array(
                            'title' => __('User', 'peatcms'),
                            'type' => 'user',
                            'slug' => '__user__',
                        ));
                    } else {
                        return $user;
                    }
                case 'instances':
                    return new Instance();
                case 'order':
                    if ($session->isAdmin()) {
                        // you can only get orders for the current instance so no need to check the admin further
                        if (isset($this->getTerms()[0])) {
                            $src = '%' . $this->getTerms()[0]; // look up by last characters
                            // look for (multiple) orders
                            $orders = Help::getDB()->fetchElementRowsWhere(
                                new Type('order'),
                                array('order_number' => $src),
                            );
                            $slug = '__order__/' . $this->getTerms()[0];
                        } else {
                            // all the orders (with paging)
                            $page = $this->getProperties()['page'][0] ?? 1;
                            $page_size = 250;
                            $peat_type = new Type('order');
                            $orders = Help::getDB()->fetchElementRowsPage($peat_type, $page, $page_size);
                            $slug = '__order__/page:' . $page;
                            $pages = Help::getDB()->fetchElementRowsPageNumbers($peat_type, $page_size);
                        }
                        if (count($orders) === 1) {
                            $order = new Order($orders[0]);

                            return new BaseElement((object)array(
                                'title' => __('Order detail', 'peatcms'),
                                'template_pointer' => (object)array('name' => 'order', 'admin' => true),
                                'type' => 'order',
                                'order_id' => $order->getId(),
                                'slug' => '__order__/' . $order->getOrderNumber(),
                                '__order__' => array($order->getOutput()),
                            ));
                        } else {
                            return new BaseElement((object)array(
                                'title' => __('Order overview', 'peatcms'),
                                'template_pointer' => (object)array('name' => 'order_overview', 'admin' => true),
                                'type' => 'search',
                                'slug' => $slug,
                                '__orders__' => $orders,
                                '__pages__' => $pages ?? array(),
                            ));
                        }
                    }
            }
        }
        // resolve
        $type_name = 'search';
        $element_id = 0;
        $terms = $this->getTerms();
        $num_terms = count($terms);
        if (0 === $num_terms) { // homepage is requested
            $type_name = 'page';
            $element_id = $session->getInstance()->getHomepageId();
        } elseif (1 === $num_terms) {
            // find element by slug
            if (null !== ($row = Help::getDB()->fetchElementIdAndTypeBySlug($terms[0], $no_cache))) {
                $element_id = (int)$row->id;
                $type_name = (string)$row->type;
            } else {
                // try to get it from history
                if ($slug = Help::getDB()->getCurrentSlugBySlug($terms[0])) {
                    if (null !== ($row = Help::getDB()->fetchElementIdAndTypeBySlug($slug, $no_cache))) {
                        $element_id = (int)$row->id;
                        $type_name = (string)$row->type;
                        $from_history = true;
                    }
                }
            }
        }
        $peat_type = new Type($type_name);
        $element = $peat_type->getElement();
        // null means element is deleted or something, perform a default search
        if (null === $element->fetchById($element_id)) {
            $element = new Search;
        }
        // load the properties, to be used by filters
        $element->setProperties($this->getProperties());
        // if it’s a search go do that
        if ($element instanceof Search) {
            $element->find($this->getTerms());
        }

        //$this->element = $element;
        return $element;
    }

    private function getAdminElement(Session $session, array $terms): ?BaseLogic
    {
        if (isset($terms[0]) and $go = $terms[0]) {
            switch ($go) {
                case 'admin':
                    $element = $session->getAdmin();
                    break;
                case 'instance':
                    if (isset($terms[1])) {
                        $element = new Instance(Help::getDB()->fetchInstance($terms[1]));
                    } else {
                        $element = $session->getInstance();
                    }
                    break;
                case 'template':
                    if (isset($terms[1])) {
                        if (($row = Help::getDB()->getTemplateRow($terms[1], null))) {
                            $element = new Template($row);
                            if (false === $session->getAdmin()->isRelatedInstanceId($element->getInstanceId())) unset($element);
                        }
                    }
                    break;
                case 'search_settings':
                    if (isset($terms[1])) {
                        if (($element = (new Search())->fetchById($terms[1]))) {
                            $element->setForAdmin();
                            if (false === $session->getAdmin()->isRelatedInstanceId($element->getInstanceId())) unset($element);
                        }
                    }
                    break;
                case 'menu_item':
                    // get the menu_item_id and fetch it
                    $menu_item_id = $terms[1] ?? null; // assumption the menu_item_id is in there
                    // menu items are only returned for the current instance
                    $type = new Type('menu_item');
                    $element = $type->getElement(Help::getDB()->fetchElementRow($type, $menu_item_id));
                    break;
                case 'payment_service_provider':
                    // get payment service provider for instance (this is a factory...)
                    if (isset($terms[1])) {
                        if (($row = Help::getDB()->getPaymentServiceProviderRow($terms[1], null))) {
                            if (class_exists(($class_name = __NAMESPACE__ . '\\' . ucfirst($row->provider_name)))) {
                                $element = new $class_name($row);
                            } else {
                                $element = new PaymentServiceProvider($row); // for admin the neutral version is allowed
                            }
                            if (false === $session->getAdmin()->isRelatedInstanceId($element->getInstanceId()))
                                unset($element);
                        }
                    }
                    break;
            }
        }

        return $element ?? null;
    }

    public function getId(): int
    {
        return 0; // yeah, we don't have that
    }

    public function getPath(): string
    {
        if (!isset($this->path)) {
            $this->path = Help::turnIntoPath($this->terms, $this->properties);
        }

        return $this->path;
    }
}