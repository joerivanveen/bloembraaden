<?php
declare(strict_types=1);

namespace Peat;
class Search extends BaseElement
{
    protected bool $admin = false;
    protected int $result_count = 0;
    public const MIN_TERM_LENGTH = 3;

    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
        $this->type_name = 'search';
        if (null === $this->row) $this->row = new \stdClass;
    }

    public function getResultCount(): int
    {
        return $this->result_count;
    }

    /**
     * @param array $terms
     * @param int|null $hydrate_until null means hydrate all, else hydrate only if less than $hydrate_until items are found
     */
    public function findWeighted(array $terms, ?int $hydrate_until = null): void
    {
        // search queries are also cached by path! when result_count > 0.
        $original_terms = $terms;
        $terms = $this->cleanTerms($terms);
        $properties = $this->getProperties();
        $this->row->item_count = 0;
        $this->row->content = 'Bloembraaden searched for these terms: `' . var_export($terms, true) .
            PHP_EOL . '` and these properties: `' . var_export($properties, true) . '`';
        // set template_id to default search template, if necessary (and if it exists)
        if (!isset($this->row->template_id)) {
            $this->row->template_id = Help::getDB()->getDefaultTemplateIdFor('search');
        }
        $template_settings = $this->getAndSetTemplateSettings();
        // @since 0.12.0 get results from ci_ai table
        $results = $this->getResults($terms);
        $this->row->__results__ = $results;
        $item_count = count($results);
        if (null === $hydrate_until || $item_count <= $hydrate_until) {
            // make elements from results
            foreach ($results as $i => $result) {
                $type_name = $result->type_name;
                if ('variant' === $type_name // TODO implement page size for other than variants
                    && isset($this->row->__variants__)
                    && count($this->row->__variants__) > $template_settings->variant_page_size) {
                    continue;
                }
                if (!($element = (new Type($type_name))->getElement()->fetchById($result->id))) continue;
                $output = $element->getOutputFull();
                // only include if all properties are present
                $all_properties_present = true;
                if (true === isset($output->__x_values__)) {
                    foreach ($properties as $property => $property_values) {
                        if ('price_max' === $property && isset($property_values[0])) {
                            if (true === isset($output->price) &&
                                Help::getAsFloat($property_values[0]) < Help::getAsFloat($output->price)
                            ) {
                                $all_properties_present = false;
                                break;
                            }
                            continue;
                        }
                        if ('price_min' === $property && isset($property_values[0])) {
                            if (true === isset($output->price) &&
                                Help::getAsFloat($output->price) < Help::getAsFloat($property_values[0])
                            ) {
                                $all_properties_present = false;
                                break;
                            }
                            continue;
                        }
                        foreach ($property_values as $index => $value) {
                            foreach ($output->__x_values__ as $x_i => $x_value) {
                                if (false === is_int($x_i)) continue; // not a row
                                if ($x_value->property_slug === $property && $x_value->slug === $value) {
                                    continue 2;
                                }
                            }
                            $all_properties_present = false;
                            break 2;
                        }
                    }
                }
                $output = null;
                if ($all_properties_present) {
                    $plural = '__' . $type_name . 's__';
                    if (false === isset($this->row->$plural)) $this->row->$plural = array();
                    $this->row->{$plural}[] = $element->getOutput();
                    $this->row->item_count += 1;
                }
            }
            if (isset($terms[0]) && 'not_online' !== $terms[0]) $this->result_count = $this->row->item_count; // means it will be cached if > 0
        } else {
            $this->row->item_count = $item_count;
        }
        if (0 === $this->result_count) {
            $terms = $original_terms;
        }
        $this->row->slug = implode('/', $terms);
        $this->row->title = htmlentities(implode(' ', $terms));
    }

    private function getResults(array $clean_terms): array
    {
        // unfortunately special cases:
        if (isset($clean_terms[0]) && ('not_online' === ($term = $clean_terms[0]) || 'price_from' === $term)) {
            return Help::getDB()->findSpecialVariants($term);
        }

        return Help::getDB()->findCiAi($clean_terms, static function (string $haystack, array $needles): float {
                // the getWeight function
                $weight = 0.0;
                foreach ($needles as $index => $needle) {
                    $one = count(explode($needle, $haystack));
                    $two = strpos($haystack, $needle) + 1;
                    $weight += $one / $two;
                }

                return $weight;
            }) ?? array();
    }

    public function pageVariants(int $variant_page): int
    {
        // search does not do paging at all
        return 0;
    }

    public function cleanTerms(array $terms): array
    {
        if (count($terms) > 1 and isset($this->row->slug)) {
            if ($terms[0] === $this->row->slug) unset($terms[0]);
        }
        // todo swap alternatives for correct entries
        // todo remove stop words
        // todo remove duplicate entries
        // order the terms following a fixed way, currently alphabetically
        usort($terms, function ($a, $b) {
            if (is_numeric($a) && !is_numeric($b))
                return 1;
            else if (!is_numeric($a) && is_numeric($b))
                return -1;
            else
                return ($a < $b) ? -1 : 1;
        });

        return array_values(array_map(static function($term) {
            return Help::removeAccents($term);
        }, array_filter($terms, static function ($term) {
            return strlen($term) >= self::MIN_TERM_LENGTH;
        })));
    }

    /**
     * Based on the path this will load all possible property values
     * meanwhile, for each path this is cached
     *
     * @param string $path
     * @param int|null $instance_id
     * @param bool $rewrite
     * @return array
     * @since 0.8.12
     */
    public function getRelevantPropertyValuesAndPrices(string $path, int $instance_id = null, bool $rewrite = false): array
    {
        $instance_id = $instance_id ?? Setup::$instance_id;
        $filename = Setup::$DBCACHE . 'filter/' . $instance_id . '/' . rawurlencode($path) . '.serialized';
        if (true === file_exists($filename) && false === $rewrite) {
            return unserialize(file_get_contents($filename));
        }
        //
        $containing_folder = dirname($filename);
        if (false === file_exists($containing_folder)) {
            mkdir($containing_folder, 0755);
        }
        //
        $resolver = new Resolver($path, $instance_id);
        // @since 0.12.0
        $this->setProperties($resolver->getProperties());
        $variant_ids = $this->getAllVariantIds($resolver->getTerms());
        $return_arr = array();
        // with all the variant_ids -> get all the appropriate property values that may be shown and return them
        $return_arr['property_values'] = Help::getDB()->fetchAllPossiblePropertyValues($variant_ids);
        // and get the prices
        $return_arr['prices'] = Help::getDB()->fetchAllPricesAsInts($variant_ids);
        // cache the values as a file
        file_put_contents($filename, serialize($return_arr), LOCK_EX); // overwrites by default

        return $return_arr;
    }

    /**
     * @param array $terms
     * @return array
     * @since 0.8.12
     */
    private function getAllVariantIds(array $terms): array
    {
        if (0 === count($terms)) return array();
        // probably when one term is present this is a property or a property_value
        if (1 === count($terms)) {
            if (($row = Help::getDB()->fetchElementIdAndTypeBySlug($terms[0])) && 'search' !== $row->type) {
                // only when we definitely found an element for this slug, try to get all attached variants
                return Help::getDB()->fetchAllVariantIdsFor($row->type, $row->id, $this->getProperties());
            }
        }
        return Help::getDB()->findElementIds('variant', $terms, $this->getProperties());
    }

    /**
     * set the search object to admin, so you can load the tables etc.
     * @since 0.7.0
     */
    public function setForAdmin()
    {
        $this->admin = true;
    }

    /**
     * @param int $variant_id
     * @param int $quantity
     * @return array
     */
    public function getRelatedForVariant(int $variant_id, int $quantity = 8): array
    {
        $variant_ids_collect = Help::getDB()->fetchRelatedVariantIds($variant_id);
        // exclude the variant itself
        $variant_ids_show = array_values(array_diff($variant_ids_collect, array($variant_id)));
        if (count($variant_ids_show) === 0) {
            return $this->outputRows(Help::getDB()->listVariants($quantity, array($variant_id)), 'variant');
        }

        return $this->getVariantsByIds($variant_ids_show, $variant_ids_collect, $quantity);
    }

    /**
     * @param int $page_id
     * @param int $quantity
     * @return array indexed array holding pages that are not the directly linked pages of page_id
     * @since 0.8.18
     */
    public function getRelatedForPage(int $page_id, int $quantity = 8): array
    {
        $not_in = array($page_id);
        $linked_pages = Help::getDB()->fetchElementRowsLinked(
            new Type('page'), $page_id, new Type('page'), 'cross_parent', 0, 0
        );
        foreach ($linked_pages as $index => $row) {
            $not_in[] = $row->page_id;
        }
        $linked_pages = null;
        $rows = Help::getDB()->fetchElementRowsWhereIn(new Type('page'), 'page_id', $not_in, true, 3);

        return $this->outputRows($rows, 'page');
    }

    /**
     * @param int $shoppinglist_id
     * @param int $quantity
     * @return array indexed array holding variants
     * @since 0.5.15
     */
    public function getRelatedForShoppinglist(int $shoppinglist_id, int $quantity = 8): array
    {
        $list = Help::getDB()->fetchShoppingListRows($shoppinglist_id); // ordered from old to new by default
        // walk in reverse so the newest item gets the most attention
        $index = count($list);
        $variant_ids_collect = array();
        $variant_ids_in_list = array();
        $variant_ids_show = array();
        while ($index and count($variant_ids_show) < $quantity) {
            --$index;
            $variant_ids_in_list[] = ($variant_id = $list[$index]->variant_id);
            $variant_ids_collect = array_merge(Help::getDB()->fetchRelatedVariantIds($variant_id), $variant_ids_collect);
            // exclude the variants that are already in the shoppinglist and reindex the array
            $variant_ids_show = array_values(array_diff($variant_ids_collect, $variant_ids_in_list));
        }

        return $this->getVariantsByIds($variant_ids_show, $variant_ids_in_list, $quantity);
    }

    /**
     * @param array $in
     * @param array $not_in
     * @param int $fixed_quantity
     * @return array Indexed array holding a fixed amount of ‘out’ variant object, or less if there aren't enough
     * @since 0.5.15
     */
    private function getVariantsByIds(array $in, array $not_in, int $fixed_quantity): array
    {
        $type = new Type('variant');
        $rows = Help::getDB()->fetchElementRowsWhereIn($type, 'variant_id', $in);
        if (count($rows) < $fixed_quantity) {
            // add some more rows from somewhere else
            // TODO adding id 0 as a temp bugfix for WhereIn returns an empty array when $not_in is empty...
            $not_in = array_merge($in, $not_in, array(0)); // don’t repeat the ones you already have
            $rows = array_merge(
                $rows,
                Help::getDB()->fetchElementRowsWhereIn($type, 'variant_id', $not_in, true)
            );
        }
        if ($fixed_quantity > 0) array_splice($rows, $fixed_quantity); // $quantity > 0 means you want to cut off the results there

        return $this->outputRows($rows, 'variant');
    }

    /**
     * @param array $terms Terms to look for while suggesting, like search
     * @param int $limit Max number of suggestions to return
     * @return array indexed array holding ‘out’ variant objects
     * @since 0.5.15
     */
    public function suggestVariants(array $terms = array(), int $limit = 8): array
    {
        if (count($terms) > 0) {
            // fill the row object with nice stuff, that will be returned by getOutput()
            $this->findWeighted($this->cleanTerms($terms), $limit);
            $rows = $this->row->__variants__;
            //$rows = Help::getDB()->findElements('variant', $terms);
        } else {
            $rows = Help::getDB()->listVariants($limit);
        }
        if ($limit > 0) array_splice($rows, $limit); // $limit > 0 means you want to cut off the results there

        return $this->outputRows($rows, 'variant');
    }

    public function suggestPages(array $terms = array(), int $limit = 8): array
    {
        // for now make it just so something is returned:
        $rows = Help::getDB()->fetchElementRowsWhere(new Type('page'), array('online' => true));
        if ($limit > 0) array_splice($rows, $limit); // $limit > 0 means you want to cut off the results there

        return $this->outputRows($rows, 'page');
    }

    private function outputRows(array $rows, string $type_name): array
    {
        if (count($rows) === 0) return array();
        $peat_type = new Type($type_name);
        // now you have the single ones, make objects from them
        foreach ($rows as $index => $row) {
            $rows[$index] = $peat_type->getElement($row)->getOutput();
        }

        return $rows;
    }

    public function completeRowForOutput(): void
    {
        if (true === $this->admin) {
            Help::prepareAdminRowForOutput($this->row, 'search_settings', (string)$this->getId());
            $this->row->template_id = null;
            // load the stopwords and alternatives
            // TODO use a meaningful order, maybe in javascript...
            if (($rows = Help::getDB()->fetchSearchAlternatives($this->getId()))) {
                $this->row->__alternatives__ = $rows;
            } else {
                $this->row->__alternatives__ = array();
            }
            /*if (($rows = Help::getDB()->fetchSearchStopwords($this->getId()))) {
                $this->row->__stopwords__ = $rows;
            } else {
                $this->row->__stopwords__ = array();
            }*/
            //
        }
    }

    /**
     * @return \stdClass|null
     * @since always
     */
    public function getTableInfoForOutput(): ?\stdClass
    {
        return null; // search doesn't have table_info
    }
}