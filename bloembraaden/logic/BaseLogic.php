<?php
declare(strict_types=1);

namespace Bloembraaden;
class BaseLogic extends Base
{
    protected Type $type;
    protected ?\stdClass $row, $template_settings;
    protected object $template_pointer;
    protected string $type_name;
    protected int $id;
    // nested_level keeps track of how deep this element is stuck in the spaghetti, start at 1 (also set by first getOutput() )
    protected int $nested_level = 1, $nested_max = 2, $variant_page_size = 60, $variant_page_counter = 1;
    protected bool $nested_show_first_only = true;
    // cache whether completeRowForOutput has run
    protected bool $completedRowForOutput = false;
    protected \stdClass $output_object;

    public function __construct(\stdClass $row = null)
    {
        parent::__construct();
        //$this->type_name is set to correct type (e.g. search, page, variant) by child class
        // populate the instance with the row when present
        if (is_null($row)) {
            $this->row = null;
        } else {
            $this->row = $row;
            if (isset($row->type)) $this->type_name = (string)$row->type;
            if (isset($row->template_pointer)) {
                $this->template_pointer = $row->template_pointer;
            } elseif (isset($row->template_name)) {
                $this->template_pointer = (object)(array(
                    'name' => $row->template_name,
                    'admin' => false, // admin defaults to false always
                ));
            }
        }
    }

    public function __destruct()
    {
        parent::__destruct();
        $this->row = null;
    }

    /**
     * @return int|null the instance_id this element belongs to, if mentioned in the row, null otherwise
     * @since 0.6.2
     */
    public function getInstanceId(): ?int
    {
        if (isset($this->row->instance_id)) return (int)$this->row->instance_id;

        return null;
    }

    public function getRow(): \stdClass
    {
        if (is_null($this->row)) {
            $this->addError("{$this->getType()->typeName()}->getRow() called while row is NULL");
            $this->row = new \stdClass();
        }
        $return_value = $this->row;
        $return_value->template_pointer = $this->getTemplatePointer();
        $return_value->type = $this->getType()->typeName();

        return $return_value;
    }

    public function getResultCount(): int
    {
        return 1; // overridden by children with results to return the actual number of results
    }

    public function getOutput(): \stdClass
    {
        if (null === $this->row) {
            $this->addError($this->getType()->typeName() . '->getOutput() called while row is NULL');
            $this->row = new \stdClass();
        }
        if (false === $this->completedRowForOutput) {
            $this->completedRowForOutput = true;
            $this->completeRowForOutput();
            // continue for all elements
            $row =& $this->row;
            // hidden columns that are never output
            if (isset($row->ci_ai)) unset($row->ci_ai);
            // constructed fields
            if (!isset($row->template_pointer)) $row->template_pointer = $this->getTemplatePointer();
            $row->type = $this->getType()->typeName();
            // fields title, excerpt, description and content need to be parsed
            $parser = new Parser();
            $row->excerpt_parsed = $parser->parse($row->excerpt ?? null);
            $row->description_parsed = $parser->parse($row->description ?? null);
            $row->content_parsed = $parser->parse($row->content ?? null);
            $row->title_parsed = $parser->parse($row->title ?? null, true);
            $parser = null;
        }

        return $this->row;
    }

    public function getOutputObject(): \stdClass
    {
        if (isset($this->output_object)) return $this->output_object;
        // @since 0.7.1 set master template settings, this is done only once per request
        // all children obey to these settings due to __construct() in BaseLogic
        $GLOBALS['template_settings'] = $this->getAndSetTemplateSettings();
        $out = $this->getOutput();
        $out->slugs = $GLOBALS['slugs'];
        $this->output_object = $out;

        return $out;
    }

    /**
     *
     * @param bool $returnOutputObject
     * @return \stdClass|null
     * @since 0.8.8
     */
    public function cacheOutputObject(bool $returnOutputObject = true): ?\stdClass
    {
        $out = $this->getOutputObject();
        // cache the slug if it’s not a dynamic one (containing ‘__’)
        if (isset($out->__ref) and false === strpos($out->__ref, '__')) {
            $db = Help::getDB();
            // update slug...
            $slug = $out->__ref;
            // for elements with no results, drop the cache
            if (0 === $this->getResultCount()) {
                $db->deleteFromCache($slug);

                return ($returnOutputObject) ? $this->getOutputObject() : null;
            }
            // cache the first page always
            $db->cache($out, $this->getTypeName(), $this->getId(), 1);
            $this->variant_page_counter = 2; // go on to the next page
            // loop through all the variant_pages to cache these separately and remember the paging as well in the out element
            // remove all the variants and add the next page of variants
            while ($this->pageVariants($this->variant_page_counter) > 0) {
                $out = $this->getOutput();
                $out->slugs = $GLOBALS['slugs'];
                $out->variant_page = $this->variant_page_counter;
                $db->cache($out, $this->getTypeName(), $this->getId(), $this->variant_page_counter);
                $this->variant_page_counter++;
                if ($this->variant_page_counter > 60) break; // no more than 60 pages forget it
            }
            // remove any lingering pages
            $db->deleteFromCache($slug, $this->variant_page_counter);
            // add the variant paging for template when appropriate
            if ($this->variant_page_counter > 2) {
                $count = $this->variant_page_counter - 1;
                $pages = array();
                $pages[] = (object)array('page_number' => 1, 'slug' => $slug); // same as /variant_page1
                for ($index = 2; $index <= $count; ++$index) {
                    $pages[] = (object)array('page_number' => $index, 'slug' => $slug . '/variant_page' . $index);
                }
                $json = json_encode($pages);
                $pages = null;
                if ($count !== ($affected = $db->updateVariantPageJsonInCache($slug, $json))) {
                    $this->addError("Updated $affected rows in cache for $slug");
                }
            }
            // when the cache is built during this request, sorry you can only get the first page back for now
            // this may return null sometimes on the first try...
            if ($returnOutputObject) {
                if (null === ($out = $db->cached($slug, 1))) {
                    usleep(387); // wait for the database to get ready an arbitrary number of milliseconds
                    if (null === ($out = $db->cached($slug, 1))) {
                        $this->handleErrorAndStop('Could not read cache after creating it', __('Cache error, please try again', 'peatcms'));
                    }
                }
            }
        }

        return (($returnOutputObject) ? $out : null);
    }

    /**
     * @param array $plural
     * @return array
     * @since 0.8.12
     */
    private function getVariantIdsFromPlural(array $plural): array
    {
        $arr = array();
        foreach ($plural as $index => $variant) {
            $arr[] = $variant->variant_id;
        }

        return $arr;
    }

    public function pageVariants(int $variant_page): int
    {
        return 0; // when no variants are linked, of course the default is 0 variants are in this page...
    }

    public function getTemplatePointer(): object
    {
        if (isset($this->template_pointer)) {
            return $this->template_pointer;
        } else {
            return (object)array(
                'name' => $this->getType()->typeName(), // use type template by default
                'admin' => false,
            );
        }
    }

    protected function completeRowForOutput(): void
    {
        // override in child classes when necessary
    }

    /**
     * returns the template settings for this element, by template_id
     * currently nested_max, nested_show_first_only and variant_page_size
     * @return \stdClass
     */
    public function getAndSetTemplateSettings(): \stdClass
    {
        if (isset($this->template_settings)) return $this->template_settings;
        if (!isset($this->row->template_id) or null === ($settings = Help::getDB()->fetchTemplateSettings($this->row->template_id))) { // return default settings
            $settings = (object)array(
                'nested_max' => $this->nested_max,
                'nested_show_first_only' => $this->nested_show_first_only,
                'variant_page_size' => $this->variant_page_size
            );
        }
        $this->template_settings = $settings;

        return $settings;
    }

    /**
     * @return int the id of this element
     * @since 0.0.0
     */
    public function getId(): int
    {
        if (true === isset($this->id)) return $this->id;
        $id_column = $this->getType()->idColumn();
        if (isset($this->row->{$id_column})) {
            $this->id = $this->row->{$id_column};
        } else { // we don't know
            if ($id_column !== 'search_settings_id') $this->addError("Failed to get id: $id_column");
            $this->id = 0;
        }

        return $this->id;
    }

    /**
     * @return bool whether the element is considered online (for non admins)
     * @since 0.7.6
     */
    public function isOnline(): bool
    {
        if (isset($this->row->deleted) && true === $this->row->deleted) return false;
        $this->getOutput();
        if (isset($this->row->is_published) && false === $this->row->is_published) return false;
        // if the column is absent, the element can only be online
        return $this->row->online ?? true;
    }

    /**
     * @param array $row
     * @return bool
     * @since 0.7.9
     */
    public function updateRow(array $row): bool
    {
        // for each element in $row update $this->row
        foreach ($row as $item => $value) {
            $this->row->{$item} = $value;
        }

        // todo maybe save everything on shutdown?
        return Help::getDB()->updateElement($this->getType(), $row, $this->getId());
    }

    /**
     * @return string
     * @since 0.6.0 for caching
     */
    public function getTypeName(): string
    {
        return $this->type_name;
    }

    public function getType(): Type
    {
        if (false === isset($this->type)) {
            $this->type = new Type($this->type_name); // throws error when type_name is not set
        }

        return $this->type;
    }
}