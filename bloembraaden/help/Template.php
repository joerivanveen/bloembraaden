<?php
declare(strict_types=1);

namespace Bloembraaden;
/**
 * Class Template
 * @package Peat
 * Holds template specific settings and the html of it.
 * Also handles the caching of the html and the prepared template (json).
 */
class Template extends BaseLogic
{
    private array $partial_templates, $hints, $doublechecking, $json_by_template_id;
    private string $json_fresh, $html, $version;

    public function __construct(?\stdClass $row = null)
    {
        // @since 0.5.16 you can instantiate the template with null and load it later
        //if ($row === null) $this->handleErrorAndStop('Attempting to instantiate template with $row null');
        parent::__construct($row);
        $this->version = Setup::$VERSION;
        $this->type_name = 'template';
        $this->doublechecking = array(); // I don’t want to worry about it being empty
        $this->json_by_template_id = array(); // I don’t want to worry about it being empty
    }

    public function __destruct()
    {
        parent::__destruct();
        unset($this->json_fresh);
        unset($this->partial_templates);
    }

    public function checkIfPublished(): bool
    {
        if ($this->getFreshJson() === $this->row->json_prepared) {
            return true;
        }

        return false;
    }

    public function getFreshJson(): string
    {
        if (false === isset($this->json_fresh)) {
            $fresh = $this->prepare($this->cleanTemplateHtml($this->row->html));
            $this->json_fresh = json_encode($fresh);
        }

        return $this->json_fresh;
    }

    public function publish(): bool
    {
        // grab the html, prepare into a json object
        $json_prepared = $this->getFreshJson();
        // save it in json_prepared for this template
        if (true === Help::getDB()->updateColumns(
                '_template',
                array('json_prepared' => $json_prepared),
                $this->getId(),
            )) {
            $this->row->json_prepared = $json_prepared;
            //gzencode ( string $data [, int $level = -1 [, int $encoding_mode = FORCE_GZIP ]] ) : string
            // save it to disk: /presentation/[template_id].gz
            $template_folder = Setup::$DBCACHE . 'templates/';
            if (true === file_exists($template_folder)) {
                $template_file = "$template_folder{$this->getId()}.gz"; // json saved as gzipped string
                if (false === file_put_contents(
                        $template_file,
                        gzencode($json_prepared, 9), // compress as well
                        LOCK_EX
                    )) {
                    $this->addMessage(sprintf(__('Could not write %s to disk', 'peatcms'), $template_file), 'error');

                    return false;
                }

                return Help::getDB()->updateColumns('_template', array(
                    'published' => true,
                    'date_published' => 'NOW()',
                ), $this->getId());
            } else {
                $this->addMessage(sprintf(__('Folder %s does not exist', 'peatcms'), $template_folder), 'error');

                return false;
            }
        }

        return false;
    }

    public function getId(): int
    {
        return $this->row->template_id;
    }

    public function completeRowForOutput(): void
    {
        if (false === isset($this->row->__instances__)) {
            $this->row->__instances__ = [Help::getDB()->selectRow('_instance', $this->row->instance_id)];
        }
        Help::prepareAdminRowForOutput($this->row, 'template', (string)$this->getId());
    }

    /**
     * Throws exception if no default template is found
     *
     * @param string $element_name
     * @throws \Exception when loading a default template failed
     * @since 0.5.16
     */
    public function loadDefaultFor(string $element_name): void
    {
        $element_name = strtolower($element_name);
        if (($template_id = Help::getDB()->getDefaultTemplateIdFor($element_name))) {
            $this->row = Help::getDB()->getTemplateRow($template_id);
        } elseif (null === $this->loadByTemplatePointer($element_name)) { // try to get it from disk, the peatcms defaults, if that fails you have to throw an error
            throw new \Exception(sprintf('Could not load default template for %s', $element_name));
        }
    }

    /**
     * Tries to load html template from disk and make json on the fly, returns true on success, false on fail
     *
     * @param string $template_name
     * @param bool $admin
     * @return string|null the html or null when not loaded
     * @since 0.5.16
     */
    public function loadByTemplatePointer(string $template_name, bool $admin = false): ?string
    {
        if (false === $admin) {
            $location = CORE . "presentation/html_templates/frontend/$template_name.html";
        } else {
            $location = CORE . "presentation/html_templates/admin/$template_name.html";
        }
        if (file_exists($location)) {
            // TODO move the cleaning to the caching procedure
            $html = $this->cleanTemplateHtml(file_get_contents($location));
            $json = $this->prepare($html);
            $this->row = new \stdClass();
            $this->row->html = $html;
            $this->row->json_dirty = $json;
            $this->row->json_prepared = $json;
            $this->row->instance_id = Setup::$instance_id;

            return $html;
        }

        return null;
    }

    /**
     * Tags may already been present from another element, just don’t update then.
     *
     * @param \stdClass $output_object
     * @param \stdClass $tags
     * @return \stdClass
     */
    public function addTags(\stdClass $output_object, \stdClass $tags): \stdClass
    {
        foreach ($tags as $key => $value) {
            if (false === isset($output_object->{$key})) {
                $output_object->{$key} = $value;
            }
        }

        return $output_object;
    }

    /**
     * Add elements when called upon by the template to the output object when found in cache, as well as instagram feeds
     *
     * @param \stdClass $output_object
     * @return \stdClass
     */
    public function addComplexTags(\stdClass $output_object): \stdClass
    {
        if (isset($output_object->__ref) && ($ref = $output_object->__ref)) {
            if (null !== ($obj = $this->getTemplateObjectForElement($output_object->slugs->{$ref}))) {
                foreach ($obj as $path => $template) {
                    if (0 === strpos($path, '__action__/instagram/feed/')) {
                        $feed = (new Instagram())->feed(substr($path, 26));
                        $this->addTags($output_object, (object)array($feed->slug => $feed));
                    }
                    if (0 === strpos($path, '__')) continue; // non-insta actions cannot be processed here
                    // for now, only get it from cache, if not in cache, then accept the progressive loading
                    if (($object_from_cache = Help::getDB()->cached($path))) {
                        $this->addTags($output_object->slugs, $object_from_cache->slugs);
                        if (isset($object_from_cache->__ref) && ($ref = $object_from_cache->__ref)) {
                            $this->addTags($output_object, (object)array($ref => $object_from_cache->slugs->{$ref}));
                        }
                    }
                }
                unset($obj);
            }
        }

        return $output_object;
    }

    /**
     * depends on main ->render() method being called first
     * adds the console + admin html and scripts to the existing html property of this template
     *
     * @param \stdClass $output generated stdClass output from an element or pseudo element
     */
    public function renderConsole(\stdClass $output): void
    {
        if ($html = $this->loadByTemplatePointer('console', true)) {
            // some standard properties
            $output->hints = $this->getHints();
            if (count($output->hints) > 0) {
                $output->hints['title'] = __('Template hints', 'peatcms');
            }
            if (isset($output->__adminerrors__) && count($output->__adminerrors__) > 0) {
                $output->__adminerrors__['title'] = __('Object errors', 'peatcms');
            }
            // render the html
            $html = $this->renderOutput($output, $this->prepare($html));
        }
        // add the html
        $this->insertIntoHtml($this->convertTagsRemaining($html));
        // add admin scripts
        $this->insertIntoHtml("<script id='bloembraaden-admin-js' src='/client/admin.js?version=$this->version' async='async'></script>", false);
        $this->insertIntoHtml("<link rel='stylesheet' id='bloembraaden-admin-css' href='/client/admin.css?version=$this->version'>", false);
        // add config for editor
        $default_location = CORE . '../htdocs/instance/editor_config.json';
        $custom_location = CORE . '../htdocs/instance/' . Setup::$PRESENTATION_INSTANCE . '/editor_config.json';
        if (file_exists($default_location)) {
            ob_start();
            echo '<script id="peatcms-editor-config" nonce="';
            echo $output->nonce;
            echo '">var peatcms_editor_config=';
            echo file_get_contents($default_location);
            if (file_exists($custom_location)) {
                echo ';var peatcms_editor_config_custom=';
                echo file_get_contents($custom_location);
            }
            echo ';</script>';
            $this->insertIntoHtml(ob_get_clean());
        }
    }

    /**
     * @param array $client_globals
     */
    public function renderGlobalsOnce(array $client_globals): void
    {
        // put global values in a special data element for reading by javascript
        ob_start();
        echo '<div id="peatcms_globals"';
        foreach ($client_globals as $var_name => $value) {
            echo ' data-';
            echo $var_name;
            echo '=\'';
            echo htmlspecialchars(json_encode($value), ENT_QUOTES);
            //echo str_replace('"', '&quot;', json_encode($value));
            echo '\'';
        }
        echo '></div>';
        $this->insertIntoHtml(ob_get_clean());
    }

    /**
     * Insert html (string) into $this->html at the end of the document (before </body>) or as first element of the
     * head if $end_of_document = false
     *
     * @param string $html_to_insert
     * @param bool $end_of_document @since 0.5.5 added boolean to choose where you want the html (string) inserted
     * @since 0.1.0
     */
    private function insertIntoHtml(string $html_to_insert, bool $end_of_document = true): void
    {
        if ($this->html === '') {
            $this->html = $html_to_insert;
        } else {
            $existing_html = $this->html;
            if (true === $end_of_document) { // put it right before the last </body> tag
                $pos = strrpos($existing_html, '</body>');
            } else { // put it at the beginning, so after the first <head [..] > tag...
                $pos = strpos($existing_html, '<head'); // head may have attributes we need to skip over
                $pos = strpos($existing_html, '>', $pos + 5) + 1; // skip over the > character regarding the position
            }
            if (false === $pos) {
                $this->handleErrorAndStop(
                    'Template does not contain head and / or body tags',
                    sprintf(__('Template must contain at least: %s', 'peatcms'), htmlentities('<html>, <head>, <body>'))
                );
            }
            $this->html = substr_replace($existing_html, $html_to_insert, $pos, 0);
        }
    }

    /**
     * Simple render function that returns the html
     * Template must be initialized / constructed with the row object for this to work
     * Largely a wrapper for actual renderOutput function
     * @param \stdClass $output
     * @return string
     * @since 0.8.0
     */
    public function renderObject(\stdClass $output): string
    {
        if (false === isset($this->row)) {
            $this->addError('Template must be set / initialized before calling ->renderObject');

            return '';
        }
        if (ADMIN) {
            $obj = json_decode($this->getFreshJson());
        } else { // get the published value
            $obj = json_decode($this->row->json_prepared);
        }
        if (null !== $obj) { // probably null if never published or id's changed in the database
            return $this->convertTagsRemaining($this->renderOutput($output, (array)$obj));
        }
        $this->addError('Template not published or accessible during ->renderObject');

        return '';
    }

    /**
     * Original render function, will render everything using the (element’s) $output object
     * automatically loads the correct template (by id or by file pointer) when not already loaded
     * this must be called as first of all the render functions, we don't check if this is the case
     *
     * @param \stdClass $out
     */
    public function render(\stdClass $out): void
    {
        // @since 0.8.0 use __ref
        if (isset($out->slugs)) {
            $GLOBALS['slugs'] = $out->slugs;
            unset($out->slugs);
        }
        if (isset($out->__ref)) {
            $out = (object)array_merge((array)$GLOBALS['slugs']->{$out->__ref}, (array)$out);
            unset($out->__ref);
        }
        if (null !== ($obj = $this->getTemplateObjectForElement($out))) {
            $this->html = $this->convertTagsRemaining($this->renderOutput($out, (array)$obj));
            unset($obj);

            return;
        }
        if (isset($out->template_pointer) && ($template_pointer = $out->template_pointer)) {
            if (($html = $this->loadByTemplatePointer($template_pointer->name, $template_pointer->admin))) {
                // TODO remember prepared template somewhere?
                $html = $this->renderOutput($out, $this->prepare($html));
                // remove tags remaining once at the end (calls are removed from renderOutput())
                $this->html = $this->convertTagsRemaining($html);
            } else {
                $this->handleErrorAndStop(
                    "Template $template_pointer->name not found in theme ",
                    sprintf(__('Could not get template %s', 'peatcms'), $template_pointer->name)
                );
            }
        }
        if (false === isset($this->html)) {
            $this->handleErrorAndStop(
                'Template html is not set during render',
                sprintf(__('Could not get template %s', 'peatcms'), '')
            );
        }
    }

    /**
     * @param $output
     * @param array $template
     * @return string
     */
    private function renderOutput($output, array $template): string
    {
        $html = $template['__html__'];
        // @since 0.8.0 use __ref
        if (isset($output->__ref)) {
            $output = (object)array_merge((array)$GLOBALS['slugs']->{$output->__ref}, (array)$output);
            unset($output->__ref);
        }
//        if (isset($output->__ref)) {
//            $output = $GLOBALS['slugs']->{$output->__ref};
//            unset($output->__ref);
//        }
        //
        $check_if = array(); // @since 0.10.7 remember simple tags to check for if-statements in template last
        foreach ($output as $tag_name => $output_object) { // for each tag in the output object
            if (in_array($out_type = gettype($output_object), array('string', 'integer', 'double', 'boolean'))) {
                if ($out_type === 'boolean') {
                    $output_object = $output_object ? 'true' : 'false'; // else the object will be 1 versus 0...
                } else {
                    $output_object = (string)$output_object;
                }
                $check_if[$tag_name] = $output_object;
                // regular tag replacement
                if (false !== strpos($html, "{{{$tag_name}}}")) {
                    $html = str_replace("{{{$tag_name}}}", $output_object, $html);
                }
                // @since 0.4.6: simple tags can be processed using a function, {{tag|function_name}}
                while (($str_pos = strpos($html, "{{{$tag_name}|")) !== false) {
                    // for each occurrence, grab the function name and (try to) execute it
                    $str_pos = $str_pos + strlen($tag_name) + 3;
                    $end_pos = strpos($html, '}}', $str_pos);
                    $function_name = substr($html, $str_pos, $end_pos - $str_pos);
                    // @since 0.5.9: when not found add a hint but stay silent in the contents (for javascript-only functions)
                    if (method_exists($this, $method_name = "peat_$function_name")) {
                        $processed_object = $this->{$method_name}($output_object);
                    } else {
                        $this->addHint(strip_tags($function_name) . ' not found');
                        $processed_object = $output_object;
                    }
                    $html = str_replace("{{{$tag_name}|$function_name}}", (string)$processed_object, $html);
                }
            } else {
                $output_object = (array)$output_object; // this is a complex element which might contain indexed values that are rows
                if (isset($template[$tag_name])) {
                    // for each occurrence in the template, render this $output_object please
                    foreach ($template[$tag_name] as $index => $sub_template) {
                        $sub_template = (array)$sub_template; // TODO TEMP 0.5.5
                        $temp_remember_html = $sub_template['__html__'];
                        if (isset($output_object[0])) { // build rows if present
                            if (isset($output_object['item_count'])) {
                                $count = $output_object['item_count'];
                            } else {
                                $count = count($output_object);
                                $output_object['item_count'] = $count;
                            }
                            //$output_object['__count__'] = $count; //<- count is also added to each row
                            // prepare template for rows
                            $sub_template = $this->prepare_templateForRows($sub_template);
                            if (isset($sub_template['__row__'])) { // only render rows when present in the template
                                // $sub_template contains ['__row__'] indexed array, for all the row parts in ['__html__']
                                // so render those separately..., and finally render the ['__html__']
                                // for each occurrence in the template
                                $sub_template_row = $sub_template['__row__'];
                                foreach ($sub_template_row as $template_index => $row_template) {
                                    ob_start();
                                    foreach ($output_object as $row_index => $row_output) {
                                        if (false === is_int($row_index)) continue; // this is not a row
                                        if (true === is_string($row_output)) { // row consists of a single string value.
                                            $obj = (object)array('value' => $row_output);
                                        } else {
                                            if (isset($row_output->__ref)) {
                                                //$row_output = $GLOBALS['slugs']->{$row_output->__ref};
                                                $row_output = (object)array_merge((array)$GLOBALS['slugs']->{$row_output->__ref}, (array)$row_output);
                                                unset($row_output->__ref);
                                            }
                                            // @since 0.7.6 do not render items that are not online
                                            if (false === ADMIN && isset($row_output->online) && false === $row_output->online) continue;
                                            $obj = $row_output;
                                        }
                                        $obj->__index__ = $row_index;
                                        $obj->__count__ = $count;
                                        // if this row doesn't contain any tags that are different for each row,
                                        // just leave it at the first execution, repetition is unnecessary
//                                        if ($row_index === 1) { // check this only the second time the row is processed TODO POC JOERI
//                                            if ($this->renderOutput($obj, (array)$row_template) === $build_rows) {
//                                                // leave it as is and stop processing rows
//                                                $sub_template['__html__'] = $build_rows;//$temp_remember_html;
//                                                break; // don't process any more rows from this $output_object
//                                            }
//                                        }
                                        echo $this->renderOutput($obj, (array)$row_template);
                                    }
                                    $sub_template['__html__'] = str_replace("{{__row__[$template_index]}}", ob_get_clean(), $sub_template['__html__']);
                                }
                            }
                        }
                        $sub_html = $this->renderOutput($output_object, $sub_template);
                        // remove entirely if no content was added
                        if ($sub_html === $temp_remember_html) {
                            $sub_html = '';
                        }
                        $html = str_replace("{{{$tag_name}[$index]}}", $sub_html, $html);
                    }
                }
            }
        }
        //$check_if = array_reverse($check_if, true); // check in reverse order, to target the ::value:: in deeper nested tags first
        foreach ($check_if as $tag_name => $output_object) {
            // @since 0.4.12: simple elements can show / hide parts of the template
            while (false !== ($str_pos = strpos($html, "{{{$tag_name}:"))) {
                // @since 0.7.9 you can use ‘equal to’ operator ‘==’
                if (strpos($html, "{{{$tag_name}:==") === $str_pos) {
                    $str_pos = $str_pos + strlen($tag_name) + 5;
                    $equals = strtolower(substr($html, $str_pos, strpos($html, ':', $str_pos) - $str_pos));
                    $is_false = (false === isset($output->{$tag_name}) || strtolower((string)$output->{$tag_name}) !== $equals);
                    $str_pos = $str_pos + strlen($equals) + 1;
                } else {
                    $str_pos = $str_pos + strlen($tag_name) + 3;
                    $is_false = (!$output_object || 'false' === $output_object);
                    $equals = null;
                }
                $end_pos = strpos($html, '}}', $str_pos);
                if (false === $end_pos) { // error: if tag has no end
                    $html = str_replace(
                        "{{{$tag_name}:",
                        "If-error near $tag_name",
                        $html
                    );
                    continue;
                }
                $content = substr($html, $str_pos, $end_pos - $str_pos);
                // @since 0.16.3 allow nested ifs
                if (false !== strpos($content, '{{')) {
                    while (substr_count($content, '{{') > substr_count($content, '}}')) {
                        //while (count(explode('{{', $content)) > count(explode('}}', $content))) {
                        $end_pos = strpos($html, '}}', $end_pos + 2);
                        $content = substr($html, $str_pos, $end_pos - $str_pos);
                    }
                    if (false === $end_pos) { // error: if tag has no end
                        $html = str_replace(
                            "{{{$tag_name}:",
                            "Nested if-error near $tag_name",
                            $html
                        );
//                        echo '<textarea style="width:75vw;height:145px">';
//                        var_dump($tag_name, $output_object, $content);
//                        echo '</textarea>';
                        continue;
                    }
                }
                $parts = explode(':not:', $content); // the content can be divided in true and false part using :not:
                if (true === isset($equals)) {
                    $str_to_replace = "{{{$tag_name}:==$equals:$content}}";
                } else {
                    $str_to_replace = "{{{$tag_name}:$content}}";
                }
                if ($is_false) {
                    if (count($parts) > 1) { // display the 'false' part
                        $html = str_replace($str_to_replace, $parts[1], $html);
                    } else { // forget it
                        $html = str_replace($str_to_replace, '', $html);
                    }
                } else { // display the 'true' part
                    $true_part = $parts[0];
                    if (false !== strpos($true_part, '::value::')) {
                        // subsitute the ::value:: with the actual value for this level only, not the nested levels
                        $parts = explode('::value::', $true_part);
                        $bracket_count = 0;
                        $parts_length = count($parts);
//                        echo '<textarea style="width:75vw;height:70px">';
//                        var_dump($tag_name, $parts_length, $parts);
//                        echo '</textarea>';
                        foreach ($parts as $index => $part) {
                            if ($index === $parts_length - 1) break;
                            $bracket_count += substr_count($part, '{{') - substr_count($part, '}}');
//                            echo '<textarea style="width:75vw;height:70px">';
//                            var_dump($bracket_count, $output_object, $part);
//                            echo '</textarea>';
                            if (0 === $bracket_count) {
                                $parts[$index] = "$part$output_object";
                            } else {
                                $parts[$index] = "$part::value::";
                            }
                        }
                        $true_part = implode($parts);
//                        echo '<textarea style="width:75vw;height:70px">';
//                        var_dump($true_part);
//                        echo '</textarea>';
                    }
                    $html = str_replace($str_to_replace, $true_part, $html);
                }
//                echo '<textarea style="width:75vw;height:145px">';
//                var_dump($tag_name, $output_object, $str_to_replace, $parts);
//                echo '</textarea>';
            }
        }
        $check_if = null;
        $output = null;
        $output_object = null;
//        die('ruf');

        return $html;
    }

    /**
     * @param string $html The html you want to check for complex tags
     * @return string|null The first complex tag name found in $html or null when no complex tag exists
     */
    private function findComplexTagName(string $html): ?string
    {
        $start = strpos($html, '{%');
        if ($start !== false) {
            // grab the tagName:
            $start += 2;

            return substr($html, $start, strpos($html, '%}', $start) - $start);
        }

        return null;
    }

    /**
     * Searches the haystack for a complex tag {%tagname%}.....{%tagname%} and returns
     * all the occurrences as string in an array
     *
     * @param string $tag_name needle, the name of the complex tag we're going to find
     * @param string $html haystack
     * @param int $offset start looking from here (to find all complex tags with that name)
     * @return array Array holding strings that are complex tags
     */
    private function getComplexTagStrings(string $tag_name, string $html, int $offset = 0): array
    {
        $html = substr($html, $offset);
        if ($string = $this->getComplexTagString($tag_name, $html)) {
            $strings = $this->getComplexTagStrings($tag_name, $html, $offset + strlen($string));
            $strings[] = $string;

            return $strings;
        }

        return array();
    }

    /**
     * @param string $tag_name
     * @param string $html
     * @param int $offset
     * @return string|null the whole tagstring, or null on failure
     */
    private function getComplexTagString(string $tag_name, string $html, int $offset = 0): ?string
    {
        // always grab them with an EVEN number of complex tags between them
        $search = "{%$tag_name%}";
        $start = strpos($html, $search);
        if ($offset <= $start) $offset = $start + 1;
        if ($offset < strlen($html)) {
            $end = strpos($html, $search, $offset); // look for the next occurrence of this complex tag
            if ($end !== false) {
                $end += strlen($search);
                $string = substr($html, $start, $end - $start); // this string includes the outer complex tags
                if ($this->hasCorrectlyNestedComplexTags($string)) {
                    $this->doublechecking = array();

                    return $string;
                } else {
                    if (isset($this->doublechecking[$string])) {
                        $this->addError("Error in ->getComplexTagString for this string: $string");
                        $this->addMessage(sprintf(__('Error in template for %s', 'peatcms'), htmlentities($string)), 'error');

                        return str_replace($search, '', $string); // this is clearly broken, it will display something ugly anyway
                    } else {
                        $this->doublechecking[$string] = true;
                    }
                    // these are nested tags, so skip the next same one as well, to speed things up
                    $offset = strpos($html, $search, $end + 1) + 1;

                    return $this->getComplexTagString($tag_name, $html, $offset);
                }
            }
        }

        return null;
    }

    /**
     * @param string $string the string to check for (nested) complex tags
     * @return bool true when the tags are correctly nested, false when not
     */
    private function hasCorrectlyNestedComplexTags(string $string): bool // used to be hasEvenNumberOfComplexTags
    {
        // all the tags need to form a 'pyramid', always be in pairs, from outside to in, if not they are incorrectly nested
        if ($tag_name = $this->findComplexTagName($string)) {
            $search = "{%$tag_name%}";
            $len = strlen($search);
            // remove the outer two occurrences and check if the inner part still ->hasCorrectlyNestedComplexTags
            if (false !== ($pos = strpos($string, $search))) {
                $string = substr($string, $pos + $len);
                if (false !== ($pos = strrpos($string, $search))) { // look from the end (note the extra 'r' in strrpos)
                    $string = substr($string, 0, $pos);

                    return $this->hasCorrectlyNestedComplexTags($string);
                } else { // there was only one of the tags, that is an error
                    return false;
                }
            }
        }

        // if there are 0 complex tags left, the pyramid has reached its summit correctly
        return true;
    }

    /**
     * @return array
     */
    private function getPartialTemplates(): array
    {
        if (false === isset($this->partial_templates)) {
            // get partial templates that belong to the same instance as this template does
            $rows = Help::getDB()->getPartialTemplates($this->row->instance_id);
            $partials = array(); // named array holding template rows, by template_name
            foreach ($rows as $key => $row) {
                if (isset($partials[$partial_name = strtolower($row->name)])) {
                    $this->addMessage(sprintf(__('Multiple templates found with name %s', 'peatcms'), $partial_name), 'warn');
                }
                $partials[$partial_name] = $row;
            }
            unset($rows);
            $this->partial_templates = $partials;
            unset($partials);
        }

        return $this->partial_templates;
    }

    public function getPrepared(string $html): array
    {
        return $this->prepare($this->cleanTemplateHtml($html));
    }

    private function prepare(string $html): array
    {
        // replace all the include tags with the partial templates
        while (false !== ($pos = strpos($html, '{{>'))) {
            $pos += 3;
            $end = strpos($html, '}}', $pos);
            $partial_name_lower = strtolower(($partial_name = substr($html, $pos, $end - $pos)));
            $partials = $this->getPartialTemplates();
            if (isset($partials[$partial_name_lower])) {
                $temp = new Template($partials[$partial_name_lower]);
                $html = str_replace("{{>$partial_name}}", $this->cleanTemplateHtml($temp->row->html), $html); // insert the partial into this html
            } else {
                $this->addMessage(sprintf(__('Template %1$s not found for inclusion in %2$s', 'peatcms'), $partial_name, $this->row->name), 'warn');
                $html = str_replace("{{>$partial_name}}", '', $html); // remove the partial tag
            }
        }
        $template = array();
        // find all the first level complex tags and save them in array as a separate template to be reinserted later
        while ($tag_name = $this->findComplexTagName($html)) {
            if (false === isset($template[$tag_name])) $template[$tag_name] = array();
            if (($string = $this->getComplexTagString($tag_name, $html))) {
                $number = count($template[$tag_name]);
                $template[$tag_name][$number] = $this->prepare($this->getInnerContent($string));
                $html = str_replace($string, "{{{$tag_name}[$number]}}", $html);
            } else {
                $this->addMessage(
                    sprintf(__('Error in complex tag string %s', 'peatcms'), $tag_name),
                    'warn');
                $html = str_replace("{%$tag_name", '', $html);
            }
        }
        // add the correct javascript and css location(s) this template needs
        // instance has a global date_published for now we should adhere to regarding the js and css versioning
        if (false !== strpos($html, '</head>')) {
            // build css and js link for the head
            ob_start();
            if (!isset($this->row->element) || 'invoice' !== $this->row->element) {
                $instance_id = $this->getInstanceId();// ?? Setup::$instance_id;
                $file_location = Setup::$DBCACHE . "css/$instance_id.css";
                $css_ok = false;
                if (false === Setup::$VERBOSE && file_exists($file_location)) {
                    echo '<style id="bloembraaden-css">';
                    echo file_get_contents($file_location);
                    echo '</style>';
                    if (ob_get_length() < 500) {
                        ob_clean();
                        unlink($file_location);
                        $this->addError(sprintf('%s was empty, removed', $file_location));
                    } else {
                        $css_ok = true;
                    }
                }
                if (false === $css_ok) { // link to server generated stylesheet
                    echo '<link rel="stylesheet" id="bloembraaden-css" href="/__action__/stylesheet?version=';
                    echo $this->version;
                    echo '-{{template_published}}">';
                }
            }
            echo '<script id="bloembraaden-js" src="/__action__/javascript?version=';
            echo $this->version;
            echo '-{{template_published}}" async="async" defer="defer"></script>';
            echo '</head>';
            // replace the end of the head tag with these scripts / links
            $html = str_replace('</head>', ob_get_clean(), $html);
            // insert our tag
            $html = "<!-- 
    
Bloembraaden.io, made by humans for humans

-->
 
$html";
        }
        $template['__html__'] = $html;

        return $template;
    }

    private function cleanTemplateHtml(?string $html): string
    {
        if (null === $html) return '';
        $html = str_replace(array("\r", "\n", "\t"), '', $html);
        while (false !== strpos($html, '  ')) {
            $html = str_replace('  ', ' ', $html);
        }
        //$html = str_replace('> <', '><', $html);
        $html = str_replace('{{> ', '{{>', $html);
        $html = str_replace('{{ ', '{{', $html);
        $html = str_replace(' }}', '}}', $html);
        $html = str_replace('{% ', '{%', $html);
        $html = str_replace(' %}', '%}', $html);

        return $html;
    }

    private function prepare_templateForRows(array $template): array
    {
        $html = $template['__html__'];
        // convert the whole line to a row, this will be undone during processing
        // when it turns out this template does not contain tags specific for the row
        if (false === isset($template['__row__'])) {
            $template['__row__'] = array($template);
            $html = '{{__row__[0]}}';
        }
        $template['__html__'] = $html;

        return $template;
    }

    /**
     * Searches a string for remaining tags
     * complex tags {%...%} are converted to indexed option element
     * simple tags {{...}} are removed at the end
     *
     * @param string $html the html to strip remaining tags from
     * @return string Always returns $html with the tags stripped or unchanged when the tags were not found
     */
    private function convertTagsRemaining(string $html): string
    {
        // all the tags with indexes may be progressive loading tags, they must be replaced with an option tag
        for ($index = 0; $index < 100; ++$index) {
            $search = "[$index]}}";
            if (false === strpos($html, $search)) break; // don't loop any further if the indexes became too high
            // grab all the tags to replace
            $arr = explode($search, $html);
            foreach ($arr as $key => $html_part) {
                $tag_name = substr($html_part, strrpos($html_part, '{{') + 2);
                $replacer = "{{{$tag_name}$search";
                $html = str_replace($replacer, "<option data-peatcms-placeholder='1' id='{$tag_name}_$index'></option>", $html);
            }
        }
        // now only single tags should be left, remove them
        while ($start = strpos($html, '{{')) {
            $end = strpos($html, '}}', $start);
            $next_start = strpos($html, '{{', $start + 2);
            while (false !== $next_start && $next_start < $end) {
                $next_start = strpos($html, '{{', $end);
                $end = strpos($html, '}}', $end + 2);
            }
            if (false === $end) {
                $search = substr($html, $start, 20);
                $this->addHint("tag error: $search...");
                $html = str_replace($search, '<span class="error">tag_error</span>', $html);
            }
            $search = substr($html, $start, $end - $start + 2);
            $html = str_replace($search, '', $html);
        }

        return $html;
    }

    public function addHint(string $message)
    {
        $this->hints[] = $message;
    }

    public function getHints(): array
    {
        return $this->hints ?? array();
    }

    private function getInnerContent(string $string): string // this assumes the string is correct, there are no checks
    {
        $start = strpos($string, '%}') + 2; // start at the end of the opening tag
        $end = strrpos($string, '{%'); // end at the beginning of the end tag (notice strrpos: REVERSE here)

        return substr($string, $start, $end - $start);
    }

    public function getCleanedHtml(): string
    {
        $parts = explode('</script>', $this->html);
        $i = 0;
        // @since 0.10.4 remove options from script blocks, e.g. ld+json that is rendered in the template
        foreach ($parts as $index => $part) {
            while (false !== ($option_pos = strrpos($part, '<option'))
                && false !== ($script_pos = strrpos($part, '<script'))
                && $option_pos > $script_pos
            ) {
                $option_end = strpos($part, '</option>', $option_pos);
                $option_str = substr($part, $option_pos, $option_end + 9 - $option_pos);
                $part = str_replace($option_str, '', $part);
                if (15 === ++$i) break;
            }
            $parts[$index] = $part;
        }

        return implode('</script>', $parts);
    }

    /**
     * Returns the template object based on the template id present in the output object (element)
     * Caches the object by template_id for the request
     *
     * @param \stdClass $out
     * @return \stdClass|null
     */
    private function getTemplateObjectForElement(\stdClass $out): ?\stdClass
    {
        if ('template' !== $out->type_name && isset($out->template_id) && ($template_id = $out->template_id) > 0) {
            if (isset($this->json_by_template_id[$template_id])) {
                return $this->json_by_template_id[$template_id];
            }
            $obj = null;
            if (isset($this->row) || ($this->row = Help::getDB()->getTemplateRow($template_id))) {
                if (ADMIN) {
                    $obj = json_decode($this->getFreshJson());
                } else { // get the published value
                    $obj = json_decode($this->row->json_prepared);
                }
            }
            $this->json_by_template_id[$template_id] = $obj; // can be null if never published or id's changed in the database

            return $obj;
        }

        return null;
    }

    /**
     * SECTION with functions (filters) that can be called in the template within a simple tag
     * using a pipe character | (eg: {{title|clean}}
     * Ideally these should exactly mirror the ones in javascript (peat.js)...
     */
    /**
     * @param string $str the dirty (html) string
     * @return string stripped and cleaned version for use in head, textareas etc.
     * @noinspection PhpUnusedPrivateMethodInspection This is a template function callable from templates using |clean
     */
    private function peat_clean(string $str): string
    {
        return htmlentities(str_replace(array("\n", "\r", "\t"), ' ', strip_tags(str_replace('><', '> <', $str))));
    }

    /**
     * @param string $str the (html) string that may contain template tags
     * @return string all the opening curly brackets are replaced with &#123;
     */
    private function peat_no_render(string $str): string
    {
        return str_replace('{', '&#123;', $str);
    }

    private function peat_as_float(string $str): float
    {
        return Help::getAsFloat($str, 0);
    }

    private function peat_minus_one(string $str): string
    {
        if (is_numeric($str)) return (string)((int)$str - 1);

        return $str;
    }

    private function peat_plus_one(string $str): string
    {
        if (is_numeric($str)) return (string)((int)$str + 1);

        return $str;
    }

    private function peat_encode_for_template(string $str): string
    {
        $str = htmlentities($str);

        return $this->peat_no_render($str);
    }

    private function peat_enquote(string $str): string
    {
        return str_replace('"', '&quot;', $str);
    }
}
