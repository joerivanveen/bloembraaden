<?php

namespace Peat;
class BaseElement extends BaseLogic implements Element
{
    // $row, $template_pointer, $type_name, defined in BaseLogic
    protected array $properties;
    protected \stdClass $linked_types;

    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
    }

    public function fetchById(int $id = 0): ?BaseElement
    {
        if ($id > 0) {
            if (!empty($this->row)) $this->addError('fetchById() called on an already filled ‘' .
                $this->getType()->typeName() . '’ with id: ' . $id);
            if (($this->row = $this->getDB()->fetchElementRow($this->getType(), $id))) {
                $this->id = $id;

                return $this;
            }
        }

        return null;
    }

    private function refreshRow(): bool
    {
        if (($id = $this->getId()) && ($type = $this->getType())) {
            if (($row = $this->getDB()->fetchElementRow($type, $id))) {
                // update the row with fresh values (leave everything else alone)
                foreach ($row as $column_name => $value) {
                    $this->row->$column_name = $value;
                }
                $row = null;

                return true;
            }
        }

        return false;
    }

    public function create(): ?int
    {
        $this->addError('element->create() needs to be overridden by the child class');

        return null;
    }

    public function update(array $data): bool
    {
        if (true === $this->getDB()->updateElement($this->getType(), $data, $this->getId())) {
            if (true === $this->refreshRow()) return true;
            $this->addError('could not refresh row');
            return true; // update still ok
        }

        return false;
    }

    /**
     * @return bool success
     * @since 0.5.7
     */
    public function delete(): bool
    {
        return $this->getDB()->deleteElement($this->getType(), $this->getId());
    }

    public function updateRef(string $column_name, string $column_value): ?array
    {
        $peat_type = $this->getType();
        if ($ref_names = $this->getDB()->getReferencingTables($peat_type)) {
            foreach ($ref_names as $key => $ref_name) {
                $keys = $this->getDB()->updateElementsWhere(
                    new Type($ref_name),
                    array($column_name => $column_value),
                    array($peat_type->idColumn() => $this->getId())
                );
                if (($rows_affected = count($keys)) > 0) {
                    $this->addMessage(sprintf(
                    // #translators: 1 = quantity, 2 = element name (singular), 3 = the parent(id) that is being updated
                        __('%3$s: %1$s %2$s(s) updated as well.', 'peatcms'),
                        $rows_affected, $ref_name, $peat_type->typeName()));
                }
            }
        }
        // for product_id update the serie_id, for serie_id update the brand_id
        // setup data array for chainParents
        // TODO the element knows too much, the chain must be automated, like with ReferencingTables
        $arr = array($column_name => $column_value);
        if (isset($arr['product_id'])) {
            if (($tmp = $this->getDB()->fetchElementRow(new Type('product'), $column_value))) {
                if ($serie_id = $tmp->serie_id) {
                    $arr = array_merge($arr, array('serie_id' => $serie_id));
                } else {
                    $this->addMessage(sprintf(__('%1$s not set for this %2$s', 'peatcms'), 'Series', 'product'), 'warn');
                }
            } else {
                $this->addError(sprintf('Parent chain update fail for product_id ‘%s’', $column_value));
            }
        }
        if (isset($arr['serie_id'])) {
            if (($tmp = $this->getDB()->fetchElementRow(new Type('serie'), $column_value))) {
                if ($brand_id = $tmp->brand_id) {
                    $arr = array_merge($arr, array('brand_id' => $brand_id));
                } else {
                    $this->addMessage(sprintf(__('%1$s not set for this %2$s', 'peatcms'), 'Brand', 'series'), 'warn');
                }
            } else {
                $this->addError(sprintf('Parent chain update fail for serie_id ‘%s’', $column_value));
            }
        }

        return $arr;
    }

    /**
     * @param string|null $element_name
     * @return array two-dimensional named array containing the plural tags (indexed array) by plural tag (e.g. __products__)
     */
    public function getLinked(?string $element_name = null): array
    {
        $linked = $this->getLinkedTypes();
        $arr = array();
        foreach ($linked as $type => $relation) {
            $plural_tag = "__{$type}s__";
            if (isset($this->row->$plural_tag)) {
                $arr[$type] = $this->row->$plural_tag;
            } else {
                $arr[$type] = [];
            }
        }
        if (null === $element_name) return $arr;
        if (isset($arr[$element_name])) return $arr[$element_name];

        return array();
    }

    public function getLinkedTypes(): \stdClass
    {
        if (false === isset($this->linked_types)) {
            $this->fetchLinked();
        }

        return $this->linked_types;
    }

    /**
     * @param int $variant_page the page of variants to go to
     * @return int the number of variants in the new page (0 when no variants are in the page)
     */
    public function pageVariants(int $variant_page): int
    {
        // search must override this
        if (isset($this->row->__variants__)) {
            // remove all current variants from $globals['slugs']
            foreach ($this->row->__variants__ as $index => $obj) {
                unset($GLOBALS['slugs'][$obj->__ref]);
            }
            // clean the linked table
            unset($this->row->__variants__);
            // call fetchlinked again, will overwrite any linked items that aren’t set (yet)
            if (true === $this->fetchLinked()) {
                // return the number of variants
                return count($this->row->__variants__);
            } else {
                $this->addError('pageVariants failed...');
            }
        }

        return 0; // stop it!
    }

    /**
     * @return bool true when success, false on failure
     * @since 0.0.0
     * @since 0.8.3 it checks whether values are already set, so you can call it multiple times to update e.g. __variants__ only
     */
    protected function fetchLinked(): bool // pay attention, use ->getLinkedTypes() to get linked elements, never use ->fetchLinked directly
    {
        // fetch linked elements for this and add them to $this->row
        if ('search' === $this->type_name) { // search doesn’t have linked elements nor an id, so just leave it at that
            $this->linked_types = new \stdClass;

            return true;
        }
        $id = $this->getId();
        $peat_type = $this->getType();
        if (!$id) {
            $this->addError(__CLASS__ . '->fetchLinked() called while id is ' . $id);
            $this->linked_types = new \stdClass;

            return false;
        }
        $linked_types = $this->getDB()->getLinkTables($peat_type);
        foreach ($linked_types as $linked_type_name => $relation) {
            if ('x_value' === $linked_type_name) { // linked property values are a special breed...
                if ('properties' === $relation) {
                    if (!isset($this->row->__x_values__)) {
                        // to improve performance we only get property slug and title, and property_value slug and title
                        $this->row->__x_values__ = $this->getDB()->fetchPropertyRowsLinked($peat_type, $id) ?? array();
                    }
                } elseif ($this->nested_level === 1) { // only get these from base property and property_value
                    // relation must be the other way around, an indexed array is supplied containing the elements linked
                    //if (false === is_array($relation)) $this->handleErrorAndStop('x_value relation must contain array');
                    foreach ($relation as $index => $element_name) {
                        $linked_type = new Type($element_name);
                        $plural_tag = "__{$element_name}s__";
                        if (!isset($this->row->$plural_tag)) {
                            $this->row->$plural_tag = array();
                            if (($tmp = $this->getDB()->fetchElementRowsLinkedX(
                                $peat_type, $id, $linked_type, $this->variant_page_size, $this->variant_page_counter, $this->getProperties()
                            ))) {
                                foreach ($tmp as $key => $row) {
                                    $this->row->$plural_tag[] = $linked_type->getElement($row)->getOutput(1);
                                }
                            }
                        }
                    }
                }
            } else {
                $linked_type = new Type($linked_type_name);
                // property values are only shown one deep, and always shown all, so decouple $nested_level
                $nested_level = ('property_value' === $linked_type_name) ? 2 : $this->nested_level;
                $plural_tag = "__{$linked_type_name}s__"; // this is for the template etc., '__files__' in stead of 'file'
                if (!isset($this->row->$plural_tag)) {
                    $this->row->$plural_tag = array();
                    if (($tmp = $this->getDB()->fetchElementRowsLinked(
                        $peat_type, $id, $linked_type, $relation, $this->variant_page_size, $this->variant_page_counter
                    ))) {
                        // in the deepest level, only display the first record (e.g. first image) when requested
                        // NOTE use $this->nested_level to check for the loop or the one, not $nested_level
                        if ($this->nested_level > 1 and true === $this->nested_show_first_only) {
                            if (count($tmp) > 0) {
                                $this->row->$plural_tag[] = $linked_type->getElement($tmp[0])->getOutput($nested_level);
                            }
                        } else {
                            foreach ($tmp as $key => $row) {
                                // linked items only one level deep, or else you create loops with elements
                                // linking up and down cross relationships
                                $this->row->$plural_tag[] = $linked_type->getElement($row)->getOutput($nested_level);
                            }
                        }
                    }
                }
            }
        }
        $this->linked_types = $linked_types; // this is also available in the table_info as linked_tables, but only for admins

        return true;
    }

    public function link(string $sub_element_name, int $sub_id, bool $unlink = false): bool
    {
        unset($this->linked_types); // these are possibly changed
        if (($sub_type = new Type($sub_element_name))) {
            if ($this->getDB()->upsertLinked($this->getType(), $this->getId(), $sub_type, $sub_id, $unlink)) {
                return $this->reCache();
            }
        } else {
            $this->addError("Could not link ‘{$sub_element_name}’");
        }

        return false;
    }

    public function linkX(int $property_id, int $property_value_id): bool
    {
        if ($this->getDB()->addXValueLink($this->getType(), $this->getId(), $property_id, $property_value_id)) {
            return $this->reCache();
        } else {
            $this->addError('Could not link ‘property’');
        }

        return false;
    }

    public function orderLinked(string $linkable_type_name, string $slug, string $before_slug, bool $full_feedback = true): array
    {
        $linkable_type = new Type($linkable_type_name);
        $elements = $this->getLinked()[$linkable_type_name];
        $order_column = ($this->getLinkedTypes()->$linkable_type_name === 'cross_parent') ? 'o' : 'sub_o';
        $keep_order = 1;
        foreach ($elements as $key => $element_row) {
            if (!isset($element_row->slug)) $element_row = $GLOBALS['slugs'][$element_row->__ref];
            $link_element = $linkable_type->getElement($element_row);
            if ($link_element->getSlug() === $slug) continue; // don't process the dropped item in the row
            if ($link_element->getSlug() === $before_slug) {
                $dropped_element = $this->getDB()->fetchElementIdAndTypeBySlug($slug);
                $this->getDB()->upsertLinked($this->getType(), $this->getId(),
                    new Type($dropped_element->type), $dropped_element->id, false, $keep_order);
                $keep_order++;
            }
            if ($link_element->getRow()->$order_column !== $keep_order) {
                $this->getDB()->upsertLinked($this->getType(), $this->getId(),
                    $link_element->getType(), $link_element->getId(), false, $keep_order);
            }
            $keep_order++;
        }
        if (!$this->reCache()) {
            $this->addError(sprintf('Uncaching failed for %s, order is wrong', $this->getSlug()));
        }

        return $this->refreshLinked($linkable_type_name, $full_feedback);
        //return $this->getLinked()[$linkable_type_name];
    }

    /**
     * Returns an updated array of the supplied $linkable_type_name taking advantage of the
     * fetchLinked() method that only gets the missing items from db
     * @param string $linkable_type_name
     * @param bool $full_feedback
     * @return array
     * @since 0.8.3
     * @since 0.8.16 only output single items for this is for admins only
     */
    protected function refreshLinked(string $linkable_type_name, bool $full_feedback = true): array
    {
        $plural_tag = "__{$linkable_type_name}s__";
        unset($this->row->$plural_tag);
        if (false === $full_feedback) {
            $id = $this->getId();
            $peat_type = $this->getType();
            $linked_type = new Type($linkable_type_name);
            $relation = $this->getLinkedTypes()->$linkable_type_name;
            //$relation = 'cross_child';
            $GLOBALS['slugs'] = array();

            return $this->getDB()->fetchElementRowsLinked(
                $peat_type,
                $id,
                $linked_type,
                $relation,
                $this->variant_page_size,
                $this->variant_page_counter
            ) ?: array();
        }
        if ($this->fetchLinked()) {
            return $this->getLinked()[$linkable_type_name];
        } else {
            $this->addError(sprintf('->fetchLinked fail for %s', $linkable_type_name));
        }

        return array();
    }

    /**
     * @param int $x_value_id
     * @param int $before_x_value_id
     * @return array
     */
    public function orderXValue(int $x_value_id, int $before_x_value_id): array
    {
        $table_name = $this->getType()->tableName() . '_x_properties';
        $elements = $this->getLinked()['x_value'];
        $keep_order = 1;
        foreach ($elements as $key => $element_row) {
            if ($element_row->x_value_id === $x_value_id) continue; // don't process the dropped item in the row
            if ($element_row->x_value_id === $before_x_value_id) {
                $this->getDB()->updateColumns($table_name, array('o' => $keep_order), $x_value_id);
                $keep_order++;
            }
            $this->getDB()->updateColumns($table_name, array('o' => $keep_order), $element_row->x_value_id);
            $keep_order++;
        }
        unset($this->linked_types);
        if (true !== $this->reCache()) {
            $this->addError(sprintf('Uncaching failed for %s, order is wrong', $this->getSlug()));
        }

        return $this->refreshLinked('x_value');
        //return $this->getLinked()['x_value'];
    }

    public function reCache(): bool
    {
        if (isset($this->row->slug)) {
            return $this->getDB()->reCacheWithWarmup($this->row->slug);
        }

        return false;
    }

    /**
     * Gets the $row of the element as \stdClass ready for json, makes sure the linked types are included
     *
     * @param int $nest_level the depth of the element determines whether linked types are included etc.
     * @return \stdClass the row object including linked types
     */
    public function getOutput(int $nest_level = 0): \stdClass
    {
        //$slug = $this->row->slug ?? null;
        $slug = $this->getPath();
        // @since 0.8.0 get from packed object, only at the level of elements this is cached for the request
        if (isset($GLOBALS['slugs'][$slug]) && $GLOBALS['slugs'][$slug]->nest_level <= $nest_level) {
            return (object)array('__ref' => $slug);
        }
        // @since 0.7.1 master template settings for the request are stored in global var
        if (isset($GLOBALS['template_settings'])) {
            $this->nested_max = ($settings = $GLOBALS['template_settings'])->nested_max;
            $this->nested_show_first_only = $settings->nested_show_first_only;
            $this->variant_page_size = $settings->variant_page_size;
        }
        //
        $this->row = parent::getOutput();
        $this->row->nest_level = $nest_level;
        // max nest level can be 1 or 2, 2 is slower but displays more in the linked elements, with tags present in the template
        if ($nest_level < $this->nested_max) {
            $this->nested_level = $nest_level + 1;
            $linked_types = $this->getLinkedTypes();
            $this->row->linked_types = $linked_types;
            // from all the linked stuff, put the first one as default one (eg image:slug, image:description etc.)
            foreach ($linked_types as $table_name => $relation) {
                $plural_tag = "__{$table_name}s__";
                if (isset($this->row->$plural_tag[0]) && ($el = $this->row->$plural_tag[0])) {
                    if (isset($el->__ref)) $el = $GLOBALS['slugs'][$el->__ref]; // @since 0.8.0
                    $this->addParentTags($el, $table_name);
                }
            }
        }
        // @since 0.8.0 format the property values nicely for output
        if (isset($this->row->__x_values__) && is_array($values = $this->row->__x_values__)) {
            $properties = array();
            foreach ($values as $index => $row) {
                $property_name = $row->property_slug; // the slug is used for summoning
                if (isset($properties[$property_name])) {
                    $properties[$property_name][] = (object)array('title' => $row->title, 'value' => $row->x_value, 'slug' => $row->slug);
                } else {
                    // make an indexed array containing the objects as formatted for templating
                    $properties[$property_name] = array((object)array('title' => $row->title, 'value' => $row->x_value, 'slug' => $row->slug));
                }
            }
            $this->row->__properties__ = $properties;
        }
        // @since 0.8.19 allow is_published as a shorthand for date_published < now()
        if (isset($this->row->date_published)) {
            try {
                if (strtotime($this->row->date_published) <= Setup::getNow()) {
                    $this->row->is_published = true;
                } else {
                    $this->row->is_published = false;
                    // and also set it to stale on the specific date so it will in fact be published
                    if (isset($slug)) $this->getDB()->markStaleFrom($slug, $this->row->date_published);
                }
            } catch (\Exception $e) {
                $this->addError($e);
            }
        }
        // @since 0.8.0 packed objects at the level of elements (this level: BaseElement)
        if (isset($slug)) {
            $GLOBALS['slugs'][$slug] = $this->row;

            return (object)array('__ref' => $slug);
        }

        return $this->row;
    }

    /**
     * Returns the full output object like in earlier versions was returned by getOutput
     * @return mixed|\stdClass
     * @since 0.8.0
     */
    public function getOutputFull(): \stdClass
    {
        $out = $this->getOutput();
        if (isset($out->__ref)) return $GLOBALS['slugs'][$out->__ref];

        return $out;
    }

    protected function addParentTags(\stdClass $row, string $name_space): void
    { // TODO this is funky, please make it right
        foreach ($row as $column_name => $column_value) {
            if (false !== strpos($column_name, ':')) continue;
            if (is_numeric($column_value) || (is_string($column_value) && strlen($column_value) < 256)) { // @since 0.8.0
                $name = $name_space . ':' . $column_name;
                $this->row->$name = $column_value;
            }
        }
    }

    public function setProperties(array $properties = []): void
    {
        /* since 0.8.16 remove nonsense properties so they don’t turn up in the path as well */
        $allowed_properties = $this->getDB()->fetchPropertiesForInstance();
        $allowed_properties['price_min'] = '';
        $allowed_properties['price_max'] = '';
        foreach ($properties as $name => $values) {
            if (false === isset($allowed_properties[$name])) unset ($properties[$name]);
        }

        $this->properties = $properties;
    }

    public function getProperties(): array
    {
        return $this->properties ?? array();
    }

    public function getInstanceId(): int
    {
        if (isset($this->getRow()->instance_id)) {
            return $this->getRow()->instance_id;
        }
        $this->addError(sprintf('getInstanceId() on type ‘%s’ called while there was none', $this->type_name));

        return 0; // this will not belong to anything
    }

    /**
     * Gets slug of an element
     * @return string the slug of the element
     */
    public function getSlug(): ?string
    {
        if (!isset($this->row)) $this->handleErrorAndStop('->getSlug() called with no row present');

        // slugs can only be in column 'slug'
        return $this->row->slug ?? $this->row->order_number ?? $this->addError('->getSlug(): row had no slug');
    }

    public function getPath(): string
    {
        return Help::turnIntoPath(explode('/', $this->getSlug()), $this->getProperties());
    }

    public function getRow(): \stdClass
    {
        if (isset($this->row->slug)) return $this->row;
        $this->row = parent::getRow();
        if (isset($this->row->__ref) && !isset($this->row->slug)) {
            $this->row = $GLOBALS['slugs'][$this->row->__ref];
        }

        return $this->row;
    }

    public function saveFile(string $temp_location): bool
    {
        $file_name = Help::scanFileAndSaveWhenOk($temp_location);
        if (null === $file_name) {
            $this->addError(sprintf(__('Scanning temp file %s failed', 'peatcms'), $temp_location));

            return false;
        }
        $data['filename_saved'] = $file_name;
        $data['extension'] = strtolower(pathinfo($this->row->filename_original, PATHINFO_EXTENSION));

        return $this->update($data);
    }
}