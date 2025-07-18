<?php
declare(strict_types=1);

namespace Bloembraaden;
class Handler extends BaseLogic
{
    private Resolver $resolver;
    private ?string $action;

    public function __construct()
    {
        parent::__construct();
        // the resolver will set up itself based on the supplied url, and then set up the necessary global constants
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
        $instance = Help::$session->getInstance();
        // NOTE you always get the current version even if you ask for a previous one, this is no big deal I think
        $version = Setup::$VERSION . '-' . strtotime($instance->getSetting('date_updated'));
        // start with some actions that are valid without csrf
        if ('javascript' === $action) {
            // @since 0.7.6 get cached version when available for non-admins
            $file_location = Setup::$DBCACHE . 'js/' . Setup::$instance_id . "-$version.js.gz";
            if (false === ADMIN && true === file_exists($file_location)) {
                $response = file_get_contents($file_location);
                header('Cache-Control: max-age=31536000'); //1 year (60sec * 60min * 24hours * 365days)
                header('Content-Type: text/javascript');
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($response));
                echo $response;
                die();
            }
            // $doc = the javascript file we’re going to build
            $doc = "'use strict';var VERSION='$version';var VERBOSE=" . (Setup::$VERBOSE ? 'true' : 'false') . ";\n";
            $doc .= file_get_contents(CORE . "../htdocs/_site/{$instance->getPresentationInstance()}/script.js");
            $doc .= file_get_contents(CORE . '_front/peat.js');
            //if (ADMIN) $doc .= \file_get_contents(CORE . '../htdocs/_front/admin.js'); <- added by console later
            if (ADMIN) {
                header('Content-Type: text/javascript');
                echo $doc;
                die();
            }
            try {
                $doc = \JShrink\Minifier::minify($doc);
            } catch (\Exception $e) {
                $this->addError($e->getMessage());
            }
            $response = gzencode($doc, 9);
            $doc = null;
            // @since 0.7.6 cache this file on disk (if it isn’t cached already by another concurrent request)
            if (false === file_exists($file_location)) file_put_contents($file_location, $response, LOCK_EX);
            header('Cache-Control: max-age=31536000'); //1 year (60sec * 60min * 24hours * 365days)
            header('Content-Type: text/javascript');
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($response));
            echo $response;
            die();
        } elseif ('stylesheet' === $action) {
            // TODO the stylesheet is cached in htdocs/_site now, so this is only for admins
            // @since 0.7.6 get cached version when available for non-admins
            $file_location = Setup::$DBCACHE . 'css/' . Setup::$instance_id . "-$version.css.gz";
            if (false === ADMIN && true === file_exists($file_location)) {
                $response = file_get_contents($file_location);
                header('Cache-Control: max-age=31536000'); //1 year (60sec * 60min * 24hours * 365days)
                header('Content-Type: text/css');
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($response));
                echo $response;
                die();
            }
            $doc = file_get_contents(CORE . '_front/peat.css');
            $doc .= file_get_contents(CORE . '../htdocs/_site/' . $instance->getPresentationInstance() . '/style.css');
            $response = gzencode($doc, 9);
            $doc = null;
            header('Cache-Control: max-age=31536000'); //1 year (60sec * 60min * 24hours * 365days)
            header('Content-Type: text/css');
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($response));
            // @since 0.7.6 cache this file on disk (if it isn’t cached already by another concurrent request)
            if (false === file_exists($file_location)) file_put_contents($file_location, $response, LOCK_EX);
            echo $response;
            die();
        } elseif ('poll' === $action) {
            Help::$OUTPUT_JSON = true;
            // get any update since last time, so the client can fetch it when appropriate
            $props = $this->resolver->getProperties();
            $until = Setup::getNow();
            if (true === isset($props['from'][0]) && 0 < ($timestamp = (int)$props['from'][0])) {
                $rows = Help::getDB()->fetchHistoryFrom($timestamp, ADMIN);
            } else {
                $rows = array();
            }
            // this is a get request, without csrf or admin, so don’t give any specific information
            $out = array('changes' => $rows, 'is_admin' => ADMIN, 'until' => $until);
        } elseif ('session' === $action) {
            Help::$OUTPUT_JSON = true;
            // get timestamps for session and user
            $user = Help::$session->getUser();
            $out = array('session' => Help::$session->getUpdatedTimestamp());
            if ($user) {
                $out['user'] = Help::getDB()->fetchHistoryTimestamp(array('user_id' => $user->getId()));
            }
        } elseif ('get_template' === $action) {
            // NOTE since a template can contain a template for __messages__, you may never add __messages__ to the template object
            if (true === isset($post_data->template_name)) {
                // as of 0.5.5 load templates by id (from cache) with fallback to the old ways
                if (true === isset($post_data->template_id) && is_numeric(($template_id = $post_data->template_id))) {
                    if (ADMIN && ($row = Help::getDB()->fetchTemplateRow($template_id, Setup::$instance_id))) {
                        $temp = new Template($row);
                        if (false === $temp->checkIfPublished()) {
                            $out = $temp->getFreshJson();
                            $out['__template_status__'] = 'sandbox';
                            echo json_encode($out);
                            die();
                        }
                    }
                    $filename = Setup::$DBCACHE . "templates/$template_id.gz";
                    if (true === file_exists($filename)) {
                        header('Content-Type: application/json');
                        header('Content-Encoding: gzip');
                        header('Content-Length: ' . filesize($filename));
                        readfile($filename);
                        die();
                    }
                }
                // use Template() by loading html from disk
                $temp = new Template(null);
                $admin = ((true === isset($post_data->admin) && true === $post_data->admin) && Help::$session->isAdmin());
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
                $this->handleErrorAndStop(sprintf('No template_name found in data %s.', \var_export($post_data, true)),
                    __('Could not load template.', 'peatcms'));
            }
        } elseif ('get_template_by_name' === $action) {
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
                            __('Template not found on disk with id %s.', 'peatcms'),
                            $template_row->template_id
                        ));
                    }
                }
            }
        } elseif ('suggest' === $action) {
            // suggest should post type and id so we can infer some interesting stuff
            $src = new Search();
            // terms can be passed as query string ?__terms__=term1,term2 etc in the complex tag, as can limit
            $props = $this->resolver->getProperties();
            $limit = (int)$this->resolver->getInstruction('limit') ?: 8;
            $terms = $this->resolver->getTerms();
            $src->setProperties($props);
            // allow overriding the type
            if (($type_name = $this->resolver->getInstruction('type'))) {
                unset($post_data->id); // this is no longer the correct id
            } else {
                $type_name = $post_data->type ?? 'variant';
            }
            if ($this->resolver->hasInstruction('shoppinglist')) { // based on current item(s) in list
                if (true === ($name = $this->resolver->getInstruction('shoppinglist'))) $name = '';
                $out = array('__variants__' => $src->getRelatedForShoppinglist($name, $limit));
            } elseif ('shoppinglist' === $type_name) { // from the shoppinglist page
                $out = array('__variants__' => $src->getRelatedForShoppinglist($post_data->name, $limit));
            } elseif ('variant' === $type_name) {
                $out = array('__variants__' => $src->getRelatedForVariant($terms, $post_data->id ?? 0, $limit));
            } elseif ('page' === $type_name) {
                if (count($terms) < 1 && isset($post_data->id)) {
                    $out = array('__pages__' => $src->getRelatedForPage($post_data->id, $limit));
                } else {
                    $out = array('__pages__' => $src->suggestPages($terms, $limit));
                }
            } else {
                if (true === isset($post_data->hydrate_until)) {
                    $hydrate_until = (int)$post_data->hydrate_until;
                } elseif (true === isset($props['hydrate_until'][0])) {
                    $hydrate_until = (int)($props['hydrate_until'][0]);
                } else {
                    $hydrate_until = -$limit;
                }
                if (true === isset($post_data->only_of_type)) {
                    $src->findWeighted($terms, $hydrate_until, array($post_data->only_of_type), false);
                } else {
                    $src->findWeighted($terms, $hydrate_until, (array)($post_data->ignore ?? null));
                }
                $out = $src->getOutput();
            }
            $src = null;
            if (is_array($out)) {
                $out['slug'] = 'suggest';
            }
            //$out = array('__variants__' => $variants, 'slug' => 'suggest');
        } elseif ('download' === $action) {
            if (($slug = $this->resolver->getTerms()[0] ?? null)) {
                if (null === ($el = Help::getDB()->fetchElementIdAndTypeBySlug($slug))) {
                    $el = Help::getDB()->fetchElementIdAndTypeByAncientSlug($slug);
                }
                if ($el && 'file' === $el->type_name) {
                    $file = new File(Help::getDB()->fetchElementRow(new Type('file'), $el->id));
                    $file->serve();
                } else {
                    $this->addError(sprintf('Download: no file found with slug %s.', $slug));
                    $this->addMessage(__('File not found.', 'peatcms'), 'warn');
                }
            }
        } elseif ('account_delete_session' === $action) {
            if (false === isset(($terms = $this->resolver->getTerms())[0])) {
                Help::$session->delete();
                $this->addMessage(__('Session has been deleted.', 'peatcms'), 'log');
                $out = array('success' => true, 'is_account' => false, '__user__' => new \stdClass());
            } else {
                $session_id = (int)$terms[0];
                $my_session = Help::$session;
                if ($session_id === $my_session->getId()) {
                    $this->addMessage(__('You can not destroy your own session this way.', 'peatcms'), 'warn');
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
                    $this->addMessage(__('Failed destroying the session.', 'peatcms'), 'error');
                }
            }
        } elseif ('payment_status_update' === $action) {
            //header('Access-Control-Allow-Origin: https://apirequest.io'); // This is temp for testing, not necessary for curl
            Help::$OUTPUT_JSON = true;
            if (($psp = $instance->getPaymentServiceProvider())) {
                if (true === $psp->updatePaymentStatus($post_data)) {
                    $out = $psp->successBody();
                } else {
                    $this->handleErrorAndStop('Did not accept payment_status_update with ' . json_encode($post_data));
                }
            } else {
                $this->handleErrorAndStop(
                    sprintf('Could not get PaymentServiceProvider for %s.', $instance->getName()),
                    __('No PaymentServiceProvider found.', 'peatcms')
                );
            }
        } elseif ('payment_return' === $action) {
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
                    sprintf('Could not get PaymentServiceProvider for %s.', $instance->getName()),
                    __('No PaymentServiceProvider found.', 'peatcms')
                );
            }
        } elseif ('properties' === $action) { // @since 0.8.11: retrieve all the properties and relations for this instance
            $for = $post_data->for ?? 'variant';
            $out = array('properties' => Help::getDB()->fetchProperties($for));
        } elseif ('properties_valid_values_for_path' === $action) { // @since 0.8.12: retrieve valid options for the path
            // get _all_ the variant id’s for this path, and then all the property_values that are coupled
            // only property and property_value can be filtered thusly, and search as well through its own method
            // in all other cases return an empty array (which should close the filter)
            if (true === isset($post_data->path)) {
                $src = new Search();
                $out = $src->getRelevantPropertyValuesAndPrices($post_data->path);
            }
        } elseif ('pay' === $action) { // this is a payment link, supply the order number and slug of the payment page
            $properties = $this->resolver->getProperties();
            if (true === isset($properties['order_number'])) {
                $order_number = str_replace(' ', '', htmlentities($properties['order_number'][0]));
                // check a couple of things: if its already paid, do not do this (ie payment_transaction_id has to be NULL)
                // else remove the tracking id so payment_start can be fresh
                if (($order_row = Help::getDB()->getOrderByNumber($order_number))) {
                    if ($order_row->payment_confirmed_bool) {
                        $this->addMessage(sprintf(__('Order %s is marked as paid.', 'peatcms'), $order_number), 'note');
                        $out = (object)array('redirect_uri' => '/');
                    } else {
                        Help::getDB()->updateElement(new Type('order'), array(
                            'payment_tracking_id' => NULL,
                            'payment_transaction_id' => NULL,
                        ), $order_row->order_id);
                        Help::$session->setVar('order_number', $order_number);
                        if (true === isset($properties['slug'][0])) {
                            $redirect_uri = Help::slugify($properties['slug'][0]);
                        } else {
                            $redirect_uri = 'payment_link';
                        }
                        $out = (object)array('redirect_uri' => "/$redirect_uri");
                    }
                } else {
                    $this->addMessage(sprintf(__('Order %s not found.', 'peatcms'), $order_number), 'warn');
                }
            }
        } elseif ('reorder' === $action) {
            $properties = $this->resolver->getProperties();
            if (false === isset($properties['shoppinglist'])) {
                $this->addError('Shoppinglist is not set for order action.');
                $out = true;
            } elseif (null === ($user = Help::$session->getUser())) {
                $this->addMessage(__('You must be logged in to reorder.', 'peatcms'), 'warn');
                $out = true;
            } elseif (true === isset($properties['order_number'])) {
                $shoppinglist_name = $properties['shoppinglist'][0];
                $shoppinglist = new Shoppinglist($shoppinglist_name);
                $order_number = str_replace(' ', '', htmlentities($properties['order_number'][0]));
                if (($order_row = Help::getDB()->getOrderByNumber($order_number))) {
                    if ($order_row->user_id !== $user->getId()) {
                        $this->addMessage(__('You can only reorder your own orders.', 'peatcms'), 'warn');
                        $out = true;
                    } else {
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
                            $this->addMessage(sprintf(__('%1$s order row added to %2$s.', 'peatcms'), $count, $shoppinglist_name));
                        } else {
                            $this->addMessage(sprintf(__('%1$s order rows added to %2$s.', 'peatcms'), $count, $shoppinglist_name));
                        }
                        if (true === isset($properties['redirect_uri'])) {
                            $out = array('redirect_uri' => $properties['redirect_uri'][0]);
                        } else {
                            $out = array('redirect_uri' => "/__shoppinglist__/$shoppinglist_name");
                        }
                    }
                } else {
                    $this->addMessage(__('Order not found.', 'peatcms'), 'warn');
                }
            }
        } elseif ('invoice' === $action) {
            if (false === Help::$session->getAdmin() instanceof Admin) {
                $this->addMessage(__('Invoice can only be accessed by admin.', 'peatcms'), 'warn');
            } elseif (true === isset($this->resolver->getProperties()['order_number'])) {
                $order_number = htmlentities(trim($this->resolver->getProperties()['order_number'][0]));
                $filename = Help::getInvoiceFileName($order_number);
                //#TRANSLATORS this is the invoice title, %s is the order number
                $filename_for_client = Help::slugify(sprintf(__('Invoice for order %s', 'peatcms'), $order_number));
                if (true === file_exists($filename)) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $filename_for_client . '.pdf"');
                    header('Content-Length: ' . filesize($filename));
                    readfile($filename);
                    die();
                } else {
                    $this->addMessage(sprintf(__('File not found: %s.', 'peatcms'), basename($filename)));
                }
            }
        } elseif ('process_file' === $action) {
            $props = $this->resolver->getProperties();
            $sse = new SseLogger();
            if (false === Help::$session->getAdmin() instanceof Admin) {
                $sse->addError('To process a file you must be admin.');
                die();
            }
            if (($slug = $props['slug'][0])) {
                // find element by slug
                if (null === ($row = Help::getDB()->fetchElementIdAndTypeBySlug($slug))) {
                    $this->addError(sprintf('process_file: no element found with slug %s.', $slug));
                    $sse->log(sprintf(__('Could not process %s, check your logs to know more.', 'peatcms'), $slug));
                }
                $peat_type = new Type($row->type_name);
                $element = $peat_type->getElement();
                // null means element is deleted or something
                if (null === $element->fetchById($row->id)) {
                    $this->addError(sprintf('process_file: could not get element for slug %s.', $slug));
                    $sse->log(sprintf(__('Could not process %s, check your logs to know more.', 'peatcms'), $slug));
                }
                if (method_exists($element, 'process')) {
                    $level = (int)$props['level'][0] ?? 1;
                    $element->process($sse, $level);
                } else {
                    $this->addError(sprintf('process_file: element %s has no method process.', $slug));
                    $sse->log(sprintf(__('Could not process %s, check your logs to know more.', 'peatcms'), $slug));
                }
            } else {
                $sse->log(__('No slug found to process.', 'peatcms'));
            }
            die(); // after an sse logger you cannot provide any more content yo
        } elseif ('admin_import_export_instance' === $action) {
            $props = $this->resolver->getProperties();
            $sse = new SseLogger();
            if (false === ($admin = Help::$session->getAdmin()) instanceof Admin) {
                $sse->log(__('Import / export can only be accessed by admin.', 'peatcms'));
                $sse->addError('Import / export can only be accessed by admin.');
            } elseif (true === isset($props['instance_id'][0]) && ($instance_id = (int)$props['instance_id'][0])) {
                if (true === $admin->isRelatedInstanceId($instance_id)) {
                    $include_user_data = true === isset($props['include_user_data'][0]) && 'true' === $props['include_user_data'][0];
                    Help::export_instance($instance_id, $sse, $include_user_data);
                } else {
                    $sse->log(__('You may not import / export that instance.', 'peatcms'));
                    $sse->addError("{$admin->getRow()->name} may not import / export instance $instance_id.");
                }
            } elseif (($import_file_name = Help::$session->getValue('import_file_name', true))) {
                // TODO check if the instance is empty, if not, request a special user-agent string

                Help::import_into_this_instance($import_file_name, $sse);
                // clear cache
                $rows_affected = Help::getDB()->clear_cache_for_instance(Setup::$instance_id);
                $sse->log(sprintf(__('Cleared %s items from cache.', 'peatcms'), $rows_affected));
                // publish templates
                if (true === Help::publishTemplates(Setup::$instance_id)) {
                    $sse->log('Publishing the templates for you.');
                }
            } else {
                $sse->log(__('Nothing to do.', 'peatcms'));
            }
            die(); // after an sse logger you cannot provide any more content, yo
        }
        // don’t bother if you already processed out, or without csrf
        if (null === $out) {
            if (true === isset($post_data->csrf_token)
                && $post_data->csrf_token === Help::$session->getValue('csrf_token')
            ) {
                $out = array('success' => false); // default feedback, so you don’t get into the View part later
                if ('set_session_var' === $action) {
                    $name = $post_data->name;
                    // times keeps track of how many times this var is (being) updated
                    Help::$session->setVar($name, $post_data->value, $post_data->times);
                    $out['success'] = true;
                } elseif ('post_comment' === $action && (true === Help::turnstileVerify($instance, $post_data))) {
                    $post_data = $this->resolver->escape($post_data);
                    $valid = true;
                    // validation process
                    if (isset($post_data->email)) {
                        if (false === filter_var($post_data->email, FILTER_VALIDATE_EMAIL)) {
                            $this->addMessage(sprintf(__('%s is not recognized as a valid email address.', 'peatcms'), $post_data->email), 'warn');
                            $valid = false;
                        }
                    } else {
                        $post_data->email = 'N/A';
                    }
                    if (true === isset($_SERVER['HTTP_REFERER']) && $url_parts = explode('/', urldecode($_SERVER['HTTP_REFERER']))) {
                        $post_data->referer = end($url_parts);
                        if (null === ($element_row = Help::getDB()->fetchElementIdAndTypeBySlug($post_data->referer))) {
                            $this->addError(sprintf('Commented with unknown referer %s.', $post_data->referer));
                            $this->addMessage(__('This page does not accept comments.', 'peatcms'), 'warn');
                            $valid = false;
                        }
                    } else {
                        $this->addError('No referer when posting a comment.');
                        $this->addMessage(__('To post a comment your browser must also send a referer.', 'peatcms'), 'error');
                        $valid = false;
                    }
                    if (true === $valid) {
                        // check the other mandatory fields
                        foreach (array('nickname', 'content',) as $index => $field_name) {
                            if (false === isset($post_data->{$field_name}) || '' === trim((string)$post_data->{$field_name})) {
                                $this->addMessage(sprintf(__('Mandatory field %s not found in post data.', 'peatcms'), $field_name), 'warn');
                                $valid = false;
                            }
                        }
                    }
                    if (true === $valid) {
                        $session =& Help::$session; // point to this session
                        $peat_type = new Type('comment');
                        $title = Help::summarize(127, $post_data->title ?? '');
                        if ('' === $title) $title = Help::summarize(127, $post_data->content);
                        $referer = $post_data->referer;
                        $slug = Help::slugify("$referer $title");
                        $reply_to_id = $post_data->reply_to_id ?? null;
                        $reply_after = $post_data->reply_after ?? $reply_to_id;
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
                            $element_type = new Type($element_row->type_name);
                            $element_id = $element_row->id;
                            $element = ($element_type)->getElement()->fetchById($element_id);
                            if (true === $element->link('comment', $comment_id)) {
                                if (null !== $reply_after) {
                                    if (false === Help::getDB()->orderAfterId($element_type, $element_id, $peat_type, $comment_id, $reply_after)) {
                                        $this->addMessage(__('Comment added to end.', 'peatcms'), 'warn');
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
                                $this->addError(sprintf('Comment could not be linked to %s as %s.', $post_data->referer, var_export($element_row, true)));
                                $this->addMessage(__('Comment not added.', 'peatcms'), 'warn');
                            }
                        } else {
                            $this->addError(sprintf('Comment not added with data %s.', var_export($post_data, true)));
                            $this->addMessage(__('Comment not added.', 'peatcms'), 'warn');
                        }
                    }
                } elseif ('sendmail' === $action && (true === Help::turnstileVerify($instance, $post_data))) {
                    $post_data = $this->resolver->escape($post_data);
                    $out = $this->sendMail($instance, $post_data);
                } elseif ('countries' === $action) {
                    $out = array('__rows__' => Help::getDB()->getCountries());
                    $out['slug'] = 'countries';
                } elseif ('suggest_address' === $action) {
                    $country_code = $post_data->address_country_iso2;
                    $query = htmlspecialchars($post_data->query, ENT_QUOTES);
                    $addresses = new Addresses($instance->getSetting('myparcel_api_key'));
                    $suggestions = $addresses->suggest($country_code, $query);
                    $out = array('success' => true, 'suggestions' => $suggestions);
                } elseif ('validate_address' === $action) {
                    if (isset($post_data->address_country_iso2,
                        $post_data->address_postal_code,
                        $post_data->address_street,
                        $post_data->address_number,
                        $post_data->address_city)
                    ) {
                        $country_code = $post_data->address_country_iso2;
                        $postal_code = $post_data->address_postal_code;
                        $street = $post_data->address_street;
                        $number = $post_data->address_number;
                        $city = $post_data->address_city;
                        $addresses = new Addresses($instance->getSetting('myparcel_api_key'));
                        $valid = $addresses->validate($country_code, $postal_code, $number, $street, $city);
                        $out = array('success' => true, 'valid' => $valid);
                    }
                } elseif ('order' === $action) {
                    if (true === Help::turnstileVerify($instance, $post_data)) {
                        $post_data = $this->resolver->escape($post_data);
                        if (false === isset($post_data->shoppinglist)) {
                            $this->addError('Shoppinglist is not set for order action.');
                            $out = true;
                        } elseif (true === isset($post_data->email, $post_data->shipping_country_id)) {
                            $valid = true;
                            // validation process
                            if (false === filter_var($post_data->email, FILTER_VALIDATE_EMAIL)) {
                                $this->addMessage(sprintf(__('%s is not recognized as a valid email address.', 'peatcms'), $post_data->email), 'warn');
                                $valid = false;
                            } elseif (null === Help::getDB()->getCountryById((int)$post_data->shipping_country_id)) {
                                $this->addMessage(sprintf(__('%s is not recognized as a country id.', 'peatcms'), $post_data->shipping_country_id), 'warn');
                                $valid = false;
                            }
                            if (true === $valid) {
                                // check the other mandatory fields
                                foreach (array(
                                             'shipping_address_postal_code',
                                             'shipping_address_number',
                                             'shipping_address_street',
                                             'shipping_address_city',
                                         ) as $index => $field_name) {
                                    if (false === isset($post_data->{$field_name}) || '' === trim($post_data->{$field_name})) {
                                        $this->addMessage(sprintf(__('Mandatory field %s not found in post data.', 'peatcms'), $field_name), 'warn');
                                        $valid = false;
                                    }
                                }
                            }
                            if (true === $valid) {
                                // check vat, if supplied
                                if (true === isset($post_data->vat_number, $post_data->vat_country_iso2)) {
                                    $check = Help::validate_vat($post_data->vat_country_iso2, $post_data->vat_number);
                                    if (false === isset($check['valid']) || false === $check['valid']) {
                                        $valid = false;
                                        $this->addMessage(__('Vat number not valid according to VIES.', 'peatcms'), 'warn');
                                    } else {
                                        $post_data->vat_valid = true;
                                        $post_data->vat_history = json_encode($check);
                                    }
                                }
                            }
                            if (true === $valid) {
                                $session =& Help::$session; // point to this session
                                $shoppinglist = new Shoppinglist($post_data->shoppinglist);
                                // both session value shipping_address_collect and posting local_pickup directly result in local pickup active
                                if (true === Help::$session->getValue('shipping_address_collect')) {
                                    $post_data->local_pickup = true;
                                }
                                if (null !== ($order_number = Help::getDB()->placeOrder($shoppinglist, $session, (array)$post_data))) {
                                    $session->setVar('order_number', $order_number);
                                    // out object
                                    $out = array('success' => true, 'order_number' => $order_number);
                                    // leave everything be, so the next page (the forms action) will be loaded
                                } else {
                                    $this->addError('DB->placeOrder() failed.');
                                    $this->addMessage(__('Order process failed.', 'peatcms'), 'error');
                                    $out = true;
                                }
                            }
                        } else {
                            $this->addMessage(__('Please provide a valid emailaddress and choose a shipping country.', 'peatcms'), 'warn');
                            $this->addError('Posting of inputs named email and shipping_country_id is mandatory.');
                            $out = true;
                        }
                    } else {
                        $out = true;
                    }
                } elseif ('order_rate' === $action) {
                    if (true === isset($post_data->order_number, $post_data->rating)) {
                        $order_number = htmlentities(trim($post_data->order_number));
                        $rating = Help::asFloat($post_data->rating);
                        $order = Help::getDB()->getOrderByNumber($order_number);
                        $own = Help::$session->getId() === $order->session_id;
                        if (true === isset($order, $rating)) {
                            if (true === $own) {
                                $out = array(
                                    'success' => Help::getDB()->updateColumns('_order',
                                        array('rating' => $rating),
                                        $order->order_id,
                                    )
                                );
                            } else {
                                $this->addError('Order rating error: NOT OWN ORDER.');
                            }
                        } else {
                            $this->addError('Order rating error: ' . var_export($post_data, true));
                        }
                    }
                } elseif ('account_login' === $action) {
                    // TODO for admin this works without turnstile, but I want to put a rate limiter etc. on it
                    if (true === isset($post_data->email, $post_data->pass)) {
                        $as_admin = $this->resolver->hasInstruction('admin');
                        if (true === $as_admin || true === Help::turnstileVerify($instance, $post_data)) {
                            if (false === Help::$session->login($post_data->email, (string)$post_data->pass, $as_admin)) {
                                $this->addMessage(__('Could not login.', 'peatcms'), 'warn');
                            } elseif (true === $as_admin) {
                                $out = array('redirect_uri' => '/'); // @since 0.7.8 reload to get all the admin css and js
                            } else {
                                $this->addMessage(__('Login successful.', 'peatcms'), 'log');
                                $out = array(
                                    'success' => true,
                                    'is_account' => true,
                                    '__user__' => Help::$session->getUser()->getOutput()
                                );
                            }
                        }
                    } else {
                        $this->addMessage(__('No e-mail and / or pass received.', 'peatcms'), 'warn');
                    }
                } elseif ('account_create' === $action) {
                    if (true === Help::turnstileVerify($instance, $post_data)) {
                        if (true === isset($post_data->email, $post_data->pass)
                            && strpos(($email_address = $post_data->email), '@')
                        ) {
                            $password = (string)$post_data->pass;
                            if (8 > strlen($password)) {
                                $this->addMessage(__('Password must be at least 8 characters long.', 'peatcms'), 'warn');
                            } elseif (null !== ($user_id = Help::getDB()->insertUserAccount(
                                    $email_address,
                                    Help::passwordHash($password))
                                )) { // todo what if the e-mail address already is an account? Now it just errors.
                                $this->addMessage(__('Account created.', 'peatcms'), 'note');
                                // @since 0.26.0 add current order and address to the account already, by session id
                                Help::getDB()->updateColumnsWhere('_order',
                                    array('user_id' => $user_id),
                                    array('session_id' => Help::$session->getId()) // could be slow, no index
                                );
                                // get the shop addresses so they will not be added to the account
                                $by_key = array();
                                foreach (Help::getDB()->fetchInstanceAddresses(Setup::$instance_id) as $index => $address) {
                                    $by_key[Address::makeKey($address)] = 'Shop';
                                }
                                // get the orders for this user to supplement the addresses to the account
                                $rows = Help::getDB()->fetchOrdersByUserId($user_id);
                                Help::supplementAddresses($rows, $by_key);
                                // auto login
                                if (false === Help::$session->login($email_address, $password, false)) {
                                    $this->addMessage(__('Could not login.', 'peatcms'), 'error');
                                } else {
                                    $this->addMessage(__('Login successful.', 'peatcms'), 'log');
                                    $out = array(
                                        'success' => true,
                                        'is_account' => true,
                                        '__user__' => Help::$session->getUser()->getOutput()
                                    );
                                }
                            } else {
                                $this->addMessage(__('Account could not be created.', 'peatcms'), 'note');
                            }
                        } else {
                            $this->addMessage(__('No e-mail and / or pass received.', 'peatcms'), 'warn');
                        }
                    } else {
                        $out = true;
                    }
                } elseif ('account_password_forgotten' === $action) {
                    if (true === Help::turnstileVerify($instance, $post_data)) {
                        if (true === isset($post_data->email) && strpos(($email_address = $post_data->email), '@')) {
                            $post_data->check_string = Help::getDB()->putInLocker(0,
                                (object)array('email_address' => $email_address));
                            // locker is put in the properties for the request, NOTE does not work as querystring, only this proprietary format
                            $post_data->confirm_link = sprintf('%s/%s/locker:%s',
                                $instance->getDomain(true),
                                ($post_data->slug ?? 'account'),
                                $post_data->check_string);
                            $post_data->instance_name = $instance->getName();
                            /* this largely duplicate code must be in a helper function or something... */
                            if (true === isset($post_data->template) && $template_row = Help::getDB()->getMailTemplate($post_data->template)) {
                                $temp = new Template($template_row);
                                $body = $temp->renderObject($post_data);
                            }
                            if (false === isset($body) || '' === $body) {
                                $body = "Click link or paste in your browser to reset your account password: <$post_data->confirm_link>";
                            }
                            $mail = new Mailer($instance->getSetting('mailgun_custom_domain'));
                            $mail->set(array(
                                'to' => $email_address,
                                'from' => $instance->getSetting('mail_verified_sender'),
                                'subject' => $post_data->subject ?? "Mailed by {$instance->getDomain()}",
                                'text' => Help::html_to_text($body),
                                'html' => $body,
                            ));
                            $out = $mail->send();
                            $out = $this->after_posting($out, $post_data);
                        } else {
                            $this->addMessage(__('E-mail is required.', 'peatcms'), 'warn');
                        }
                    } else {
                        $out = true;
                    }
                } elseif ('account_password_update' === $action) {
                    if (true === Help::turnstileVerify($instance, $post_data)) {
                        if (true === isset($post_data->email, $post_data->pass)) {
                            $password = (string)$post_data->pass;
                            if (8 > strlen($password)) {
                                $this->addMessage(__('Password must be at least 8 characters long.', 'peatcms'), 'warn');
                            } elseif (true === isset($post_data->locker)
                                && $row = Help::getDB()->emptyLocker($post_data->locker)
                            ) {
                                if (true === isset($row->information, $row->information->email_address)
                                    && ($email_address = $row->information->email_address) === $post_data->email
                                ) {
                                    // if it’s indeed an account, update the password
                                    // (since the code proves the emailaddress is read by the owner)
                                    if (false === Help::getDB()->updateUserPassword($email_address, Help::passwordHash($password))) {
                                        // create an account then...
                                        $this->action = 'account_create';
                                        $this->Act();
                                    } else {
                                        $this->addMessage(__('Password updated.', 'peatcms'), 'note');
                                        $out['success'] = true;
                                        if (true === Help::$session->login($email_address, $password, false)) {
                                            $this->addMessage(__('Login successful.', 'peatcms'), 'log');
                                            $out = array(
                                                'success' => true,
                                                'is_account' => true,
                                                '__user__' => Help::$session->getUser()->getOutput()
                                            );
                                        }
                                    }
                                } else {
                                    $this->addMessage(__('E-mail address did not match.', 'peatcms'), 'warn');
                                }
                            } else {
                                $this->addMessage(__('Link is invalid or expired.', 'peatcms'), 'warn');
                            }
                        } else {
                            $this->addMessage(__('No e-mail and / or pass received.', 'peatcms'), 'warn');
                        }
                    } else {
                        $out = true; // turnstile failed, so no action
                    }
                } elseif ('account_update' === $action) {
                    if (true === Help::turnstileVerify($instance, $post_data)) {
                        if (null !== ($user = Help::$session->getUser())) {
                            // check which column is being updated... (multiple is possible)
                            $data = array();
                            if (true === isset($post_data->phone)) $data['phone'] = $post_data->phone;
                            if (true === isset($post_data->gender)) $data['gender'] = $post_data->gender;
                            if (true === isset($post_data->nickname)) $data['nickname'] = $post_data->nickname;
                            if (count($data) > 0) {
                                $out = array('success' => $user->updateRow($data));
                            }
                            if (true === isset($post_data->email)) {
                                // updating email address is a process, you need to authenticate again
                                $this->addMessage('Currently updating emailaddress is not possible.', 'note');
                            }
                            if (true === isset($out)) {
                                $out['__user__'] = $user->getOutput(); // get a new user
                            }
                        }
                    } else {
                        $out = true; // turnstile failed, so no action
                    }
                } elseif ('account_delete_sessions' === $action) {
                    if ((null !== ($user = Help::$session->getUser()))) {
                        $out['success'] = 0 < Help::getDB()->deleteSessionsForUser(
                                $user->getId(),
                                Help::$session->getId()
                            );
                        $out['__user__'] = Help::$session->getUser()->getOutput();
                    }
                } elseif ('validate_vat' === $action) {
                    $out = Help::validate_vat($post_data->country_iso2, $post_data->number);
                } elseif (('update_address' === $action || 'delete_address' === $action)) {
                    if (true === Help::turnstileVerify($instance, $post_data)) {
                        //$post_data = $this->resolver->escape($post_data);
                        if ((null !== ($user = Help::$session->getUser())) && isset($post_data->address_id)) {
                            $address_id = (int)$post_data->address_id;
                            if ('delete_address' === $action) $post_data->deleted = true;
                            // clean posted data before updating
                            unset($post_data->json);
                            unset($post_data->timestamp);
                            unset($post_data->csrf_token);
                            if (1 === Help::getDB()->updateColumnsWhere(
                                    '_address',
                                    (array)$post_data,
                                    array('address_id' => $address_id, 'user_id' => $user->getId()) // user_id checks whether the address belongs to the user
                                )) {
                                $out = Help::getDB()->fetchElementRow(new Type('address'), $address_id);
                                if ('delete_address' === $action) {
                                    $out = array('success' => true);
                                } elseif (null === $out) {
                                    $this->addMessage(__('Error retrieving updated address.', 'peatcms'), 'error');
                                } else {
                                    $out->success = true;
                                }
                            } else {
                                $this->addMessage(__('Address could not be updated.', 'peatcms'), 'warn');
                            }
                        } elseif (isset($post_data->address_id)) {
                            $this->addMessage(__('You need to be logged in to manage addresses.', 'peatcms'), 'warn');
                        } else {
                            $this->addError('address_id is missing.');
                        }
                    }
                    if (false === isset($out)) $out = array('success' => false);
                } elseif ('create_address' === $action) {
                    if (true === Help::turnstileVerify($instance, $post_data)) {
                        $post_data = $this->resolver->escape($post_data);
                        if ((null !== ($user = Help::$session->getUser()))) {
                            if (null !== ($address_id = Help::getDB()->insertElement(
                                    new Type('address'),
                                    array('user_id' => $user->getId())))
                            ) {
                                $out = array('success' => true);
                            }
                        } else {
                            $this->addMessage(__('You need to be logged in to manage addresses.', 'peatcms'), 'warn');
                        }
                    }
                    if (false === isset($out)) $out = array('success' => false);
                } elseif ('detail' === $action && $this->resolver->hasInstruction('order')) {
                    // session values can be manipulated, so you need to check if this order belongs to the session
                    $order_number = Help::$session->getValue('order_number');
                    if (null === $order_number) {
                        $out = array('slug' => '__order__');
                    } elseif (($row = Help::getDB()->getOrderByNumber($order_number))
                        && $row->session_id === Help::$session->getId()
                    ) {
                        $out = (new Order($row))->getOutput();
                    } else {
                        $this->addError("Order $order_number not found for this session.");
                        $out = array('slug' => "__order__/$order_number");
                    }
                } elseif ('payment_start' === $action) {
                    // TODO NOTE the order may not belong to the current user, so do not expose any sensitive data
                    if (true === isset($post_data->order_number)) {
                        if (($row = Help::getDB()->getOrderByNumber($post_data->order_number))) {
                            if (($order = new Order($row))) {
                                $max_age = max(3600, $instance->getSetting('payment_link_valid_hours', 24) * 3600); // in seconds
                                if (true === $row->cancelled) { // you can’t pay for a cancelled order
                                    $this->addMessage(__('Order is cancelled.', 'peatcms'), 'warn');
                                } elseif ($max_age < Setup::getNow() - Date::intFromDate($row->date_created)) {
                                    $this->addMessage(__('Payment has expired, please make a new order.', 'peatcms'), 'note');
                                } elseif (($payment_tracking_id = $order->getPaymentTrackingId())) {
                                    $live_flag = $row->payment_live_flag ?? false;
                                    $out = array('tracking_id' => $payment_tracking_id, 'live_flag' => $live_flag, 'success' => true);
                                } elseif (($psp = $instance->getPaymentServiceProvider())) {
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
                                        $this->addError(sprintf('Payment Service Provider error %s.', $psp->getLastError()));
                                    }
                                } else {
                                    $this->addError(__('No default Payment Service Provider found.', 'peatcms'));
                                }
                            } else {
                                $this->addError(sprintf('Could not get order for %s.', htmlentities($post_data->order_number)));
                            }
                        } else {
                            $this->addMessage(__('Order not found, please refresh the page.', 'peatcms'), 'warn');
                            $this->addError(sprintf('DB returned null for %s', htmlentities($post_data->order_number)));
                        }
                    } else {
                        $this->addError('Order number must be posted in order to start a payment.');
                    }
                    if (false === isset($out)) {
                        $out = true;
                        $this->addMessage(__('Payment Service Provider error.', 'peatcms'), 'error');
                    }
                } elseif (in_array($action, array('add_to_list', 'remove_from_list', 'update_quantity_in_list'))) {
                    $out = array('success' => $this->updateList($action, $post_data));
                } elseif (($admin = Help::$session->getAdmin()) instanceof Admin) {
                    /**
                     * Admin actions, permission needs to be checked every time
                     */
                    if ('update_element' === $action) {
                        if (true === isset($post_data->element, $post_data->id)) {
                            if (($element = $this->getElementById($post_data->element, $post_data->id))) {
                                if (true === $admin->isRelatedElement($element)) {
                                    // @since 0.8.19 check the prices
                                    if (in_array($post_data->column_name, array('price', 'price_from'))) {
                                        $value = Help::asFloat($post_data->column_value);
                                        $value = Help::asMoney($value);
                                        if ('' === $value && $value !== $post_data->column_value) {
                                            $this->addMessage(sprintf(
                                                __('%s not recognized as money.', 'peatcms'),
                                                $post_data->column_value), 'warn');
                                        }
                                        $post_data->column_value = $value;
                                    }
                                    //
                                    if (false === $element->update(array($post_data->column_name => $post_data->column_value))) {
                                        $this->addMessage(sprintf(__('Update of element %s failed.', 'peatcms'), $post_data->element), 'error');
                                    }
                                    $out = $element->row;
                                }
                            }
                        } else {
                            $this->handleErrorAndStop('Update element failed. ' . var_export($post_data, true));
                        }
                    } elseif ('create_element' === $action) {
                        if ($element = $this->createElement($post_data->element, $post_data->online ?? false)) {
                            $out = $element->row;
                        } else {
                            $this->handleErrorAndStop('Create element failed. ' . var_export($post_data, true));
                        }
                    } elseif ('delete_element' === $action) {
                        if (true === isset($post_data->element_name, $post_data->id)) {
                            $success = false;
                            $type = new Type($post_data->element_name);
                            $element = $type->getElement()->fetchById((int)$post_data->id);
                            if (null === $element) {
                                $this->addMessage(__('Element not found', 'peatcms'), 'warn');
                            } elseif ($admin->isRelatedElement($element)) {
                                $path = $element->getSlug();
                                if (true === ($success = $element->delete())) {
                                    Help::getDB()->reCacheWithWarmup($path);
                                    $this->addMessage(__('Please allow 5 - 10 minutes for the element to disappear completely.', 'peatcms'));
                                }
                            } else {
                                $this->addMessage('Security warning, after multiple warnings your account may be blocked.', 'warn');
                            }
                            unset($element);
                            $out = array('success' => $success);
                        } else {
                            $this->handleErrorAndStop('Delete element failed. ' . var_export($post_data, true));
                        }
                    } elseif ('admin_get_elements' === $action) {
                        $out = array('rows' => $this->getElements($post_data->element));
                    } elseif ('admin_get_element_suggestions' === $action) {
                        $out = $this->getElementSuggestions($post_data->element, $post_data->src);
                    } elseif ('admin_get_element' === $action) {
                        $peat_type = new Type($post_data->element);
                        $element = $peat_type->getElement();
                        if ($element->fetchById((int)$post_data->id)) {
                            if (true === $admin->isRelatedElement($element)) {
                                // elements must be enhanced, but prevent getting all the linked items (for eg serie or brand) by supplying 2
                                // todo make it configurable in the request
                                $out = $element->getOutput(2);
                            } else {
                                $this->addMessage(sprintf(__('No %1$s found with id %2$s.', 'peatcms'), $post_data->element, $post_data->id), 'error');
                                $out = true;
                            }
                            unset($element);
                        } else {
                            $out = true;
                        }
                    } elseif ('admin_publish_element' === $action) { // @since 0.24.0 // todo permissions
                        $element = $this->getElementById($post_data->element, (int)$post_data->id);
                        if (null === $element) {
                            $this->addMessage(__('Element not found.', 'peatcms'), 'error');
                        } else {
                            $element_title = $element->row->title;
                            // if there is a linked element (probably file or image), set its online property to true
                            // also update the title and slug
                            foreach ($element->getLinked() as $type_name => $elements) {
                                foreach ($elements as $key => $out) {
                                    if (false === is_int($key)) continue; // not an element
                                    $id = $GLOBALS['slugs']->{$out->__ref}->{"{$type_name}_id"};
                                    // #TRANSLATORS: %1$s is the type name, %2$s is the element title
                                    $title = sprintf(__('%1$s added to %2$s.', 'peatcms'), ucfirst($type_name), $element_title);
                                    //var_dump($key, $id);
                                    Help::getDB()->updateElement(new Type($type_name), array(
                                        'title' => $title,
                                        'slug' => $title,
                                    ), $id);
                                }
                            }
                            // if you received properties, add them as x_values
                            foreach ($post_data->properties as $property => $value) {
                                $property = Help::slugify($property);
                                $value = Help::slugify($value);
                                $row = Help::getDB()->fetchElementIdAndTypeBySlug($property);
                                if (null === $row || 'property' !== $row->type_name) {
                                    $this->addMessage(sprintf(__('Property %s not found.', 'peatcms'), $property), 'error');
                                    continue;
                                }
                                $property_id = $row->id;
                                $row = Help::getDB()->fetchElementIdAndTypeBySlug($value);
                                if (null === $row || 'property_value' !== $row->type_name) {
                                    $this->addMessage(sprintf(__('Property value %s not found.', 'peatcms'), $value), 'error');
                                    continue;
                                }
                                $property_value_id = $row->id;
                                unset($row);
                                if (false === $element->linkX($property_id, $property_value_id)) {
                                    // TRANSLATORS: %1$s is the property, %2$s is the value
                                    $this->addMessage(sprintf(__('Could not link %1$s %2$s to element.', 'peatcms'), $property, $value), 'error');
                                }
                            }
                            // set this element to true and add template
                            $update = array('online' => true);
                            // if you received a css class, add that as well
                            if (true === isset($post_data->css_class)) {
                                $update['css_class'] = "$post_data->css_class {$element->row->css_class}";
                            }
                            // if you received a template string, set the correct template id, else, set the default id for this type
                            if (true === isset($post_data->template_name)
                                && ($row = Help::getDB()->getTemplateByName($post_data->template_name, Setup::$instance_id))
                            ) {
                                $update['template_id'] = $row->template_id;
                            } else {
                                $update['template_id'] = Help::getDB()->getDefaultTemplateIdFor($post_data->element);
                            }
                            if (false === $element->update($update)) {
                                $this->addMessage(sprintf(__('Could not update %s.', 'peatcms'), $element_title), 'error');
                                $out = array('success' => false);
                            } else {
                                $out = array(
                                    'success' => true,
                                    'slug' => $element->getSlug(),
                                );
                            }
                        }
                    } elseif ('admin_uncache' === $action) {
                        if (true === isset($post_data->path)) {
                            $path = $post_data->path;
                            if (true === Help::getDB()->reCacheWithWarmup($path)) {
                                if (false === isset($post_data->silent) || false === $post_data->silent) {
                                    $this->addMessage(sprintf(__('%s refreshed in cache.', 'peatcms'), $path), 'log');
                                }
                            }
                            $out = array('slug' => $path);
                        }
                    } elseif ('admin_clear_cache_for_instance' === $action) {
                        if (true === isset($post_data->instance_id) && $admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                            $out = array('rows_affected' => ($rows_affected = Help::getDB()->clear_cache_for_instance($instance_id)));
                            $this->addMessage(sprintf(__('Cleared %s items from cache.', 'peatcms'), $rows_affected));
                        }
                    } elseif ('admin_export_templates_by_name' === $action) {
                        if (true === isset($post_data->instance_id) && $admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                            $content = Help::getDB()->getTemplates($instance_id);
                            $file_name = Help::slugify(Help::$session->getInstance()->getName()) . '-Templates.json';
                            $out = array('download' => array('content' => $content, 'file_name' => $file_name));
                        }
                    } elseif ('admin_import_templates_by_name' === $action) {
                        if (true === isset($post_data->instance_id) && $admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                            if (true === isset($post_data->template_json) && ($templates = json_decode($post_data->template_json))) {
                                $count_done = 0;
                                foreach ($templates as $key => $posted_row) {
                                    if (($template_name = $posted_row->name)) {
                                        if (($db_row = Help::getDB()->getTemplateByName($template_name, $instance_id))) {
                                            $template_id = $db_row->template_id;
                                        } else {
                                            // insert
                                            if (!($template_id = Help::getDB()->insertTemplate($template_name, $instance_id))) {
                                                $this->addMessage(sprintf(__('Update %s failed.', 'peatcms'), $template_name), 'error');
                                                continue;
                                            }
                                            $this->addMessage(sprintf(__('Created new template %s’.', 'peatcms'), $template_name), 'note');
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
                                            $this->addMessage(sprintf(__('Template %s updated.', 'peatcms'), $template_name));
                                            $count_done++;
                                        } else {
                                            $this->addMessage(sprintf(__('Update %s failed.', 'peatcms'), $template_name), 'error');
                                        }
                                    }
                                }
                                if (0 === $count_done) {
                                    $this->addMessage(__('No templates found in json.', 'peatcms'), 'warn');
                                } else {
                                    $this->updatePublishedForTemplates($instance_id);
                                }
                            } else {
                                $this->addMessage(__('json not recognized.', 'peatcms'), 'warn');
                            }
                            if (true === isset($post_data->re_render)) {
                                $out = array('re_render' => $post_data->re_render);
                            } else {
                                $out = true;
                            }
                        }
                    } elseif (in_array($action, array(
                        'admin_linkable_link',
                        'admin_linkable_slug',
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
                                if ('admin_linkable_slug' === $action) { // id is the sub, slug is the parent
                                    if (true === isset($post_data->slug) && ($row = Help::getDB()->fetchElementIdAndTypeBySlug($post_data->slug))) {
                                        $parent = (new Type($row->type_name))->getElement()->fetchById($row->id);
                                        $element_type_name = $element->type_name;
                                        $success = $parent->link($element_type_name, $element->id);
                                        $linked = $parent->getLinked($element_type_name);
                                        // verify / change order
                                        if (true === $success && true === isset($post_data->place)) {
                                            $place = (int)$post_data->place;
                                            $slug_in_place = $linked[$place]->__ref;
                                            if ($element->getSlug() !== $slug_in_place) {
                                                $linked = $parent->orderLinked($element_type_name, $element->getSlug(), $slug_in_place);
                                            }
                                        }
                                        if (true === $success && true === isset($post_data->total)) {
                                            $total = (int)$post_data->total;
                                            if (true === isset($linked[$total])) {
                                                $id = $GLOBALS['slugs']->{$linked[$total]->__ref}->{"{$element_type_name}_id"};
                                                if (false === $parent->link($element_type_name, $id, true)) {
                                                    $this->addError("Could not unlink element nr $total.");
                                                }
                                            }
                                        }
                                        $out = array('success' => $success);
                                    } else {
                                        $this->addError(sprintf('Slug %s not found.', var_export($post_data->slug, true)));
                                    }
                                } elseif ('admin_linkable_link' === $action) {
                                    $unlink = (true === isset($post_data->unlink) && true === $post_data->unlink);
                                    if ($element->link($post_data->sub_element, $post_data->sub_id, $unlink)) {
                                        $out = $element->getLinked();
                                    } else {
                                        $this->addError('Could not link that.');
                                        $out = true;
                                    }
                                } elseif ('admin_linkable_order' === $action) {
                                    $full_feedback = (false !== $post_data->full_feedback);
                                    $out = $element->orderLinked($post_data->linkable_type, $post_data->slug, $post_data->before_slug, $full_feedback);
                                } elseif ('admin_x_value_link' === $action) {
                                    // make the entry in x_value table :-)
                                    if ($element->linkX((int)$post_data->property_id, (int)$post_data->property_value_id)) {
                                        $out = $element->getLinked('x_value');
                                    } else {
                                        $this->addError('Property link error.');
                                        $out = true;
                                    }
                                } elseif ('admin_x_value_order' === $action) {
                                    $out = $element->orderXValue($post_data->x_value_id, $post_data->before_x_value_id);
                                } elseif ('admin_x_value_remove' === $action) {
                                    if (isset($post_data->x_value_id) and $x_value_id = (int)$post_data->x_value_id) {
                                        // todo move this to a method in baseelement
                                        Help::getDB()->deleteXValueLink($peat_type, $element->getId(), $x_value_id);
                                        $element->reCache();
                                        $out = $element->getLinked('x_value');
                                    }
                                } elseif ('admin_x_value_create' === $action) {
                                    // todo move this to a method in baseelement
                                    if (isset($post_data->property_value_title) && isset($post_data->property_id)) {
                                        $title = $post_data->property_value_title;
                                        $property_id = $post_data->property_id;
                                        $property = (new Property())->fetchById($property_id);
                                        if (false === $admin->isRelatedElement($property)) {
                                            $this->addMessage('Security warning, after multiple warnings your account may be blocked.', 'warn');
                                            $out = true;
                                        } elseif (($property_value_id = Help::getDB()->insertElement(new Type('property_value'), array(
                                            'title' => $title,
                                            'slug' => Help::slugify($title),
                                            'content' => __('Auto generated property value.', 'peatcms'),
                                            'excerpt' => '',
                                            'template_id' => Help::getDB()->getDefaultTemplateIdFor('property_value'),
                                            'online' => true // for here the default is true, or else we can’t add simply from edit screen
                                        )))) { // create a property value
                                            // index immediately
                                            if (false === Help::getDB()->updateSearchIndex((new PropertyValue())->fetchById($property_value_id))) {
                                                $this->addMessage(__('Please wait a few minutes for the property value to become available.', 'peatcms'), 'warn');
                                            }
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
                                                    $this->addMessage(sprintf(__('Could not link property value to %s.', 'peatcms'), $element->getTypeName()), 'error');
                                                }
                                                $element->reCache();
                                            } else {
                                                $this->addMessage(sprintf(__('Could not link property value to %s.', 'peatcms'), 'property'), 'error');
                                            }
                                            // return the x_value entries (linked)
                                            $out = $element->getLinked('x_value');
                                        } else {
                                            $this->addMessage(__('Could not create new property value.', 'peatcms'), 'error');
                                        }
                                    }
                                }
                            } else {
                                $this->addMessage('Security warning, after multiple warnings your account may be blocked.', 'warn');
                                $out = true;
                            }
                        } else {
                            // error message element not found
                            $this->addMessage(sprintf(__('No %1$s found with id %2$s.', 'peatcms'), $post_data->element, $post_data->id), 'error');
                        }
                        unset($element);
                    } elseif ('admin_put_menu_item' === $action) {
                        if (($row = Help::getDB()->fetchElementIdAndTypeBySlug($post_data->menu)) && 'menu' === $row->type_name) {
                            $type = new Type('menu');
                            $menu = $type->getElement();
                            if ($menu->fetchById($row->id) instanceof Menu) {
                                if ($admin->isRelatedElement($menu)) {
                                    // further sanitize / check data is not necessary, menu->putItem() takes care of that
                                    /** @noinspection PhpPossiblePolymorphicInvocationInspection */ /* we just checked it's a menu */
                                    if (true === $menu->putItem($post_data->dropped_menu_item_id, $post_data->menu_item_id, $post_data->command)) {
                                        $out = $menu->getOutput();
                                        if (false === Help::getDB()->reCacheWithWarmup($menu->getPath())) {
                                            Help::addMessage('Cache update of the menu failed.', 'warn');
                                        }
                                    } else {
                                        $this->addError('Could not put item in menu.');
                                        $out = true;
                                    }
                                }
                                unset($menu);
                            } else {
                                $this->handleErrorAndStop(sprintf('admin_put_menu_item could not get menu with id %s.', $row->id), 'Invalid menu id');
                            }
                        } else {
                            $this->handleErrorAndStop('admin_put_menu_item did not receive a valid menu slug.', 'Invalid slug');
                        }
                    } elseif ('admin_get_templates' === $action) { // called when an element is edited to fill the select list
                        $instance_id = (isset($post_data->type) && 'instance' === $post_data->type) ? $post_data->id : Setup::$instance_id;
                        $for = $post_data->for ?? $this->resolver->getTerms()[0] ?? null;
                        if (isset($for)) {
                            if ($admin->isRelatedInstanceId($instance_id)) {
                                $out = Help::getDB()->getTemplates($instance_id, $for);
                                if (count($out) === 0) {
                                    $this->addMessage(sprintf(__('No templates found for %s.', 'peatcms'), $for), 'warn');
                                }
                                if (isset($this->resolver->getTerms()[0])) $out['__row__'] = $out; // TODO bugfix until template engine is fixed
                                $out['slug'] = 'admin_get_templates';
                            }
                        } else {
                            $this->addError('Variable for is missing, don’t know what templates you need.');
                            $out = true;
                        }
                    } elseif ('admin_get_vat_categories' === $action) {
                        $instance_id = $post_data->instance_id ?? Setup::$instance_id;
                        if ($admin->isRelatedInstanceId($instance_id)) {
                            $out = Help::getDB()->getVatCategories($instance_id);
                            if (count($out) === 0) {
                                $this->addMessage(__('No vat categories found', 'peatcms'), 'warn');
                            }
                        }
                    } elseif ('search_log' === $action) {
                        // only shows log of the current instance
                        $rows = Help::getDB()->fetchSearchLog();
                        $out = array('__rows__' => $rows, 'item_count' => count($rows));
                    } elseif ('admin_redirect' === $action) {
                        if ($post_data->type === 'instance' && $instance_id = $post_data->id) {
                            if ($admin->isRelatedInstanceId($instance_id)) {
                                $out = array(
                                    '__row__' => Help::getDB()->getRedirects($instance_id),
                                    'slug' => 'admin_redirect',
                                );
                            }
                        }
                    } elseif ('templates' === $action) {
                        if ($post_data->type === 'instance' && $instance_id = $post_data->id) {
                            if ($admin->isRelatedInstanceId($instance_id)) {
                                $out = array(
                                    '__row__' => Help::getDB()->getTemplates($instance_id),
                                    'content' => __('Available templates.', 'peatcms'),
                                    'slug' => 'templates',
                                );
                            }
                        }
                    } elseif ('admin_publish_templates' === $action) {
                        if (false === isset($post_data->instance_id)) {
                            $this->addMessage('Please supply an instance_id.', 'warn');
                            $out = true;
                        } elseif ($admin->isRelatedInstanceId(($instance_id = $post_data->instance_id))) {
                            $out = array('success' => Help::publishTemplates($instance_id));
                        } else {
                            $this->addMessage('Security warning, after multiple warnings your account may be blocked.', 'warn');
                            $out = true;
                        }
                    } elseif ('admin_countries' === $action) {
                        if ($post_data->type === 'instance' && $instance_id = $post_data->id) {
                            if ($admin->isRelatedInstanceId($instance_id)) {
                                $out = array('__rows__' => Help::getDB()->getCountries($instance_id));
                                $out['slug'] = 'admin_countries';
                            }
                        }
                    } elseif ('admin_payment_service_providers' === $action) {
                        if ($post_data->type === 'instance' && $instance_id = $post_data->id) {
                            if ($admin->isRelatedInstanceId($instance_id)) {
                                $out = array('__rows__' => Help::getDB()->getPaymentServiceProviders($instance_id));
                                $out['slug'] = 'admin_payment_service_providers';
                            }
                        }
                    } elseif ('admin_get_payment_status_updates' === $action) {
                        // you only get them for the current instance
                        $out = array();
                        $out['slug'] = 'admin_get_payment_status_updates';
                        $out['__rows__'] = Help::getDB()->fetchPaymentStatuses();
                    } elseif ('cancel_order' === $action) {
                        if (true === isset($post_data->order_id) && ($order_id = $post_data->order_id)
                            && true === $admin->canDo($action, '_order', $order_id)
                        ) {
                            $out = array('success' => Help::getDB()->cancelOrder($order_id));
                        }
                    } elseif ('update_column' === $action) {
                        // security check
                        if (true === $admin->canDo($action, $post_data->table_name, $post_data->id)) {
                            $posted_column_name = $post_data->column_name;
                            $posted_table_name = $post_data->table_name;
                            $posted_value = $post_data->value;
                            $posted_id = $post_data->id;
                            // default update array
                            $update_arr = array($posted_column_name => $posted_value);
                            /**
                             * Some exceptions in the columns are handled first
                             */
                            if ($posted_column_name === 'password' && $posted_value !== '') {
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
                                && $posted_column_name === 'deleted'
                                && $posted_value === true
                            ) {
                                if ((int)$posted_id === Help::$session->getAdmin()->getId()) {
                                    $this->handleErrorAndStop(sprintf('Admin %s tried to delete itself.', $posted_id),
                                        __('You can’t delete yourself.', 'peatcms'));
                                }
                            } elseif ($posted_column_name === 'domain') {
                                $value = $posted_value;
                                if ($posted_table_name === '_instance'
                                    && ($instance->getDomain() === $value || $instance->getId() === (int)$posted_id)
                                ) {
                                    $this->handleErrorAndStop(
                                        sprintf('Domain %1$s was blocked for instance %2$s.', $value, $posted_id),
                                        __('Manipulating this domain is not allowed.', 'peatcms'));
                                } else { // validate the domain here
                                    // test domain utf-8 characters: 百度.co (baidu.co)
                                    if (function_exists('idn_to_ascii')) {
                                        $value = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                                    }
                                    if (false === dns_check_record($value, 'A') && false === dns_check_record($value, 'AAAA')) {
                                        $this->handleErrorAndStop(
                                            sprintf('Domain %1$s was not in DNS for instance %2$s.', $value, $posted_id),
                                            __('Domain not found, check your input and try again later.', 'peatcms'));
                                    }
                                }
                            } elseif ($posted_table_name === '_template') {
                                if ($posted_column_name === 'published') {
                                    $temp = new Template($posted_id, null);
                                    if (true === $admin->isRelatedInstanceId($temp->row->instance_id)) {
                                        // this always sends true as value, attempt to publish the template
                                        $update_arr = array('published' => $temp->publish());
                                    } else {
                                        $this->addMessage(__('Security warning, after multiple warnings your account may be blocked.', 'peatcms'), 'warn');
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
                                $this->addError(Help::getDB()->getLastError()->getMessage());
                                $this->addMessage(__('Update column failed.', 'peatcms'), 'error');
                            }
                            // check published for templates
                            if ($posted_table_name === '_template') {
                                if (($row = Help::getDB()->selectRow('_template', $posted_id))) {
                                    $out->published = $this->updatePublishedForTemplates($row->instance_id, $posted_id);
                                }
                            }
                        }
                    } elseif ('insert_row' === $action) {
                        $out = $this->insertRow($admin, $post_data);
                    } elseif ('admin_update_quantity_in_stock' === $action) {
                        if (true === isset($post_data->variant_id)
                            && true === $admin->canDo($action, 'cms_variant', $post_data->variant_id)
                        ) {
                            if (true === Help::getDB()->updateVariantQuantityInStock($post_data->variant_id, $post_data->quantity)) {
                                $out = Help::getDB()->selectRow('cms_variant', $post_data->variant_id);
                            }
                        }
                    } elseif ('admin_popvote' === $action) {
                        $pop_vote = -1;
                        $element_name = $post_data->element_name;
                        $id = $post_data->id;
                        if (true === isset($post_data->direction)
                            && true === $admin->canDo($action, "cms_$element_name", $id)
                        ) {
                            if (($direction = $post_data->direction) === 'up') {
                                $pop_vote = Help::getDB()->updatePopVote($element_name, $id);
                            } elseif ($direction === 'down') {
                                $how_much = max(1, (int)($post_data->places ?? 1));
                                $pop_vote = Help::getDB()->updatePopVote($element_name, $id, $how_much);
                            } else { // return the relative position always
                                $pop_vote = Help::getDB()->getPopVote($element_name, $id);
                            }
                        }
                        $out = array('pop_vote' => $pop_vote);
                    } elseif ('admin_set_homepage' === $action) {
                        if (isset($post_data->slug)) {
                            if (!$out = Help::getDB()->setHomepage(Setup::$instance_id, $post_data->slug)) {
                                $this->addError(sprintf(
                                    '->getDB()->setHomepage failed with slug %1$s for instanceid %2$s.',
                                    var_export($post_data->slug, true), Setup::$instance_id));
                                $out = $instance->row;
                            }
                        }
                    } elseif ('admin_file_upload' === $action) {
                        if (isset($_SERVER['HTTP_X_FILE_NAME'])) {
                            Help::$OUTPUT_JSON = true;
                            $x_file_name = urldecode($_SERVER['HTTP_X_FILE_NAME']);
                            // save the file temporarily
                            $temp_file = tempnam(sys_get_temp_dir(), $instance->getPresentationInstance() . '_');
                            $handle1 = fopen('php://input', 'r');
                            $handle2 = fopen($temp_file, 'w');
                            stream_copy_to_stream($handle1, $handle2);
                            fclose($handle1);
                            fclose($handle2);
                            if (isset($_SERVER['HTTP_X_FILE_ACTION']) && 'import_instance' === $_SERVER['HTTP_X_FILE_ACTION']) {
                                Help::$session->setVar('import_file_name', $temp_file);
                                $out = array('file_saved' => file_exists($temp_file));
                            } else {
                                $el = null;
                                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                                $post_data = array();
                                $post_data['content_type'] = finfo_file($file_info, $temp_file);
                                $post_data['filename_original'] = $x_file_name; // a column that is not editable, but maybe you can search for it
                                // prepare a default element based on the uploaded file that will be created when a new element is needed
                                $default_type = 'file';
                                if (true === str_starts_with($post_data['content_type'], 'image')) $default_type = 'image';
                                // process it in cms
                                if (true === isset($_SERVER['HTTP_X_SLUG'])) {
                                    if ($row = Help::getDB()->fetchElementIdAndTypeBySlug(urldecode($_SERVER['HTTP_X_SLUG']))) {
                                        if (in_array($row->type_name, array('file', 'image'))) { // update the existing element when file or image
                                            $el = $this->updateElement($row->type_name, $post_data, $row->id);
                                        } else { // make a new element and link it to this posted element (if possible)
                                            $el = $this->createElement($default_type);
                                            $el->update($post_data);
                                            $el->link($row->type_name, $row->id);
                                        }
                                    }
                                }
                                if (null === $el) { // if no slug or an invalid slug was provided, just create a new file
                                    $el = $this->createElement($default_type);
                                    $el->update($post_data);
                                }
                                if (false === $el->saveFile($temp_file)) {
                                    $this->addError(__('File could not be processed at this time.', 'peatcms'));
                                }
                                $out = $el->getOutput();
                            }
                        } else {
                            $this->addError(__('You need to be admin and provide X-File-Name header.', 'peatcms'));
                        }
                    } elseif ('admin_database_report' === $action) {
                        $out = array('__rows__' => Help::unpackKeyValueRows(Help::getDB()->fetchAdminReport()));
                        $opcache = function_exists('opcache_get_status') ? opcache_get_status() : 'n/a';
                        $out['__rows__'][] = array('key' => 'opcache', 'value' => print_r($opcache, true));
                        $out['slug'] = 'admin_database_report';
                    }
                } elseif ('reflect' === $action) {
                    $out = $post_data;
                }
            } else {
                $this->addMessage(sprintf(__('%s check failed, please refresh browser.', 'peatcms'), 'CSRF'), 'warn');
                $out = array('success' => false);
            }
        }
        // general failure catch will be reported as error
        if (null === $out) {
            $out = array('success' => false);
            $this->addError('Action failure: ' . var_export($post_data, true));
        }
        $out = (object)$out;
        $out->slugs = $GLOBALS['slugs']; //<- *RECURSION* ?? when, why
        $out = $this->resolver->cleanOutboundProperties($out);
        if (headers_sent()) {
            echo "#BLOEMBRAADEN_JSON:#\n";
            echo json_encode($out);
            die();
        }
        if (true === Help::$OUTPUT_JSON) {
            // add messages and errors
            $out->__messages__ = Help::getMessages();
            if (Help::$session->isAdmin()) $out->__adminerrors__ = Help::getErrorMessages();
            // pass timestamp when available
            if (true === isset($post_data->timestamp)) $out->timestamp = $post_data->timestamp;
            // @since 0.6.1 add any changed session vars for update on client
            $out->__updated_session_vars__ = Help::$session->getUpdatedVars();
            if (ob_get_length()) ob_clean(); // throw everything out the buffer means we can send a clean gzipped response
            $response = gzencode(json_encode($out), 6);
            // todo, log json error when present?
            unset($out);
            header('Content-Type: application/json');
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($response));
            echo $response;
            die();
        } elseif (true === isset($out->redirect_uri)) {
            header("Location: $out->redirect_uri", true, 302);
            die();
        } else {
            $this->addError('Fallen through action: ' . var_export($post_data, true));
            //\header('Location: /' . $this->resolver->getPath(), true, 307); <- doesn’t work
            //die();
        }
        unset($post_data);
    }

    public function View()
    {
        $slug = $this->resolver->getPath();
        $variant_page = $this->resolver->getVariantPage();
        if (false === ADMIN && true === isset($_SERVER['HTTP_X_CACHE_TIMESTAMP'])) {
            $check_timestamp = (int)$_SERVER['HTTP_X_CACHE_TIMESTAMP'];
            // check if it’s ok, then just return ok immediately
            if (true === Help::getDB()->cachex($slug, $check_timestamp)) {
                if ($variant_page > 1) $slug = "$slug/variant_page$variant_page";
                $response = sprintf('{"__ref":"%s","x_cache_timestamp_ok":true}', rawurlencode($slug));
                header('Content-Type: application/json');
                header('Content-Length: ' . strlen($response));
                echo $response;
                die();
            }
        }
        // usually we get the src from cache, only when not cached yet get it from the resolver
        // @since 0.8.2 admin also gets from cache, table_info is added later anyway
        // warmup does an update of the cache row (getDB()->cache handles this automatically) so the client never misses out
        if (true === $this->resolver->hasInstructions()) {
            $element = $this->resolver->getElement();
            $out = $element->getOutputObject();
            unset($element);
        } elseif (null === ($out = Help::getDB()->cached($slug, $variant_page))) {
            // check if it’s a paging error
            if ($variant_page !== 1) $out = Help::getDB()->cached($slug, 1);
            if (null === $out && ($element = $this->resolver->getElement()) instanceof BaseElement) {
                // construct the new path for this old slug
                if (($properties = $this->resolver->getProperties())) {
                    $path = Help::turnIntoPath(explode('/', $element->getSlug()), $properties);
                } else {
                    $path = $element->getSlug();
                }
                // try the cache one more time with the new slug, else cache it
                if (null !== ($out = Help::getDB()->cached($path))) {
                    if (extension_loaded('newrelic')) {
                        $transaction_name = (ADMIN) ? 'Admin:' : 'Visit:';
                        newrelic_name_transaction("$transaction_name from history");
                    }
                } else {
                    $out = $element->cacheOutputObject(true);
                    if (extension_loaded('newrelic')) {
                        $transaction_name = (ADMIN) ? 'Admin:' : 'Visit:';
                        newrelic_name_transaction("$transaction_name cache");
                    }
                }
                unset($element);
            }
        }
        process_out:
        // set path to __ref if not present
        if (true === isset($out->__ref)) {
            $out_path =& $out->slugs->{$out->__ref}->path;
            if (true === $this->resolver->isHomepage()) {
                $out_path = '';
            } elseif (false === isset($out_path)) {
                $out_path = $out->__ref;
            }
        }
        // variant paging
        if (isset($out->variant_page) && 1 !== $out->variant_page) {
            $out->slugs->{$out->__ref}->path .= "/variant_page$out->variant_page";
        }
        // use a properly filled element to check some of the settings
        $element_row = (true === isset($out->__ref)) ? $out->slugs->{$out->__ref} : $out;
        if (true === ADMIN) {
            $type_name = $element_row->type_name;
            // if you get a search page from cache as admin, check if the original slug also exists to see it (can be offline etc)
            if ('search' === $type_name && 1 === count(($terms = $this->resolver->getTerms()))
                && null !== ($row = Help::getDB()->fetchElementIdAndTypeBySlug($terms[0], true))
            ) {
                $this->addMessage(sprintf(__('%s is replaced by a search result for visitors.', 'peatcms'), $slug), 'warn');
                $type = new Type($row->type_name);
                $element = $type->getElement();
                $element->fetchById($row->id);
                $element->setProperties($this->resolver->getProperties());
                $out = $element->getOutputObject();
                unset($element);
                goto process_out;
            }
            // security: check access
            if (true === isset($element_row->instance_id) && $element_row->instance_id !== Setup::$instance_id) {
                if (false === Help::$session->getAdmin()->isRelatedInstanceId($element_row->instance_id)) {
                    $this->handleErrorAndStop(
                        sprintf('admin %1$s tried to access %2$s (instance_id %3$s).',
                            Help::$session->getAdmin()->getId(),
                            $this->resolver->getPath(),
                            $element_row->instance_id)
                        , __('It seems this does not belong to you.', 'peatcms')
                    );
                }
            }
            // prepare object for admin
            $out->__adminerrors__ = Help::getErrorMessages();
            // @since 0.8.2 admin also uses cache
            if ('search' !== $type_name) $out->table_info = $this->getTableInfoForOutput(new Type($type_name));
        } elseif (
            // @since 0.7.6 do not show items that are not online
            (true === isset($element_row->online) && false === $element_row->online)
            // @since 0.8.19 do not show items that are not yet published
            || (true === isset($element_row->is_published) && false === $element_row->is_published)
        ) {
            $element = new Search();
            $element->setProperties($this->resolver->getProperties());
            $element->findWeighted(array(mb_strtolower($element_row->title)));
            //$out = $src->getOutputObject();
            $out = $element->cacheOutputObject(true);
            unset($element);
        }
        unset($element_row);
//        $o = $out->slugs->{$out->__ref};
//        var_dump($o->path, $o->type_name);
//        die(' kwkkwkkwk');
        $instance = Help::$session->getInstance();
        // @since 0.7.9 load the properties in the out object as well
        $out->__query_properties__ = $this->resolver->getProperties();
        $out->template_published = strtotime($instance->getSetting('date_updated'));
        $out->is_admin = ADMIN;
        $out = $this->resolver->cleanOutboundProperties($out);
        // output
        if (true === Help::$OUTPUT_JSON) {
            if (($post_data = $this->resolver->getPostData())) {
                if (true === isset($post_data->timestamp)) {
                    $out->timestamp = $post_data->timestamp;
                }
            }
            $out->__messages__ = Help::getMessages();
            // @since 0.6.1 add any changed session vars for update on client
            $out->__updated_session_vars__ = Help::$session->getUpdatedVars();
            if (true === headers_sent()) {
                echo "#BLOEMBRAADEN_JSON:#\n";
                echo json_encode($out);
            } else {
                if (ob_get_length()) ob_clean(); // throw everything out the buffer means we can send a clean gzipped response
                $response = gzencode(json_encode($out), 6);
                unset($out);
                header('Content-Type: application/json');
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($response));
                echo $response;
            }
        } else { // use template
            if (true === $instance->isParked() && false === ADMIN && false === $this->resolver->hasInstruction('admin')) {
                $presentation_instance = $instance->getPresentationInstance();
                if (file_exists(CORE . "../htdocs/_site/$presentation_instance/park.html")) {
                    header("Location: /_site/$presentation_instance/park.html", TRUE, 307);
                    die();
                } else {
                    die(sprintf(__('Website is parked, but %s is not found.', 'peatcms'), 'park.html'));
                }
            }
            $session = Help::$session;
            // add some global items
            $out->nonce = Help::randomString(32);
            $out->version = Setup::$VERSION;
            $root = $instance->getDomain(true);
            $out->root = $root;
            if (true === $instance->getSetting('plausible_active')) {
                if (true === $instance->getSetting('plausible_revenue')) {
                    $plausible = '{"data_domain":"%s","src":"https://plausible.io/js/script.tagged-events.revenue.js"}';
                } elseif (true === $instance->getSetting('plausible_events')) {
                    $plausible = '{"data_domain":"%s","src":"https://plausible.io/js/script.tagged-events.js"}';
                } else {
                    $plausible = '{"data_domain":"%s","src":"https://plausible.io/js/script.js"}';
                }
                $domain = $instance->getDomain();
                if (str_starts_with($domain, 'www.')) $domain = substr($domain, 4);
                $plausible = sprintf($plausible, $domain);
            } else {
                $plausible = 'null';
            }
            // @since 0.7.9 get the user and setup account related stuff
            $user = $session->getUser();
            if (null === $user) {
                $user_output = '{}';
                $out->is_account = false;
            } else {
                $user_output = $user->getOutput();
                $out->is_account = true;
            }
            // @since 0.8.18
            $out->__session__ = $session->getValues();
            // render in template
            $temp = new Template(null);
            // @since 0.10.6 add complex tags (menus, other elements) to make integral to the first output
            $temp->addComplexTags($out);
            // render the page already
            $temp->render($out);
            // render server values for the site to be picked up by javascript client
            $temp->renderGlobalsOnce(array(
                'version' => Setup::$VERSION,
                'version_timestamp' => strtotime($instance->getSetting('date_published', '')),
                'nonce' => $out->nonce,
                'decimal_separator' => Setup::$DECIMAL_SEPARATOR,
                'radix' => Setup::$RADIX,
                'google_tracking_id' => $instance->getSetting('google_tracking_id', ''),
                'turnstile_site_key' => $instance->getSetting('turnstile_site_key', ''),
                'root' => $root,
                'date' => date('Y-m-d'),
                'plausible' => $plausible,
                'session' => $session->getVars(),
                'slug' => $out,
                'slugs' => $GLOBALS['slugs'],
                'is_account' => $out->is_account,
                'timestamp' => Setup::getNowFrom((int)(1000 * (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? 0))),
                '__user__' => $user_output,
                '__messages__' => Help::getMessages(),
            ));
            if (true === ADMIN) {
                // TODO show the hints as well... and update live maybe, not only once
                $out->__adminerrors__ = Help::getErrorMessages(); // re-assign, for there may be some messages added...
                $temp->renderConsole($out);
            }
            // set content security policy header (CSP), which can differ between instances
            $cdn_root = Setup::$CDNROOT;
            $frame_ancestors = Setup::$FRAME_ANCESTORS;
            // TODO make csp flexible using settings for the instance
            $csp = "Content-Security-Policy: frame-ancestors $frame_ancestors; default-src 'self' {$instance->getDefaultSrc()}; script-src 'self' 'nonce-$out->nonce'; connect-src 'self' https://plausible.io https://*.google-analytics.com; img-src 'self' blob: $cdn_root *.googletagmanager.com https://*.google-analytics.com data:;font-src 'self' https://fonts.gstatic.com https://*.typekit.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://*.typekit.net;base-uri 'self';form-action 'self';";
            unset($out);
            if (true === headers_sent()) { // warnings that slipped through our error handler could be sent, apparently
                // TODO when this happens there is no CSP
                echo $temp->getCleanedHtml();
            } else {
                ob_get_clean();
                $response = gzencode($temp->getCleanedHtml(), 6);
                unset($temp);
                header($csp, true);
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
            if (true === isset($post_data->template) && $template_row = Help::getDB()->getMailTemplate($post_data->template)) {
                $temp = new Template($template_row);
                $body = $temp->renderObject($post_data);
            }
            if (false === isset($body) || '' === $body) {
                $body = var_export($post_data, true);
            }
            if (true === isset($post_data->to)) {
                $to = $post_data->to;
                $allowed_recipients = array_map('trim', explode(',', $instance->getSetting('mail_form_allowed_to') ?? ''));
                if (false === in_array($to, $allowed_recipients)) {
                    $this->addError(sprintf('%s not in allow list for this instance.', htmlspecialchars($to)));
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
        } else {
            $out = array('success' => false);
        }

        return $this->after_posting($out, $post_data);
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
            if (($resolver = $this->resolver)->hasInstruction('shoppinglist') && null !== ($term = ($resolver->getTerms()[0] ?? null))) {
                $data->shoppinglist = $term;
            } else {
                $this->addMessage(sprintf(__('Form input %s is missing.', 'peatcms'), 'shoppinglist'), 'warn');

                return false;
            }
        }
        $list_name = $data->shoppinglist;
        if (false === isset($data->variant_id)) {
            $this->addMessage(sprintf(__('Form input %s is missing.', 'peatcms'), 'variant_id'), 'warn');

            return false;
        }
        $variant_id = $data->variant_id;
        if (null === ($variant_id = Help::asInteger($variant_id))) {
            $this->addMessage(sprintf(__('Form input %1$s must be %2$s.', 'peatcms'), 'variant_id', 'integer'), 'warn');

            return false;
        }
        // get the quantity (defaults to 1)
        $quantity = (isset($data->quantity) ? Help::asInteger($data->quantity, 1) : 1);
        // update list
        $variant = $this->getElementById('variant', $variant_id);
        $list = new Shoppinglist($list_name);
        if ($variant instanceof Variant) {
            if ($action === 'add_to_list') {
                if (false === $list->addVariant($variant, $quantity)) {
                    $this->addMessage(sprintf(__('Adding to list %s failed.', 'peatcms'), $list_name), 'warn');

                    return false;
                }
            } elseif ($action === 'remove_from_list') {
                if (false === $list->removeVariant($variant)) {
                    $this->addMessage(sprintf(__('Removing from list %s failed.', 'peatcms'), $list_name), 'warn');

                    return false;
                }
            } elseif ($action === 'update_quantity_in_list') {
                if (false === $list->updateQuantity($variant, $quantity)) {
                    $this->addMessage(sprintf(__('Update quantity in list %s failed.', 'peatcms'), $list_name), 'warn');

                    return false;
                }
            }

            return true;
        } else {
            // element not found, this can happen when the variant is deleted or with imported sites
            $this->addError(sprintf(__('No %1$s found with id %2$s.', 'peatcms'), 'variant', $variant_id));
            if (true === $list->removeVariantById($variant_id)) { // remove it from the list, if it was there
                $this->addMessage(sprintf(
                    __('An item in %s is no longer available and has been removed.', 'peatcms'),
                    $list_name), 'note'
                );
            } else {
                $this->addError('Could not be removed');
            }
        }

        return false;
    }

    public function getSession()
    {
        return Help::$session;
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

    private function getElementSuggestions(string $type_name, string $term = ''): ?object
    {
        if ('x_value' === $type_name) {
            return Help::getDB()->fetchPropertiesRowSuggestions($term);
        } elseif (($type = new Type($type_name))) {
            if (strlen($term) >= Search::MIN_TERM_LENGTH && 'menu_item' !== $type_name) {
                $src = new Search();
                $src->findWeighted(array($term), 0, array($type_name), false);

                return (object)array(
                    'element' => $type_name,
                    'src' => $term,
                    'rows' => $src->getOutputFull()->__results__,
                );
            } else {
                return Help::getDB()->fetchElementRowSuggestions($type, $term);
            }
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
        if (false === Help::$session->isAdmin()) return null;
        if ($type_name !== 'search' && $peat_type = new Type($type_name)) {
            $el = $peat_type->getElement();
            if (($id = $el->create($online))) {
                if ($el->fetchById($id)) {
                    return $el;
                } else {
                    $this->addError(sprintf('Element %s created, but could not be retrieved with id %d.', $type_name, $id));
                }
            } else {
                $this->addError('element->create() failed');
            }
        } else {
            $this->addError(sprintf('Element %s cannot be created by ->createElement().', $type_name));
        }

        return null;
    }

    private function insertRow(Admin $admin, \stdClass $post_data): ?object
    {
        if ($post_data->table_name === '_instance') {
            // permission check: only admins with instance_id = 0 can insert new instances...
            if ($admin->getOutput()->instance_id !== 0) {
                $this->addMessage(
                    __('Security warning, after multiple warnings your account may be blocked.',
                        'peatcms'), 'warn');

                return null;
            }
            if (($instance_id = Help::getDB()->insertInstance(
                'example.com',
                __('New instance', 'peatcms'),
                //Help::$session->getAdmin()->getClient()->getOutput()->client_id
                $admin->getClient()->getId()
            ))) {
                // a new instance must have a homepage
                if (($page_id = Help::getDB()->insertElement(new Type('page'), array(
                    'title' => 'homepage',
                    'slug' => 'home',
                    'template' => 'peatcms',
                    'content' => 'My first homepage.',
                    'instance_id' => $instance_id
                )))) {
                    Help::getDB()->updateInstance($instance_id, array('homepage_id' => $page_id));

                    return Help::getDB()->selectRow('_instance', $instance_id);
                } else {
                    $this->addError('Could not add homepage to instance.');

                    return (object)true;
                }
            } else {
                $this->addError('Could not create new instance.');

                return (object)true;
            }
        } elseif ($post_data->where->parent_id_name === 'instance_id' // authorize admin for current instance to manipulate it
            && ($instance_id = (int)$post_data->where->parent_id_value)
            && $admin->isRelatedInstanceId($instance_id)) {
            // switch tasks according to table name
            switch ($post_data->table_name) {
                case '_country':
                    if ($country_id = Help::getDB()->insertCountry(
                        __('New country', 'peatcms'),
                        $instance_id,
                    )) {
                        return Help::getDB()->selectRow('_country', $country_id);
                    } else {
                        $this->addError('Could not create new country.');

                        return (object)true;
                    }
                case '_address_shop':
                    if ($id = Help::getDB()->insertAddressShop(
                        __('New address', 'peatcms'),
                        $instance_id,
                    )) {
                        return Help::getDB()->selectRow('_address_shop', $id);
                    } else {
                        $this->addError('Could not create new address.');

                        return (object)true;
                    }
                case '_template':
                    if ($template_id = Help::getDB()->insertTemplate(
                        __('New template', 'peatcms'),
                        $instance_id,
                    )) {
                        return Help::getDB()->selectRow('_template', $template_id);
                    } else {
                        $this->addError('Could not create new template.');

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
                                Help::randomString(10) . "@$domain_name", // email
                                Help::passwordHash(Help::randomString(10)), // password
                                $client_id, // client_id
                                $instance_id // instance_id
                            )) {
                            $this->addMessage('Insert failed.', 'peatcms');
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
                        $this->addError('Could not create new psp.');

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
                        'online' => true,
                    ));
                    break;
                default:
                    $this->handleErrorAndStop(sprintf('Table %s not recognized by insert_row.',
                        $post_data->table_name));
            }
        } else {
            $this->addError('No appropriate parent id received.');

            return (object)true;
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
     * should be (is...) only called by admin, since it may contain sensitive information
     *
     * @param Type $peat_type
     * @return \stdClass|null information about the database table (of an element), or null if there isn’t
     */
    public function getTableInfoForOutput(Type $peat_type): ?\stdClass
    {
        $arr = (array)Help::getDB()->getTableInfo($peat_type->tableName());
        $info = new \stdClass();
        foreach ($arr as $key => $value) {
            // trim is needed to remove the \0 byte denoting a (former) private property
            // https://stackoverflow.com/questions/5484574/php-fatal-error-cannot-access-property-started-with-0
            $key = trim(str_replace('Bloembraaden\TableInfo', '', $key)); // also remove className
            // convert all the columns (which are an object with private properties) to arrays as well
            if ($key === 'columns') {
                foreach ($value as $column_name => $properties) {
                    // cleanup the properties as well
                    $arr_props = (array)$properties;
                    foreach ($arr_props as $prop_name => $prop_value) {
                        $arr_props[trim(str_replace('Bloembraaden\Column', '', $prop_name))] = $prop_value;
                        unset($arr_props[$prop_name]);
                    }
                    $value[$column_name] = $arr_props;
                    $arr_props = null;
                }
            }
            $info->{$key} = $value;
        }
        $arr = null;
        $info->link_tables = Help::getDB()->getLinkTables($peat_type);

        return $info;
    }

    /**
     * @param \stdClass $out
     * @param \stdClass $post_data
     * @return \stdClass $out with added property ‘redirect_uri’ if necessary
     */
    public function after_posting(\stdClass $out, \stdClass $post_data): \stdClass
    {
        if (false === $out->success) {
            if (true === isset($post_data->failure_url)
                && null !== ($url = Help::safeUrl($post_data->failure_url))
            ) {
                $out->redirect_uri = $url;
            } elseif (true === isset($post_data->failure_message)) {
                $this->addMessage($post_data->failure_message, 'error');
            } else {
                $this->addMessage(__('Form posting failed.', 'peatcms'), 'error');
            }

            return $out;
        }

        if (true === isset($post_data->success_url)
            && null !== ($url = Help::safeUrl($post_data->success_url))
        ) {
            $out->redirect_uri = $url;
        } elseif (true === isset($post_data->success_message)) {
            $this->addMessage($post_data->success_message);
        }

        return $out;
    }
}
