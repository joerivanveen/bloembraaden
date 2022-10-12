<?php
declare(strict_types=1);

namespace Peat;
class Handler extends BaseLogic
{
    private Session $session;
    private Resolver $resolver;
    private ?string $action;

    public function __construct(Session $session)
    {
        parent::__construct();
        $this->session = $session;
        // the resolver will setup itself based on the supplied url, and then setup the necessary global constants
        $this->resolver = new Resolver($_SERVER['REQUEST_URI'], Setup::$instance_id);
        $this->action = $this->resolver->getAction();
        if (extension_loaded('newrelic')) {
            $transaction_name = (ADMIN) ? 'Admin: ' : 'Visit: ';
            $transaction_name .= ($this->action) ? 'act' : 'view';
            newrelic_name_transaction($transaction_name);
        }
    }

    public function Act(): void
    {
        if (null === ($action = $this->action)) return;
        // here you can do the actions, based on what’s $post-ed
        $out = null;
        $post_data = $this->resolver->getPostData();
        $instance = $this->session->getInstance();
        // NOTE you always get the current version even if you ask for a previous one, this is no big deal I think
        $version = Setup::$VERSION . '-' . strtotime($instance->getSetting('date_updated'));
        // start with some actions that are valid without csrf
        if ($action === 'javascript') {
            // @since 0.7.6 get cached version when available for non-admins
            $file_location = Setup::$DBCACHE . 'js/' . Setup::$instance_id . '-' . $version . '.js.gz';
            if (false === ADMIN and true === file_exists($file_location)) {
                $response = file_get_contents($file_location);
                header('Cache-Control: max-age=2592000'); //30days (60sec * 60min * 24hours * 30days)
                header('Content-Type: text/javascript');
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($response));
                echo $response;
                die();
            }
            // $doc = the javascript file we’re going to build
            $doc = '"use strict";var VERSION="' . $version . '";var VERBOSE=' . (Setup::$VERBOSE ? 'true' : 'false') . ";\n";
            $doc .= file_get_contents(CORE . '../htdocs/instance/' . $instance->getPresentationInstance() . '/script.js');
            $doc .= file_get_contents(CORE . 'client/peat.js');
            //if (ADMIN) $doc .= \file_get_contents(CORE . '../htdocs/client/admin.js'); <- added by console later
            if (ADMIN) {
                header('Content-Type: text/javascript');
                echo $doc;
                die();
            }
            try {
                $doc = \JShrink\Minifier::minify($doc);
            } catch (\Exception $e) {
                $this->addError($e);
            }
            $response = gzencode($doc, 9);
            $doc = null;
            // @since 0.7.6 cache this file on disk (if it isn’t cached already by another concurrent request)
            if (false === file_exists($file_location)) file_put_contents($file_location, $response, LOCK_EX);
            header('Cache-Control: max-age=2592000'); //30days (60sec * 60min * 24hours * 30days)
            header('Content-Type: text/javascript');
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($response));
            echo $response;
            die();
        } elseif ($action === 'stylesheet') {
            // TODO the stylesheet is cached in htdocs/instance now, so this is only for admins
            // @since 0.7.6 get cached version when available for non-admins
            $file_location = Setup::$DBCACHE . 'css/' . Setup::$instance_id . '-' . $version . '.css.gz';
            if (false === ADMIN and true === file_exists($file_location)) {
                $response = file_get_contents($file_location);
                header('Cache-Control: max-age=2592000'); //30days (60sec * 60min * 24hours * 30days)
                header('Content-Type: text/css');
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($response));
                echo $response;
                die();
            }
            $doc = file_get_contents(CORE . 'client/peat.css');
            $doc .= file_get_contents(CORE . '../htdocs/instance/' . $instance->getPresentationInstance() . '/style.css');
            $response = gzencode($doc, 9);
            $doc = null;
            header('Cache-Control: max-age=2592000'); //30days (60sec * 60min * 24hours * 30days)
            header('Content-Type: text/css');
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($response));
            // @since 0.7.6 cache this file on disk (if it isn’t cached already by another concurrent request)
            if (false === file_exists($file_location)) file_put_contents($file_location, $response, LOCK_EX);
            echo $response;
            die();
        } elseif ('poll' === $action) {
            if (false === defined('OUTPUT_JSON')) define('OUTPUT_JSON', true);
            // get any update since last time, so the admin can fetch it when appropriate
            // this is a get request, without csrf or admin, so don’t give any specific information
            $out = array('new' => false, 'is_admin' => ADMIN);
            // todo maybe you still want to die here, for less resources
        } elseif ($action === 'download') {
            if ($el = Help::getDB()->fetchElementIdAndTypeBySlug($this->resolver->getTerms()[1] ?? '')) {
                if ($el->type === 'file') {
                    $f = new File(Help::getDB()->fetchElementRow(new Type('file'), $el->id));
                    $f->serve();
                }
            }
        } elseif ($action === 'account_delete_session') {
            if (!isset(($terms = $this->resolver->getTerms())[1])) {
                $this->getSession()->delete();
                $this->addMessage(__('Session has been deleted', 'peatcms'), 'log');
                $out = array('success' => true, 'is_account' => false, '__user__' => new \stdClass);
            } else {
                $session_id = (int)$terms[1];
                $my_session = $this->getSession();
                if ($session_id === $my_session->getId()) {
                    $this->addMessage(__('You can not destroy your own session this way', 'peatcms'), 'warn');
                } elseif (true === Help::getDB()->deleteSessionById(
                        $session_id,
                        ($user = $my_session->getUser()) ? $user->getId() : 0,
                        ($admin = $my_session->getAdmin()) ? $admin->getId() : 0
                    )) {
                    $out = array(
                        'success' => true,
                        'destroyed_session_id' => $session_id,
                    );
                } else {
                    $this->addMessage(__('Failed destroying the session', 'peatcms'), 'error');
                }
            }
        } elseif ($action === 'payment_status_update') {
            //header('Access-Control-Allow-Origin: https://apirequest.io'); //TODO this is temp for testing, not necessary for curl
            if (false === defined('OUTPUT_JSON')) define('OUTPUT_JSON', true);
            if (($psp = $instance->getPaymentServiceProvider())) {
                if (true === $psp->updatePaymentStatus($post_data)) {
                    $out = $psp->successBody();
                } else {
                    $this->handleErrorAndStop('Did not accept payment_status_update with ' . json_encode($post_data));
                }
            } else {
                $this->handleErrorAndStop(
                    sprintf('Could not get PaymentServiceProvider for %s', $instance->getName()),
                    __('No PaymentServiceProvider found', 'peatcms')
                );
            }
        } elseif ($action === 'payment_return') {
            // the url the client is sent to after completing payment, but also after it failed
            if (($psp = $instance->getPaymentServiceProvider())) {
                if (isset($_GET['id'])) {
                    $status = $psp->checkPaymentStatusByPaymentId($_GET['id']);
                    if (1 === $status) {
                        $out = array('redirect_uri' => $psp->getFieldValue('successUrl'));
                    } elseif (0 === $status) {
                        $out = array('redirect_uri' => $psp->getFieldValue('pendingUrl'));
                    } else {
                        $out = array('redirect_uri' => $psp->getFieldValue('failedUrl'));
                    }
                } else {
                    $out = array('redirect_uri' => $psp->getFieldValue('returnUrl'));
                }
            } else {
                $this->handleErrorAndStop(
                    sprintf('Could not get PaymentServiceProvider for %s', $instance->getName()),
                    __('No PaymentServiceProvider found', 'peatcms')
                );
            }
        } elseif ('properties' === $action) { // @since 0.8.11: retrieve all the properties and relations for this instance
            $for = $post_data->for ?? 'variant';
            $out = array('properties' => Help::getDB()->fetchProperties($for));
        } elseif ('properties_valid_values_for_path' === $action) { // @since 0.8.12: retrieve valid options for the path
            // get _all_ the variant id’s for this path, and then all the property_values that are coupled
            // only property and property_value can be filtered thusly, and search as well through its own method
            // in all other cases return an empty array (which should close the filter)
            if (isset($post_data->path)) {
                $src = new Search();
                $out = $src->getRelevantPropertyValuesAndPrices($post_data->path);
            }
        } elseif ('instagram' === $action) { // @since 0.7.2
            if (isset((($terms = $this->resolver->getTerms()))[1])) {
                $insta = new Instagram();
                if ('feed' === ($command = $terms[1])) {
                    if (isset($post_data->csrf_token) && $post_data->csrf_token === $this->session->getValue('csrf_token')) {
                        $feed_name = $terms[2] ?? '';
                        $out = $insta->feed($feed_name);
                    } else {
                        $this->addMessage(sprintf(__('%s check failed, please refresh browser', 'peatcms'), 'CSRF'), 'warn');
                        $out = true;
                    }
                } elseif ('authorize' === $command) {
                    $out = $insta->authorize($post_data);
                } elseif ('delete' === $command) {
                    // signed request:
                    // https://developers.facebook.com/docs/apps/delete-data/
                    // URL to track the deletion:
                    $confirmation_code = $insta->delete($post_data);
                    $status_url = $this->session->getInstance()->getDomain(true);
                    $status_url .= '__action__/instagram/confirm/';
                    $status_url .= $confirmation_code;
                    // instagram wants the response as json
                    if (false === defined('OUTPUT_JSON')) define('OUTPUT_JSON', true);
                    $out = array(
                        'url' => $status_url,
                        'confirmation_code' => $confirmation_code
                    );
                } elseif ('confirm' === $command) {
                    if (isset($term[2])) {
                        $this->addMessage(sprintf(
                            __('Bloembraaden has been de-authorized, your data will be removed within 10 minutes. Confirmation code: %s', 'peatcms'),
                            $terms[2]), 'note');
                    }
                    $out = array('redirect_uri' => '/');
                }
            } else {
                $this->addMessage(__('Instagram action needs an instruction what to do', 'peatcms'), 'warn');
                $out = true;
            }
        } elseif ('pay' === $action) { // this is a payment link, supply the order number and slug of the payment page
            $properties = $this->resolver->getProperties();
            if (isset($properties['order_number'])) {
                $order_number = str_replace(' ', '', htmlentities($properties['order_number'][0]));
                // check a couple of things: if its already paid, do not do this (ie payment_transaction_id has to be NULL)
                // else remove the tracking id so payment_start can be fresh
                if (($order_row = Help::getDB()->getOrderByNumber($order_number))) {
                    if ($order_row->payment_confirmed_bool) {
                        $this->addMessage(sprintf(__('Order ‘%s’ is marked as paid', 'peatcms'), $order_number), 'note');
                        $out = (object)array('redirect_uri' => '/');
                    } else {
                        Help::getDB()->updateElement(new Type('order'), array(
                            'payment_tracking_id' => NULL,
                            'payment_transaction_id' => NULL,
                        ), $order_row->order_id);
                        $this->getSession()->setVar('order_number', $order_number);
                        if (isset($properties['slug'][0])) {
                            $redirect_uri = Help::slugify($properties['slug'][0]);
                        } else {
                            $redirect_uri = 'payment_link';
                        }
                        $out = (object)array('redirect_uri' => '/' . $redirect_uri);
                    }
                } else {
                    $this->addMessage(sprintf(__('Order ‘%s’ not found', 'peatcms'), $order_number), 'warn');
                }
            }
        } elseif ('reorder' === $action) {
            $properties = $this->resolver->getProperties();
            if (false === isset($properties['shoppinglist'])) {
                $this->addError('Shoppinglist is not set for order action');
                $out = true;
            } else if (isset($properties['order_number'])) {
                $shoppinglist_name = $properties['shoppinglist'][0];
                $shoppinglist = new Shoppinglist($shoppinglist_name, $this->session);
                $order_number = str_replace(' ', '', htmlentities($properties['order_number'][0]));
                if (($order_row = Help::getDB()->getOrderByNumber($order_number))) {
                    $order = new Order($order_row);
                    $count = 0;
                    foreach ($order->getRows() as $index => $order_item) {
                        if (true === $order_item->deleted) continue;
                        $variant = $this->getElementById('variant', $order_item->variant_id);
                        if ($variant instanceof Variant) {
                            if (true === $shoppinglist->addVariant($variant, $order_item->quantity)) {
                                $count += 1;
                            }
                        }
                    }
                    if (1 === $count) {
                        $this->addMessage(sprintf(__('%1$s order row added to %2$s', 'peatcms'), $count, $shoppinglist_name));
                    } else {
                        $this->addMessage(sprintf(__('%1$s order rows added to %2$s', 'peatcms'), $count, $shoppinglist_name));
                    }
                    $out = array('redirect_uri' => isset($properties['redirect_uri'])
                        ? htmlentities($properties['redirect_uri'][0])
                        : '/__shoppinglist__/' . $shoppinglist_name);
                } else {
                    $this->addMessage(__('Order not found', 'peatcms'), 'warn');
                }
            }
        } elseif ('invoice' === $action) {
            if (!$this->getSession()->getAdmin() instanceof Admin) {
                $this->addMessage(__('Invoice can only be accessed by admin', 'peatcms'), 'warn');
            } else {
                if (isset($this->resolver->getProperties()['order_number'])) {
                    $order_number = htmlentities(trim($this->resolver->getProperties()['order_number'][0]));
                    $filename = Help::getInvoiceFileName($order_number);
                    //#TRANSLATORS this is the invoice title, %s is the order number
                    $filename_for_client = Help::slugify(sprintf(__('Invoice for order %s', 'peatcms'), $order_number));
                    if (file_exists($filename)) {
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: attachment; filename="' . $filename_for_client . '.pdf"');
                        header('Content-Length: ' . filesize($filename));
                        readfile($filename);
                        die();
                    } else {
                        $this->addMessage(sprintf(__('File not found: %s', 'peatcms'), basename($filename)));
                    }
                }
            }
        } elseif ('process_file' === $action) {
            $props = $this->resolver->getProperties();
            $sse = new SseLogger();
            if (false === $this->getSession()->getAdmin() instanceof Admin) {
                $sse->addError('To process a file you must be admin');
                die();
            }
            if (($slug = $props['slug'][0])) {
                // find element by slug
                if (null === ($row = Help::getDB()->fetchElementIdAndTypeBySlug($slug))) {
                    $this->addError(sprintf('process_file: no element found with slug %s', $slug));
                    $sse->log(sprintf(__('Could not process %s, check your logs to know more', 'peatcms'), $slug));
                }
                $peat_type = new Type($row->type);
                $element = $peat_type->getElement();
                // null means element is deleted or something
                if (null === $element->fetchById($row->id)) {
                    $this->addError(sprintf('process_file: could not get element for slug %s', $slug));
                    $sse->log(sprintf(__('Could not process %s, check your logs to know more', 'peatcms'), $slug));
                }
                if (method_exists($element, 'process')) {
                    $level = (int)$props['level'][0] ?? 1;
                    $element->process($sse, $level);
                } else {
                    $this->addError(sprintf('process_file: element %s has no method ‘process’', $slug));
                    $sse->log(sprintf(__('Could not process %s, check your logs to know more', 'peatcms'), $slug));
                }
            } else {
                $sse->log(__('No slug found to process', 'peatcms'));
            }
            die(); // after an sse logger you cannot provide any more content yo
        }
        // following is only valid with csrf
        if (isset($post_data->csrf_token) and $post_data->csrf_token === $this->session->getValue('csrf_token')) {
            if ('get_template' === $action) {
                // NOTE since a template can contain a template for __messages__, you may never add __messages__ to the template object
                if (isset($post_data->template_name)) {
                    // as of 0.5.5 load templates by id (from cache) with fallback to the old ways
                    if (isset($post_data->template_id) and is_numeric(($template_id = $post_data->template_id))) {
                        if (ADMIN and $row = Help::getDB()->getTemplateRow($template_id)) {
                            $temp = new Template($row);
                            if (false === $temp->checkIfPublished()) {
                                $out = json_decode($temp->getFreshJson());
                                $out->__template_status__ = 'sandbox';
                                echo json_encode($out);
                                die();
                            }
                        }
                        $filename = Setup::$DBCACHE . 'templates/' . $template_id . '.gz';
                        if (true === file_exists($filename)) {
                            header('Content-Type: application/json');
                            header('Content-Encoding: gzip');
                            header('Content-Length: ' . filesize($filename));
                            readfile($filename);
                            die();
                        }
                    }
                    // use Template() by loading html from disk
                    $temp = new Template();
                    $admin = ((isset($post_data->admin) and $post_data->admin === true) and $this->session->isAdmin());
                    //$out = array('html' => $temp->load($data->template_name, $admin));
                    if ($html = $temp->loadByTemplatePointer($post_data->template_name, $admin)) {
                        $out = $temp->getPrepared($html);
                        $out['__template_status__'] = 'default';
                    } else {
                        $out['__template_status__'] = 'not found';
                    }
                    if (ob_get_length()) { // false or 0 when there's no content in it
                        echo json_encode($out);
                        die();
                    } else {
                        $response = gzencode(json_encode($out), 9);
                        header('Content-Type: application/json');
                        header('Content-Encoding: gzip');
                        header('Content-Length: ' . strlen($response));
                        echo $response;
                    }
                    unset($temp);
                    unset($out);
                    unset($response);
                    die();
                } else {
                    $this->handleErrorAndStop(sprintf('No template_name found in data %s', \var_export($post_data, true)),
                        __('Could not load template', 'peatcms'));
                }
            } elseif ($action === 'get_template_by_name') {
                if (isset($post_data->template_name)) {
                    if (($template_row = Help::getDB()->getTemplateByName($post_data->template_name))) {
                        $filename = Setup::$DBCACHE . 'templates/' . $template_row->template_id . '.gz';
                        // TODO temp fallback remove when all templates are regenerated anew 0.10.5
                        if (false === file_exists($filename)) {
                            $filename = CORE . 'presentation/templates/' . $template_row->template_id . '.gz';
                        }
                        if (true === file_exists($filename)) {
                            header('Content-Type: application/json');
                            header('Content-Encoding: gzip');
                            header('Content-Length: ' . filesize($filename));
                            readfile($filename);
                            die();
                        } else {
                            $this->addError(sprintf(
                                __('Template not found on disk with id ‘%s’', 'peatcms'),
                                $template_row->template_id
                            ));
                        }
                    }
                }
            } elseif ($action === 'suggest') {
                // suggest should post type and id so we can infer some interesting stuff
                $src = new Search();
                // terms can be passed als query string ?terms=term1,term2 etc in the complex tag, as can limit
                $props = $this->resolver->getProperties();
                $limit = isset($props['limit']) ? (int)$props['limit'][0] : 8;
                $terms = $props['terms'] ?? array();
                $type_name = $props['type'][0] ?? $post_data->type ?? 'variant';
                // TODO base it on taxonomy, properties, crosslinked items and stuff
                if ('shoppinglist' === $type_name) { // based on current item(s) in list
                    // for now just get some id's that are linked to shoppinglist via other shoppinglists and / or orders
                    $out = array('__variants__' => $src->getRelatedForShoppinglist($post_data->id, $limit));
                } elseif ('variant' === $type_name) {
                    if (isset($post_data->id)) {
                        $out = array('__variants__' => $src->getRelatedForVariant($post_data->id, $limit));
                    } else {
                        $out = array('__variants__' => $src->suggestVariants($terms, $limit));
                    }
                } elseif ('page' === $type_name) {
                    if (isset($post_data->id)) {
                        $out = array('__pages__' => $src->getRelatedForPage($post_data->id, $limit));
                    } else {
                        $out = array('__pages__' => $src->suggestPages($terms, $limit));
                    }
                } else {
                    $hydrate_until = (isset($props['hydrate_until'][0])) ? (int)($props['hydrate_until'][0]) : null;
                    $src->findWeighted($terms, $hydrate_until);
                    $out = $src->getOutput();
                }
                $src = null;
                if (is_array($out)) {
                    $out['slug'] = 'suggest';
                }
                //$out = array('__variants__' => $variants, 'slug' => 'suggest');
            } elseif ($action === 'set_session_var') {
                $name = $post_data->name;
                // times keeps track of how many times this var is (being) updated
                $this->getSession()->setVar($name, $post_data->value, $post_data->times);
                $out = true; //array($name => $this->getSession()->getVar($name)); // the var object including value and times properties is returned
            } elseif ($action === 'post_comment' and (true === Help::recaptchaVerify($instance, $post_data))) {
                $post_data = $this->resolver->escape($post_data);
                $valid = true;
                // validation process
                if (isset($post_data->email)) {
                    if (false === filter_var($post_data->email, FILTER_VALIDATE_EMAIL)) {
                        $this->addMessage(sprintf(__('‘%s’ is not recognized as a valid email address', 'peatcms'), $post_data->email), 'warn');
                        $valid = false;
                    }
                } else {
                    $post_data->email = 'N/A';
                }
                if (isset($_SERVER['HTTP_REFERER']) && $url_parts = explode('/', urldecode($_SERVER['HTTP_REFERER']))) {
                    $post_data->referer = end($url_parts);
                    if (null === ($element_row = Help::getDB()->fetchElementIdAndTypeBySlug($post_data->referer))) {
                        $this->addError(sprintf('Commented with unknown referer %s', $post_data->referer));
                        $this->addMessage(__('This page does not accept comments', 'peatcms'), 'warn');
                        $valid = false;
                    }
                } else {
                    $this->addError('No referer when posting a comment');
                    $this->addMessage(__('To post a comment your browser must also send a referer', 'peatcms'), 'error');
                    $valid = false;
                }
                if (true === $valid) {
                    // check the other mandatory fields
                    foreach ([
                                 'nickname',
                                 'content',
                             ] as $index => $field_name) {
                        if (false === isset($post_data->$field_name) or trim((string)$post_data->$field_name) === '') {
                            $this->addMessage(sprintf(__('Mandatory field ‘%s’ not found in post data', 'peatcms'), $field_name), 'warn');
                            $valid = false;
                        }
                    }
                }
                if (true === $valid) {
                    $session =& $this->session; // point to this session
                    $peat_type = new Type('comment');
                    $title = Help::summarize(127, $post_data->title ?? '');
                    if ('' === $title) $title = Help::summarize(127, $post_data->content);
                    $referer = $post_data->referer;
                    $slug = Help::slugify("$referer $title");
                    $reply_to_id = $post_data->reply_to_id ?? null;
                    if (null !== ($comment_id = Help::getDB()->insertElement($peat_type, array(
                            'referer' => $referer,
                            'slug' => $slug,
                            'email' => $post_data->email,
                            'nickname' => $post_data->nickname,
                            'title' => $title,
                            'content' => $post_data->content,
                            'rating' => $post_data->rating ?? null, // todo normalize for 0 - 1
                            'reply_to_id' => $reply_to_id,
                            'user_id' => ($user = $session->getUser()) ? $user->getId() : 0,
                            'admin_id' => ($admin = $session->getAdmin()) ? $admin->getId() : 0,
                            'ip_address' => $session->getIpAddress(),
                            'user_agent' => $session->getUserAgent(),
                            'online' => false,
                        )))) {
                        $comment = new Comment(Help::getDB()->fetchElementRow($peat_type, $comment_id));
                        // note: link the comment to the element (not the other way around) to get the correct order
                        $element_type = new Type($element_row->type);
                        $element_id = $element_row->id;
                        $element = ($element_type)->getElement()->fetchById($element_id);
                        if (true === $element->link('comment', $comment_id)) {
                            if (null !== $reply_to_id) {
                                if (false === Help::getDB()->orderAfterId($element_type, $element_id, $peat_type, $comment_id, $reply_to_id)) {
                                    $this->addMessage(__('Comment added to end', 'peatcms'), 'warn');
                                }
                            }
                            $out = $comment->getOutput();
                            $out->success = true;
                            // TODO it should be a setting and new comments must also be visible / obvious in the admin panels
                            if (!isset($post_data->from_email)) $post_data->from_email = $instance->getSetting('mail_verified_sender');
                            $post_data->slug = $slug;
                            $post_data->title = $title;
                            $this->sendMail($instance, $post_data);
                        } else {
                            $this->addError(sprintf('Comment could not be linked to %s as %s', $post_data->referer, var_export($element_row, true)));
                            $this->addMessage(__('Comment not added', 'peatcms'), 'warn');
                        }
                    } else {
                        $this->addError(sprintf('Comment not added with data %s', var_export($post_data, true)));
                        $this->addMessage(__('Comment not added', 'peatcms'), 'warn');
                    }
                }
            } elseif ($action === 'sendmail' and (true === Help::recaptchaVerify($instance, $post_data))) {
                $post_data = $this->resolver->escape($post_data);
                $out = $this->sendMail($instance, $post_data);
            } elseif ($action === 'countries') {
                $out = array('__rows__' => Help::getDB()->getCountries());
                $out['slug'] = 'countries';
            } elseif ($action === 'postcode') { // TODO refactor completely but now I’m in a hurry
                // check here: https://api.postcode.nl/documentation/nl/v1/Address/viewByPostcode
                if (isset($post_data->postal_code) and isset($post_data->number)) {
                    $addition = $post_data->number_addition ?? null;
                    try {
                        $Postcode = new \PostcodeNl_Api_RestClient(
                            Setup::$POSTCODE->api_key,
                            Setup::$POSTCODE->secret,
                            Setup::$POSTCODE->api_url
                        );
                        $response = $Postcode->lookupAddress($post_data->postal_code, $post_data->number, $addition);
                        $out = array('response' => $response, 'success' => true);
                    } catch (\Exception $e) { // it uses exceptions as a means of control
                        $this->addError($e);
                        $error_type = '';
                        if ($e instanceof \PostcodeNl_Api_RestClient_ClientException)
                            $error_type = 'Client error';
                        else if ($e instanceof \PostcodeNl_Api_RestClient_ServiceException)
                            $error_type = 'Service error';
                        else if ($e instanceof \PostcodeNl_Api_RestClient_AddressNotFoundException)
                            $error_type = 'Address not found';
                        else if ($e instanceof \PostcodeNl_Api_RestClient_InputInvalidException)
                            $error_type = 'Input error';
                        else if ($e instanceof \PostcodeNl_Api_RestClient_AuthenticationException)
                            $error_type = 'Authentication error';
                        //$this->addMessage($type, 'warn'); // todo not always like this make it more user friendly
                        $this->addError($error_type); // silently ignore
                        $out = array('success' => false, 'error_message' => $error_type);
                    }
                } else {
                    $this->addMessage(__('Did not receive postal_code and number for address checking', 'peatcms'));
                    $out = true;
                }
            } elseif ('order' === $action) {
                if (true === Help::recaptchaVerify($instance, $post_data)) {
                    $post_data = $this->resolver->escape($post_data);
                    if (false === isset($post_data->shoppinglist)) {
                        $this->addError('Shoppinglist is not set for order action');
                        $out = true;
                    } else {
                        if (isset($post_data->email) and isset($post_data->shipping_country_id)) {
                            $valid = true;
                            // validation process
                            if (false === filter_var($post_data->email, FILTER_VALIDATE_EMAIL)) {
                                $this->addMessage(sprintf(__('‘%s’ is not recognized as a valid email address', 'peatcms'), $post_data->email), 'warn');
                                $valid = false;
                            } elseif (null === Help::getDB()->getCountryById((int)$post_data->shipping_country_id)) {
                                $this->addMessage(sprintf(__('‘%s’ is not recognized as a country id', 'peatcms'), $post_data->shipping_country_id), 'warn');
                                $valid = false;
                            }
                            if (true === $valid) {
                                // check the other mandatory fields
                                foreach ([
                                             'shipping_address_postal_code',
                                             'shipping_address_number',
                                             'shipping_address_street',
                                             'shipping_address_city',
                                         ] as $index => $field_name) {
                                    if (false === isset($post_data->$field_name) or trim($post_data->$field_name) === '') {
                                        $this->addMessage(sprintf(__('Mandatory field ‘%s’ not found in post data', 'peatcms'), $field_name), 'warn');
                                        $valid = false;
                                    }
                                }
                            }
                            if (true === $valid) {
                                $session =& $this->session; // point to this session
                                $shoppinglist = new Shoppinglist($post_data->shoppinglist, $session);
                                if (null !== ($order_number = Help::getDB()->placeOrder($shoppinglist, $session, (array)$post_data))) {
                                    $session->setVar('order_number', $order_number);
                                    // out object
                                    $out = array('success' => true, 'order_number' => $order_number);
                                    // leave everything be, so the next page (the forms action) will be loaded
                                } else {
                                    $this->addError('DB->placeOrder() failed');
                                    $this->addMessage(__('Order process failed.', 'peatcms'), 'error');
                                    $out = true;
                                }
                            }
                        } else {
                            $this->addMessage(__('Please provide a valid emailaddress and choose a shipping country.', 'peatcms'), 'warn');
                            $this->addError('Posting of inputs named email and shipping_country_id is mandatory.');
                            $out = true;
                        }
                    }
                } else {
                    $out = true;
                }
            } elseif ($action === 'account_login') {
                // TODO for admin this works without recaptcha, but I want to put a rate limiter etc. on it
                if (isset($post_data->email) && isset($post_data->pass)) {
                    $as_admin = $this->resolver->hasInstruction('admin');
                    if ($as_admin or true === Help::recaptchaVerify($instance, $post_data)) {
                        if (false === $this->session->login($post_data->email, $post_data->pass, $as_admin)) {
                            $this->addMessage(__('Could not login', 'peatcms'), 'warn');
                        } else {
                            if ($as_admin) {
                                $out = array('redirect_uri' => '/'); // @since 0.7.8 reload to get all the admin css and js
                            } else {
                                $this->addMessage(__('Login successful', 'peatcms'), 'log');
                                $out = array(
                                    'success' => true,
                                    'is_account' => true,
                                    '__user__' => $this->session->getUser()->getOutput()
                                );
                            }
                        }
                    }
                } else {
                    $this->addMessage(__('No e-mail and / or pass received', 'peatcms'), 'warn');
                }
            } elseif ($action === 'account_create' and (true === Help::recaptchaVerify($instance, $post_data))) {
                $out = array('success' => false);
                if (isset($post_data->email)
                    and isset($post_data->pass)
                    and strpos(($email_address = $post_data->email), '@')
                ) {
                    if (null !== ($user_id = Help::getDB()->insertUserAccount(
                            $email_address,
                            Help::passwordHash($post_data->pass)))
                    ) {
                        $this->addMessage(__('Account created', 'peatcms'), 'note');
                        // auto login
                        if (false === $this->session->login($email_address, $post_data->pass, false)) {
                            $this->addMessage(__('Could not login', 'peatcms'), 'error');
                        } else {
                            $this->addMessage(__('Login successful', 'peatcms'), 'log');
                            $out = array(
                                'success' => true,
                                'is_account' => true,
                                '__user__' => $this->session->getUser()->getOutput()
                            );
                        }
                    } else {
                        $this->addMessage(__('Account could not be created', 'peatcms'), 'error');
                    }
                } else {
                    $this->addMessage(__('No e-mail and / or pass received', 'peatcms'), 'warn');
                }
            } elseif ($action === 'account_password_forgotten' and (true === Help::recaptchaVerify($instance, $post_data))) {
                if (isset($post_data->email) and strpos(($email_address = $post_data->email), '@')) {
                    $post_data->check_string = Help::getDB()->putInLocker(0,
                        (object)array('email_address' => $email_address));
                    // locker is put in the properties for the request, NOTE does not work as querystring, only this proprietary format
                    $post_data->confirm_link = $instance->getDomain(true) .
                        '/' . ((isset($post_data->slug)) ? $post_data->slug : 'account') .
                        '/locker:' . $post_data->check_string;
                    $post_data->instance_name = $instance->getName();
                    /* this largely duplicate code must be in a helper function or something... */
                    if (isset($post_data->template) and $template_row = Help::getDB()->getMailTemplate($post_data->template)) {
                        $temp = new Template($template_row);
                        $body = $temp->renderObject($post_data);
                    }
                    if (false === isset($body)) {
                        $body = 'Click link or paste in your browser to reset your account password: <' . $post_data->confirm_link . '>';
                    }
                    $mail = new Mailer($instance->getSetting('mailgun_custom_domain'));
                    $mail->set(array(
                        'to' => $email_address,
                        'from' => $instance->getSetting('mail_verified_sender'),
                        'subject' => isset($post_data->subject) ? $post_data->subject : 'Mailed by ' . $instance->getDomain(),
                        'text' => Help::html_to_text($body),
                        'html' => $body,
                    ));
                    $out = $mail->send();
                    if (false === $out->success) {
                        if (isset($post_data->failure_message)) {
                            $this->addMessage($post_data->failure_message, 'error');
                        } else {
                            $this->addMessage(__('Failed to send mail', 'peatcms'), 'error');
                        }
                    } elseif (isset($post_data->success_message)) {
                        $this->addMessage($post_data->success_message);
                    }
                } else {
                    $this->addMessage(__('E-mail is required', 'peatcms'), 'warn');
                }
            } elseif ($action === 'account_password_update' and (true === Help::recaptchaVerify($instance, $post_data))) {
                $out = array('success' => false);
                if (isset($post_data->email) and isset($post_data->pass)) {
                    if (isset($post_data->locker)
                        and $row = Help::getDB()->emptyLocker($post_data->locker)
                    ) {
                        if (isset($row->information)
                            and isset($row->information->email_address)
                            and ($email_address = $row->information->email_address) === $post_data->email
                        ) {
                            $password = $post_data->pass;
                            // if it’s indeed an account, update the password
                            // (since the code proves the emailaddress is read by the owner)
                            if (false === Help::getDB()->updateUserPassword($email_address, Help::passwordHash($password))) {
                                $this->addMessage(__('Account update failed', 'peatcms'), 'warn');
                            } else {
                                $this->addMessage(__('Password updated', 'peatcms'), 'note');
                                $out['success'] = true;
                                if (true === $this->getSession()->login($email_address, $password, false)) {
                                    $this->addMessage(__('Login successful', 'peatcms'), 'log');
                                    $out = array(
                                        'success' => true,
                                        'is_account' => true,
                                        '__user__' => $this->session->getUser()->getOutput()
                                    );
                                }
                            }
                        } else {
                            $this->addMessage(__('E-mail address did not match', 'peatcms'), 'warn');
                        }
                    } else {
                        $this->addMessage(__('Link is invalid or expired', 'peatcms'), 'warn');
                    }
                } else {
                    $this->addMessage(__('No e-mail and / or pass received', 'peatcms'), 'warn');
                }
            } elseif ('account_update' === $action and (true === Help::recaptchaVerify($instance, $post_data))) {
                if (null !== ($user = $this->getSession()->getUser())) {
                    // check which column is being updated... (multiple is possible)
                    $data = array();
                    if (isset($post_data->phone)) $data['phone'] = $post_data->phone;
                    if (isset($post_data->gender)) $data['gender'] = $post_data->gender;
                    if (isset($post_data->nickname)) $data['nickname'] = $post_data->nickname;
                    if (count($data) > 0) {
                        $out = array(
                            'success' => $user->updateRow($data),
                        );
                    }
                    if (true === isset($post_data->email)) {
                        // updating email address is a process, you need to authenticate again
                        $this->addMessage('Currently updating emailaddress is not possible', 'note');
                    }
                    if (true === isset($out)) {
                        $out['__user__'] = $user->getOutput(); // get a new user
                    }
                }
            } elseif ('account_delete_sessions' === $action) {
                if ((null !== ($user = $this->getSession()->getUser()))) {
                    $out['success'] = 0 < Help::getDB()->deleteSessionsForUser(
                            $user->getId(),
                            $this->getSession()->getId()
                        );
                    $out['__user__'] = $this->getSession()->getUser()->getOutput();
                }
            } elseif (('update_address' === $action or 'delete_address' === $action)
                and (true === Help::recaptchaVerify($instance, $post_data))
            ) {
                //$post_data = $this->resolver->escape($post_data);
                if ((null !== ($user = $this->getSession()->getUser())) and isset($post_data->address_id)) {
                    $address_id = intval($post_data->address_id);
                    if ($action === 'delete_address') $post_data->deleted = true;
                    if (1 === Help::getDB()->updateColumnsWhere(
                            '_address',
                            (array)$post_data,
                            array('address_id' => $address_id, 'user_id' => $user->getId()) // user_id checks whether the address belongs to the user
                        )) {
                        $out = Help::getDB()->fetchElementRow(new Type('address'), $address_id);
                        if ($action === 'delete_address') {
                            $out = array('success' => true);
                        } else {
                            if (null === $out) {
                                $this->addMessage(__('Error retrieving updated address', 'peatcms'), 'error');
                            } else {
                                $out->success = true;
                            }
                        }
                    } else {
                        $this->addMessage(__('Address could not be updated', 'peatcms'), 'warn');
                    }
                } else {
                    if (isset($post_data->address_id)) {
                        $this->addMessage(__('You need to be logged in to manage addresses', 'peatcms'), 'warn');
                    } else {
                        $this->addError('address_id is missing');
                    }
                }
                if (false === isset($out)) $out = array('success' => false);
            } elseif ($action === 'create_address' and (true === Help::recaptchaVerify($instance, $post_data))) {
                $post_data = $this->resolver->escape($post_data);
                if ((null !== ($user = $this->getSession()->getUser()))) {
                    if (null !== ($address_id = Help::getDB()->insertElement(
                            new Type('address'),
                            array('user_id' => $user->getId())))
                    ) {
                        $out = array('success' => true);
                    }
                } else {
                    $this->addMessage(__('You need to be logged in to manage addresses', 'peatcms'), 'warn');
                }
                if (false === isset($out)) $out = array('success' => false);
            } elseif ($action === 'detail' and $this->resolver->hasInstruction('order')) {
                // TODO get it by order number (in the url) ONLY FOR logged in users that actually OWN that order_number
                // TODO or you can check if the order requested was actually placed by the session id, to show the whole thing
                // for now only get it from the session and add some anonymous values to $out...
                $out = new \stdClass;
                if ($order_number = $this->getSession()->getValue('order_number')) {
                    $out->order_number = $order_number;
                    $out->order_number_readable = wordwrap($order_number, 4, ' ', true);
                    if (($row = Help::getDB()->getOrderByNumber($order_number))) {
                        $out->amount_grand_total = Help::asMoney($row->amount_grand_total / 100.0);
                        $out->payment_confirmed_bool = $row->payment_confirmed_bool;
                        $out->emailed_order_confirmation_success = $row->emailed_order_confirmation_success;
                        $out->emailed_payment_confirmation_success = $row->emailed_payment_confirmation_success;
                        $out->date_created = $row->date_created;
                        $out->deleted = $row->deleted;
                    }
                }
                // You can't get the html by order_number because session values are not safe, they can be set client-side
                $out->slug = '__order__/' . $order_number;
            } elseif ($action === 'payment_start') {
                // TODO LET OP het is niet gecheckt dat deze order bij deze user hoort, dus geen gegevens prijsgeven
                if (isset($post_data->order_number)) {
                    if (($row = Help::getDB()->getOrderByNumber($post_data->order_number))) {
                        if (($order = new Order($row))) {
                            if (($payment_tracking_id = $order->getPaymentTrackingId())) {
                                // TODO maybe not access the row directly here, for sanity reasons
                                $live_flag = $row->payment_live_flag ?? false;
                                $out = array('tracking_id' => $payment_tracking_id, 'live_flag' => $live_flag, 'success' => true);
                            } else {
                                if (($psp = $instance->getPaymentServiceProvider())) {
                                    if (false === $psp->hasError()) {
                                        $live_flag = $psp->isLive();
                                        if (($tracking_id = $psp->beginTransaction($order, $instance))) {
                                            // update order with the payment transaction id
                                            if (Help::getDB()->updateElement(new Type('order'), array(
                                                'payment_tracking_id' => $tracking_id,
                                                'payment_live_flag' => $live_flag,
                                            ), $order->getId()))
                                                $out = array('tracking_id' => $tracking_id, 'live_flag' => $live_flag, 'success' => true);
                                        } else {
                                            $out = array('live_flag' => $live_flag, 'success' => false);
                                        }
                                    } else {
                                        $this->addError(sprintf('Payment Service Provider error %s', $psp->getLastError()));
                                    }
                                } else {
                                    $this->addError(__('No default Payment Service Provider found', 'peatcms'));
                                }
                            }
                        } else {
                            $this->addError(sprintf('Could not get order for %s', htmlentities($post_data->order_number)));
                        }
                    } else {
                        $this->addMessage(__('Order not found, please refresh the page', 'peatcms'), 'warn');
                        $this->addError(sprintf('DB returned null for %s', htmlentities($post_data->order_number)));
                    }
                } else {
                    $this->addError('Order number must be posted in order to start a payment');
                }
                if (false === isset($out)) {
                    $out = true;
                    $this->addMessage(__('Payment Service Provider error', 'peatcms'), 'error');
                }
            } elseif (in_array($action, array('add_to_list', 'remove_from_list', 'update_quantity_in_list'))) {
                $out = array('success' => $this->updateList($action, $post_data));
            } elseif (($admin = $this->getSession()->getAdmin()) instanceof Admin) {
                /**
                 * Admin actions, permission needs to be checked every time
                 */
                if ($action === 'update_element') {
                    if ($element = $this->getElementById($post_data->element, $post_data->id)) {
                        if (true === $admin->isRelatedElement($element)) {
                            // @since 0.8.19 check the prices
                            if (in_array($post_data->column_name, array('price', 'price_from'))) {
                                $value = Help::getAsFloat($post_data->column_value);
                                $value = Help::asMoney($value);
                                if ('' === $value && $value !== $post_data->column_value) {
                                    $this->addMessage(sprintf(
                                        __('%s not recognized as ‘money’', 'peatcms'),
                                        $post_data->column_value), 'warn');
                                }
                                $post_data->column_value = $value;
                            }
                            //
                            if (false === $element->update(array($post_data->column_name => $post_data->column_value))) {
                                $this->addMessage(sprintf(__('Update of element ‘%s’ failed', 'peatcms'), $post_data->element), 'error');
                            }
                            $out = $element->row;
                        }
                    }
                } elseif ($action === 'create_element') {
                    if ($element = $this->createElement($post_data->element, $post_data->online ?? false)) {
                        $out = $element->row;
                    } else {
                        $this->handleErrorAndStop(sprintf('Create element ‘%s’ failed', $post_data->element));
                    }
                } elseif ($action === 'delete_element') {
                    if (isset($post_data->element_name) and isset($post_data->id)) {
                        $success = false;
                        $type = new Type($post_data->element_name);
                        $element = $type->getElement()->fetchById((int)$post_data->id);
                        if ($admin->isRelatedElement($element)) {
                            $path = $element->getSlug();
                            if (true === ($success = $element->delete())) {
                                Help::getDB()->reCacheWithWarmup($path);
                                $this->addMessage(__('Please allow 5 - 10 minutes for the element to disappear completely', 'peatcms'));
                            }
                        } else {
                            $this->addMessage('Security warning, after multiple warnings your account may be blocked', 'warn');
                        }
                        unset($element);
                        $out = array('success' => $success);
                    }
                } elseif ($action === 'admin_get_elements') {
                    $out = array('rows' => $this->getElements($post_data->element));
                } elseif ($action === 'admin_get_element_suggestions') {
                    $out = $this->getElementSuggestions($post_data->element, $post_data->src);
                } elseif ($action === 'admin_get_element') {
                    $peat_type = new Type($post_data->element);
                    $element = $peat_type->getElement();
                    if ($element->fetchById((int)$post_data->id)) {
                        if (true === $admin->isRelatedElement($element)) {
                            $out = $element->row;
                        } else {
                            $this->addMessage(sprintf(__('No ‘%1$s’ found with id %2$s', 'peatcms'), $post_data->element, $post_data->id), 'error');
                            $out = true;
                        }
                        unset($element);
                    }
                } elseif ($action === 'admin_uncache') {
                    if (isset($post_data->path)) {
                        $path = $post_data->path;
                        if (true === Help::getDB()->reCacheWithWarmup($path)) {
                            if (false === isset($post_data->silent) || false === $post_data->silent) {
                                $this->addMessage(sprintf(__('‘%s’ refreshed in cache', 'peatcms'), $path), 'log');
                            }
                        }
                        $out = array('slug' => $path);
                    }
                } elseif ($action === 'admin_clear_cache_for_instance') {
                    if (isset($post_data->instance_id) and $admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                        $out = array('rows_affected' => ($rows_affected = Help::getDB()->clear_cache_for_instance($instance_id)));
                        $this->addMessage(sprintf(__('Cleared %s items from cache', 'peatcms'), $rows_affected));
                    }
                } elseif ($action === 'admin_export_templates_by_name') {
                    if (isset($post_data->instance_id) and $admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                        $content = Help::getDB()->getTemplates($instance_id);
                        $file_name = Help::slugify($this->session->getInstance()->getName()) . '-Templates.json';
                        $out = array('download' => array('content' => $content, 'file_name' => $file_name));
                    }
                } elseif ($action === 'admin_import_templates_by_name') {
                    if (isset($post_data->instance_id) and $admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                        if (isset($post_data->template_json) && ($templates = json_decode($post_data->template_json))) {
                            $count_done = 0;
                            foreach ($templates as $key => $posted_row) {
                                if (($template_name = $posted_row->name)) {
                                    if (($db_row = Help::getDB()->getTemplateByName($template_name, $instance_id))) {
                                        $template_id = $db_row->template_id;
                                    } else {
                                        // insert
                                        if (!($template_id = Help::getDB()->insertTemplate($template_name, $instance_id))) {
                                            $this->addMessage(sprintf(__('Update ‘%s’ failed', 'peatcms'), $template_name), 'error');
                                            continue;
                                        }
                                        $this->addMessage(sprintf(__('Created new template ‘%s’', 'peatcms'), $template_name), 'note');
                                    }
                                    // update
                                    if (true === Help::getDB()->updateColumns('_template', array(
                                            'element' => $posted_row->element,
                                            'html' => $posted_row->html,
                                            'instance_id' => $instance_id,
                                            'name' => $template_name,
                                            'nested_max' => $posted_row->nested_max,
                                            'nested_show_first_only' => $posted_row->nested_show_first_only,
                                            'variant_page_size' => $posted_row->variant_page_size,
                                            'deleted' => $posted_row->deleted,
                                            'published' => false,
                                        ), $template_id)) {
                                        $this->addMessage(sprintf(__('Template ‘%s’ updated', 'peatcms'), $template_name));
                                        $count_done++;
                                    } else {
                                        $this->addMessage(sprintf(__('Update ‘%s’ failed', 'peatcms'), $template_name), 'error');
                                    }
                                }
                            }
                            if (0 === $count_done) {
                                $this->addMessage(__('No templates found in json', 'peatcms'), 'warn');
                            } else {
                                $this->updatePublishedForTemplates($instance_id);
                            }
                        } else {
                            $this->addMessage(__('json not recognized', 'peatcms'), 'warn');
                        }
                        if (isset($post_data->re_render)) {
                            $out = array('re_render' => $post_data->re_render);
                        } else {
                            $out = true;
                        }
                    }
                } elseif (in_array($action, array(
                    'admin_linkable_link',
                    'admin_linkable_order',
                    'admin_x_value_link',
                    'admin_x_value_create',
                    'admin_x_value_order',
                    'admin_x_value_remove'
                ))) {
                    $peat_type = new Type($post_data->element);
                    $element = $peat_type->getElement();
                    if ($element->fetchById((int)$post_data->id)) {
                        if (true === $admin->isRelatedElement($element)) {
                            if ($action === 'admin_linkable_link') {
                                $unlink = (isset($post_data->unlink) and true === $post_data->unlink);
                                if ($element->link($post_data->sub_element, $post_data->sub_id, $unlink)) {
                                    $out = $element->getLinked();
                                } else {
                                    $this->addError('Could not link that');
                                    $out = true;
                                }
                            } elseif ($action === 'admin_linkable_order') {
                                $full_feedback = (false !== $post_data->full_feedback);
                                $out = $element->orderLinked($post_data->linkable_type, $post_data->slug, $post_data->before_slug, $full_feedback);
                            } elseif ($action === 'admin_x_value_link') {
                                // make the entry in x_value table :-)
                                if ($element->linkX((int)$post_data->property_id, (int)$post_data->property_value_id)) {
                                    $out = $element->getLinked('x_value');
                                } else {
                                    $this->addError('Property link error');
                                    $out = true;
                                }
                            } elseif ($action === 'admin_x_value_order') {
                                $out = $element->orderXValue($post_data->x_value_id, $post_data->before_x_value_id);
                            } elseif ($action === 'admin_x_value_remove') {
                                if (isset($post_data->x_value_id) and $x_value_id = (int)$post_data->x_value_id) {
                                    // todo move this to a method in baseelement
                                    Help::getDB()->deleteXValueLink($peat_type, $element->getId(), $x_value_id);
                                    $element->reCache();
                                    $out = $element->getLinked('x_value');
                                }
                            } elseif ($action === 'admin_x_value_create') {
                                // todo move this to a method in baseelement
                                if (isset($post_data->property_value_title) && isset($post_data->property_id)) {
                                    $title = $post_data->property_value_title;
                                    $property_id = $post_data->property_id;
                                    $property = (new Property())->fetchById($property_id);
                                    if (false === $admin->isRelatedElement($property)) {
                                        $this->addMessage('Security warning, after multiple warnings your account may be blocked', 'warn');
                                        $out = true;
                                    } else {
                                        // create a property value
                                        if (($property_value_id = Help::getDB()->insertElement(new Type('property_value'), array(
                                            'title' => $title,
                                            'slug' => Help::slugify($title),
                                            'content' => __('Auto generated property value', 'peatcms'),
                                            'excerpt' => '',
                                            'template_id' => Help::getDB()->getDefaultTemplateIdFor('property_value'),
                                            'online' => true // for here the default is true, or else we can’t add simply from edit screen
                                        )))) {
                                            // link to the supplied property
                                            if (true === $property->link('property_value', $property_value_id)) {
                                                // create x_value entry
                                                if (!Help::getDB()->insertRowAndReturnKey(
                                                    $peat_type->tableName() . '_x_properties',
                                                    array(
                                                        $peat_type->idColumn() => $element->getId(),
                                                        'property_id' => $property_id,
                                                        'property_value_id' => $property_value_id,
                                                        'online' => true
                                                    )
                                                )) {
                                                    $this->addMessage(sprintf(__('Could not link property value to %s', 'peatcms'), $element->getTypeName()), 'error');
                                                }
                                                $element->reCache();
                                            } else {
                                                $this->addMessage(sprintf(__('Could not link property value to %s', 'peatcms'), 'property'), 'error');
                                            }
                                            // return the x_value entries (linked)
                                            $out = $element->getLinked('x_value');
                                        } else {
                                            $this->addMessage(__('Could not create new property value', 'peatcms'), 'error');
                                        }
                                    }
                                }
                            }
                        } else {
                            $this->addMessage('Security warning, after multiple warnings your account may be blocked', 'warn');
                            $out = true;
                        }
                    } else {
                        // error message element not found
                        $this->addMessage(sprintf(__('No ‘%1$s’ found with id %2$s', 'peatcms'), $post_data->element, $post_data->id), 'error');
                    }
                    unset($element);
                } elseif ($action === 'admin_put_menu_item') {
                    if (($row = Help::getDB()->fetchElementIdAndTypeBySlug($post_data->menu)) and $row->type === 'menu') {
                        $type = new Type('menu');
                        $menu = $type->getElement();
                        if ($menu->fetchById($row->id) instanceof Menu) {
                            if ($admin->isRelatedElement($menu)) {
                                // further sanitize / check data is not necessary, menu->putItem() takes care of that
                                /** @noinspection PhpPossiblePolymorphicInvocationInspection */ /* we just checked it's a menu */
                                if (true === $menu->putItem($post_data->dropped_menu_item_id, $post_data->menu_item_id, $post_data->command)) {
                                    $out = $menu->getOutput();
                                    if (false === Help::getDB()->reCacheWithWarmup($menu->getPath())) {
                                        Help::addMessage('Cache update of the menu failed', 'warn');
                                    }
                                } else {
                                    $this->addError('Could not put item in menu');
                                    $out = true;
                                }
                            }
                            unset($menu);
                        } else {
                            $this->handleErrorAndStop(sprintf('admin_put_menu_item could not get menu with id %s', $row->id), 'Invalid menu id');
                        }
                    } else {
                        $this->handleErrorAndStop('admin_put_menu_item did not receive a valid menu slug', 'Invalid slug');
                    }
                } elseif ($action === 'admin_get_templates') { // called when an element is edited to fill the select list
                    $instance_id = (isset($post_data->type) && 'instance' === $post_data->type) ? $post_data->id : Setup::$instance_id;
                    $for = $post_data->for ?? $this->resolver->getTerms()[1] ?? null;
                    if (isset($for)) {
                        if ($admin->isRelatedInstanceId($instance_id)) {
                            $out = Help::getDB()->getTemplates($instance_id, $for);
                            if (count($out) === 0) {
                                $this->addMessage(sprintf(__('No templates found for ‘%s’', 'peatcms'), $for), 'warn');
                            }
                            if (isset($this->resolver->getTerms()[1])) $out['__row__'] = $out; // TODO bugfix until template engine is fixed
                            $out['slug'] = 'admin_get_templates';
                        }
                    } else {
                        $this->addError('Var ‘for’ is missing, don’t know what templates you need');
                        $out = true;
                    }
                } elseif ($action === 'admin_get_vat_categories') {
                    $instance_id = $post_data->instance_id ?? Setup::$instance_id;
                    if ($admin->isRelatedInstanceId($instance_id)) {
                        $out = Help::getDB()->getVatCategories($instance_id);
                        if (count($out) === 0) {
                            $this->addMessage(__('No vat categories found', 'peatcms'), 'warn');
                        }
                    }
                } elseif ($action === 'search_log') {
                    // only shows log of the current instance
                    $rows = Help::getDB()->fetchSearchLog();
                    $out = array('__rows__' => $rows, 'item_count' => count($rows));
                } elseif ($action === 'admin_instagram') {
                    if ($post_data->type === 'instance' and $instance_id = $post_data->id) {
                        if ($admin->isRelatedInstanceId($instance_id)) {
                            $out = array(
                                '__feeds__' => Help::getDB()->getInstagramFeedSpecs($instance_id),
                                '__authorizations__' => Help::getDB()->getInstagramAuthorizations($instance_id),
                                'slug' => 'admin_instagram',
                            );
                            // @since 0.10.11: allow media to be used directly in admin
                            foreach ($out['__feeds__'] as $key => $feed) {
                                if (isset($feed->feed)) {
                                    $feed->__media__ = json_decode($feed->feed);
                                    unset($feed->feed);
                                }
                            }
                        }
                    }
                } elseif ($action === 'admin_redirect') {
                    if ($post_data->type === 'instance' and $instance_id = $post_data->id) {
                        if ($admin->isRelatedInstanceId($instance_id)) {
                            $out = array(
                                '__row__' => Help::getDB()->getRedirects($instance_id),
                                'slug' => 'admin_redirect',
                            );
                        }
                    }
                } elseif ($action === 'templates') {
                    if ($post_data->type === 'instance' and $instance_id = $post_data->id) {
                        if ($admin->isRelatedInstanceId($instance_id)) {
                            $out = array(
                                '__row__' => Help::getDB()->getTemplates($instance_id),
                                'content' => __('Available templates', 'peatcms'),
                                'slug' => 'templates',
                            );
                        }
                    }
                } elseif ($action === 'admin_publish_templates') {
                    if (false === isset($post_data->instance_id)) {
                        $this->addMessage('Please supply an instance_id', 'warn');
                        $out = true;
                    } elseif ($admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                        // update the instance with global published value
                        if (false === Help::getDB()->updateColumns('_instance', array(
                                'date_published' => 'NOW()'
                            ), $instance_id)) {
                            $this->addMessage(__('Could not update date published', 'peatcms'), 'warn');
                        }
                        // @since 0.9.4 cache css file on disk when all templates are published, to be included later
                        $edit_instance = $instance;
                        if ($instance->getId() !== $instance_id) {
                            $edit_instance = new Instance(Help::getDB()->fetchInstanceById($instance_id));
                        }
                        $file_location = Setup::$DBCACHE . 'css/' . $instance_id . '.css';
                        $doc = file_get_contents(CORE . 'client/peat.css'); // reset and some basic stuff
                        $doc .= file_get_contents(CORE . '../htdocs/instance/' . $edit_instance->getPresentationInstance() . '/style.css');
                        // minify https://idiallo.com/blog/css-minifier-in-php
                        $doc = str_replace(array("\n", "\r", "\t"), '', $doc);
                        // strip out the comments:
                        $doc = preg_replace("/\/\*.*?\*\//", '', $doc); // .*? means match everything (.) as many times as possible (*) but non-greedy (?)
                        // reduce multiple spaces to 1
                        $doc = preg_replace("/\s{2,}/", ' ', $doc);
                        // remove some spaces that are never necessary, that is, only when not in an explicit string
                        $doc = preg_replace("/, (?!')/", ',', $doc);
                        $doc = preg_replace("/: (?!')/", ':', $doc);
                        $doc = preg_replace("/; (?!')/", ';', $doc);
                        // the curly brace is probably always ok to attach to its surroundings snugly
                        $doc = str_replace(' { ', '{', $doc);
                        // write plain css to disk, will be included in template and gzipped along with it
                        if (false === file_put_contents($file_location, $doc, LOCK_EX)) {
                            $this->addMessage(sprintf(__('Could not write ‘%s’ to disk', 'peatcms'), $file_location), 'warn');
                        }
                        if ('' === file_get_contents($file_location)) {
                            unlink($file_location);
                            $this->handleErrorAndStop('Saving css failed', __('Saving css failed', 'peatcms'));
                        }
                        // get all templates for this instance_id, loop through them and publish
                        $rows = Help::getDB()->getTemplates($instance_id);
                        foreach ($rows as $index => $row) {
                            $temp = new Template($row);
                            if (false === $temp->publish()) {
                                $this->addMessage(__('Publishing failed.', 'peatcms'), 'error');
                                $out = true;
                                break;
                            }
                        }
                        if (!$out) {
                            $this->addMessage(__('Publishing done', 'peatcms'));
                            $out = array('success' => true);
                        }
                        unset($rows);
                    } else {
                        $this->addMessage('Security warning, after multiple warnings your account may be blocked', 'warn');
                        $out = true;
                    }
                } elseif ($action === 'admin_countries') {
                    if ($post_data->type === 'instance' and $instance_id = $post_data->id) {
                        if ($admin->isRelatedInstanceId($instance_id)) {
                            $out = array('__rows__' => Help::getDB()->getCountries($instance_id));
                            $out['slug'] = 'admin_countries';
                        }
                    }
                } elseif ($action === 'admin_payment_service_providers') {
                    if ($post_data->type === 'instance' and $instance_id = $post_data->id) {
                        if ($admin->isRelatedInstanceId($instance_id)) {
                            $out = array('__rows__' => Help::getDB()->getPaymentServiceProviders($instance_id));
                            $out['slug'] = 'admin_payment_service_providers';
                        }
                    }
                } elseif ($action === 'admin_payment_capture') {
                    if (isset($post_data->order_id)) {
                        if (($psp = $instance->getPaymentServiceProvider())) {
                            if (false === $psp->capturePayment((int)$post_data->order_id)) {
                                $this->handleErrorAndStop(sprintf(
                                    '%s->capturePayment was false for order_id %s',
                                    $post_data->order_id,
                                    $psp->getFieldValue('given_name')
                                ));
                            }

                            return;
                        }
                    }
                } elseif ($action === 'admin_get_payment_status_updates') {
                    // you only get them for the current instance
                    $out = array();
                    $out['slug'] = 'admin_get_payment_status_updates';
                    $out['__rows__'] = Help::getDB()->fetchPaymentStatuses();
                } elseif ('update_column' === $action) {
                    // security check
                    $allowed = false;
                    if ($row = Help::getDB()->selectRow($post_data->table_name, $post_data->id)) {
                        if (isset($row->instance_id)) {
                            $allowed = $admin->isRelatedInstanceId($row->instance_id);
                        } elseif (isset($row->property_id)) {
                            $allowed = $admin->isRelatedElement((new Property())->fetchById($row->property_id));
                        }
                    }
                    if (false === $allowed) {
                        $this->addMessage('Security warning, after multiple warnings your account may be blocked', 'warn');
                    } else {
                        $posted_column_name = $post_data->column_name;
                        $posted_table_name = $post_data->table_name;
                        $posted_value = $post_data->value;
                        $posted_id = $post_data->id;
                        // default update array
                        $update_arr = array($posted_column_name => $posted_value);
                        /**
                         * Some exceptions in the columns are handled first
                         */
                        if ($posted_column_name === 'password' and $posted_value !== '') {
                            $update_arr = array('password_hash' => Help::passwordHash($posted_value));
                        }
                        // for admin and user, any change must invalidate all sessions
                        if ('_admin' === $posted_table_name) {
                            $admin_id = (int)$posted_id;
                            $rows = Help::getDB()->fetchAdminSessions($admin_id);
                            foreach ($rows as $key => $row) {
                                Help::getDB()->deleteSessionById($row->session_id, 0, $admin_id);
                            }
                        } elseif ('_user' === $posted_table_name) {
                            $user_id = (int)$posted_id;
                            Help::getDB()->deleteSessionsForUser($user_id, 0);
                        }
                        //
                        if ($posted_table_name === '_admin'
                            and $posted_column_name === 'deleted'
                            and $posted_value === true) {
                            if ((int)$posted_id === $this->session->getAdmin()->getId()) {
                                $this->handleErrorAndStop(sprintf('Admin %s tried to delete itself', $posted_id),
                                    __('You can’t delete yourself', 'peatcms'));
                            }
                        } elseif ($posted_column_name === 'domain') {
                            $value = $posted_value;
                            if ($posted_table_name === '_instance' and ($instance->getDomain() === $value or
                                    $instance->getId() === (int)$posted_id)) {
                                $this->handleErrorAndStop(
                                    sprintf('Domain %1$s was blocked for instance %2$s', $value, $posted_id),
                                    __('Manipulating this domain is not allowed.', 'peatcms'));
                            } else { // validate the domain here
                                // test domain utf-8 characters: όνομα.gr
                                if (function_exists('idn_to_ascii')) {
                                    $value = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                                }
                                if (dns_check_record($value, 'A') or dns_check_record($value, 'AAAA')) {
                                    $posted_value = $value; // set the possibly to punycode converted value back
                                } else {
                                    $this->handleErrorAndStop(
                                        sprintf('Domain %1$s was not in DNS for instance %2$s', $value, $posted_id),
                                        __('Domain not found, check your input and try again later.', 'peatcms'));
                                }
                            }
                        } elseif ($posted_table_name === '_template') {
                            if ($posted_column_name === 'published') {
                                $temp = new Template(Help::getDB()->getTemplateRow($posted_id, null));
                                if (true === $admin->isRelatedInstanceId($temp->row->instance_id)) {
                                    // this always sends true as value, attempt to publish the template
                                    $update_arr = array(
                                        'published' => $temp->publish(),
                                    );
                                } else {
                                    $this->addMessage(__('Security warning, after multiple warnings your account may be blocked', 'peatcms'), 'warn');
                                }
                                unset($temp);
                            }
                        } elseif ($posted_column_name === 'to_slug') {
                            // you don’t have to check if the slug is valid, because this is duplicate by design
                            // but the value must be in the form of a slug or it is useless anyway
                            $update_arr = array('to_slug' => Help::slugify($posted_value));
                        }
                        /**
                         * Generic column update statement
                         */
                        if (Help::getDB()->updateColumns($posted_table_name, $update_arr, $posted_id)) {
                            $out = Help::getDB()->selectRow($posted_table_name, $posted_id);
                        } else {
                            $this->addError(Help::getDB()->getLastError());
                            $this->addMessage(__('Update column failed', 'peatcms'), 'error');
                        }
                        // check published for templates
                        if ($posted_table_name === '_template') {
                            // find the instance this admin is currently working with
                            if (($row = Help::getDB()->selectRow('_template', $posted_id))) {
                                $out->published = $this->updatePublishedForTemplates($row->instance_id, $posted_id);
                            }
                        }
                    }
                } elseif ($action === 'insert_row') {
                    $out = $this->insertRow($admin, $post_data);
                } elseif ($action === 'admin_popvote') {
                    $pop_vote = -1;
                    $element_name = $post_data->element_name;
                    $id = $post_data->id;
                    if (isset($post_data->direction)) {
                        if (($direction = $post_data->direction) === 'up') {
                            $pop_vote = Help::getDB()->updatePopVote($element_name, $id);
                        } elseif ($direction === 'down') {
                            $pop_vote = Help::getDB()->updatePopVote($element_name, $id, true);
                        } else { // return the relative position always
                            $pop_vote = Help::getDB()->getPopVote($element_name, $id);
                        }
                    }
                    $out = array('pop_vote' => $pop_vote);
                } elseif ($action === 'admin_set_homepage') {
                    if (isset($post_data->slug)) {
                        if (!$out = Help::getDB()->setHomepage(Setup::$instance_id, $post_data->slug)) {
                            $this->addError(sprintf(
                                '->getDB()->setHomepage failed with slug ‘%1$s’ for instanceid %2$s',
                                var_export($post_data->slug, true), Setup::$instance_id));
                            $out = $instance;
                        }
                    }
                } elseif ($action === 'file_upload_admin') {
                    if (isset($_SERVER['HTTP_X_FILE_NAME'])) {
                        if (false === defined('OUTPUT_JSON')) define('OUTPUT_JSON', true);
                        $el = null;
                        $x_file_name = urldecode($_SERVER['HTTP_X_FILE_NAME']);
                        // save the file temporarily
                        $temp_file = tempnam(sys_get_temp_dir(), $instance->getName() . '_');
                        $handle1 = fopen('php://input', 'r');
                        $handle2 = fopen($temp_file, 'w');
                        stream_copy_to_stream($handle1, $handle2);
                        fclose($handle1);
                        fclose($handle2);
                        $file_info = finfo_open(FILEINFO_MIME_TYPE);
                        $post_data = array();
                        $post_data['content_type'] = finfo_file($file_info, $temp_file);
                        $post_data['filename_original'] = $x_file_name; // a column that is not editable, but maybe you can search for it
                        // prepare a default element based on the uploaded file that will be created when a new element is needed
                        $default_type = 'file';
                        if (substr($post_data['content_type'], 0, 5) === 'image') $default_type = 'image';
                        // process it in cms
                        if (isset($_SERVER['HTTP_X_SLUG'])) {
                            if ($row = Help::getDB()->fetchElementIdAndTypeBySlug(urldecode($_SERVER['HTTP_X_SLUG']))) {
                                if (in_array($row->type, array('file', 'image'))) { // update the existing element when file or image
                                    $el = $this->updateElement($row->type, $post_data, $row->id);
                                } else { // make a new element and link it to this posted element (if possible)
                                    $el = $this->createElement($default_type);
                                    $el->update($post_data);
                                    $el->link($row->type, $row->id);
                                }
                            }
                        }
                        if (null === $el) { // if no slug or an invalid slug was provided, just create a new file
                            $el = $this->createElement($default_type);
                            $el->update($post_data);
                        }
                        if (false === $el->saveFile($temp_file)) {
                            $this->addError(__('File could not be processed at this time', 'peatcms'));
                        }
                        $out = $el->getOutput();
                    } else {
                        $this->addError(__('You need to be admin and provide X-File-Name header.', 'peatcms'));
                    }
                } elseif ('admin_database_report' === $action) {
                    $out = array('__rows__' => Help::unpackKeyValueRows(Help::getDB()->fetchAdminReport()));
                    $out['slug'] = 'admin_database_report';
                }
            } elseif ($action === 'reflect') {
                $out = $post_data;
            }
        } elseif (null !== $post_data && count(get_object_vars($post_data)) > 1) { // only add the error if you're not just asking for json
            $this->addMessage(sprintf(__('%s check failed, please refresh browser', 'peatcms'), 'CSRF'), 'warn');
        }
        if ($out !== null) {
            $out = (object)$out;
            $out->slugs = $GLOBALS['slugs'];
            if ($tag = $this->resolver->getRenderInTag()) $out->render_in_tag = $tag;
            // TODO make it generic / some fields can never be output
            if (isset($out->password_hash)) unset($out->password_hash);
            if (false === ADMIN && isset($out->recaptcha_secret_key)) unset($out->recaptcha_secret_key);
            if (defined('OUTPUT_JSON')) {
                // add messages and errors
                $out->__messages__ = Help::getMessages();
                if ($this->session->isAdmin()) $out->__adminerrors__ = Help::getErrorMessages();
                // pass timestamp when available
                if (isset($post_data->timestamp)) $out->timestamp = $post_data->timestamp;
                // @since 0.6.1 add any changed session vars for update on client
                $out->__session__ = $this->getSession()->getUpdatedVars();
                $out->session = $this->getSession()->getUpdatedVars(); // TODO remove this backwards compatibility from 0.10.9
                ob_clean(); // throw everything out the buffer means we can send a clean gzipped response
                $response = gzencode(json_encode($out), 6);
                unset($out);
                header('Content-Type: application/json');
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($response));
                echo $response;
                die();
            } else {
                if (isset($out->redirect_uri)) {
                    header('Location: ' . $out->redirect_uri, true, 302);
                    die();
                } else {
                    $this->addMessage(__('Fallen through action', 'peatcms'));
                    //\header('Location: /' . $this->resolver->getPath(), true, 307); <- doesn’t work
                    //die();
                }
            }
        }
        unset($post_data);
    }

    public function View()
    {
        $slug = $this->resolver->getPath();
        $variant_page = $this->resolver->getVariantPage();
        if (isset($_SERVER['HTTP_X_CACHE_TIMESTAMP'])) {
            $check_timestamp = (int)$_SERVER['HTTP_X_CACHE_TIMESTAMP'];
            // check if it’s ok, then just return ok immediately
            if (true === Help::getDB()->cachex($slug, $check_timestamp)) {
                if ($variant_page > 1) $slug .= '/variant_page' . $variant_page;
                $response = sprintf('{"__ref":"%s","x_cache_timestamp_ok":true}', rawurlencode($slug));
                header('Content-Type: application/json');
                header('Content-Length: ' . strlen($response));
                echo $response;
                die();
            }
        }
        $instance = $this->session->getInstance();
        // usually we get the element from cache, only when not cached yet get it from the resolver
        // @since 0.8.2 admin also gets from cache, table_info is added later anyway
        // warmup does an update of the cache row (getDB()->cache handles this automatically) so the client never misses out
        if ($this->resolver->hasInstructions()) {
            $element = $this->resolver->getElement($from_history, $this->session);
            $out = $element->getOutputObject();
        } elseif (null === ($out = Help::getDB()->cached($slug, $variant_page))) {
            // check if it’s a paging error
            $out = ($variant_page !== 1) ? Help::getDB()->cached($slug, 1) : null;
            if (null === $out) {
                $element = $this->resolver->getElement($from_history, $this->session);
                $out = $element->cacheOutputObject(true);
                unset($element);
                if (extension_loaded('newrelic')) {
                    $transaction_name = (ADMIN) ? 'Admin: ' : 'Visit: ';
                    newrelic_name_transaction($transaction_name . 'cache');
                }
            }
        }
        // set path to __ref if not present
        if (isset($out->__ref)) {
            $out_path =& $out->slugs->{$out->__ref}->path;
            if (!isset($out_path)) $out_path = $out->__ref;
        }
        // variant paging
        if (isset($out->variant_page) && $out->variant_page !== 1) {
            $out->slugs->{$out->__ref}->path .= '/variant_page' . $out->variant_page;
        }
        // use a properly filled element to check some of the settings
        $base_element = (true === isset($out->__ref)) ? $out->slugs->{$out->__ref} : $out;
        $render_in_tag = $this->resolver->getRenderInTag();
        if (null !== $render_in_tag) $out->render_in_tag = $render_in_tag;
        if (true === ADMIN) {
            // security: check access
            if (isset($base_element->instance_id) and $base_element->instance_id !== Setup::$instance_id) {
                if (false === $this->session->getAdmin()->isRelatedInstanceId($base_element->instance_id)) {
                    $this->handleErrorAndStop(
                        sprintf('admin %1$s tried to access %2$s (instance_id %3$s)',
                            $this->session->getAdmin()->getId(),
                            $this->resolver->getPath(),
                            $base_element->instance_id)
                        , __('It seems this does not belong to you', 'peatcms')
                    );
                }
            }
            // prepare object for admin
            $out->__adminerrors__ = Help::getErrorMessages();
            // @since 0.8.2 admin also uses cache
            $type = $base_element->type;
            if ('search' !== $type) $out->table_info = $this->getTableInfoForOutput(new Type($type));
        } else {
            if (
                // @since 0.7.6 do not show items that are not online
                (isset($base_element->online) && false === $base_element->online)
                // @since 0.8.19 do not show items that are not yet published
                || (isset($base_element->is_published) and false === $base_element->is_published)
            ) {
                $element = new Search();
                $element->findWeighted(array($base_element->title));
                $out = $element->getOutputObject();
                if (null !== $render_in_tag) $out->render_in_tag = $render_in_tag;
            }
        }
        unset($base_element);
        // @since 0.7.9 load the properties in the out object as well
        $out->__query_properties__ = $this->resolver->getProperties();
        $out->template_published = strtotime($instance->getSetting('date_updated'));
        $out->is_admin = ADMIN;
        // TODO make it generic / some fields can never be output
        if (isset($out->password_hash)) unset($out->password_hash);
        if (isset($out->recaptcha_secret_key)) unset($out->recaptcha_secret_key);
        // output
        if (defined('OUTPUT_JSON')) {
            if (($post_data = $this->resolver->getPostData())) {
                if (isset($post_data->timestamp)) {
                    $out->timestamp = $post_data->timestamp;
                }
            }
            $out->__messages__ = Help::getMessages();
            // @since 0.6.1 add any changed session vars for update on client
            $out->__session__ = $this->getSession()->getUpdatedVars();
            $out->session = $this->getSession()->getUpdatedVars(); // TODO remove this backwards compatibility from 0.10.9
            ob_clean(); // throw everything out the buffer means we can send a clean gzipped response
            $response = gzencode(json_encode($out), 6);
            unset($out);
            header('Content-Type: application/json');
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($response));
            echo $response;
        } else { // use template
            if (true === $instance->isParked() and false === ADMIN and false === $this->resolver->hasInstruction('admin')) {
                if (file_exists(CORE . '../htdocs/instance/' . $instance->getPresentationInstance() . '/park.html')) {
                    header('Location: /instance/' . $instance->getPresentationInstance() . '/park.html', TRUE, 307);
                    die();
                } else {
                    die(sprintf(__('Website is parked, but %s is not found.', 'peatcms'), 'park.html'));
                }
            }
            $session = $this->getSession();
            // add some global items
            $out->csrf_token = $session->getValue('csrf_token'); // necessary for login page at the moment
            $out->nonce = Help::randomString(32);
            $out->version = Setup::$VERSION;
            $out->root = $instance->getDomain(true);
            $out->presentation_instance = $instance->getPresentationInstance();
            // @since 0.7.9 get the user and setup account related stuff
            $user = $session->getUser();
            $out->is_account = ($user !== null);
            // @since 0.8.18 TODO make a mechanism to distinguish session values that must be directly output...
            $out->dark_mode = $session->getValue('dark_mode');
            // render in template
            $temp = new Template();
            // @since 0.10.6 add complex tags (menus, instagram feeds, other elements) to make integral to the first output
            $temp->addComplexTags($out);
            // render the page already
            $temp->render($out);
            // render server values for the site to be picked up by javascript client
            $temp->renderGlobalsOnce(array(
                'version' => Setup::$VERSION,
                'nonce' => $out->nonce,
                'decimal_separator' => Setup::$DECIMAL_SEPARATOR,
                'radix' => Setup::$RADIX,
                'google_tracking_id' => $instance->getSetting('google_tracking_id'),
                'recaptcha_site_key' => $instance->getSetting('recaptcha_site_key'),
                'root' => $out->root,
                'presentation_instance' => $out->presentation_instance,
                'session' => $session->getVars(),
                'slug' => $out,
                'slugs' => $GLOBALS['slugs'],
                'is_account' => $out->is_account,
                '__user__' => ($user === null) ? (object)null : $user->getOutput(),
                '__messages__' => Help::getMessages(),
            ));
            if (true === ADMIN) {
                // TODO show the hints as well... and update live maybe, not only once
                $out->__adminerrors__ = Help::getErrorMessages(); // re-assign, for there may be some messages added...
                $temp->renderConsole($out);
            }
            // set content security policy header (CSP), which can differ between instances
            $csp = "frame-ancestors 'none';default-src 'self' https://player.vimeo.com https://www.youtube-nocookie.com https://www.youtube.com https://www.google.com; script-src 'self' 'nonce-$out->nonce'; connect-src 'self' https://*.google-analytics.com; img-src 'self' blob: " . Setup::$CDNROOT . " *.googletagmanager.com https://*.google-analytics.com data:;font-src 'self' https://fonts.gstatic.com https://*.typekit.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://*.typekit.net;base-uri 'self';form-action 'self';";
            // TODO make it flexible using settings for the instance
            header(sprintf('Content-Security-Policy: %s', $csp), true);
            unset($out);
            if (ob_get_length()) { // false or 0 when there's no content in it
                echo $temp->getCleanedHtml();
            } else {
                $response = gzencode($temp->getCleanedHtml(), 6);
                unset($temp);
                header('Content-Type: text/html');
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($response));
                echo $response;
            }
        }
        die();
        /**
         * Testing
         *
         * $str = 'Étiø tíeft: 整 /__नारी / <hr/> 100% v/d Russen_zegt-ola: Австралия <hr/>';
         * echo $str;
         * echo 'Help::slugify(): ' . Help::slugify($str) . '<br/>';
         * echo 'preg_replace 1: ' . preg_replace('/[^\p{L}\s]/u','',$str) . '<br/>';
         * echo 'preg_replace 2: ' . preg_replace('/[^\p{L}\p{N}\p{M}\s]/u','',$str) . '<br/>';
         * echo 'DB()->slugify(): ' . Help::getDB()->slugify($str);
         */
    }

    private function sendMail(Instance $instance, \stdClass $post_data): ?\stdClass
    {
        $out = null;
        if (true === isset($post_data->from_email)
            && strpos(($from_email = $post_data->from_email), '@')
        ) {
            if (isset($post_data->template) && $template_row = Help::getDB()->getMailTemplate($post_data->template)) {
                $temp = new Template($template_row);
                $body = $temp->renderObject($post_data);
            }
            if (false === isset($body)) {
                $body = \var_export($post_data, true);
            }
            if (true === isset($post_data->to)) {
                $to = $post_data->to;
                $allowed_recipients = array_map('trim', explode(',', $instance->getSetting('mail_form_allowed_to') ?? ''));
                if (false === in_array($to, $allowed_recipients)) {
                    $this->addMessage(__('‘To’ mail address not in allow list for this instance.', 'peatcms'), 'warn');
                    $to = $instance->getSetting('mail_default_receiver');
                }
            } else {
                $to = $instance->getSetting('mail_default_receiver');
            }
            $mail = new Mailer($instance->getSetting('mailgun_custom_domain'));
            $mail->set(array(
                'to' => $to,
                'from' => $instance->getSetting('mail_verified_sender'),
                'reply_to' => $from_email,
                'subject' => $post_data->subject ?? 'Mailed by ' . $instance->getDomain(),
                'text' => Help::html_to_text($body),
                'html' => str_replace("\n", '<br/>', $body),
            ));
            $out = $mail->send();
            if (false === $out->success) {
                if (isset($post_data->failure_message)) {
                    $this->addMessage($post_data->failure_message, 'error');
                } else {
                    $this->addMessage(__('Failed to send mail', 'peatcms'), 'error');
                }
            } elseif (isset($post_data->success_message)) {
                $this->addMessage($post_data->success_message);
            }
        } else {
            $this->addError('‘from_email’ is missing or not an e-mailaddress');
            if (isset($post_data->failure_message)) {
                $this->addMessage($post_data->failure_message, 'error');
            } else {
                $this->addMessage(__('Failed to send mail', 'peatcms'), 'error');
            }
        }

        return $out;
    }

    /**
     * @param string $action what you want to do with the posted data in the list: add_to_list, remove_from_list or update_quantity_in_list
     * @param \stdClass $data the posted data
     * @return bool success
     * @since 0.5.1
     */
    private function updateList(string $action, \stdClass $data): bool
    {
        // validate
        if (false === isset($data->shoppinglist)) {
            // todo I don't think this belongs here
            if (($res = $this->resolver)->hasInstruction('shoppinglist') and isset($res->getTerms()[0])) {
                $data->shoppinglist = $res->getTerms()[0];
            } else {
                $this->addMessage(sprintf(__('Form input ‘%s’ is missing', 'peatcms'), 'shoppinglist'), 'warn');

                return false;
            }
        }
        $list_name = $data->shoppinglist;
        if (false === isset($data->variant_id)) {
            $this->addMessage(sprintf(__('Form input ‘%s’ is missing', 'peatcms'), 'variant_id'), 'warn');

            return false;
        }
        $variant_id = $data->variant_id;
        if (null === ($variant_id = Help::getAsInteger($variant_id))) {
            $this->addMessage(sprintf(__('Form input ‘%1$s’ must be %2$s', 'peatcms'), 'variant_id', 'integer'), 'warn');

            return false;
        }
        // get the quantity (defaults to 1)
        $quantity = (isset($data->quantity) ? Help::getAsInteger($data->quantity, 1) : 1);
        // update list
        $variant = $this->getElementById('variant', $variant_id);
        if ($variant instanceof Variant) {
            $list = new Shoppinglist($list_name, $this->getSession());
            if ($action === 'add_to_list') {
                if (false === $list->addVariant($variant, $quantity)) {
                    $this->addMessage(sprintf(__('Adding to list ‘%s’ failed', 'peatcms'), $list_name), 'warn');

                    return false;
                }
            } elseif ($action === 'remove_from_list') {
                if (false === $list->removeVariant($variant)) {
                    $this->addMessage(sprintf(__('Removing from list ‘%s’ failed', 'peatcms'), $list_name), 'warn');

                    return false;
                }
            } elseif ($action === 'update_quantity_in_list') {
                if (false === $list->updateQuantity($variant, $quantity)) {
                    $this->addMessage(sprintf(__('Update quantity in list ‘%s’ failed', 'peatcms'), $list_name), 'warn');

                    return false;
                }
            }

            return true;
        } else {
            // error message element not found
            $this->addMessage(sprintf(__('No ‘%1$s’ found with id %2$s', 'peatcms'), 'variant', $variant_id), 'error');
        }

        return false;
    }

    public function getSession()
    {
        return $this->session;
    }

    /**
     * @param string $type_name The name of the element
     * @param $data array containing column and value pairs to be updated
     * @param int $id The id of the element
     * @return BaseElement|null Returns the updated element when succeeded, null when update failed
     */
    private function updateElement(string $type_name, array $data, int $id): ?BaseElement
    {
        // TODO access control permissions based on admin etc. -> is the admin from a client that can handle this instance? And do they have that role?
        if ($type_name !== 'search') {
            $peat_type = new Type($type_name);
            $el = $peat_type->getElement($this->getElementRow($peat_type, $id));
            if ($el->update($data)) {
                return $el;
            }
        }
        $this->addError(sprintf('Update ‘%s’ failed', $type_name));

        return null;
    }

    private function getElementSuggestions(string $type_name, string $src = ''): ?object
    {
        if ($type_name === 'x_value') {
            return Help::getDB()->fetchPropertiesRowSuggestions($src);
        } elseif ($type = new Type($type_name)) {
            return Help::getDB()->fetchElementRowSuggestions($type, $src);
        }

        return null;
    }

    private function getElements(string $type_name): array
    {
        return Help::getDB()->fetchElementRowsWhere(new Type($type_name), array());
    }

    private function getElementRow(Type $peat_type, int $id = 0): ?\stdClass
    {
        if ('search' === $peat_type->typeName()) return null;

        return Help::getDB()->fetchElementRow($peat_type, $id);
    }

    private function getElementById(string $type_name, int $id): ?BaseElement
    {
        if ($peat_type = new Type($type_name)) {
            if ($row = Help::getDB()->fetchElementRow($peat_type, $id)) {
                return $peat_type->getElement($row);
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

    private function createElement(string $type_name, ?bool $online = false): ?BaseElement
    {
        // TODO access control permissions
        if (!$this->getSession()->isAdmin()) return null;
        if ($type_name !== 'search' && $peat_type = new Type($type_name)) {
            $el = $peat_type->getElement();
            if ($id = $el->create($online)) {
                if ($el->fetchById($id)) {
                    return $el;
                } else {
                    $this->addError(sprintf('Element ‘%s’ created, but could not be retrieved with id %d', $type_name, $id));
                }
            } else {
                $this->addError('element->create() failed');
            }
        } else {
            $this->addError(sprintf('Element ‘%s’ cannot be created by ->createElement()', $type_name));
        }

        return null;
    }

    private function insertRow(Admin $admin, \stdClass $post_data): ?object
    {
        if ($post_data->table_name === '_instance') {
            // permission check: only admins with instance_id = 0 can insert new instances...
            if ($admin->getOutput()->instance_id !== 0) {
                $this->addMessage(
                    __('Security warning, after multiple warnings your account may be blocked',
                        'peatcms'), 'warn');

                return null;
            }
            if ($instance_id = Help::getDB()->insertInstance(
                'example.com',
                __('New instance', 'peatcms'),
                //$this->Session->getAdmin()->getClient()->getOutput()->client_id
                $admin->getClient()->getId()
            )) {
                // a new instance must have a homepage
                if ($page_id = Help::getDB()->insertElement(new Type('page'), array(
                    'title' => 'homepage',
                    'slug' => 'home',
                    'template' => 'peatcms',
                    'content' => 'My first homepage',
                    'instance_id' => $instance_id
                ))) {
                    Help::getDB()->updateInstance($instance_id, array('homepage_id' => $page_id));

                    return Help::getDB()->selectRow('_instance', $instance_id);
                } else {
                    $this->addError('Could not add homepage to instance');

                    return (object)true;
                }
            } else {
                $this->addError('Could not create new instance');

                return (object)true;
            }
        } else { // authorize admin for current instance to manipulate it
            if ($post_data->where->parent_id_name === 'instance_id'
                and $instance_id = (int)$post_data->where->parent_id_value
                and $admin->isRelatedInstanceId($instance_id)) {
                // switch tasks according to table name
                switch ($post_data->table_name) {
                    case '_country':
                        if ($country_id = Help::getDB()->insertCountry(
                            __('New country', 'peatcms'),
                            $instance_id,
                        )) {
                            return Help::getDB()->selectRow('_country', $country_id);
                        } else {
                            $this->addError('Could not create new country');

                            return (object)true;
                        }
                    case '_template':
                        if ($template_id = Help::getDB()->insertTemplate(
                            __('New template', 'peatcms'),
                            $instance_id,
                        )) {
                            return Help::getDB()->selectRow('_template', $template_id);
                        } else {
                            $this->addError('Could not create new template');

                            return (object)true;
                        }
                    case '_instance_domain':
                        if ($new_instance_domain_key = Help::getDB()->insertInstanceDomain(array(
                            'domain' => 'example.com',
                            'instance_id' => $instance_id,
                        ))) {
                            return Help::getDB()->selectRow('_instance_domain', $new_instance_domain_key);
                        } else {
                            $this->addError('Could not create domain');

                            return (object)true;
                        }
                    case '_admin':
                        $instance = new Instance(Help::getDB()->selectRow('_instance', $instance_id));
                        if ($client_id = $instance->getClientId()) {
                            $domain_name = str_replace('www.', '', ($instance->getDomain()));
                            if (null === Help::getDB()->insertAdmin(
                                    Help::randomString(10) . '@' . $domain_name, // email
                                    Help::passwordHash(Help::randomString(10)), // password
                                    $client_id, // client_id
                                    $instance_id // instance_id
                                )) {
                                $this->addMessage('Insert failed', 'peatcms');
                            }
                        }
                        break;
                    case '_payment_service_provider':
                        if ($psp_id = Help::getDB()->insertPaymentServiceProvider(
                            __('New psp', 'peatcms'),
                            $instance_id,
                        )) {
                            return Help::getDB()->selectRow('_payment_service_provider', $psp_id);
                        } else {
                            $this->addError('Could not create new psp');

                            return (object)true;
                        }
                    case '_redirect':
                        Help::getDB()->insertRowAndReturnKey('_redirect', array(
                            'instance_id' => $instance_id,
                            'term' => '',
                            'to_slug' => 'to-slug',
                        ));
                        break;
                    case '_vat_category':
                        Help::getDB()->insertRowAndReturnKey('_vat_category', array(
                            'instance_id' => $instance_id,
                            'title' => __('vat', 'peatcms'),
                            'percentage' => '21',
                        ));
                        break;
                    default:
                        $this->handleErrorAndStop(sprintf('Table ‘%s’ not recognized by insert_row',
                            $post_data->table_name));
                }
            } elseif ($post_data->where->parent_id_name === 'instagram_auth_id'
                and $instagram_auth_id = (int)$post_data->where->parent_id_value) {
                // check if the user already has access to this instagram authorization:
                $rows = Help::getDB()->getInstagramAuthorizations();
                foreach ($rows as $index => $row) {
                    if ($row->instagram_auth_id === $instagram_auth_id) {
                        $feed_id = Help::getDB()->insertRowAndReturnKey('_instagram_feed', array(
                            'instagram_auth_id' => $instagram_auth_id,
                            'instance_id' => Setup::$instance_id,
                            'quantity' => 12,
                        ));

                        return (object)array('instagram_feed_id' => $feed_id);
                    }
                }
                $this->addMessage('You must (re-)authorize Instagram', 'warn');

                return (object)true;
            } else {
                $this->addError('No appropriate parent id received');

                return (object)true;
            }
        }

        return null;
    }

    private function updatePublishedForTemplates(int $instance_id, int $return_for_template_id = 0): ?bool
    {
        $published_for_template_id = null;
        // run through all the templates for this instance to set their published value correctly
        $rows = Help::getDB()->getTemplates($instance_id);
        foreach ($rows as $key => $row) {
            $temp = new Template($row);
            if (($published = $temp->checkIfPublished()) !== $row->published) { // update the value
                Help::getDB()->updateColumns('_template', array('published' => $published), $row->template_id);
            }
            unset($temp);
            // if this is the current element set the published value for return as well
            if ($row->template_id === $return_for_template_id) $published_for_template_id = $published;
        }
        unset($rows);

        return $published_for_template_id;
    }

    /**
     * should (is...) only be called by admin, since it may contain sensitive information
     *
     * @param Type $peat_type
     * @return \stdClass|null information about the database table (of an element), or null if there isn't
     */
    public function getTableInfoForOutput(Type $peat_type): ?\stdClass
    {
        $arr = (array)Help::getDB()->getTableInfo($peat_type->tableName());
        $info = new \stdClass();
        foreach ($arr as $key => $value) {
            // trim is needed to remove the \0 byte denoting a (former) private property
            // https://stackoverflow.com/questions/5484574/php-fatal-error-cannot-access-property-started-with-0
            $key = trim(str_replace('Peat\TableInfo', '', $key)); // also remove className
            // convert all the columns (which are an object with private properties) to arrays as well
            if ($key === 'columns') {
                foreach ($value as $column_name => $properties) {
                    // cleanup the properties as well
                    $arr_props = (array)$properties;
                    foreach ($arr_props as $prop_name => $prop_value) {
                        $arr_props[trim(str_replace('Peat\Column', '', $prop_name))] = $prop_value;
                        unset($arr_props[$prop_name]);
                    }
                    $value[$column_name] = $arr_props;
                    $arr_props = null;
                }
            }
            $info->$key = $value;
        }
        $arr = null;
        $info->link_tables = Help::getDB()->getLinkTables($peat_type);

        return $info;
    }
}