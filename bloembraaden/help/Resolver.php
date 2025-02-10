<?php
declare(strict_types=1);

namespace Bloembraaden;
// there are a couple of standard commands that differ from normal handling:
// - __admin__
// - __action__
// - __shoppinglist__
// - __order__
// - __user__
// - __limit__
// - __terms__
class Resolver extends BaseLogic
{
    private ?\stdClass $post_data;
    private array $terms, $properties, $instructions = array();
    private string $path;
    private int $variant_page;

    public function __construct(string $request_uri, int $instance_id)
    {
        parent::__construct();
        if (Setup::$instance_id !== $instance_id) {
            $row = Help::getDB()->fetchInstanceById($instance_id);
            //Setup::$instance_id = $instance_id;
            Setup::loadInstanceSettings(new Instance($row));
        }
        $GLOBALS['slugs'] = new \stdClass();
        // since @0.23.0 replace &nbsp; by regular space
        $request_uri = trim(str_replace('%C2%A0', ' ', $request_uri));
        // urldecode means get utf-8 characters of requested path + querystring
        $request_parts = explode('?', strip_tags(mb_strtolower(urldecode($request_uri))));
        $request_path = $request_parts[0];
        if (true === isset($request_parts[1])) {
            $src = explode('&', $request_parts[1]);
        } else {
            $src = array();
        }
//        var_dump($request_path);
//        die();
        if ('/' === $request_path) { // homepage is requested, get slug to retrieve from cache later
            $uri_parts = array(Setup::$HOMEPAGE_SLUG);
        } else {
            $uri_parts = array_values(array_filter(explode('/', $request_path), function ($value) {
                if ('' === $value) {
                    return false;
                } elseif (str_starts_with($value, '__')) {
                    $instruction = explode(':', $value);
                    $key = $instruction[0];
                    //@since 0.21.0 __terms__ are added as terms
                    if ('__terms__' === $key) {
                        if (isset($instruction[1])) {
                            $this->addTerms(explode(',', $instruction[1]));
                        }
                    } else {
                        $this->instructions[str_replace('__', '', $key)] = $instruction[1] ?? true;
                    }

                    return false;
                } elseif (str_starts_with($value, 'variant_page')) {
                    if (false === isset($this->variant_page)) {
                        $variant_page = (int)substr($value, 12);
                        $this->variant_page = ($variant_page < 1) ? 1 : $variant_page;
                    }

                    return false;
                } elseif ('sitemap.xml' === $value) {
                    Help::outputSitemap();
                    die();
                }

                return true;
            }));
        }
        // for fileupload...
        if (true === isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $csrf_token = urldecode($_SERVER['HTTP_X_CSRF_TOKEN']);
            $post_data = null;
        } else {
            // 0.5.3 / if you change this, check if the templates can still be saved
            $post_data = json_decode(file_get_contents('php://input'), false);
        }
        // null when file is uploaded, or when no json is provided in php://input
        if (null === $post_data) {
            // this is at least used when files are uploaded and for the login page __admin__
            $post_data = (object)filter_input_array(INPUT_POST); // assume form data
            if (true === isset($csrf_token)) $post_data->csrf_token = $csrf_token;
            $output_json = true === isset($post_data->json) && true === $post_data->json;
        } else {
            $output_json = true; // if you receive json you can bet it wants json back
        }
        // remember for everyone, only needed when output is imminent:
        if (true === $output_json) Help::$OUTPUT_JSON = true;
        // special case action, may exist alongside other instructions, doesn't necessarily depend on uri[0]
        if (true === isset($this->instructions['action'])) {
            // todo backwards compatible with __action__/suggest syntax, remove when sites use __action__:suggest
            if (count($uri_parts) > 0) {
                $this->instructions['action'] = htmlentities($uri_parts[0]);
                unset($uri_parts[0]);
            }
        } elseif (true === isset($post_data->action)) {
            $this->instructions['action'] = $post_data->action;
        }
        //
        $this->post_data = $post_data;
        // cleanup terms and properties
        $this->properties = array();
        // $src holds the properties (filters), and possibly other things from the query string,
        foreach ($src as $key => $value) {
            $values = explode('=', $value);
            if (isset($values[1])) $this->addProperty($values[0], explode(',', $values[1]));
        }
        // remove properties (filters) from path and add them to query
        foreach ($uri_parts as $index => $value) {
            if (false === str_contains($value, ':')) continue;
            $values = explode(':', $value);
            $this->addProperty($values[0], explode(',', $values[1]));
            unset($uri_parts[$index]);
        }
        $this->addTerms(array_values($uri_parts)); // $terms are the uri parts separated by / (forward slash)

        if (0 === count($this->terms)) { // if you do not have a term, try to get one from the properties
            foreach ($this->getProperties() as $property => $value) {
                if (str_contains($property, '_')) continue;
                if (1 === count($value)) {
                    $term = $value[0];
                    break;
                } else {
                    $term = $property;
                }
            }
            if (isset($term)) $this->terms = array($term);
        }
    }

    private function addTerms(array $terms): void
    {
        if (true === isset($this->terms)) {
            $terms = array_merge($this->terms, $terms);
        }
        $this->terms = $terms;
    }

    public function getPostData()
    {
        return $this->post_data;
    }

    public function isHomepage(): bool
    {
        return isset($this->terms[0]) && Setup::$HOMEPAGE_SLUG === $this->terms[0];
    }

    public function escape(\stdClass $post_data): \stdClass
    {
        foreach ($post_data as $key => $value) {
            if (true === is_string($value)) $post_data->{$key} = htmlspecialchars($value, ENT_QUOTES);
        }
        return $post_data;
    }

    public function getAction(): ?string
    {
        if (true === isset($this->instructions['action'])
            && true === is_string($this->instructions['action'])
        ) {
            return $this->instructions['action'];
        }

        return null;
    }

    public function hasInstructions(): bool
    {
        return (true === isset($this->instructions) && 0 < count($this->instructions));
    }

    /**
     * @param string $instruction
     * @return mixed|null
     */
    public function getInstruction(string $instruction)
    {
        return $this->instructions[$instruction] ?? null;
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

    public function cleanOutboundProperties(\stdClass $out): \stdClass
    {
        $post_data = $this->getPostData();
        // reflect properties:
        if (isset($post_data->render_in_tag)) $out->render_in_tag = $post_data->render_in_tag;
        if (isset($post_data->full_feedback)) $out->full_feedback = $post_data->full_feedback;
        // some fields can never be output:
        unset($out->password_hash);
        if (false === ADMIN) {
            unset($out->recaptcha_secret_key);
            unset($out->myparcel_api_key);
        }

        unset($post_data);

        return $out;
    }

    public function getTerms(): array
    {
        return $this->terms;
    }

    public function getElement(?bool &$from_history = false): BaseLogic
    {
        $limit = null;
        // run over the instructions (skipped for regular urls)
        foreach ($this->instructions as $instruction => $value) {
            if (null === ($session = Help::$session)) $this->handleErrorAndStop('Resolver->getElement() cannot get instructions-element with session = null');
            // btw, instructions may not be cached
            switch ($instruction) {
                case 'limit':
                    $limit = -$value;
                    break;
                case 'admin':
                    if ($session->isAdmin()) {
                        if ($element = $this->getAdminElement($session, $this->getTerms())) {
                            return $element;
                        }
                    } else {
                        return new BaseElement((object)array(
                            'title' => 'Admin login',
                            'template_pointer' => (object)array('name' => 'login', 'admin' => true),
                            'type_name' => 'search',
                            'slug' => '__admin__',
                        ));
                    }
                    break;
                case 'shoppinglist':
                    if (count($terms = $this->getTerms()) > 0) {
                        return new Shoppinglist($terms[0]);
                    }
                    break;
                case 'user':
                    if (null === ($user = $session->getUser())) {
                        return new BaseElement((object)array(
                            'title' => __('User', 'peatcms'),
                            'type_name' => 'user',
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
                            $term = $this->getTerms()[0];
                            $src = "%$term"; // look up by last characters
                            // look for (multiple) orders
                            $orders = Help::getDB()->fetchElementRowsWhere(
                                new Type('order'),
                                array('order_number' => $src),
                            );
                            $slug = "__order__/$term";
                        } else {
                            // all the orders (with paging)
                            $page = (int)($this->getInstruction('page') ?? 1);
                            $page_size = 250;
                            $peat_type = new Type('order');
                            $orders = Help::getDB()->fetchElementRowsPage($peat_type, $page, $page_size);
                            $slug = "__order__/__page__:$page";
                            $pages = Help::getDB()->fetchElementRowsPageNumbers($peat_type, $page, $page_size);
                        }
                        if (1 === count($orders)) {
                            $order = new Order($orders[0]);

                            return new BaseElement((object)array(
                                'title' => __('Order detail', 'peatcms'),
                                'template_pointer' => (object)array('name' => 'order', 'admin' => true),
                                'type_name' => 'order',
                                'order_id' => $order->getId(),
                                'slug' => "__order__/{$order->getOrderNumber()}",
                                '__order__' => array($order->getOutput()),
                            ));
                        } else {
                            return new BaseElement((object)array(
                                'title' => __('Order overview', 'peatcms'),
                                'template_pointer' => (object)array('name' => 'order_overview', 'admin' => true),
                                'type_name' => 'search',
                                'slug' => $slug,
                                '__orders__' => $orders,
                                '__pages__' => $pages ?? array(),
                            ));
                        }
                    }
            }
        }
        resolve:
        $type_name = 'search';
        $element_id = 0;
        $terms = $this->getTerms();
        $num_terms = count($terms);
        if (0 === $num_terms) { // homepage is requested
            $type_name = 'page';
            $element_id = Help::$session->getInstance()->getHomepageId();
        } elseif (1 === $num_terms) {
            $term = $terms[0];
            // find element by slug TODO: no cache is necessary, but also slower, how to fix
            if (null !== ($row = Help::getDB()->fetchElementIdAndTypeBySlug($term, true))) {
                $element_id = (int)$row->id;
                $type_name = (string)$row->type_name;
            } elseif (null !== ($row = Help::getDB()->fetchElementIdAndTypeByAncientSlug($term))) {
                $element_id = (int)$row->id;
                $type_name = (string)$row->type_name;
                $from_history = true;
            }
        }
        $peat_type = new Type($type_name);
        $element = $peat_type->getElement();
        // null means element is deleted or something, perform a default search
        if (null === $element->fetchById($element_id)) {
            $element = new Search();
            // convert a single slug to separate terms for searching
            if (1 === $num_terms) {
                $term = $terms[0];
                $this->terms = $element->cleanTerms(explode('-', $term));
                // if the cleaned term is still one term, but different from the original, retry it
                if (1 === count($this->terms) && $this->terms[0] !== $term) {
                   goto resolve;
                }
            }
        } elseif ((false === defined('ADMIN') || false === ADMIN)
            && false === $element->isOnline()
        ) { // @since 0.23.1 do not get offline stuff when not admin (also prevents caching of offline items)
            $title = $element->row->title;
            //$this->addError("{$element->getSlug()} is offline and replaced by a search for visitors.");
            $element = new Search();
            $this->terms = $element->cleanTerms(explode(' ', $title));
        }
        // load the properties, to be used by filters
        $element->setProperties($this->getProperties());
        // if it’s a search go do that
        if ($element instanceof Search) {
            // find the types we need from the template
            // todo for admin when working on templates without publishing, elements will be missing...
            // todo expand this to other places to reduce data (e.g. petitclos.nl/drank)
            $template_id = Help::getDB()->getDefaultTemplateIdFor('search');
            $json_prepared = Help::getDB()->appCacheGet("templates/$template_id");
            if (is_array($json_prepared)) {
                $types = array();
                $keys = array_flip(array_keys($json_prepared)); // flip to use isset which is fast
                foreach (DB::TYPES_WITH_CI_AI as $index => $type_name) {
                    if (true === isset($keys["__{$type_name}s__"])) $types[] = $type_name;
                }
                $element->findWeighted($this->getTerms(), $limit, $types, false);
            } else { // ignore optimization possible with types
                $element->findWeighted($this->getTerms(), $limit);
            }
        }

        return $element;
    }

    private function getAdminElement(Session $session, array $terms): ?BaseLogic
    {
        if (isset($terms[0]) && $go = $terms[0]) {
            switch ($go) {
                case 'admin':
                    $element = $session->getAdmin();
                    break;
                case 'instance':
                    if (true === isset($terms[1])) {
                        $element = new Instance(Help::getDB()->fetchInstance($terms[1]));
                    } else {
                        $element = $session->getInstance();
                    }
                    break;
                case 'template':
                    if (true === isset($terms[1]) && ($id = (int)$terms[1])) {
                        $element = new Template($id, null);
                        if (null === ($element_instance_id = $element->getInstanceId()) // when deleted
                            || (false === $session->getAdmin()->isRelatedInstanceId($element_instance_id))
                        ) {
                            unset($element);
                        }
                    }
                    break;
                case 'search_settings':
                    if (true === isset($terms[1]) && ($id = (int)$terms[1])) {
                        if (($element = (new Search())->fetchById($id))) {
                            $element->setForAdmin();
                            if (false === $session->getAdmin()->isRelatedInstanceId($element->getInstanceId())) unset($element);
                        }
                    }
                    break;
                case 'menu_item':
                    // get the menu_item_id and fetch it
                    if (true === isset($terms[1]) && ($id = (int)$terms[1])) {
                        // menu items are only returned for the current instance
                        $type = new Type('menu_item');
                        $element = $type->getElement(Help::getDB()->fetchElementRow($type, $id));
                    }
                    break;
                case 'payment_service_provider':
                    // get payment service provider for instance (this is a factory...)
                    if (isset($terms[1]) && ($id = (int)$terms[1])) {
                        if (($row = Help::getDB()->getPaymentServiceProviderRow($id, null))) {
                            if (isset($row->provider_name) && class_exists(($class_name = __NAMESPACE__ . '\\' . ucfirst($row->provider_name)))) {
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
        if (false === isset($this->path)) {
            $this->path = Help::turnIntoPath($this->terms, $this->properties);
        }

        return $this->path;
    }
}