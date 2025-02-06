<?php
declare(strict_types=1);

namespace Bloembraaden;

class DB extends Base
{
    private string $db_version, $db_schema, $cache_folder;
    private ?\PDO $conn;
    private array $cache_keys, $stale_slugs, $tables_with_slugs, $id_exists;
    public const TABLES_WITHOUT_HISTORY = array(
        '_history',
        '_ci_ai',
        '_session',
        '_sessionvars',
        '_system',
        '_shoppinglist',
        '_shoppinglist_variant',
        '_payment_status_update',
        '_cache',
        '_stale',
        '_search_log',
        '_locker',
    );
    public const TYPES_WITH_CI_AI = array(
        'brand',
        'embed',
        'file',
        'image',
        'page',
        'product',
        'property',
        'property_value',
        'serie',
        'variant',
    );
    public const REDACTED_COLUMN_NAMES = array(
        'password_hash',
        'recaptcha_site_key',
        'recaptcha_secret_key',
        'access_token',
    );

    public function __construct()
    {
        parent::__construct();
        // setup constants for this instance
        if (!isset(Setup::$instance_id)) Setup::$instance_id = -1;
        $this->conn = Setup::getMainDatabaseConnection();
        $this->db_schema = 'public';
        $this->cache_folder = Setup::$DBCACHE;
    }

    /**
     * Will roll back a pending transaction or fail
     *
     * @return void
     */
    public function resetConnection(): void
    {
        if (true === $this->conn->inTransaction()) {
            try {
                $this->conn->rollBack();
            } catch (\Exception $e) {
                $this->handleErrorAndStop($e, __('Database error.', 'peatcms'));
            }
        }
    }

    /**
     * These are tables linked by crosslink tables (_x_-tables), returns the relations both ways:
     * element_name=>'child' or element_name=>'parent'
     * @param Type $type the element you want to get the cross linked tables for
     * @return \stdClass array containing named arrays: element_name=>relation (parent or child), array has no keys when no tables are linked
     */
    public function getLinkTables(Type $type): \stdClass
    {
        $type_name = $type->typeName();
        $arr = array();
        if ('variant' === $type_name) {
            $arr = array('x_value' => 'properties', 'image' => 'cross_parent', 'embed' => 'cross_parent',
                'file' => 'cross_parent', 'product' => 'direct_child', 'serie' => 'direct_child',
                'brand' => 'direct_child', 'page' => 'cross_child', 'comment' => 'cross_parent');
        } elseif ('page' === $type_name) {
            $arr = array('x_value' => 'properties', 'page' => 'cross_parent', 'image' => 'cross_parent',
                'embed' => 'cross_parent', 'file' => 'cross_parent', 'variant' => 'cross_parent', 'comment' => 'cross_parent');
        } elseif ('file' === $type_name) {
            $arr = array('image' => 'cross_parent', 'page' => 'cross_child', 'brand' => 'cross_child',
                'serie' => 'cross_child', 'product' => 'cross_child', 'variant' => 'cross_child');
        } elseif ('image' === $type_name) {
            $arr = array('page' => 'cross_child', 'brand' => 'cross_child', 'serie' => 'cross_child',
                'product' => 'cross_child', 'variant' => 'cross_child', 'embed' => 'cross_child', 'comment' => 'cross_parent');
        } elseif ('embed' === $type_name) {
            $arr = array('image' => 'cross_parent', 'page' => 'cross_child', 'brand' => 'cross_child',
                'serie' => 'cross_child', 'product' => 'cross_child', 'variant' => 'cross_child', 'comment' => 'cross_parent');
        } elseif ('product' === $type_name) {
            $arr = array('image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent',
                'serie' => 'direct_child', 'brand' => 'direct_child', 'variant' => 'direct_parent', 'comment' => 'cross_parent');
        } elseif ('serie' === $type_name) {
            $arr = array('image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent',
                'brand' => 'direct_child', 'product' => 'direct_parent', 'variant' => 'direct_parent', 'comment' => 'cross_parent');
        } elseif ('brand' === $type_name) {
            $arr = array('image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent',
                'serie' => 'direct_parent', 'product' => 'direct_parent', 'variant' => 'direct_parent', 'comment' => 'cross_parent');
        } elseif ('property_value' === $type_name) {
            $arr = array('property' => 'cross_parent', 'x_value' => array('variant', 'page'),
                'image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent');
        } elseif ('property' === $type_name) {
            $arr = array('property_value' => 'cross_child', 'x_value' => array('variant', 'page'));
        } elseif ('comment' === $type_name) {
            $arr = array('brand' => 'cross_child', 'embed' => 'cross_child', 'image' => 'cross_child',
                'page' => 'cross_child', 'product' => 'cross_child', 'serie' => 'cross_child', 'variant' => 'cross_child');
        }

        return (object)$arr; // convert to object for json which does not accept named arrays
    }

    /**
     * runs sql directly on the database, used by install at the moment, please do not use it anywhere else
     * @param string $sql
     * @return false|int
     */
    public function run($sql = 'please do not use this')
        // TODO this needs to go to a separate upgrade script, not in the application itself
    {
        return $this->conn->exec($sql);
    }

    /**
     * @return string the version of peatcms in the database (might not be the currently configured version)
     * @since 0.0.0
     */
    public function getDbVersion(): string
    {
        if (false === isset($this->db_version)) {
            $statement = $this->conn->prepare('SELECT version FROM _system');
            $statement->execute();
            $this->db_version = $statement->fetchColumn(0) ?: '';
            $statement = null;
        }

        return $this->db_version;
    }

    public function fetchAdminReport(): array
    {
        $rows = array();
        $statement = $this->conn->prepare('select last_value from _sessionvars_sessionvars_id_seq');
        $statement->execute();
        $rows['sessionvars_seq'] = $statement->fetchColumn(0);
        $statement = $this->conn->prepare('select last_value from _session_session_id_seq');
        $statement->execute();
        $rows['session_seq'] = $statement->fetchColumn(0);
        $statement = $this->conn->prepare('select count(*) from _session');
        $statement->execute();
        $rows['number of sessions'] = $statement->fetchColumn(0);
        $statement = $this->conn->prepare('select count(*) from _shoppinglist');
        $statement->execute();
        $rows['number of shoppinglists'] = $statement->fetchColumn(0);
        $statement = null;

        return $rows;
    }

    /**
     * @param string $column_name
     * @return string|null
     * @since 0.10.7
     */
    public function getSystemValue(string $column_name): ?string
    {
        return $this->fetchRow('_system', [$column_name], [])->{$column_name};
    }

    /**
     * @param string $column_name
     * @param string|null $value
     * @return bool
     * @since 0.10.7
     */
    public function setSystemValue(string $column_name, ?string $value): bool
    {
        return 0 !== $this->updateColumnsWhere('_system', array(
                $column_name => $value,
            ), array());
    }

    /**
     * Put expiring info in a locker, receive a key to open it once with emptyLocker
     * which will return the info and the instance_id
     * @param int $key_type not used at the moment, may be used to specify complexity or length of the key
     * @param object|null $information optional info you want associated with the key, will be returned by emptyLocker
     * @param int $expires_after optional seconds until it expires, default 3600 (1 hour)
     * @return string|null the key on success, null on fail
     * @since 0.7.2
     */
    public function putInLocker(int $key_type, ?object $information = null, int $expires_after = 3600): ?string
    {
        $info_as_json = json_encode($information);
        if (json_last_error() !== 0) {
            $this->addError(json_last_error_msg());
            $info_as_json = '{}'; // empty object
        }
        $statement = $this->conn->prepare('INSERT INTO _locker (key, instance_id, information, valid_until)
            VALUES (:key, :instance_id, :information, NOW() + (:expires_after  * interval \'1 second\'));');
        $statement->bindValue(':instance_id', Setup::$instance_id); // the original instance that filled this locker
        $statement->bindValue(':information', $info_as_json);
        $statement->bindValue(':expires_after', $expires_after);
        $key = ''; // for php storm
        $tries = 10; // don’t go on indefinitely because something else is probably wrong
        while ($statement->rowCount() !== 1) {
            if ($tries-- === 0) {
                $this->addMessage(__('Could not set key.', 'peatcms'), 'warn');

                return null;
            }
            $key = Help::randomString(30);
            $statement->bindValue(':key', $key);
            try {
                $statement->execute();
            } catch (\Throwable) {
                // if it’s not a unique key an exception will be thrown, but we want to try again with a new key so just catch it
            }
        }

        return $key;
    }

    /**
     * Check a given key, it returns the information object when found, or null when not, which means it has expired or never existed
     * this also removes the key thereby invalidating any requests with this key for the foreseeable future
     * @param string $key
     * @return \stdClass|null stdClass holding instance_id and info object, or null
     * @since 0.7.2
     */
    public function emptyLocker(string $key): ?\stdClass
    {
        $statement = $this->conn->prepare(
            'SELECT information, instance_id FROM _locker WHERE key = :key AND valid_until > NOW();');
        $statement->bindValue(':key', $key);
        $statement->execute();
        if ($statement->rowCount() === 1) {
            $row = $statement->fetchAll(5)[0];
            $row->information = json_decode($row->information);
            // remove the entry
            $statement = $this->conn->prepare('DELETE FROM _locker WHERE key = :key;');
            $statement->bindValue(':key', $key);
            $statement->execute();
            $statement = null;

            return $row;
        }
        $this->addMessage(__('Key not found', 'peatcms'), 'warn');

        return null;
    }

    /**
     * @param array $properties
     * @param string $cms_type_name
     * @param string|null $x_alias
     * @return array sub queries that filter the type based on the properties
     * @since 0.9.0
     */
    private function queriesProperties(array $properties, string $cms_type_name, ?string $x_alias = null): array
    {
        if (false === in_array($cms_type_name, array('variant', 'page'))) return array();
        // get all the relevant property_value_id s from $properties, and select only items that also have those in their x-table
        $sub_queries = array();
        $values_by_property = array();
        foreach ($properties as $property_name => $property_values) {
            if ('price_min' === $property_name && 'variant' === $cms_type_name) {
                $sub_queries[] = sprintf(
                    '(peat_parse_float(price, \'%1$s\', \'%2$s\') > %3$s) ',
                    Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$property_values[0]
                );
            } elseif ('price_max' === $property_name && 'variant' === $cms_type_name) {
                $sub_queries[] = sprintf(
                    '(peat_parse_float(price, \'%1$s\', \'%2$s\') <= %3$s) ',
                    Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$property_values[0]
                );
            } else {
                $values_by_property[$property_name] = array(); // since the properties are sorted and formatted, we can do this
                foreach ($property_values as $index => $value) {
                    if (($row = $this->fetchElementIdAndTypeBySlug($value))) {
                        if ('property_value' === $row->type_name) {
                            $values_by_property[$property_name][] = (int)$row->id;
                        }
                    }
                }
            }
        }
        if (isset($x_alias)) $x_alias .= '.';
        else $x_alias = '';
        $sub_query_format = "$x_alias{$cms_type_name}_id IN (SELECT {$cms_type_name }_id
            FROM cms_{$cms_type_name}_x_properties WHERE property_value_id = %d) ";
        foreach ($values_by_property as $property_name => $property_value_ids) {
            if (0 === count($property_value_ids)) continue;
            $sub_sub_queries = array();
            foreach ($property_value_ids as $index => $property_value_id) {
                $sub_sub_queries[] = sprintf($sub_query_format, $property_value_id);
//                $sub_sub_queries[] =
//                    "x.variant_id IN (SELECT variant_id FROM cms_variant_x_properties WHERE property_value_id = $property_value_id)";
            }
            $sub_queries[] = '(' . implode(' OR ', $sub_sub_queries) . ')'; // todo make or / and here configurable?
        }

        return $sub_queries;
    }

    /**
     * Returns all element ids in random order of a specific $type_name found using $terms and $properties
     *
     * @param string $type_name
     * @param array $clean_terms
     * @param array $properties
     * @return array
     * @since 0.12.0
     */
    public function findElementResults(string $type_name, array $clean_terms, array $properties = array()): array
    {
        if (0 === count($clean_terms)) return array();
        if (false === in_array($type_name, self::TYPES_WITH_CI_AI)) {
            $this->handleErrorAndStop("$type_name is not a type with ci_ai, cannot find element ids.");
        }
        $special_variant_term = '';
        // get the id's from the _ci_ai table
        $statement = $this->conn->prepare('
            SELECT title, slug, type_name, id FROM _ci_ai 
            WHERE instance_id = :instance_id AND type_name = :type_name AND ci_ai LIKE lower(:term);
        ');
        $arr = array();
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':type_name', $type_name);
        foreach ($clean_terms as $index => $term) {
            // unfortunately special cases for variant...
            if ('variant' === $type_name) {
                $rows = array();
                if (in_array($term, array('price_from', 'not_online'))) {
                    $rows = Help::getDB()->findSpecialVariantResults($term);
                } elseif (null !== ($row = Help::getDB()->fetchElementIdAndTypeBySlug($term))) {
                    $rows = Help::getDB()->findSpecialVariantResults($row->type_name, $row->id);
                }
                if (0 !== count($rows)) {
                    $ids = array();
                    foreach ($rows as $key => $row) {
                        $ids[$row->id] = $row;
                    }
                    $arr[$term] = $ids;

                    if ('' === $special_variant_term) $special_variant_term = $term;

                    continue;
                }
            }
            $statement->bindValue(':term', "%$term%");
            $statement->execute();
            $rows = $statement->fetchAll(5);
            $ids = array();
            foreach ($rows as $key => $row) {
                $ids[$row->id] = $row; // since we're doing array_intersect_key later, the id must be the key
            }
            $arr[$term] = $ids;
        }
        $statement = null;
        // special term should dictate the order, so should be the first intersected!
        if (true === isset($arr[$special_variant_term]) && 0 < count(($intersected = $arr[$special_variant_term]))) {
            unset($arr[$special_variant_term]);
        } else { // or else quasi random
            $intersected = array_shift($arr);
        }
        foreach ($arr as $term => $ids) {
            $intersected = array_intersect_key($ids, $intersected); // AND
        }
        if (0 === count($intersected)) return array();
        if (0 === count($properties)) return $intersected;
        // filter by properties before returning:
        $sub_queries = $this->queriesProperties($properties, $type_name);
        if (0 !== count($sub_queries)) { // TODO duplicate code
            $imploded_sub_queries = ' AND ' . implode(' AND ', $sub_queries);
        } else {
            $imploded_sub_queries = '';
        }
        $ids_as_string = implode(',', array_keys($intersected));
        $statement = $this->conn->prepare("
            SELECT title, slug, '$type_name' as type_name, {$type_name}_id AS id FROM cms_$type_name 
            WHERE {$type_name}_id IN ($ids_as_string) $imploded_sub_queries;
        ");
        //Help::addMessage(str_replace(':id', (string)$id, $statement->queryString), 'note');
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * Finds results (title, slug, type_name, id) in ci_ai based on $terms (must be cleaned of accented characters)
     *
     * @param array $clean_terms
     * @param array $clean_types must be filtered already, to be only types with CI_AI
     * @param callable $getWeight must accept two parameters: string $text, string $needle, to assign weight
     * @return array
     * @since 0.12.0
     */
    public function findCiAi(array $clean_terms, array $clean_types, callable $getWeight): array
    {
        $arr = array(); // collect the results (by slug) so you can intersect them, leaving only results with all the terms in them
        $type_query = '';
        $online_query = '';
        if (0 < count($clean_types)) {
            // pay attention to the single quotes placed around every type in the clean_types array
            $type_query = implode('\',\'', $clean_types);
            $type_query = "AND type_name IN ('$type_query')";
        }
        // TODO have online work better everywhere the same way and loose the ADMIN constant, must be manageable in 1 place
        if (false === defined('ADMIN') || false === ADMIN) $online_query = 'AND online = TRUE';
        $statement = $this->conn->prepare("
            SELECT DISTINCT ci_ai, title, slug, type_name, id, online FROM _ci_ai 
            WHERE instance_id = :instance_id AND ci_ai LIKE LOWER(:term) $online_query $type_query;
        ");
        $statement->bindValue(':instance_id', Setup::$instance_id);
        foreach ($clean_terms as $index => $term) {
            if ('' === $term) continue;
            $rows = array();
            //$term = Help::removeAccents($term); // already done when terms are clean
            $statement->bindValue(':term', "%$term%");
            $statement->execute();
            $temp = $statement->fetchAll(5);
            foreach ($temp as $index_row => $row) {
                $rows[$row->slug] = $row;
            }
            unset ($temp);
            $arr[$term] = $rows;
        }
        $statement = null;
        $intersected = null;
        foreach ($arr as $index => $term) {
            if (null === $intersected) {
                $intersected = $term;
            } else {
                $intersected = array_intersect_key($term, $intersected);
            }
        }
        /**
         * Next line fixes a bug where the original item (not online) in the search results has the exact same
         * slug as the search results. This slug would be registered in cache as a search, but would point
         * to the original item (in json). This leads to several problems down the line.
         */
        if (1 === count($arr)) unset($intersected[array_key_first($arr)]);
        if (null === $intersected) return array(); // nothing found
        // calculate the weights
        foreach ($intersected as $index => $row) {
            $row->weight = $getWeight($row->ci_ai, $clean_terms);
            unset($row->ci_ai);
        }
        // order by weight
        usort($intersected, function ($a, $b) {
            return ($a->weight < $b->weight) ? 1 : -1;
        });

        return array_values($intersected);
    }

    /**
     * For cases 'price_from' and 'not_online' returns the variants
     *
     * @param string $case
     * @param int|null $id
     * @return array
     * @since 0.12.0
     */
    public function findSpecialVariantResults(string $case, ?int $id = null): array
    {
        if ('not_online' === $case) { // select only variants that are not online (for admins...)
            $statement = $this->conn->prepare("
                        SELECT title, slug, 'variant' AS type_name, variant_id AS id FROM cms_variant 
                        WHERE instance_id = :instance_id AND deleted = FALSE AND online = FALSE
                        ORDER BY date_updated DESC;
                    ");
        } else {
            $online = (true === defined('ADMIN') && true === ADMIN) ? '' : 'AND online = TRUE';
            if ('price_from' === $case) { // select only variants that have a from price
                $statement = $this->conn->prepare("
                        SELECT title, slug, 'variant' AS type_name, variant_id AS id FROM cms_variant 
                        WHERE instance_id = :instance_id AND deleted = FALSE AND price_from <> '' $online
                        ORDER BY date_popvote DESC;
                    ");
            } elseif (null !== $id && true === in_array($case, array('brand', 'serie', 'product'))) {
                $statement = $this->conn->prepare("
                        SELECT title, slug, 'variant' AS type_name, variant_id AS id FROM cms_variant 
                        WHERE instance_id = :instance_id AND deleted = FALSE AND {$case}_id = :id $online
                        ORDER BY date_popvote DESC;
                    ");
                $statement->bindValue(':id', $id);
            } else {
                return array();
            }
        }

        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;

    }

    /**
     * @param int $limit
     * @return array
     * @since 0.7.0
     */
    public function fetchSearchLog(int $limit = 500): array
    {
        $statement = $this->conn->prepare("SELECT * FROM _search_log WHERE instance_id = :instance_id
            AND deleted = FALSE ORDER BY date_updated DESC LIMIT $limit;");
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * @param string $slug the slug to find an element by
     * @param bool $no_cache default false, set to true if you want to ignore cache when getting the type and id
     * @return \stdClass|null returns a stdClass (normalized row) with ->type and ->id, or null when not found
     * @since 0.0.0
     */
    public function fetchElementIdAndTypeBySlug(string $slug, bool $no_cache = false): ?\stdClass
    {
        // @since 0.7.5 if the slug is not in the format of a slug, no need to go look for it
        if ($slug !== Help::slugify($slug)) return null;
        // find appropriate item in database
        $rows = array();
        // @since 0.6.0 check the cache first
        if (false === $no_cache) {
            $statement = $this->conn->prepare('SELECT id, type_name FROM _cache WHERE slug = :slug AND instance_id = :instance_id LIMIT 1;');
            $statement->bindValue(':slug', $slug);
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute(); // error handling necessary?
            $rows = $statement->fetchAll(5);
        }
        if (0 === count($rows)) {
            $statement = $this->conn->prepare('
                SELECT page_id AS id, \'page\' AS type_name FROM cms_page WHERE slug = :slug AND instance_id = :instance_id
                UNION ALL 
                SELECT image_id AS id, \'image\' AS type_name FROM cms_image WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT embed_id AS id, \'embed\' AS type_name FROM cms_embed WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT file_id AS id, \'file\' AS type_name FROM cms_file WHERE slug = :slug AND instance_id = :instance_id
                UNION ALL 
                SELECT menu_id AS id, \'menu\' AS type_name FROM cms_menu WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT brand_id AS id, \'brand\' AS type_name FROM cms_brand WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT serie_id AS id, \'serie\' AS type_name FROM cms_serie WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT product_id AS id, \'product\' AS type_name FROM cms_product WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT variant_id AS id, \'variant\' AS type_name FROM cms_variant WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT comment_id AS id, \'comment\' AS type_name FROM cms_comment WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT property_id AS id, \'property\' AS type_name FROM cms_property WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT property_value_id AS id, \'property_value\' AS type_name FROM cms_property_value WHERE slug = :slug AND instance_id = :instance_id 
                ;
            ');
            $statement->bindValue(':slug', $slug);
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute(); // error handling necessary?
            $rows = $statement->fetchAll(5);
        }
        $statement = null;
        if (1 === count($rows)) {
            return $rows[0];
        } else {
            if (count($rows) > 1) {
                $this->addError(sprintf('DB->fetchElementIdAndTypeBySlug: %1$s returned %2$d rows', $slug, count($rows)));
            }

            return null; // if no element found, or too many
        }
    }

    public function fetchSearchAlternatives(int $search_settings_id = 0): array
    {
        return $this->fetchRows('_search_alternatives', array(
            'search_alternatives_id',
            'alternative',
            'correct',
        ), array(
            'search_settings_id' => $search_settings_id,
        ));
    }

    public function fetchSearchStopwords(int $search_settings_id = 0): array
    {
        return array();
    }

    public function fetchElementRow(Type $peat_type, int $id): ?\stdClass
    {
        return $this->fetchRow($peat_type->tableName(), array('*'), array($peat_type->idColumn() => $id));
    }

    /**
     * For suggestion boxes this returns a small portion of an element based on searched string
     *
     * @param Type $peat_type
     * @param string $src
     * @return \stdClass row containing only the [type_]id, title and slug
     * @since 0.x.x
     */
    public function fetchElementRowSuggestions(Type $peat_type, string $src): \stdClass
    {
        $out = new \stdClass();
        $out->rows = $this->fetchRows(
            $peat_type->tableName(),
            array($peat_type->idColumn(), 'title', 'slug'),
            array('title' => "%$src%"),
            false,
            40
        );
        $out->element = $peat_type->typeName();
        $out->src = $src;

        return $out;
    }

    /**
     * @param string $src
     * @return \stdClass
     * @since 0.8.0
     */
    public function fetchPropertiesRowSuggestions(string $src): \stdClass
    {
        $return_obj = new \stdClass();
        $src = Help::removeAccents(mb_strtolower($src));
        $instance_id = Setup::$instance_id;
        $statement = $this->conn->prepare("
            SELECT DISTINCT p.property_id, pv.property_value_id, CONCAT(p.title, ': ', v.title) AS title, 
            v.slug, (v.online AND p.online) AS online, v.date_updated FROM cms_property p 
            INNER JOIN cms_property_x_property_value pv ON p.property_id = pv.sub_property_id 
            INNER JOIN cms_property_value v ON v.property_value_id = pv.property_value_id 
            INNER JOIN _ci_ai scp ON scp.type_name = 'property' AND scp.id = p.property_id
            INNER JOIN _ci_ai scpv ON scpv.type_name = 'property_value' AND scpv.id = pv.property_value_id
            WHERE pv.deleted = FALSE AND p.deleted = FALSE AND v.deleted = FALSE AND p.instance_id = $instance_id 
                AND (LEFT(scp.ci_ai, 20) LIKE :src OR scpv.ci_ai LIKE :src) ORDER BY v.date_updated DESC
            LIMIT 40;
        ");
        $statement->bindValue(':src', "%$src%");
        $statement->execute();
        $return_obj->rows = $statement->fetchAll(5);
        $statement = null;
        /*$r->rows = $this->fetchRows('cms_property_value',
            array('property_value_id', 'title', 'slug'),
            array('title' => '%' . $src . '%'));*/
        $return_obj->element = 'x_value';
        $return_obj->src = $src;

        return $return_obj;
    }

    /**
     * @param string $for either ‘variant’ or ‘page’, the only elements with properties
     * @return array
     * @since 0.8.0
     */
    public function fetchProperties(string $for): array
    {
        if ('page' !== $for) $for = 'variant';
        $statement = $this->conn->prepare("
            SELECT DISTINCT p.slug property_slug, p.title property_title, pv.slug, pv.title, pxv.sub_o, count(pv.slug) item_count
            FROM cms_property p
            INNER JOIN cms_{$for}_x_properties xp ON p.property_id = xp.property_id
            INNER JOIN cms_$for el ON el.{$for}_id = xp.{$for}_id
            INNER JOIN cms_property_value pv ON pv.property_value_id = xp.property_value_id
            INNER JOIN cms_property_x_property_value pxv ON pxv.property_value_id = xp.property_value_id
            WHERE p.instance_id = :instance_id AND p.deleted = FALSE AND pv.deleted = FALSE AND pxv.deleted = FALSE
            AND el.online = TRUE AND pv.online = TRUE
            GROUP BY p.slug, p.title, pv.slug, pv.title, pxv.sub_o
            ORDER BY p.title, pxv.sub_o, pv.title;
        ");
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;
        $return_arr = array();
        $current_prop = '';
        foreach ($rows as $index => $row) {
            if ($row->property_slug !== $current_prop) {
                $current_prop = $row->property_slug;
                $return_arr[$current_prop] = array();
            }
            $return_arr[$current_prop][$row->slug] = array('title' => $row->title, 'item_count' => $row->item_count);
        }

        return $return_arr;
    }

    /**
     * Return all valid properties that are in the database for that instance
     *
     * @return array associative array with entries $slug => $title
     * @since 0.8.16
     */
    public function fetchPropertiesForInstance(): array
    {
        $statement = $this->conn->prepare('SELECT slug, title FROM cms_property WHERE instance_id = :instance_id;');
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;
        $return_arr = array();
        foreach ($rows as $index => $row) {
            $return_arr[$row->slug] = $row->title;
        }

        return $return_arr;
    }

    /**
     * @param Type $peat_type
     * @param int $id
     * @param int $property_id
     * @param int $property_value_id
     * @return \stdClass
     * @since 0.8.0
     */
    public function addXValueLink(Type $peat_type, int $id, int $property_id, int $property_value_id): \stdClass
    {
        $table_name = $peat_type->tableName();
        $x_table_name = "{$table_name}_x_properties";
        $o = $this->getHighestO($x_table_name);
        $key = $this->insertRowAndReturnLastId(
            $x_table_name,
            array(
                $peat_type->idColumn() => $id,
                'property_id' => $property_id,
                'property_value_id' => $property_value_id,
                'o' => $o
            )
        );

        return $this->selectRow($x_table_name, $key);
    }

    /**
     * @param Type $peat_type
     * @param int $id
     * @param int $x_value_id
     * @return bool
     * @since 0.8.0
     */
    public function deleteXValueLink(Type $peat_type, int $id, int $x_value_id): bool
    {
        $table_name = $peat_type->tableName();
        $type_name = $peat_type->typeName();
        if (1 === ($affected = $this->deleteRowWhereAndReturnAffected(
                "{$table_name}_x_properties",
                array(
                    "{$type_name}_id" => $id,
                    "{$type_name}_x_properties_id" => $x_value_id,
                )
            ))) {
            return true;
        } else {
            $this->handleErrorAndStop("->deleteXValueLink affected $affected rows");
        }

        return false;
    }

    /**
     * Standard way to list variants ordered by popvote desc, the expected order
     *
     * @param int $limit
     * @return array
     * @since 0.5.15
     */
    public function listVariantIds(int $limit = 8): array
    {
        $statement = $this->conn->prepare("SELECT variant_id FROM cms_variant ORDER BY date_popvote DESC LIMIT $limit;");
        $statement->execute();
        $rows = $statement->fetchAll(3);
        $statement = null;
        foreach ($rows as $index => $row) {
            $rows[$index] = $row[0];
        }

        return $rows;
    }

    /**
     * @param int $variant_id
     * @return array Indexed array containing the variant ids collected
     * @since 0.5.15
     */
    public function fetchRelatedVariantIds(int $variant_id): array
    {
        // fetch some variant_id’s based on other orders that include the supplied variant_id
        // you don’t need to check instance id because in each order variants are necessarily of the same instance
        $statement = $this->conn->prepare('SELECT DISTINCT variant_id FROM _order_variant WHERE order_id IN (SELECT order_id FROM _order_variant WHERE variant_id = :variant_id) AND variant_id <> :variant_id;');
        $statement->bindValue(':variant_id', $variant_id);
        $statement->execute();
        $rows = $statement->fetchAll(3);
        $statement = null;
        foreach ($rows as $index => $row) {
            $rows[$index] = $row[0];
        }

        return $rows;
    }

    /**
     * Return the variant_ids that have all the provided property values attached to them as
     * well as the property->id or property_value->id that is the first two arguments
     * @param string $type_name literal ‘property’, ‘property_value’, ‘serie’ or ‘brand’
     * @param int $id the id of the property or property_value that is mandatory for the variant_ids,
     * or the variant_id whose serie or brand you need
     * @param array $properties
     * @return array indexed array with all the variant_ids
     * @since 0.8.12
     */
    public function fetchAllVariantIdsFor(string $type_name, int $id, array $properties): array
    {
        if (false === in_array($type_name, array('property', 'property_value', 'serie', 'brand'))) return array();
        // TODO also get the attached (x-table) variants for eg page?
        $sub_queries = $this->queriesProperties($properties, 'variant', 'x');
        if (0 !== count($sub_queries)) { // todo duplicate code
            $imploded_sub_queries = ' AND ' . implode(' AND ', $sub_queries);
        } else {
            $imploded_sub_queries = '';
        }
        if ('serie' === $type_name || 'brand' === $type_name) {
            $where_table = "v.{$type_name}_id = (SELECT {$type_name}_id FROM cms_variant WHERE variant_id = :id)";
        } else {
            $where_table = "x.{$type_name}_id = :id";
        }
        $statement = $this->conn->prepare("
            SELECT DISTINCT x.variant_id, v.date_popvote FROM cms_variant_x_properties x
            INNER JOIN cms_variant v ON v.variant_id = x.variant_id
            WHERE $where_table AND x.deleted = FALSE AND v.online = TRUE AND v.deleted = FALSE $imploded_sub_queries
            ORDER BY date_popvote DESC;
        ");

        $statement->bindValue(':id', $id);
        //Help::addMessage(str_replace(':id', (string)$id, $statement->queryString), 'note');
        $statement->execute();
        $rows = $statement->fetchAll(3);
        $statement = null;
        foreach ($rows as $index => $row) {
            $rows[$index] = $row[0];
        }

        return $rows;
    }

    /**
     * @param array $variant_ids
     * @return array
     * @since 0.8.12
     */
    public function fetchAllPricesAsInts(array $variant_ids): array
    {
        if (0 === count($variant_ids)) return array();
        $statement = $this->conn->prepare(sprintf('
            SELECT DISTINCT peat_parse_float(price, \'%1$s\', \'%2$s\') FROM cms_variant WHERE variant_id IN (%3$s);
        ', Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, implode(',', $variant_ids)));
        $statement->execute();
        $rows = $statement->fetchAll(3);
        $statement = null;
        $return_arr = array();
        foreach ($rows as $index => $row) {
            $return_arr[] = (int)$row[0];
        }
        $rows = null;

        return $return_arr;
    }

    /**
     * @param array $variant_ids
     * @return array
     * @since 0.8.12
     */
    public function fetchAllPossiblePropertyValues(array $variant_ids): array
    {
        if (0 === count($variant_ids)) return array();
        $in_str = implode(',', $variant_ids);
        $statement = $this->conn->prepare("
            SELECT DISTINCT v.slug, count(v.slug) item_count FROM cms_variant_x_properties x
            INNER JOIN cms_property_value v ON x.property_value_id = v.property_value_id
            WHERE x.variant_id IN ($in_str) GROUP BY v.slug;
        ");
        $statement->execute();
        $rows = $statement->fetchAll(3);
        //Help::addMessage($statement->queryString, 'note');
        $statement = null;
        $return_array = array();
        foreach ($rows as $index => $row) {
            $return_array[] = array('slug' => $row[0], 'item_count' => $row[1]);
        }

        return $return_array;
    }

    /**
     * Returns product_id and serie_id for any product that has a broken chain (in this case: where the brand_id is incorrect)
     *
     * @return array normalized rows
     * @since 0.12.1
     */
    public function jobIncorrectChainForProduct(): array
    {
        $statement = $this->conn->prepare('
            SELECT DISTINCT instance_id, serie_id FROM cms_product p 
            WHERE serie_id <> 0 AND
                NOT EXISTS(SELECT 1 FROM cms_serie s WHERE s.serie_id = p.serie_id AND s.brand_id = p.brand_id)
            ORDER BY instance_id, serie_id;
        ');
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * Returns variant_id and product_id for variants that have their parent chain broken
     * (serie_id and / or brand_id may need updating)
     *
     * @return array normalized rows
     * @since 0.12.1
     */
    public function jobIncorrectChainForVariant(): array
    {
        $statement = $this->conn->prepare('
            SELECT DISTINCT instance_id, product_id FROM cms_variant v
            WHERE product_id <> 0 AND
                NOT EXISTS(SELECT 1 FROM cms_product p
                WHERE p.product_id = v.product_id AND p.serie_id = v.serie_id AND p.brand_id = v.brand_id)
            ORDER BY instance_id, product_id;
        ');
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * @param Type $peat_type
     * @param array $where
     * @return array
     * @since 0.x.x
     */
    public function fetchElementRowsWhere(Type $peat_type, array $where): array
    {
        return $this->fetchRows($peat_type->tableName(), array('*'), $where);
    }

    /**
     * @param Type $peat_type
     * @param int $page default 1 the page you need from the entire table
     * @param int $page_size default 400 the number of records on a page
     * @return array indexed array holding plain row objects including not online ones
     */
    public function fetchElementRowsPage(Type $peat_type, int $page = 1, int $page_size = 400): array
    {
        $table_name = $peat_type->tableName();
        $table_info = $this->getTableInfo($table_name);
        $offset = ($page - 1) * $page_size;
        $instance_id = Setup::$instance_id;
        if ($table_info->hasStandardColumns()) {
            $sorting = 'ORDER BY date_created DESC';
            $and_where = 'AND deleted = FALSE';
        } else {
            $sorting = '';
            $and_where = '';
        }
        // preferred sorting:
        if ($table_info->getColumnByName('date_popvote')) {
            $sorting = 'ORDER BY date_popvote DESC';
        } elseif ($table_info->getColumnByName('date_published')) {
            $sorting = 'ORDER BY date_published DESC';
        }
        $statement = $this->conn->prepare("SELECT * FROM $table_name WHERE instance_id = $instance_id $and_where $sorting LIMIT $page_size OFFSET $offset;");
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * To help the fetchElementRowsPage functionality have paging controls
     *
     * @param Type $peat_type
     * @param int $page_size default 400 use the same as fetchElementRowsPage obviously to get the right amount returned
     * @return array
     */
    public function fetchElementRowsPageNumbers(Type $peat_type, int $current_page, int $page_size = 400): array
    {
        $table_name = $peat_type->tableName();
        $table_info = $this->getTableInfo($table_name);
        $instance_id = Setup::$instance_id;
        if ($table_info->hasStandardColumns()) {
            $and_where = 'AND deleted = FALSE';
        } else {
            $and_where = '';
        }
        $statement = $this->conn->prepare("SELECT COUNT(1) FROM $table_name WHERE instance_id = $instance_id $and_where");
        $statement->execute();
        $number_of_pages = ceil($statement->fetchColumn(0) / $page_size);
        $statement = null;
        $return = array();
        for ($i = 1; $i <= $number_of_pages; ++$i) {
            $return[] = (object)array(
                'page_number' => $i,
                'page_distance' => abs($i - $current_page),
            );
        }

        return $return;
    }

    /**
     * @param Type $peat_type
     * @param string $column_name
     * @param array $in indexed array holding id’s, when empty this method always returns an empty array
     * @param bool $exclude default false, when true ‘NOT IN’ is used rather than ‘IN’
     * @param int $limit default 1000 limits the number of rows
     * @return array indexed array holding plain row objects that are online (non-admin only) and not deleted
     * @since 0.5.15
     */
    public function fetchElementRowsWhereIn(Type $peat_type, string $column_name, array $in, bool $exclude = false, int $limit = 1000): array
    {
        if (0 === count($in)) return array();
        $table_name = $peat_type->tableName();
        $table_info = $this->getTableInfo($table_name);
        $id_column = $table_info->getIdColumn();
        $instance_id = Setup::$instance_id;
        if (false === $exclude) {
            $not = '';
        } else {
            $not = 'NOT';
        }
        $in_placeholders = str_repeat('?,', count($in) - 1); // NOTE you need one more ? at the end of this
        if ($table_info->getColumnByName('date_popvote')) {
            $sorting = 'ORDER BY date_popvote DESC';
        } elseif ($table_info->getColumnByName('date_published')) {
            $sorting = 'ORDER BY date_published DESC';
        } elseif ($table_info->getColumnByName('o')) {
            $sorting = 'ORDER BY o ASC';
        } else {
            $sorting = '';
        }
        if ($table_info->getColumnByName($column_name)) {
            if (defined('ADMIN') && false === ADMIN) {
                $and_where_online = ' AND el.online = TRUE';
            } else {
                $and_where_online = '';
            }
            $statement = $this->conn->prepare("SELECT el.*, $id_column AS id, '$table_name' AS table_name
                FROM $table_name el WHERE el.deleted = FALSE AND el.instance_id = $instance_id $and_where_online
                AND el.$column_name $not IN ($in_placeholders?) $sorting LIMIT $limit;");
            $statement->execute($in);
            $rows = $statement->fetchAll(5);
            $statement = null;

            return $rows;
        } else {
            $this->addError(sprintf('Column %s does not exist', $column_name));

            return array();
        }
    }

    /**
     * Returns the properties of a given element (by type and id) if any
     * @param Type $type
     * @param int $id
     * @return array
     * @since 0.8.0
     */
    public function fetchPropertyRowsLinked(Type $type, int $id): array
    {
        $table_name = $type->tableName();
        $type_name = $type->typeName();
        $id_column = $type->idColumn();
        $link_table = "{$table_name}_x_properties";
        $statement = $this->conn->prepare("
            SELECT {$type_name}_x_properties_id x_value_id, x.o, x.x_value, p.slug property_slug, p.title property_title, 
            v.slug, v.title, (p.online AND v.online) AS online, EXISTS(
                SELECT 1 FROM cms_{$type_name}_x_properties WHERE property_value_id = x.property_value_id AND TRIM(x_value) <> ''
            ) property_value_uses_x_value FROM $link_table x 
                INNER JOIN cms_property p ON p.property_id = x.property_id 
                INNER JOIN cms_property_value v ON v.property_value_id = x.property_value_id 
            WHERE x.$id_column = :id AND v.deleted = FALSE AND x.deleted = FALSE ORDER BY x.o;
        ");
        $statement->bindValue(':id', $id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * This is the ‘properties’ (x-value cross-link-table) version of ->fetchElementRowsLinked
     * where the $linked_type is page or variant, it uses the order within the elements themselves (date_published or popvote)
     *
     * @param Type $peat_type property or property_value type, determines the selection in the x_value table
     * @param int $id property or property_value id for selecting rows
     * @param Type $linked_type the type we want to get back that has this property or property_value
     * @param int $variant_page_size
     * @param int $variant_page
     * @param array|null $properties
     * @return array
     * @since 0.8.0
     * @noinspection PhpTooManyParametersInspection
     */
    public function fetchElementRowsLinkedX(Type $peat_type, int $id, Type $linked_type, int $variant_page_size, int $variant_page, ?array $properties): array
    {
        // gets the specified $linked_type through the appropriate x_value cross table by $peat_type $id
        $table_name = $linked_type->tableName();
        $x_table = "{$table_name}_x_properties";
        $id_column = $linked_type->idColumn();
        $sub_queries = array();
        if (isset($properties)) {
            $sub_queries = $this->queriesProperties($properties, $linked_type->typeName(), 'el');
        }
        // sorting...
        $table_info = $this->getTableInfo($table_name);
        if ($table_name === 'cms_variant') {
            $sub_queries[] = 'el.online = TRUE'; // @since 0.8.15 no variants that are not online, because of paging
            $sorting = "ORDER BY date_popvote DESC LIMIT $variant_page_size OFFSET " . ($variant_page_size * ($variant_page - 1));
        } elseif ($table_info->getColumnByName('date_published')) {
            $sorting = 'ORDER BY date_published DESC ';
            $sub_queries[] = '(date_published IS NULL OR date_published < NOW() - INTERVAL \'5 minutes\')'; // allow a few minutes for the cache to update
        } else {
            $sorting = 'ORDER BY date_created DESC ';
        }
        $type_id_column = $peat_type->idColumn();
        if (0 !== count($sub_queries)) { // TODO duplicate code
            $imploded_sub_queries = ' AND ' . implode(' AND ', $sub_queries);
        } else {
            $imploded_sub_queries = '';
        }
        $statement = $this->conn->prepare("
            SELECT DISTINCT el.* FROM $x_table x INNER JOIN $table_name el ON el.$id_column = x.$id_column
            WHERE x.$type_id_column = :id AND x.deleted = FALSE and el.deleted = FALSE $imploded_sub_queries $sorting;
        ");
        $statement->bindValue(':id', $id);
        $statement->execute();
        //Help::addMessage(str_replace(':id', (string)$id, $statement->queryString), 'note');
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * Fetches the linked items as specified by $linked_type according to the structure of the
     * cross-links tables respecting the order therein as well as the ordering within the types themselves when relevant
     * In case of variants, it will use the page size and page number supplied
     *
     * @param Type $peat_type
     * @param int $id
     * @param Type $linked_type
     * @param string $relation
     * @param int $variant_page_size
     * @param int $variant_page
     * @return array
     * @noinspection PhpTooManyParametersInspection
     */
    public function fetchElementRowsLinked(Type $peat_type, int $id, Type $linked_type, string $relation, int $variant_page_size, int $variant_page): array
    {
        $peat_type_name = $peat_type->typeName();
        $linked_type_name = $linked_type->typeName();
        if ('variant' === $linked_type_name) {
            $offset = ($variant_page_size * ($variant_page - 1));
            $paging = " LIMIT $variant_page_size OFFSET $offset";
        } else {
            $paging = '';
        }
        if ('cross_parent' === $relation) { // switch $type and $linked_type around
            $link_table = "cms_{$linked_type_name}_x_$peat_type_name";
            $statement = $this->conn->prepare(
                "SELECT el.*, x.o FROM cms_$linked_type_name el 
                INNER JOIN $link_table x ON x.sub_{$linked_type_name}_id = el.{$linked_type_name}_id 
                WHERE x.{$peat_type_name}_id = :id AND el.deleted = FALSE AND x.deleted = FALSE ORDER BY x.o $paging;"
            );
        } elseif ('cross_child' === $relation) {
            $link_table = "cms_{$peat_type_name}_x_$linked_type_name";
            $statement = $this->conn->prepare(
                "SELECT el.*, x.sub_o FROM cms_$linked_type_name el 
                INNER JOIN $link_table x ON x.{$linked_type_name}_id = el.{$linked_type_name}_id 
                WHERE x.sub_{$peat_type_name}_id = :id AND el.deleted = FALSE AND x.deleted = FALSE ORDER BY x.sub_o $paging;"
            );
            // direct relations: eg type = product, linked_type = variant, relation = direct_parent
        } elseif ('direct_parent' === $relation) { // get the e-commerce element this table is a direct parent of
            // honor sorting...
            $table_info = $this->getTableInfo($linked_type->tableName());
            if ($table_info->getColumnByName('date_popvote')) {
                $sorting = 'ORDER BY date_popvote DESC';
            } elseif ($table_info->getColumnByName('date_published')) {
                $sorting = 'ORDER BY date_published DESC';
            } else {
                $sorting = '';
            }
            $statement = $this->conn->prepare(
                "SELECT el.* FROM cms_{$linked_type_name} el 
                WHERE el.{$peat_type_name}_id = :id AND el.deleted = FALSE $sorting $paging;"
            );
        } elseif ('direct_child' === $relation) { // relation must be direct_child
            $statement = $this->conn->prepare(
                "SELECT el.* FROM cms_$linked_type_name el 
                INNER JOIN cms_$peat_type_name t ON t.{$linked_type_name}_id = el.{$linked_type_name}_id 
                WHERE t.{$peat_type_name}_id = :id AND el.deleted = FALSE $paging;"
            );
        } else {
            $statement = 'SELECT :id;';
        }
        $statement->bindValue(':id', $id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * Fetches the slug of a page for a specific page_id
     * @param int $page_id
     * @return string
     * @since 0.8.3
     */
    public function fetchPageSlug(int $page_id): string
    {
        $statement = $this->conn->prepare('SELECT slug FROM cms_page WHERE page_id = :page_id;');
        $statement->bindValue(':page_id', $page_id);
        $statement->execute();
        $slug = $statement->fetchColumn() ?: 'homepage';
        $statement = null;

        return $slug;
    }

    /**
     * Updates the date_popvote column to NOW() (making this element the most popular) or when $down_vote === true
     * will add a random amount of minutes to the date of the next element, putting it below it
     * Calls getPopVote to return the new position
     *
     * @param string $element_name
     * @param int $id
     * @param bool $down_vote default false: will put the element at the top
     * @return float the pop_vote value (between 0, the most popular, and 1, the least)
     * @since 0.5.12
     */
    public function updatePopVote(string $element_name, int $id, int $down_vote = 0): float
    {
        $type = new Type($element_name);
        $table_name = $type->tableName();
        $type_name = $type->typeName();
        if (0 === $down_vote) {
            $statement = $this->conn->prepare("
                UPDATE $table_name SET date_popvote = NOW() WHERE {$type_name}_id = ? AND instance_id = ?;
            ");
            $statement->execute(array($id, Setup::$instance_id));
            if ($statement->rowCount() === 1) {
                $this->reCacheWithWarmup($this->fetchElementRow($type, $id)->slug);

                return 0; // this is now the highest voted
            } else {
                $this->addError("updatePopVote error for $element_name with id $id");
            }
        } else { // downvote..., move approx x down on every vote by decreasing the date_popvote by random 1 - 20 minutes
            // or 8 hours when this is already the lowest
            $statement = $this->conn->prepare("UPDATE $table_name 
                SET date_popvote = COALESCE(
                    (SELECT MIN(date_popvote) - CAST(floor(random() * 20 + 1) ||  ' minutes' as INTERVAL) 
                     FROM (SELECT date_popvote FROM $table_name 
                        WHERE date_popvote < (SELECT date_popvote FROM $table_name 
                            WHERE {$type_name}_id = :id AND instance_id = :instance_id AND deleted = FALSE)
                        ORDER BY date_popvote DESC LIMIT $down_vote) alias)
                    , date_popvote - CAST(8 ||  ' hours' as INTERVAL)
                    ) WHERE {$type_name}_id = :id AND instance_id = :instance_id;");
            $statement->bindValue(':id', $id);
            $statement->bindValue(':instance_id', Setup::$instance_id);
//            var_dump(str_replace(':id', (string)$id, str_replace(':instance_id', (string)Setup::$instance_id, $statement->queryString)));
//            die();
            $statement->execute();
            $count = $statement->rowCount();
            $statement = null;
            if ($count === 1) {
                $this->reCacheWithWarmup($this->fetchElementRow($type, $id)->slug);

                return $this->getPopVote($element_name, $id);
            } else {
                $this->addError("updatePopVote returned $count rows affected...");
            }
        }

        return -1;
    }

    /**
     * Return the current pop_vote relative value (0 ~ 1) for the supplied element (by name and id)
     *
     * @param string $element_name
     * @param int $id
     * @return float the current value
     * @since 0.5.12
     */
    public function getPopVote(string $element_name, int $id): float
    {
        $type = new Type($element_name);
        $table_name = $type->tableName();
        $type_name = $type->typeName();
        // select the relative position of this specific id in all the records
        $statement = $this->conn->prepare("SELECT COUNT({$type_name}_id) FROM $table_name
            WHERE deleted = FALSE AND instance_id = :instance_id AND date_popvote > (
                SELECT date_popvote FROM $table_name WHERE {$type_name}_id = :id);");
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':id', $id);
        $statement->execute();
        $position = (float)$statement->fetchColumn();
        $statement = null;
        if ($position > 0) { // get it relative to the total number of rows
            $statement = $this->conn->prepare("
                SELECT COUNT({$type_name}_id) FROM $table_name WHERE deleted = FALSE AND instance_id = :instance_id;
            ");
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute();
            $rows = $statement->fetchAll(3);
            $statement = null;
            if (count($rows) < 1) {
                $this->addError('getPopVote: total count wrong');
            } else {
                $position = $position / (float)$rows[0][0];
            }
        }

        return $position;
    }

    /**
     * Inserts a country with a specific name
     * @param string $name
     * @param int $instance_id defaults to current instance
     * @return int|null the country_id for the freshly inserted country (int probably) or null on fail
     * @since 0.5.10
     */
    public function insertCountry(string $name, int $instance_id = -1): ?int
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->insertRowAndReturnLastId('_country', array(
            'name' => $name,
            'instance_id' => $instance_id,
            'o' => 0, // so it will turn up at the top of the list most likely
        ));
    }

    /**
     * Updates the sequential number for this instance with 1 and returns the new value
     * @param int $instance_id
     * @return int
     * @since 0.6.2
     */
    public function generatePaymentSequentialNumber(int $instance_id = -1): int
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;
        $statement = $this->conn->prepare('UPDATE _instance SET payment_sequential_number = payment_sequential_number + 1 WHERE instance_id = ? RETURNING payment_sequential_number;');
        $statement->execute(array($instance_id));
        $rows = $statement->fetchAll(3);
        $statement = null;
        if (1 === count($rows)) {
            return (int)$rows[0][0];
        }
        $this->handleErrorAndStop(sprintf('payment sequential number failed for instance %s', $instance_id));

        return 0;
    }

    /**
     * @param string $name the given name
     * @param int $instance_id
     * @return int|null the id of the row or null on failure
     * @since 0.6.2
     */
    public function insertPaymentServiceProvider(string $name, int $instance_id = -1): ?int
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->insertRowAndReturnLastId('_payment_service_provider', array(
            'given_name' => $name,
            'instance_id' => $instance_id,
        ));
    }

    /**
     * Returns all the redirects for admin to manage
     * @param int $instance_id the instance_id for which to return the redirects
     * @return array normalized rows containing all columns and rows of the _redirect table for this instance
     * @since 0.8.1
     */
    public function getRedirects(int $instance_id = -1): array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;
        $statement = $this->conn->prepare('SELECT * FROM _redirect WHERE instance_id = :instance_id AND deleted = FALSE ORDER BY term ASC;');
        $statement->bindValue(':instance_id', $instance_id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * Returns the countries that belong to a specific instance
     * @param int $instance_id defaults to the current instance
     * @return array|null indexed array of \stdClass (row) objects
     * @since 0.5.10
     */
    public function getCountries(int $instance_id = -1): ?array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->fetchRows(
            '_country',
            array('country_id', 'name', 'iso2', 'iso3', 'o', 'shipping_costs', 'shipping_free_from'),
            array('instance_id' => $instance_id)
        );
    }

    /**
     * @param int $instance_id
     * @return array|null the payment service provider rows for the specified instance or null when not available
     * @since 0.6.2
     */
    public function getPaymentServiceProviders(int $instance_id = -1): ?array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->fetchRows(
            '_payment_service_provider',
            array('payment_service_provider_id', 'provider_name', 'given_name', 'field_values', 'online'),
            array('instance_id' => $instance_id)
        );
    }

    /**
     * Returns a country by id for a specific instance
     * @param int $country_id
     * @param int $instance_id defaults to current instance
     * @return \stdClass|null the database row or null when not found
     * @since 0.5.10
     */
    public function getCountryById(int $country_id, int $instance_id = -1): ?\stdClass
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->fetchRow(
            '_country',
            array('country_id', 'name', 'iso2', 'iso3', 'o', 'shipping_costs', 'shipping_free_from'),
            array('country_id' => $country_id, 'instance_id' => $instance_id)
        );
    }

    /**
     * @param Shoppinglist $shoppinglist
     * @param Session $session
     * @param array $vars all the vars that are explicitly needed for the ordering process, client-friendly validation should be done already
     * @return string|null
     * @since 0.5.12
     */
    public function placeOrder(Shoppinglist $shoppinglist, Session $session, array $vars): ?string
    {
        // we need specific vars for the ordering process, validation process has run, if something misses you should throw an error
        if (count(($order_rows = $shoppinglist->getRows())) > 0) {
            if (false === isset($vars['email'])) $this->addError('DB->placeOrder: email is not present');
            if (false === isset($vars['shipping_country_id'])) $this->addError('DB->placeOrder: shipping_country_id is not present');
            if ($this->hasError()) return null;
            // @since 0.9.0 add vat, @since 0.23.0 allow ex-vat
            $ex_vat = true === isset($vars['vat_valid']) && true === $vars['vat_valid'];
            $highest_vat = 0.0;
            // set up the necessary vars
            $instance_id = Setup::$instance_id;
            $country = $this->getCountryById((int)$vars['shipping_country_id']);
            $amount_row_total = 0;
            $shipping_costs = 0;
            $quantity_total = 0; // @since 0.7.6. also count the quantity, maybe there are only empty rows, you can’t order those
            $vat_categories = $this->getVatCategoriesByIdWithDefaultIn0($instance_id);
            //$order_rows = $this->fetchShoppingListRows($shoppinglist_id); (already in if-clause)
            foreach ($order_rows as $index => $row) {
                $quantity = $row->quantity;
                $variant_row = $this->fetchElementRow(new Type('variant'), $row->variant_id);
                $vat_row = $vat_categories[$variant_row->vat_category_id] ?? $vat_categories[0] ?? 0;
                $vat_percentage = Help::asFloat($vat_row->percentage);
                // remember the highest vat for the shipping costs
                if ($vat_percentage > $highest_vat) $highest_vat = $vat_percentage;
                if (true === $ex_vat) { // re-calculate prices to be ex-vat
                    if ('' !== $row->price_from) {
                        $row->price_from = Help::asMoney(100 * Help::asFloat($row->price_from) / (100 + $vat_percentage));
                    }
                    $row->price = Help::asMoney(100 * Help::asFloat($row->price) / (100 + $vat_percentage)); // price_ex_vat
                    $row->vat_percentage = 0.0;
                } else {
                    $row->vat_percentage = $vat_percentage;
                }
                // @since 0.5.16 enrich with some default values
                $row->title = $variant_row->title;
                $row->mpn = $variant_row->mpn;
                $row->sku = $variant_row->sku;
                // count totals
                $amount_row_total += Help::asFloat($row->price) * $quantity;
                $quantity_total += $quantity;
            }
            if (0 === $quantity_total) { // @since 0.7.6 if there is nothing to order, also abandon the process
                $this->addMessage(__('There are no rows in this shoppinglist to order', 'peatcms'), 'warn');
                $this->addError('DB->placeOrder: no rows in shoppinglist to order');

                return null;
            }
            $amount_grand_total = $amount_row_total;
            if ($amount_grand_total < Help::asFloat($country->shipping_free_from)) {
                $shipping_costs = Help::asFloat($country->shipping_costs);
                if ($ex_vat && $highest_vat) {
                    $shipping_costs = 100 * $shipping_costs / (100 + $highest_vat);
                }
                $amount_grand_total += $shipping_costs;
            }
            // set up the order_number
            if (null === ($order_number = $this->createUniqueOrderNumber($instance_id))) {
                $this->addMessage(__('Could not create unique order_number', 'peatcms'));
                $this->addError('Could not create unique order_number');

                return null;
            }
            // build an order array to insert into the database
            $order_fields = array(
                'instance_id' => $instance_id,
                'session_id' => $session->getId(),
                'user_id' => (($user = $session->getUser()) ? $user->getId() : 0),
                // you need float for humans here to prevent rounding errors
                'amount_grand_total' => (int)Help::floatForHumans(100 * $amount_grand_total),
                'amount_row_total' => (int)Help::floatForHumans(100 * $amount_row_total),
                'shipping_costs' => (int)Help::floatForHumans(100 * $shipping_costs),
                'user_gender' => $vars['gender'] ?? '',
                'user_email' => strtolower(trim($vars['email'])),
                'user_phone' => strtolower(trim($vars['phone'] ?? '')),
                'shipping_address_country_name' => $country->name,
                'shipping_address_country_iso2' => $country->iso2,
                'shipping_address_country_iso3' => $country->iso3,
                'order_number' => $order_number,
            );
            // loop through the other fields we are going to insert
            foreach (array(
                         'billing_address_name',
                         'billing_address_company',
                         'billing_address_postal_code',
                         'billing_address_number',
                         'billing_address_number_addition',
                         'billing_address_street',
                         'billing_address_street_addition',
                         'billing_address_city',
                         'billing_address_country_name',
                         'billing_address_country_iso2',
                         'billing_address_country_iso3',
                         'shipping_address_name',
                         'shipping_address_company',
                         'shipping_address_postal_code',
                         'shipping_address_number',
                         'shipping_address_number_addition',
                         'shipping_address_street',
                         'shipping_address_street_addition',
                         'shipping_address_city',
                         'newsletter_subscribe',
                         'preferred_delivery_day',
                         'remarks_user',
                         'vat_number',
                         'vat_country_iso2',
                         'vat_valid',
                         'vat_history',
                     ) as $index => $key) {
                $value = $vars[$key] ?? '';
                switch ($key) {
                    case 'vat_history':
                    case 'remarks_user':
                        break;
                    case 'vat_valid':
                    case 'newsletter_subscribe':
                        $value = (bool)$value;
                        break;
                    case 'billing_address_country_iso2':
                        $value = substr($value, 0, 2);
                        break;
                    case 'billing_address_country_iso3':
                        $value = substr($value, 0, 3);
                        break;
                    default:
                        $value = mb_substr(trim($value), 0, 127);
                }
                $order_fields[$key] = $value;
            }
            try {
                $this->conn->beginTransaction();
                if (null === ($order_id = $this->insertRowAndReturnLastId('_order', $order_fields))) {
                    $this->addMessage(__('Could not create order', 'peatcms'), 'error');
                    $this->addError('Could not create order');

                    return null;
                }
                // now the rows
                foreach ($order_rows as $index => $row) {
                    $row->order_id = $order_id;
                    unset($row->variant_slug); // this is not saved in the order, prevent logging by removing now
                    if (null === $this->insertRowAndReturnLastId('_order_variant', (array)$row)) {
                        $this->resetConnection();
                        $this->addMessage(__('Order row insert failed', 'peatcms'), 'error');
                        $this->addError('Order row insert failed');

                        return null;
                    }
                }
                // ok commit
                $this->conn->commit();
            } catch (\Exception $e) {
                $this->handleErrorAndStop($e, __('Order process failure', 'peatcms'));
            }
            // clear the shoppinglist (orphaned rows will be deleted by daily job)
            if (false === $this->deleteRowAndReturnSuccess('_shoppinglist', $shoppinglist->getId())) {
                $this->addMessage(__('Order placed, shoppinglist could not be cleared', 'peatcms'), 'warn');
            }

            return $order_number;
        } else {
            $this->addMessage(__('There are no rows in this shoppinglist to order', 'peatcms'), 'warn');
            $this->addError("No rows in shoppinglist {$shoppinglist->getId()}");

            return null;
        }
    }

    /**
     * @param string $table_name
     * @param string $column_name default o, can also be sub_o when available
     * @return int
     * @since 0.10.10
     */
    private function getHighestO(string $table_name, string $column_name = 'o'): int
    {
        $statement = $this->conn->prepare("SELECT MAX ($column_name) FROM $table_name");
        $statement->execute();
        $o = (int)$statement->fetchColumn(0);
        $statement = null;

        return min(32766, $o) + 1;
    }

    /**
     * Generates a unique order number of 8 digits (numbers) including a check digit, preceded by the year (4 digits)
     * Uniqueness guaranteed per instance. You can display the number in 3 chunks of 4 digits to the user
     *
     * @param int $instance_id
     * @return string the order number
     * @since 0.5.12
     */
    private function createUniqueOrderNumber(int $instance_id): string
    {
        $count = 0;
        $order_number = null;
        $statement = $this->conn->prepare('INSERT INTO _order_number (instance_id, order_number) VALUES (:instance_id, :order_number);');
        $statement->bindValue(':instance_id', $instance_id);
        // TODO potentially a lot of trials have to be done to get a unique number, if an instance racks up like 100.000 orders or something in a year
        while ($statement->rowCount() !== 1) {
            if ($count === 20) $this->handleErrorAndStop('No more tries left to create order_number', __('Could not create unique order_number', 'peatcms'));
            $count++;
            $order_number = Help::randomString(7, '1234567890');
            // check digit for random number to prevent mistakes (later) https://en.wikipedia.org/wiki/Check_digit
            $arr = \str_split($order_number);
            $one = \intval($arr[0]) + \intval($arr[2]) + \intval($arr[4]) + \intval($arr[6]);
            $two = 3 * (\intval($arr[1]) + \intval($arr[3]) + \intval($arr[5]));
            if (($check_digit = ($one + $two) % 10) > 0) $check_digit = 10 - $check_digit;
            // add year and check digit to order number
            $order_number = date('Y') . $order_number . $check_digit;
            $statement->bindValue(':order_number', $order_number);
            try {
                $statement->execute();
            } catch (\Exception $e) {
                // if it’s not a unique number an error will be thrown, but we want to try again with a new number so just catch it
            }
        }
        $statement = null;
        if (null === $order_number) $this->handleErrorAndStop('order_number still null', __('Could not create unique order_number', 'peatcms'));

        return $order_number;
    }

    public function refreshOrderNumbers(int $instance_id): bool
    {
        $year = (new \DateTime())->format("Y");
        $this->deleteForInstance('_order_number', $instance_id);
        $statement = $this->conn->prepare('SELECT order_number FROM _order WHERE instance_id=:instance_id AND LEFT(order_number, 4) = :year;');
        $statement->bindValue(':instance_id', $instance_id);
        $statement->bindValue(':year', $year);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = $this->conn->prepare('INSERT INTO _order_number (instance_id, order_number) VALUES(?, ?);');
        foreach ($rows as $index => $row) {
            $statement->execute(array($instance_id, $row->order_number));
        }
        $statement = null;

        return true;
    }

    /**
     * @param string $order_number
     * @param int $instance_id @since 0.9.0 allow jobs to fetch orders for multiple instances
     * @return \stdClass|null
     * @since 0.5.16
     */
    public function getOrderByNumber(string $order_number, int $instance_id = 0): ?\stdClass
    {
        if (0 === $instance_id) $instance_id = Setup::$instance_id;

        return $this->fetchRow('_order', array('*'), array(
            'order_number' => $order_number,
            'instance_id' => $instance_id,
        ));
    }

    public function getOrderByPaymentTrackingId(string $payment_tracking_id): ?\stdClass
    {
        return $this->fetchRow('_order', array('*'), array(
            'payment_tracking_id' => $payment_tracking_id,
            'instance_id' => Setup::$instance_id,
        ));
    }

    /**
     * @param int $order_id
     * @return array
     * @since 0.5.16
     */
    public function fetchOrderRows(int $order_id): array
    {
        return $this->fetchRows('_order_variant', array('*'), array('order_id' => $order_id));
    }

    /**
     * @param int $order_id
     * @return array
     * @since 0.6.3
     */
    public function fetchOrderPayments(int $order_id): array
    {
        return $this->fetchRows('_payment_status_update', array('*'), array('order_id' => $order_id));
    }

    public function fetchPaymentStatuses(): array
    {
        $statement = $this->conn->prepare('SELECT u.*, o.order_number FROM _payment_status_update u LEFT OUTER JOIN _order o ON u.order_id = o.order_id WHERE u.instance_id = ' .
            Setup::$instance_id . ' ORDER BY date_created DESC, date_processed DESC LIMIT 100;');
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * ‘job’ functions are not instance specific, they cover the WHOLE database
     *
     * @return array
     * @since 0.5.16
     * @since 0.9.0 added the invoice / payment confirmation fields
     */
    public function jobGetUnconfirmedOrders(): array
    {
        $statement = $this->conn->prepare(
            'SELECT i.instance_id, i.domain, i.mailgun_custom_domain, i.mail_verified_sender,
       i.confirmation_before_payment, i.confirmation_copy_to,
       i.confirmation_of_payment, i.create_invoice, i.send_invoice_as_pdf,
       i.template_id_order_confirmation, i.template_id_payment_confirmation, i.template_id_internal_confirmation,
       o.*
            FROM _order o INNER JOIN _instance i ON i.instance_id = o.instance_id
            WHERE o.emailed_order_confirmation = FALSE
               OR (o.emailed_payment_confirmation = FALSE AND o.payment_confirmed_bool = TRUE)
            ORDER BY mailgun_custom_domain;'
        );
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    public function jobGetOrdersForMyParcel(): array
    {
        $statement = $this->conn->prepare(
            'SELECT i.instance_id, i.myparcel_api_key, o.*
            FROM _instance i
            INNER JOIN _order o ON o.instance_id = i.instance_id
            WHERE o.myparcel_exported = FALSE AND o.payment_confirmed_bool = TRUE
            ORDER BY i.instance_id, o.payment_confirmed_date DESC;'
        );
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    public function deleteSearchIndex(BaseElement $element): bool
    {
        $statement = $this->conn->prepare('DELETE FROM _ci_ai WHERE instance_id = :instance_id AND type_name = :type AND id = :id;');
        $statement->bindValue(':instance_id', $element->getInstanceId());
        $statement->bindValue(':type', $element->getTypeName());
        $statement->bindValue(':id', $element->getId());
        $statement->execute();
        $success = (2 > $statement->rowCount());
        $statement = null;

        return $success;
    }

    public function updateSearchIndex(BaseLogic $element): bool
    {
        if (false === $element instanceof BaseElement) return true;
        if (false === in_array($element->getTypeName(), self::TYPES_WITH_CI_AI)) return true; // success, we do not need to update
        // @since 0.18.0 delete immediately to not pollute search results
        $row = $element->getRow();
        if (true === $row->deleted) {
            $statement = $this->conn->prepare("
                DELETE FROM _ci_ai 
                WHERE instance_id = :instance_id AND type_name = :type_name AND _ci_ai.id = :id;
            ");
            $statement->bindValue(':instance_id', $element->getInstanceId()); // to use index
            $statement->bindValue(':type_name', $element->getTypeName());
            $statement->bindValue(':id', $row->id);
            $statement->execute();
            $statement = null;
            return true; // any amount of deleted rows means a success
        }
        $out = $element->getOutputFull();
        $ci_ai = Help::removeAccents(mb_strtolower($this->getMeaningfulSearchString($out)));
        // @since 0.12.0 maintain _ci_ai table
        $statement = $this->conn->prepare('
            INSERT INTO _ci_ai (instance_id, ci_ai, title, slug, type_name, id, online)
            VALUES (:instance_id, :ci_ai, :title, :slug, :type_name, :id, :online)
            RETURNING ci_ai_id
        ');
        $statement->bindValue(':instance_id', $out->instance_id);
        $statement->bindValue(':ci_ai', $ci_ai);
        $statement->bindValue(':title', $out->title);
        $statement->bindValue(':slug', $out->slug);
        $statement->bindValue(':type_name', $element->getTypeName());
        $statement->bindValue(':id', $out->id);
        $statement->bindValue(':online', (true === $element->isOnline()) ? 1 : 0);
        $statement->execute();
        $success = (1 === $statement->rowCount());
        $statement = null;

        return $success;
    }

    public function fetchElementsMissingCiAi(string $type_name, int $limit): array
    {
        $statement = $this->conn->prepare("
            SELECT *, {$type_name}_id AS id, 'cms_$type_name' AS table_name FROM cms_$type_name c 
            WHERE NOT EXISTS(SELECT FROM _ci_ai WHERE type_name = '$type_name' AND id = c.{$type_name}_id) 
            AND deleted = FALSE LIMIT $limit;
        ");
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    public function jobFetchImagesForCleanup(int $more_than_days = 365, int $how_many = 15): array
    {
        $statement = $this->conn->prepare("SELECT * FROM cms_image 
            WHERE date_processed < NOW() - $more_than_days * interval '1 days' AND filename_saved IS NOT NULL
            ORDER BY date_updated DESC LIMIT $how_many;");
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    public function jobFetchImagesForProcessing(int $how_many = 15): array
    {
        $statement = $this->conn->prepare("SELECT * FROM cms_image 
            WHERE filename_saved IS NOT NULL
            AND (date_processed IS NULL
                OR (RIGHT(LEFT(src_large, -5), LENGTH(slug)) <> slug))
            ORDER BY date_updated DESC LIMIT $how_many");
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * If you're getting the template for an admin, supply null as $instance_id, and then perform an ->isRelatedInstanceId()
     * on it afterwards. Otherwise templates are restricted to the current instanceid.
     *
     * @param int $template_id which template
     * @param int|null $instance_id @since 0.5.7
     * @return \stdClass|null the templaterow from the database
     */
    public function fetchTemplateRow(int $template_id, ?int $instance_id): ?\stdClass
    {
        return $this->fetchRow('_template', array('*'), array('template_id' => $template_id, 'instance_id' => $instance_id));
    }

    /**
     * fetches only necessary settings for an element from the template
     * @param int $template_id
     * @return \stdClass|null
     * @since 0.7.1
     */
    public function fetchTemplateSettings(int $template_id): ?\stdClass
    {
        return $this->fetchRow('_template', array(
            'nested_max',
            'nested_show_first_only',
            'variant_page_size'
        ), array('template_id' => $template_id, 'instance_id' => Setup::$instance_id));
    }

    /**
     * @param int $payment_service_provider_id
     * @param int|null $instance_id
     * @return \stdClass|null the payment service provider row to instantiate the class with, or null when it doesn’t exist
     * @since 0.6.2
     */
    public function getPaymentServiceProviderRow(int $payment_service_provider_id, ?int $instance_id = -1): ?\stdClass
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->fetchRow('_payment_service_provider', array('*'), array('payment_service_provider_id' => $payment_service_provider_id, 'instance_id' => $instance_id));
    }

    /**
     * Can only be gotten for current instance
     *
     * @param string $for_element
     * @return int|null
     */
    public function getDefaultTemplateIdFor(string $for_element): ?int
    {
        $instance_id = Setup::$instance_id;
        // get from cache if possible
        // because it does not start with (int) id will not be cleaned up by job
        $defaults = $this->appCacheGet("templates/defaults.$instance_id") ?: array();
        if (true === isset($defaults[$for_element])) {
            return $defaults[$for_element]; // 0 for non existent
        }
        // add this default to the cache element
        $template_id = $this->fetchDefaultTemplateIdFor($for_element); // can be null
        $defaults[$for_element] = (int)$template_id;
        $this->appCacheSet("templates/defaults.$instance_id", $defaults);

        return $template_id;
    }

    /**
     * @param string $for_element
     * @return int|null
     * @since 0.5.7
     */
    public function fetchDefaultTemplateIdFor(string $for_element): ?int
    {
        $statement = $this->conn->prepare('SELECT template_id FROM _template WHERE instance_id = :instance_id AND element = :element ORDER BY name;');
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':element', $for_element);
        $statement->execute();
        $rows = $statement->fetchAll(3);
        $statement = null;
        if (count($rows) > 0) return $rows[0][0];

        return null;
    }

    /**
     * returns the vat categories as usual rows
     *
     * @param int $instance_id
     * @return array
     * @since 0.9.0
     */
    public function getVatCategories(int $instance_id): array
    {
        return $this->fetchRows(
            '_vat_category',
            array('vat_category_id', 'title', 'percentage', 'o'),
            array('instance_id' => $instance_id)
        );
    }

    /**
     * returns the vat categories in an indexed array where the index is actually the id, duplicates the first (by o)
     * vat category row in the index 0 to use as default
     *
     * @param int $instance_id
     * @return array
     * @since 0.9.0
     */
    public function getVatCategoriesByIdWithDefaultIn0(int $instance_id): array
    {
        $vat_categories = $this->getVatCategories($instance_id);
        $return_value = array();
        foreach ($vat_categories as $index => $vat_category) {
            $return_value[$vat_category->id] = $vat_category;
            // first one = default
            if (0 === $index) {
                $return_value[0] = $vat_category;
            }
        }

        return $return_value;
    }

    /**
     * @param int $instance_id
     * @param string $for_element
     * @return array
     * @since 0.5.7
     */
    public function getTemplates(int $instance_id = -1, string $for_element = '%'): array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;
        $statement = $this->conn->prepare('SELECT * FROM _template WHERE instance_id = ? AND element LIKE ? AND deleted = FALSE ORDER BY element, name;');
        $statement->execute(array($instance_id, $for_element));
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * @param int $instance_id
     * @return array
     * @since 0.5.7
     */
    public function getPartialTemplates(int $instance_id = -1): array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;
        $statement = $this->conn->prepare('SELECT * FROM _template WHERE instance_id = ? AND element = \'partial\' AND deleted = FALSE ORDER BY element, name;');
        $statement->execute(array($instance_id));
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * Returns the template by name, or the default one when the name does not match, or null when there are no templates
     *
     * @param string $template_name the name you gave the template in the admin interface
     * @param int $instance_id defaults to the current instance
     * @return \stdClass|null the template (row) requested or the default one for ‘mail’ or null when nothing is found
     * @since 0.5.10
     */
    public function getMailTemplate(string $template_name, int $instance_id = -1): ?\stdClass
    {
        $temp = null;
        if ($instance_id === -1) $instance_id = Setup::$instance_id;
        if ($templates = $this->getTemplates($instance_id, 'mail')) {
            $template_name = strtolower($template_name);
            foreach ($templates as $index => $template) {
                if (strtolower($template->name) === $template_name) {
                    return $template;
                }
                if ($index === 0) $temp = $template; // the default one, should none have the right name
            }
        }

        return $temp;
    }

    public function getTemplateByName(string $template_name, ?int $instance_id = null): ?\stdClass
    {
        if ($row = $this->fetchRow('_template', array('*'), array(
            'name' => $template_name,
            'instance_id' => $instance_id,
        ))) {
            return $row;
        }
        $this->addError(sprintf(__('Template %s not found', 'peatcms'), $template_name));

        return null;
    }

    public function orderMenuItem(int $menu_id, int $item_id, int $to_item_id): bool
    {
        if ($to_item_id === $item_id) {
            $this->addMessage(__('You can’t order a menu item before itself', 'peatcms'), 'error');

            return false;
        }
        // $to_item_id 0 means toggle: when item_id is present delete it, if not this is probably the first item in this menu
        // every item can at most be present once in each menu, so you can safely order everything per menu regardless of level
        // check which table you need for the ordering
        $table_name = null; // will be set to the appropriate table_name (string) if insert is required
        // define some wheres you will need
        $where = array(
            'menu_id' => $menu_id,
            'sub_menu_item_id' => $item_id,
        );
        $where_not_deleted = array(
            'menu_id' => $menu_id,
            'sub_menu_item_id' => $item_id,
            'deleted' => false,
        );
        $where_to = array(
            'menu_id' => $menu_id,
            'sub_menu_item_id' => $to_item_id,
            'deleted' => false,
        );
        // check if the item is dropped in a submenu, then use the submenu cross table
        if ($to_item_id > 0) {
            if (true === $this->rowExists('cms_menu_item_x_menu_item', $where_to)) {
                $table_name = 'cms_menu_item_x_menu_item';
            } else {
                $table_name = 'cms_menu_item_x_menu';
            }
        } elseif (false === $this->rowExists('cms_menu_item_x_menu', $where_not_deleted)) {
            // if toggle is requested, remember if it existed
            if (false === $this->rowExists('cms_menu_item_x_menu_item', $where_not_deleted)) {
                $table_name = 'cms_menu_item_x_menu';
            }
        }
        // remove every trace of this menu item, yes we're doing the crude approach, because it's too complicated otherwise
        $deleted_rows = $this->deleteRowWhereAndReturnAffected('cms_menu_item_x_menu', $where);
        $deleted_rows += $this->deleteRowWhereAndReturnAffected('cms_menu_item_x_menu_item', $where);
        if ($deleted_rows > 1) {
            $this->addMessage(sprintf(__('Deleted %s rows to order menu item', 'peatcms'), $deleted_rows));
        }
        //var_dump($table_name);
        // insert the item in the right table, and move it to the correct order (if applicable)
        if (false === is_null($table_name)) {
            // when in sub menu table you need to specify the menu_item_id as well, same as the item you dropped it on
            if ('cms_menu_item_x_menu_item' === $table_name) {
                if ($row = $this->fetchRow('cms_menu_item_x_menu_item', array('menu_item_id'), $where_to)) {
                    $where_not_deleted = array_merge(array('menu_item_id' => $row->menu_item_id), $where_not_deleted);
                } else {
                    $this->addError('Could not determine the menu_item_id this item is supposed to go under');
                }
            }
            $cross_table_row_id = $this->insertRowAndReturnLastId($table_name,
                array_merge(array('online' => true, 'o' => 0), $where_not_deleted));
            if (null === $cross_table_row_id) {
                $this->addError('Inserting menu item failed');

                return false;
            }
            $where_not_deleted = null; // make it clear this var is now compromised
            // keep order / re-order (when column 'o' is requested, the results are by default ordered)
            $rows = $this->fetchRows($table_name, array('sub_menu_item_id', 'o'), array('menu_id' => $menu_id));
            $o = 1;
            $success = true; // if anything goes wrong it will be set to false
            foreach ($rows as $key => $row) {
                if ($row->sub_menu_item_id === $item_id) continue; // skip over the one we're ordering
                if ($row->sub_menu_item_id === $to_item_id or ($o === 1 and $to_item_id === 0)) { // the moved item must be inserted here
                    if (false === $this->updateRowAndReturnSuccess($table_name, array('o' => $o), $cross_table_row_id)) $success = false;
                    $o++;
                }
                if (false === $this->updateRowAndReturnSuccess($table_name, array('o' => $o), $row->id)) $success = false;
                $o++;
            }

            return $success;
        }

        return true;
    }

    public function underMenuItem(int $menu_id, int $item_id, int $to_item_id = 0): bool // to_item_id = 0 means delete this
    {
        if ($to_item_id === $item_id) {
            $this->addMessage(__('You can’t make a menu item a child of itself', 'peatcms'), 'error');

            return false;
        }
        $num_deleted = 0;
        $where = array(
            'menu_id' => $menu_id,
            'sub_menu_item_id' => $item_id,
        );
        // a child is always in the cms_menu_item_x_menu_item cross table, so remove it from _x_menu
        if (true === $this->rowExists('cms_menu_item_x_menu', $where)) {
            $num_deleted += $this->deleteRowWhereAndReturnAffected('cms_menu_item_x_menu', $where);
        }
        if (true === $this->rowExists('cms_menu_item_x_menu_item', $where)) {
            if ($to_item_id === 0) {
                $num_deleted += $this->deleteRowWhereAndReturnAffected('cms_menu_item_x_menu_item', $where);
            } else { // update the existing row to the correct $to_item_id and deleted settings
                $keys = $this->updateRowsWhereAndReturnKeys('cms_menu_item_x_menu_item', array(
                    'menu_item_id' => $to_item_id,
                    'online' => true,
                    'deleted' => false,
                ), $where);
                if (count($keys) !== 1) { // delete extra items
                    foreach ($keys as $index => $key) {
                        if ($index === 0) continue; // leave one :-)
                        $this->deleteRowAndReturnSuccess('cms_menu_item_x_menu_item', $key);
                    }
                }

                return true;
            }
        } elseif ($to_item_id > 0) {
            // insert into the menu items cross table
            return 0 !== $this->insertRowAndReturnLastId('cms_menu_item_x_menu_item', array_merge($where, array(
                    'menu_item_id' => $to_item_id,
                    'online' => true,
                    'deleted' => false,
                )));
        }

        return (bool)$num_deleted;
    }

    public function orderAfterId(Type $type, int $id, Type $sub_type, int $sub_id, int $after_id): bool
    {
        $type_name = $type->typeName();
        $sub_type_name = $sub_type->typeName();
        $link_table = "cms_{$sub_type_name}_x_$type_name";
        $id_column = "{$sub_type_name}_x_{$type_name}_id";
        $keep_order = 0;
        $statement = $this->conn->prepare("
            SELECT $id_column AS table_id, sub_{$sub_type_name}_id AS sub_id, o
            FROM $link_table WHERE deleted = FALSE AND {$type_name}_id = :id ORDER BY o
        ");
        $statement->bindValue(':id', $id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = $this->conn->prepare("
            UPDATE $link_table SET date_updated = NOW(), o = :o WHERE $id_column = :table_id
        ");
        $relocating_table_id = null;
        $relocating_o = null;
        foreach ($rows as $index => $row) {
            if ($sub_id === $row->sub_id) {
                $relocating_table_id = $row->table_id;
                continue; // skip the one we are relocating
            }
            $keep_order++;
            if ($keep_order !== $row->o) { // update order so it will count nicely from 1 onwards
                $statement->bindValue(':o', $keep_order);
                $statement->bindValue(':table_id', $row->table_id);
                $statement->execute();
            }
            if ($after_id === $row->sub_id) {
                // after the current id we want the row we are relocating
                $keep_order++;
                $relocating_o = $keep_order;
            }
        }
        if ($relocating_table_id && $relocating_o) {
            $statement->bindValue(':o', $relocating_o);
            $statement->bindValue(':table_id', $relocating_table_id);
            $success = $statement->execute();
        } else {
            $success = false;
        }
        $statement = null;

        return $success;
    }

    /** @noinspection PhpTooManyParametersInspection */
    public function upsertLinked(Type $type, int $id, Type $sub_type, int $sub_id, bool $delete = false, int $order = 0): bool
    {
        $type_name = $type->typeName();
        $sub_type_name = $sub_type->typeName();
        $tables = $this->getLinkTables($type);
        if (isset($tables->{$sub_type_name})) {
            if ('cross_parent' === $tables->{$sub_type_name}) {
                $order_column = 'o';
            } else {
                Help::swapVariables($type_name, $sub_type_name);
                Help::swapVariables($id, $sub_id);
                $order_column = 'sub_o';
            }
            $link_table = "cms_{$sub_type_name}_x_$type_name";
            $id_column = "{$sub_type_name}_x_{$type_name}_id";
            $where = array(
                "{$type_name}_id" => $id,
                "sub_{$sub_type_name}_id" => $sub_id,
            );
            $rows = $this->fetchRows($link_table, array($id_column), $where);
            if (1 === count($rows)) { // update
                $update_array = array('deleted' => $delete);
                if (false === $delete) {
                    $update_array[$order_column] = $order;
                }
                return $this->updateRowAndReturnSuccess($link_table, $update_array, $rows[0]->{$id_column});
            } elseif (false === $delete) { // insert
                $update_array = $where;
                if ($order > 0) {
                    $update_array[$order_column] = $order;
                } else { // @since 0.19.3 if no order is specified, add at the end
                    $update_array[$order_column] = $this->getHighestO($link_table, $order_column);
                }
                return 0 !== $this->insertRowAndReturnLastId($link_table, $update_array);
            } elseif (count($rows) > 0) {
                // @since 0.21.0 because only 1 linked item is possible, remove the multiple now to reset this
                $affected = $this->updateColumnsWhere($link_table, array('deleted' => true), $where);
                $this->addError("Too many found! Removed $affected items.");
                $this->addMessage(sprintf(__('An error occurred in %s.', 'peatcms'), 'upsertLinked'), 'error');
                return true;
            }
        }
        $this->addError("->upsertLinked failed, no link table found for $type_name and $sub_type_name");

        return false;
    }

    public function deleteSessionById(int $session_id, int $user_id, int $admin_id): bool
    {
        if (0 !== $admin_id) {
            $where = array(
                'session_id' => $session_id,
                'instance_id' => null,
                'admin_id' => $admin_id,
            );
        } elseif (0 === $user_id) {
            $this->addMessage('Anonymous session can not be destroyed', 'warn');
            $where = array(
                'session_id' => 0,
            );
        } else {
            $where = array(
                'session_id' => $session_id,
                'instance_id' => null,
                'user_id' => $user_id,
            );
        }

        return 1 === $this->deleteRowWhereAndReturnAffected('_session', $where);
    }

    public function deleteSessionsForUser(int $user_id, int $own_session_id): int
    {
        return $this->deleteRowWhereAndReturnAffected(
            '_session',
            array('user_id' => $user_id),
            array('session_id' => $own_session_id) // do not remove your own session
        );
    }

    public function fetchAdminSessions(int $admin_id): array
    {
        $statement = $this->conn->prepare('
            SELECT s.session_id, s.reverse_dns, s.date_created, s.date_accessed, 
                   s.user_agent, s.deleted, i.domain, i.name, u.nickname as user_name
            FROM _session s 
                LEFT OUTER JOIN _instance i 
                    ON s.instance_id = i.instance_id
                LEFT OUTER JOIN _user u
                    ON u.user_id = s.user_id
            WHERE s.admin_id = :admin_id
        ');
        $statement->bindValue(':admin_id', $admin_id);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    public function fetchUserSessionCount(int $user_id): int
    { // TODO slow query not used atm
        $statement = $this->conn->prepare('
            SELECT COUNT(session_id) FROM _session
            WHERE user_id = :user_id AND deleted = FALSE
        ');
        $statement->bindValue(':user_id', $user_id);
        $statement->execute();
        $count = (int)$statement->fetchColumn(0);
        $statement = null;

        return $count;
    }

    public function fetchSession(string $token): ?\stdClass
    {
        // NOTE sessions are by instance_id
        if (!($row = $this->fetchRow('_session', array(
            'user_id',
            'admin_id',
            'session_id',
            'ip_address',
            'reverse_dns',
        ), array('token' => $token)))) {
            //$this->addError(sprintf('DB->fetchSession() returned nothing for token %s', $token));
            return null;
        }
        // get session vars
        if (($var_rows = $this->fetchRows('_sessionvars', array('name', 'value', 'times'), array('session_id' => $row->session_id)))) {
            $vars = array();
            foreach ($var_rows as $var_key => $var_row) {
                // next line makes sure when a var occurs more than once we load the one with the highest times only
                if (isset($vars[($var_name = $var_row->name)]) && $vars[$var_name]->times > $var_row->times) continue;
                // set the var from the db into the array
                $vars[$var_name] = (object)array('value' => json_decode($var_row->value), 'times' => $var_row->times);
            }
            $row->vars = $vars;
        }

        return $row;
    }

    public function fetchForLogin(string $email, bool $fetch_admin = false): ?\stdClass
    {
        $email = mb_strtolower($email);
        if (false === $fetch_admin) {
            $statement = $this->conn->prepare('
                SELECT user_id AS id, password_hash AS hash FROM _user 
                WHERE instance_id = :instance_id AND email = :email AND is_account = TRUE AND deleted = FALSE;
            ');
        } else {
            $statement = $this->conn->prepare('
                SELECT admin_id AS id, password_hash AS hash FROM _admin 
                WHERE (instance_id = :instance_id OR instance_id = 0) AND email = :email AND deleted = FALSE;
            ');
        }
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':email', $email);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;
        $num_rows = count($rows);
        if ($num_rows === 1) {
            return $rows[0];
        } elseif ($num_rows > 1) {
            $this->handleErrorAndStop(sprintf('DB->fetchForLogin returned %d rows.', $num_rows), __('Error during login.', 'peatcms'));
        }

        return null; // $num_rows === 0
    }

    public function fetchAdmin(int $admin_id): ?\stdClass
    {
        return $this->fetchRow('_admin',
            array('client_id', 'instance_id', 'email', 'nickname'),
            array('admin_id' => $admin_id, 'instance_id' => null) // instance_id can be this instance, or 0, so leave it out
        );
    }

    public function fetchUser(int $user_id): ?\stdClass
    {
        return $this->fetchRow('_user', array('nickname', 'email', 'phone', 'gender'), array('user_id' => $user_id));
    }

    /**
     * @param int $user_id
     * @return array
     * @since 0.7.9
     */
    public function fetchAddressesByUserId(int $user_id): array
    {
        return $this->fetchRows('_address', array('*'), array('user_id' => $user_id));
    }

    public function fetchOrdersByUserId(int $user_id): array
    {
        return $this->fetchRows('_order', array('*'), array('user_id' => $user_id));
    }

    public function fetchClient(int $client_id): ?\stdClass
    {
        return $this->fetchRow('_client', array('*'), array('client_id' => $client_id));
    }

    public function fetchInstance(string $domain): ?\stdClass
    {
        return $this->fetchRow('_instance',
            array('*'),
            array('domain' => $domain));
    }

    public function fetchInstanceById(int $instance_id): ?\stdClass
    {
        return $this->fetchRow('_instance',
            array('*'),
            array('instance_id' => $instance_id));
    }

    public function fetchInstanceDomains(int $instance_id): array
    {
        return $this->fetchRows('_instance_domain', array('domain'), array(
            'instance_id' => $instance_id,
        ));
    }

    public function fetchInstanceAdmins(int $instance_id): array
    {
        return $this->fetchRows('_admin', array('nickname', 'email'), array(
            'instance_id' => $instance_id,
        ));
    }

    public function fetchInstancePsps(int $instance_id): array
    {
        return $this->fetchRows('_payment_service_provider', array('payment_service_provider_id', 'provider_name', 'given_name'), array(
            'instance_id' => $instance_id,
        ));
    }

    public function fetchInstanceVatCategories(int $instance_id): array
    {
        return $this->fetchRows('_vat_category', array('vat_category_id', 'title', 'percentage', 'o'), array(
            'instance_id' => $instance_id,
        ));
    }

    public function fetchInstances(?int $client_id = null): array
    {
        $where = array();
        if (null !== $client_id) $where = array(
            'client_id' => $client_id,
        );
        return $this->fetchRows('_instance', array('*'), $where);
    }

    public function fetchInstanceCanonicalDomain(string $domain): ?string
    {
        // TODO SECURITY: an admin can put in a domain owned by another client, and so point it at their own site
        // TODO setup a verification mechanism, maybe a DNS query?
        $statement = $this->conn->prepare('SELECT i.domain FROM _instance i 
            INNER JOIN _instance_domain d ON d.deleted = FALSE AND i.instance_id = d.instance_id
            WHERE d.domain = ? AND d.deleted = false;');
        $statement->execute(array($domain));
        $rows = $statement->fetchAll(5);
        $statement = null;
        if (count($rows) > 0) {
            return $rows[0]->domain;
        }

        return null;
    }

    // TODO regarding the menus you (also) need to take into account online and deleted flags
    public function fetchMenuItems(int $menu_id, int $menu_item_id = 0, int $nested_level = 0): array
    {
        $items = array();
        // for nested_level 0 you get the subitems by menu_id, higher levels are subitems from subitems (obviously)
        if ($nested_level === 0) {
            $statement = $this->conn->prepare('
                SELECT i.menu_item_id, i.act, i.title, i.css_class, i.content, i.online 
                FROM cms_menu_item i INNER JOIN cms_menu_item_x_menu x ON i.menu_item_id = x.sub_menu_item_id 
                WHERE x.menu_id = ? AND i.deleted = FALSE AND x.deleted = FALSE 
                ORDER BY x.o;
            ');
            $parameters = array($menu_id);
        } else { // higher levels, get the items by menu_item_id, for the specific menu_id
            $statement = $this->conn->prepare('
                SELECT i.menu_item_id, i.act, i.title, i.css_class, i.content, i.online 
                FROM cms_menu_item i INNER JOIN cms_menu_item_x_menu_item x ON i.menu_item_id = x.sub_menu_item_id 
                WHERE x.menu_id = ? AND x.menu_item_id = ? AND i.deleted = FALSE AND x.deleted = FALSE 
                ORDER BY x.o;
            ');
            $parameters = array($menu_id, $menu_item_id);
        }
        try {
            $statement->execute($parameters);
        } catch (\Exception $e) {
            $statement = null;
            $this->handleErrorAndStop($e);
            /*var_dump($statement->queryString);
            var_dump($menu_or_menu_item_id);
            die($e);*/
        }
        $rows = $statement->fetchAll(5);
        $statement = null;
        ++$nested_level;
        foreach ($rows as $key => $row) {
            try { // normalize the stuff from the act field
                if ($act = json_decode($row->act)) {
                    foreach ($act as $name => $prop) {
                        $row->{$name} = $prop;
                    }
                } else {
                    $this->addMessage(
                        sprintf(__('json_decode error on menu item %s', 'peatcms'),
                            \var_export($row->title, true)), 'warn');
                }
            } catch (\Exception) {
                // fail silently
            }
            if (($sub_items = $this->fetchMenuItems($menu_id, $row->menu_item_id, $nested_level))) {
                $row->__menu__ = array('__item__' => $sub_items);
                $row->item_count = count($sub_items);
                $row->item_index = $key;
            }
            $row->nest_level = $nested_level;
            $items[] = $row;
        }

        return $items;
    }

    public function fetchInstanceMenus(int $instance_id = 0): array
    {
        $menus = array();
        $rows = $this->fetchRows('cms_menu', array('menu_id', 'title', 'slug'), array('instance_id' => $instance_id));
        foreach ($rows as $key => $row) {
            $menus[$row->slug] = array(
                '__menu__' => array( // menu is a complex tag, hence you need this as an array
                    '__item__' => ($menu_items = $this->fetchMenuItems($row->menu_id)),
                ),
                'title' => $row->title,
                'slug' => $row->slug,
                'item_count' => count($menu_items),
            );
        }

        return $menus;
    }

    /**
     * Gets the list with the specified name for this session_id, creates it first if necessary
     *
     * @param string $name
     * @param int $session_id
     * @param int $user_id @since 0.7.9
     * @return \stdClass the row from the _list
     * @since 0.5.1
     */
    public function fetchShoppingList(string $name, int $session_id, int $user_id): \stdClass
    {
        $data = array(
            'instance_id' => Setup::$instance_id,
            'session_id' => $session_id,
            'user_id' => $user_id,
            'name' => $name,
            'deleted' => false,
        );

        // create a shoppinglist if not already exists
        if (0 === count($rows = $this->fetchRows('_shoppinglist', array('*'), $data))) {
//insert into posts (id, title, body)
// select 1, 'First post', 'Awesome'
// where not exists (
//  select null from posts
//  where (title, body) = ('First post', 'Awesome')
// )
            // the insert must only happen if not just executed by another thread already, hence the exists
            $column_list = implode(', ', array_keys($data));
            $statement = $this->conn->prepare("
                INSERT INTO _shoppinglist ($column_list) 
                SELECT :instance_id, :session_id, :user_id, :name, :deleted
                    WHERE NOT EXISTS(
                        SELECT 1 FROM _shoppinglist
                                 WHERE ($column_list) = (:instance_id, :session_id, :user_id, :name, :deleted)
                    ) RETURNING *;");
            $statement->bindValue(':instance_id', $data['instance_id']);
            $statement->bindValue(':session_id', $data['session_id']);
            $statement->bindValue(':user_id', $data['user_id']);
            $statement->bindValue(':name', $data['name']);
            $statement->bindValue(':deleted', '0');
            $statement->execute();
            $rows = $statement->fetchAll(5);
            $statement = null;
            if (0 === count($rows)) { // not inserted (this time), probably already exists then
                $rows = $this->fetchRows('_shoppinglist', array('*'), $data);
                if (0 === count($rows)) {
                    $this->handleErrorAndStop(
                        'Unable to create shoppinglist ' . var_export($data, true),
                        __('Could not get shoppinglist', 'peatcms')
                    );
                }
            }
        }

        if (1 !== count($rows)) {
            // order rows by shoppinglist_id ascending so the oldest one perseveres
            // (in case it already has something attached)
            usort($rows, function ($a, $b) {
                return $a->shoppinglist_id <=> $b->shoppinglist_id;
            });
            // build informative error message, and delete the superfluous ones (row > 1)
            $lists = array();
            foreach ($rows as $index => $row) {
                $deleted = 'NO';
                if (0 !== $index) {
                    $deleted = $this->deleteRowImmediately('_shoppinglist', $row->shoppinglist_id);
                }
                $items = $this->fetchShoppingListRows($row->shoppinglist_id);
                if (0 < ($count = count($items))) {
                    $lists[var_export($row, true)] = "$count items DEL: $deleted";
                } else {
                    $lists[var_export($row, true)] = "Empty DEL: $deleted";
                }
            }
            // report
            $this->addError(sprintf("Too many shoppinglists.\n%s", var_export($lists, true)));
//            $count = $this->deleteRowWhereAndReturnAffected('_shoppinglist', $data);
//            $this->handleErrorAndStop(
//                sprintf("Deleted $count shoppinglist entries. %s", var_export($lists, true)),
//                __('Could not get shoppinglist', 'peatcms')
//            );
        }

        return $rows[0];
    }

    /**
     * @param int $shoppinglist_id
     * @return array with \stdClass row objects containing the column values from _list_variant
     * @since 0.5.1 @improved 0.11.0
     */
    public function fetchShoppingListRows(int $shoppinglist_id): array
    {
        $statement = $this->conn->prepare('
            SELECT variant_id, variant_slug, quantity, price, price_from, o, deleted
            FROM _shoppinglist_variant WHERE shoppinglist_id = ?
            ORDER BY o, shoppinglist_variant_id DESC
        ');
        $statement->execute(array($shoppinglist_id));
        $rows = $statement->fetchAll(5);
        $variant_ids = array();
        // the rows can be double when they are just being updated, keep the most recent for each variant_id
        foreach ($rows as $key => $row) {
            if (true === in_array($row->variant_id, $variant_ids, true)) {
                unset($rows[$key]);
            } else {
                $variant_ids[] = $row->variant_id;
            }
        }

        return $rows; // array_filter?
    }

    /**
     * @param int $shoppinglist_id
     * @param array $rows
     */
    public function upsertShoppingListRows(int $shoppinglist_id, array $rows): void
    {
        // insert current rows
        $placeholders = array();
        $values = array();
        foreach ($rows as $index => $row) {
            if (false === $row->deleted) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)'; // shoppinglist_id, variant_id, variant_slug, quantity, price, price_from, o
                $values[] = $shoppinglist_id;
                $values[] = $row->variant_id;
                $values[] = $row->variant_slug;
                $values[] = $row->quantity;
                $values[] = $row->price;
                $values[] = $row->price_from;
                $values[] = $index;
            }
        }
        if (count($placeholders) > 0) {
            $placeholders_str = implode(', ', $placeholders);
            $statement = $this->conn->prepare("
                INSERT INTO _shoppinglist_variant (shoppinglist_id, variant_id, variant_slug, quantity, price, price_from, o) 
                VALUES $placeholders_str RETURNING shoppinglist_variant_id;
            ");
            $statement->execute($values);
            if (($shoppinglist_variant_id = $statement->fetchColumn(0))) {
                // delete older rows
                $statement = $this->conn->prepare('DELETE FROM _shoppinglist_variant WHERE shoppinglist_id = ? AND shoppinglist_variant_id < ?;');
                $statement->execute(array($shoppinglist_id, $shoppinglist_variant_id));
            }
        } else {
            // delete any lingering rows
            $statement = $this->conn->prepare('DELETE FROM _shoppinglist_variant WHERE shoppinglist_id = ?;');
            $statement->execute(array($shoppinglist_id));
        }
        // ok done
        $statement = null;
    }

    /**
     * deletes shoppinglist rows (the variants) that no longer belong to a shoppinglist
     * @return int rows affected
     */
    public function jobDeleteOrphanedShoppinglistVariants(): int
    {
        $statement = $this->conn->prepare('DELETE FROM _shoppinglist_variant v WHERE NOT EXISTS(SELECT 1 FROM _shoppinglist l WHERE l.shoppinglist_id = v.shoppinglist_id);');
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    /**
     * @return int affected
     */
    public function jobDeleteOrphanedSessionVars(): int
    {
        $statement = $this->conn->prepare('DELETE FROM _sessionvars v WHERE NOT EXISTS(SELECT 1 FROM _session s WHERE session_id = v.session_id);');
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    /**
     * @param int $current_session_id the session_id holding any current shoppinglists
     * @param int $to_user_id the user_id this session actually is maybe holding older shoppinglists
     * @return int ‘affected’: how many rows were updated in the process
     */
    public function mergeShoppingLists(int $current_session_id, int $to_user_id): int
    {
        $affected = 0;
        // all rows of shopping lists with this session_id must be moved to the corresponding (by NAME) user_id list
        $sessionlists = $this->fetchRows('_shoppinglist',
            array('shoppinglist_id', 'name', 'remarks_user', 'remarks_admin'),
            array('session_id' => $current_session_id));
        foreach ($sessionlists as $index => $sessionlist) {
            // if there is no user list with this name, just move this one over
            if (0 === count($userlists = $this->fetchRows('_shoppinglist',
                    array('shoppinglist_id', 'remarks_user', 'remarks_admin'),
                    array('user_id' => $to_user_id, 'name' => $sessionlist->name)))) {
                // update the current session one to the $to_user_id
                $this->updateRowAndReturnSuccess('_shoppinglist',
                    array('session_id' => 0, 'user_id' => $to_user_id),
                    $sessionlist->shoppinglist_id);
            } else { // there is a user list already...
                if (1 === count($userlists)) {
                    $userlist = $userlists[0];
                    // check how many are in the user list, to report later to the client
                    $statement = $this->conn->prepare('SELECT COUNT(1) FROM _shoppinglist_variant WHERE shoppinglist_id = :userlist_id');
                    $statement->bindValue(':userlist_id', $userlist->shoppinglist_id);
                    $statement->execute();
                    $current_count = $statement->fetchColumn(0);
                    // fork all the _shoppinglist_variant rows over
                    // special case: when the variant already exists in the list, we just want to update the quantity
                    // to the current / latest value so we remove those from the receiving (userlist) shoppinglist first
                    $this->conn->beginTransaction();
                    $statement = $this->conn->prepare('DELETE FROM _shoppinglist_variant WHERE shoppinglist_id = :userlist_id AND variant_id IN(SELECT variant_id FROM _shoppinglist_variant WHERE shoppinglist_id = :sessionlist_id);');
                    $statement->bindValue(':userlist_id', $userlist->shoppinglist_id);
                    $statement->bindValue(':sessionlist_id', $sessionlist->shoppinglist_id);
                    $statement->execute();
                    // subtract the deleted ones so you know how many were actually added
                    $current_count -= $statement->rowCount();
                    // now update the session lists to integrate with the userlist
                    $statement = $this->conn->prepare('UPDATE _shoppinglist_variant SET shoppinglist_id = :userlist_id WHERE shoppinglist_id = :sessionlist_id;');
                    $statement->bindValue(':userlist_id', $userlist->shoppinglist_id);
                    $statement->bindValue(':sessionlist_id', $sessionlist->shoppinglist_id);
                    $statement->execute();
                    $affected += $statement->rowCount();
                    if (true === $this->conn->commit() and $current_count !== 0) {
                        $this->addMessage(sprintf(
                        //# TRANSLATORS %1 is the number of rows and %2 the name of the shoppinglist (e.g. cart)
                            __('%1$s rows belonging to your account have been added to %2$s', 'peatcms')
                            , $current_count, $sessionlist->name));
                    }
                    // update the remarks as well
                    // TODO the remarks are stored in a session, so they don’t carry over at the moment
                    /*if (($remarks = trim($sessionlist->remarks_user)) !== '') {
                        if (($remarks2 = trim($userlist->remarks_user)) !== '') $remarks .= "\n" . $remarks2;
                        if (true === $this->updateRowAndReturnSuccess('_shoppinglist',
                                array('remarks_user' => $remarks),
                                array('shoppinglist_id' => $userlist->shoppinglist_id))) $affected += 1;
                    }*/
                } else { // this should never happen
                    $this->handleErrorAndStop(
                        'Multiple user lists found while merging shoppinglists',
                        sprintf(__('An error occurred in %s.', 'peatcms'), $sessionlist->name)
                    );
                }
            }
        }

        return $affected;
    }

    public function insertAdmin(string $email, string $hash, int $client_id, int $instance_id = 0): ?int
    {
        $email = mb_strtolower($email);
        if ($this->rowExists('_admin', array('email' => $email))) { // already exists
            $this->addMessage(sprintf(__('Admin with email %s already exists.', 'peatcms'), $email));
        } else { // insert the admin
            return $this->insertRowAndReturnLastId('_admin', array(
                'nickname' => $email,
                'email' => $email,
                'client_id' => $client_id,
                'instance_id' => $instance_id,
                'password_hash' => $hash,
            ));
        }

        return null;
    }

    public function insertUserAccount(string $email, string $hash): ?int
    {
        $email = mb_strtolower($email);
        if ($this->rowExists('_user', array(
            'email' => $email,
            'is_account' => true,
            'instance_id' => Setup::$instance_id,
        ))) { // already exists
            $this->addMessage(sprintf(__('User with email %s already exists.', 'peatcms'), $email));
        } else { // insert the user as an account
            return $this->insertRowAndReturnLastId('_user', array(
                'nickname' => $email,
                'email' => $email,
                'password_hash' => $hash,
                'is_account' => true,
                'instance_id' => Setup::$instance_id,
            ));
        }

        return null;
    }

    /**
     * @param string $email
     * @param string $hash
     * @return bool
     * @since 0.7.9
     */
    public function updateUserPassword(string $email, string $hash): bool
    {
        $email = mb_strtolower($email);
        $affected = $this->updateColumnsWhere('_user', array(
            'password_hash' => $hash,
        ), array(
            'email' => $email,
            'is_account' => true,
            'instance_id' => Setup::$instance_id,
        ));
        if ($affected === 1) return true;
        if ($affected > 1) $this->handleErrorAndStop(sprintf('User password updated %s rows', $affected));

        return false;
    }

    public function insertInstanceDomain(array $data): ?int
    {
        if (isset($data['domain'])) {
            if ($this->rowExists('_instance_domain', $data)) {
                $keys = $this->updateRowsWhereAndReturnKeys('_instance_domain',
                    array('deleted' => false),
                    $data);
                if (count($keys) === 1) {
                    return $keys[0];
                } else {
                    return null;
                }
            }
        }

        return $this->insertRowAndReturnLastId('_instance_domain', $data);
    }

    public function insertInstance(string $domain, string $name, int $client_id): ?int
    {
        if ($this->rowExists('_instance', array('domain' => $domain))) {
            // TODO this goes wrong with multiple clients
            if ($this->rowExists('_instance', array('domain' => $domain, 'deleted' => false))) {
                $this->addMessage(__(sprintf('Domain %s already taken', $domain), 'peatcms'));
            } elseif ($arr = $this->updateRowsWhereAndReturnKeys('_instance',
                array('deleted' => false),
                array('client_id' => $client_id, 'domain' => $domain),
            )) {
                return $arr[0];
                // for _instance table key and id are the same
            }
        } else {
            return $this->insertRowAndReturnLastId('_instance', array(
                'client_id' => $client_id,
                'name' => $name,
                'domain' => $domain,
                'theme' => 'peatcms',
            ));
        }

        return null;
    }

    /**
     * Inserts default template with name $name for instance with $instance_id
     * @param string $name
     * @param int $instance_id
     * @return int|null
     */
    public function insertTemplate(string $name, int $instance_id): ?int
    {
        return $this->insertRowAndReturnLastId('_template', array(
            'instance_id' => $instance_id,
            'name' => $name,
            'nested_max' => 2, // @since 0.7.1 better default value than 1
            'nested_show_first_only' => true,
        ));
    }

    /**
     * Set the homepage_id of an instance, so the instance knows what to serve when root is requested
     *
     * @param int $instance_id the id of the instance the homepage will be set for
     * @param string $slug slug of a page that belongs to the same instance
     * @return \stdClass|null returns the updated instance, null when failed
     */
    public function setHomepage(int $instance_id, string $slug): ?\stdClass
    {
        if ($row = $this->fetchElementIdAndTypeBySlug($slug)) {
            if ('page' === $row->type_name) {
                if ($this->updateInstance($instance_id, array('homepage_id' => $row->id))) {
                    return $this->fetchInstanceById($instance_id); // return the updated instance
                } else {
                    $this->addError('Could not update instance');
                }
            } else {
                $this->addMessage(
                    sprintf(__('Only pages can be set to homepage, received: %s', 'peatcms'),
                        var_export($row->type_name, true)),
                    'warn'
                );
            }
        } else {
            $this->addMessage(__('Only pages can be set to homepage', 'peatcms'), 'warn');
        }

        return null;
    }

    public function updateInstance(int $instance_id, array $data): bool
    {
        // assume correct data
        return $this->updateRowAndReturnSuccess('_instance', $data, $instance_id);
    }

    public function insertClient(string $name): ?int
    {
        return $this->insertRowAndReturnLastId('_client', array(
            'name' => $name,
        ));
    }

    /**
     * @param Type $peat_type must be a valid element type
     * @param array $data must contain at least 'title' and 'content'
     * @return int|null the new id as integer when insert in database was successful, null otherwise
     */
    public function insertElement(Type $peat_type, array $data): ?int
    {
        if ('menu_item' !== $peat_type->tableName() && false === isset($data['slug'])) {
            $data['slug'] = $peat_type->typeName();
        } // default
        if (false === isset($data['instance_id'])) {
            $data['instance_id'] = Setup::$instance_id;
        }

        return $this->insertRowAndReturnLastId($peat_type->tableName(), $data);
    }

    /**
     * ‘job’ functions are not instance specific, they cover the WHOLE database
     * @param int $interval the minimum number of minutes an item must have been deleted before it's purged
     * @return int total the number of items purged
     * @since 0.5.7
     */
    public function jobPurgeDeleted(int $interval): int
    {
        // find all tables that have a column 'deleted'
        // those tables must also have the standard columns (date_updated, etc.)
        $db_schema = $this->db_schema;
        $statement = $this->conn->prepare("
            SELECT t . table_name FROM information_schema . tables t
            INNER JOIN information_schema . columns c ON c . table_name = t . table_name and c . table_schema = :schema
            WHERE c . column_name = 'deleted' and t . table_schema = :schema and t . table_type = 'BASE TABLE'
        ");
        $statement->bindValue(':schema', $this->db_schema);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;
        $total_count = 0;
        // for all the tables, delete items that have TRUE for the 'deleted' column and have been updated > $interval minutes ago
        foreach ($rows as $key => $row) {
            $table_name = $row->table_name;
            $statement = $this->conn->prepare("
                DELETE FROM \"$db_schema\".\"$table_name\"
                WHERE deleted = TRUE AND date_updated < NOW() - interval '$interval minutes'
            ");
            $statement->execute();
            $total_count += $statement->rowCount();
            $statement = null;
        }
        $rows = null;

        return $total_count;
    }

    /**
     * @return int the number of lockers that has been emptied
     * @since 0.7.2
     */
    public function jobEmptyExpiredLockers(): int
    {
        $statement = $this->conn->prepare('DELETE FROM _locker WHERE valid_until < NOW();');
        $statement->execute();
        $count = $statement->rowCount();
        $statement = null;

        return $count;
    }

    /**
     * physically remove templates from disk that are no longer in the database
     * @return string
     * @since 0.7.1
     */
    public function jobCleanTemplateFolder(): string
    {
        // get all the id’s of templates in the db
        $ids = array();
        $file_names = '';
        $statement = $this->conn->prepare('SELECT template_id FROM _template');
        $statement->execute();
        while (($row = $statement->fetch(5))) {
            $ids[$row->template_id] = true;
        }
        // load the id’s of templates in the folder
        if (($files = scandir("{$this->cache_folder}templates/"))) {
            $files = array_diff($files, array('..', '.'));
            foreach ($files as $index => $file_name) {
                if (false === isset($ids[intval(explode('.', $file_name)[0])])) {
                    unlink("{$this->cache_folder}templates/$file_name");
                    $file_names .= $file_name . ', ';
                }
            }
        }

        return $file_names .= 'done';
    }

    public function jobDeleteOldSessions(int $interval_in_days = 30): int
    {
        $statement = $this->conn->prepare("
            DELETE FROM _session WHERE admin_id = 0 AND user_id = 0 AND date_accessed < NOW() - interval '$interval_in_days days';
        ");
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    public function jobDeleteOldHistory(int $interval_in_days = 30): int
    {
        $statement = $this->conn->prepare("
            DELETE FROM _history WHERE date_created < NOW() - interval '$interval_in_days days' AND table_column <> 'slug'
        ");
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    public function jobDeleteOrphanedLists(): int
    {
        $statement = $this->conn->prepare('
            DELETE FROM _shoppinglist l WHERE l.user_id = 0 AND NOT EXISTS(SELECT 1 FROM _session s WHERE s.session_id = l.session_id);
        ');
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    public function jobDeleteOrphanedCiAi(): int
    {
        $affected = 0;
        foreach (self::TYPES_WITH_CI_AI as $index => $type_name) {
            $statement = $this->conn->prepare("
                DELETE FROM _ci_ai 
                WHERE type_name = '$type_name' 
                  AND NOT EXISTS(SELECT 1 FROM cms_$type_name WHERE {$type_name}_id = _ci_ai.id);
            ");
            $statement->execute();
            $affected += $statement->rowCount();
        }
        $statement = null;

        return $affected;
    }

    /**
     * @param Type $which must be a valid element type
     * @param array $data one or more key=>value pairs that are the columns with their data to insert
     * @param int $id the id of the element
     * @return bool Returns true when update succeeded, false otherwise
     */
    public function updateElement(Type $which, array $data, int $id): bool
    {
        // we can assume there is correct data here
        return $this->updateRowAndReturnSuccess($which->tableName(), $data, $id);
    }

    public function updateElementsWhere(Type $which, array $data, array $where): array
    {
        if (true === isset($data['slug'])) {
            $this->addError('->updateElementsWhere() cannot update column `slug`');

            return array();
        }

        return $this->updateRowsWhereAndReturnKeys($which->tableName(), $data, $where);
    }

    public function insertSession(string $token, string $ip_address, string $user_agent): ?string
    {
        return (string)$this->insertRowAndReturnLastId('_session', array(
            'token' => $token,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'instance_id' => Setup::$instance_id,
        ));
    }

    public function registerSessionAccess(string $token, string $ip_address = null): bool
    {
        if (null === $ip_address) {
            $statement = $this->conn->prepare('UPDATE _session SET date_accessed = NOW() WHERE token = ?;');
            $statement->execute(array($token));
        } else {
            $statement = $this->conn->prepare(
                'UPDATE _session SET date_accessed = NOW(), date_updated = NOW(), ip_address = ?, reverse_dns = NULL WHERE token = ?;');
            $statement->execute(array($ip_address, $token));
        }
        $count = $statement->rowCount();
        $statement = null;

        return ($count === 1);
    }

    /**
     * @param int $session_id
     * @param string $name the name of the var
     * @param \stdClass $var must hold ->value and ->times, optional ->delete will remove it
     * @return \stdClass|null
     * @since 0.5.13 update only per var and take into account the ‘times’
     */
    public function updateSessionVar(int $session_id, string $name, \stdClass $var): ?\stdClass
    {
        // the var is an object holding the value, times and an optional delete flag
        // scenario: you want to update the value, but with the same times, that will make deletion impossible because of the times
        // and also because of the times, we don’t know which value is the newest, you can't use timestamp value because different
        // requests can try to update on the exact same timestamp (has been tested)
        // so we will use sessionvars_id for that, and hopefully not run out anytime soon...
        if (false === isset($var->delete)) {
            // we need to make sure there’s always the most current value available, hence we insert first and delete older ones later
            $statement = $this->conn->prepare(
                'INSERT INTO _sessionvars (session_id, name, value, times) VALUES (?, ?, ?, ?) RETURNING sessionvars_id;');
            $statement->execute(array(
                $session_id,
                $name,
                json_encode($var->value),
                $var->times,
            ));
            if (($sessionvars_id = $statement->fetchColumn(0))) {
                // delete any previous values
                $statement = $this->conn->prepare(
                    'DELETE FROM _sessionvars WHERE session_id = ? AND name = ? AND sessionvars_id < ?;');
                $statement->execute(array($session_id, $name, $sessionvars_id));
            }
            $statement = null;

            // if you are here we are going to assume it worked
            return $var;
        } else {
            $statement = $this->conn->prepare('DELETE FROM _sessionvars WHERE session_id = ? AND name = ?;');
            $statement->execute(array($session_id, $name));
            $statement = null;

            return null; // success... $var is gone now
        }
    }

    /**
     * Tries to clear a slug for use by an instance, slugs need to be unique
     * If it fails execution is halted
     *
     * @param ?string $slug the slug you want to try
     * @param ?Type $element optional default null the element you check the slug for
     * @param int $id optional the id of the element you're checking the slug for
     * @param int $depth optional default 0 keeps count of recursiveness, function fails at 100 tries
     * @return string the cleared slug safe to use, per instance, with a prefix index if necessary
     */
    public function clearSlug(string $slug = null, Type $element = null, int $id = 0, int $depth = 0): string
    {
        if (null === $element) $element = new Type('search');
        if (null === $slug || '' === $slug) $slug = "{$element->typeName()}-$id";
        if ($depth === 0) {
            // make it a safe slug (no special characters)
            $slug = Help::slugify($slug);
        }
        $slug = substr($slug, 0, 127);
        $instance_id = Setup::$instance_id;
        if (false === Help::obtainLock("clearSlug-$instance_id")) {
            $this->handleErrorAndStop("Could not obtain lock to clear slug $slug for instance $instance_id", 'Operation is locked');
        }
        $rows = $this->getTablesWithSlugs();
        $found = false;
        // loop through the tables to see if any exist that have this slug (that are not this $element and $id)
        foreach ($rows as $key => $row) {
            $table_name = $row->table_name;
            if ($table_name !== $element->tableName()) {
                if ($this->rowExists($table_name, array('slug' => $slug, 'instance_id' => $instance_id))) $found = true;
            } else { // find out if this slug belongs to this id, because if it doesn't, it's also $found
                $table = new Table($this->getTableInfo($table_name));
                $column_name = $table->getInfo()->getIdColumn()->getName();
                $statement = $this->conn->prepare("
                    SELECT $column_name AS id FROM $table_name 
                    WHERE slug = ? AND instance_id = $instance_id;
                ");
                $statement->execute(array($slug));
                $rows2 = $statement->fetchAll(0);
                $statement = null;
                if (1 === ($count2 = count($rows2))) {
                    if ($rows2[0][0] !== $id) $found = true;
                } elseif ($count2 > 1) {
                    $found = true;
                }
            }
            if ($found === true) break;
        }
        if ($found === true) {
            // update slug to 1-slug (2-slug etc) and check again
            if ($depth === 0) {
                return $this->clearSlug("1-$slug", $element, $id, 1);
            } else {
                if ($depth === 100) $this->handleErrorAndStop('Clear slug loops >100, probably error');
                // remove current depth number
                $slug = substr($slug, (strlen((string)$depth) + 1));
                ++$depth; // increase depth to try again

                return $this->clearSlug("$depth-$slug", $element, $id, $depth);
            }
        }

        Help::releaseLock("clearSlug-$instance_id");

        // return the cleared slug, since it hasn't been $found apparently
        return $slug;
    }

    public function getTablesWithSlugs(): array
    {
        return $this->withApplicationCaching('var.tables_with_slugs', function () {
            // get all tables that contain a slug column, except tables where slug is used as foreign key
            $statement = $this->conn->prepare("
                SELECT t.table_name FROM information_schema.tables t
                INNER JOIN information_schema.columns c ON c.table_name = t.table_name AND c.table_schema = :schema
                WHERE c.column_name = 'slug' AND t.table_schema = :schema AND t.table_type = 'BASE TABLE'
                AND t.table_name <> '_cache' AND t.table_name <> '_stale' AND t.table_name <> '_ci_ai'
            ");
            $statement->bindValue(':schema', $this->db_schema);
            $statement->execute();
            $tables = $statement->fetchAll(5);
            $statement = null;

            return $tables;
        });
    }

    public function fetchTablesToExport(bool $include_user_data = false): array
    {
        $never = array('_cache', '_stale', '_ci_ai', '_admin', '_client', '_locker', '_system', '_session', '_sessionvars', '_instance_domain', '_order_number');
        if (false === $include_user_data) {
            $never = array_merge($never, array('_payment_status_update', '_address', '_shoppinglist', '_shoppinglist_variant', '_order', '_order_number', '_order_variant', '_user'));
        }
        $tables_not_exported = implode('\',\'', $never);
        // get all tables that contain a slug column, except tables where slug is used as foreign key
        $statement = $this->conn->prepare("
            SELECT DISTINCT t.table_name FROM information_schema.tables t
            INNER JOIN information_schema.columns c ON c.table_name = t.table_name AND c.table_schema = :schema
            WHERE t.table_schema = :schema AND t.table_type = 'BASE TABLE'
            AND t.table_name NOT IN('$tables_not_exported');
        ");
        $statement->bindValue(':schema', $this->db_schema);
        $statement->execute();
        $tables = $statement->fetchAll(5);
        $statement = null;

        return $tables;
    }

    public function queryRowsForExport(string $table_name, int $instance_id): \PDOStatement
    {
        if (
            false !== ($is_x_table = strpos($table_name, '_x_'))
            || '_order_variant' === $table_name
            || '_shoppinglist_variant' === $table_name
        ) {
            if ($is_x_table) {
                $split = explode('_x_', $table_name);
                $foreign_part = str_replace('properties', 'property', $split[1]);
                $foreign_table = "cms_$foreign_part";
                $foreign_id = "{$foreign_part}_id";
            } else {
                $foreign_table = str_replace('_variant', '', $table_name);
                $foreign_id = substr("{$foreign_table}_id", 1);
            }
            $statement = $this->conn->prepare("SELECT * FROM $table_name WHERE $foreign_id IN
                    (SELECT $foreign_id FROM $foreign_table WHERE instance_id = ?);");
        } else {
            $statement = $this->conn->prepare("SELECT * FROM $table_name WHERE instance_id = ?;");
        }
        $statement->execute(array($instance_id));

        return $statement;
    }

    /**
     * @param string $table_name holding correct table_name, fatal error is thrown when table_name is wrong
     * @param array $columns
     * @param array $where
     * @param bool $single true when 1 return row is expected, false to return an array with every returned row (0 or more)
     * @param int $limit
     * @return array|\stdClass|null null when failed, row object when single = true, array with row objects otherwise
     * @since 0.0.0
     */
    private function fetchRows(string $table_name, array $columns, array $where = array(), bool $single = false, int $limit = 1000): array|\stdClass|null
    {
        // TODO when admin chosen online must be taken into account
        // TODO use data_popvote to order desc when available
        // $columns is an indexed array holding column names as strings,
        // $where is an array with key => value pairs holding column_name => value
        $table_info = $this->getTableInfo($table_name); // fatal error is thrown when the table_name is wrong
        $table = new Table($table_info);
        // normalize / check columns and where data
        if (count($columns) !== 0) {
            if ($columns[0] === '*') { // get all the columns
                $columns = $table_info->getColumnNames();
            }
            $columns = $table->getColumnsByName($columns);
        } else {
            $columns = array('names' => array());
        }
        $columns['names'][] = "{$table_info->getIdColumn()} AS id"; // always get the id of the table, as ->id
        $columns['names'][] = "'$table_name' AS table_name"; // always return the table_name as well
        $where = $table->formatColumnsAndData($where);
        // @since 0.7.9 source of potential bugs, the changing of a where clause, will cause a fatal error from now on
        if (0 !== count($where['discarded'])) {
            $this->handleErrorAndStop('->fetchRows discarded columns in where clause');
        }
        // build the query
        ob_start();
        echo 'SELECT ';
        echo implode(', ', $columns['names']);
        echo ' FROM ';
        echo $table_name;
        if ('' !== ($where_statement = implode(' AND ', $where['parameterized']))) {
            echo ' WHERE ';
            echo $where_statement;
        }
        if (in_array('o', $columns['names'])) {
            echo ' ORDER BY o';
        } elseif ('_template' !== $table_name && $table_info->getColumnByName('date_published')) {
            if (defined('ADMIN') && false === ADMIN) {
                echo ' AND (date_published IS NULL OR date_published < NOW() - INTERVAL \'5 minutes\')'; // allow a few minutes for the cache to update
            }
            echo ' ORDER BY date_published DESC';
        } elseif ('_order' !== $table_name && in_array('date_updated', $columns['names'])) {
            echo ' ORDER BY date_updated DESC';
        } elseif (in_array('date_created', $columns['names'])) {
            echo ' ORDER BY date_created DESC';
        }
        echo ' LIMIT ', $limit, ';';
        // prepare the statement to let it execute as fast as possible
        $statement = $this->conn->prepare(ob_get_clean());
        $statement->execute($where['values']);
        $rows = $statement->fetchAll(5);
        $statement = null;
        if (true === $single) {
            if (($count = count($rows)) === 1) {
                return $rows[0];
            } else {
                if ($count > 1) {
                    $this->addError(sprintf('Query DB->fetchRow() returned %d rows', count($rows)));
                }

                return null;
            }
        } else {
            return $rows;
        }
    }

    /**
     * @param string $table_name holding correct table name
     * @param array $columns
     * @param array $where
     * @return \stdClass|null null when failed, normalized row object when succeeded
     * @since 0.0.0
     */
    private function fetchRow(string $table_name, array $columns, array $where): ?\stdClass
    {
        return $this->fetchRows($table_name, $columns, $where, true);
    }

    /**
     * @param string $table_name
     * @param $key
     * @return \stdClass|null
     */
    public function selectRow(string $table_name, $key): ?\stdClass
    {
        $table_info = $this->getTableInfo($table_name); // fatal error is thrown when table_name is wrong
        // TODO use internal functionality, the problem is that you need to ignore instance_id sometimes
        // or we use it like this, but then with some checks
        $primary_key_column_name = $table_info->getPrimaryKeyColumn()->getName();
        $sql = "SELECT * FROM $table_name WHERE $primary_key_column_name = ?;";
        $statement = $this->conn->prepare($sql);
        $statement->execute(array($key));
        $rows = $statement->fetchAll(5);
        $statement = null;
        if (count($rows) === 1) {
            return $rows[0];
        }

        return null;
        //return $this->fetchRow($table_name, array('*'), array($table_info->getIdColumn()->getName() => $id));
    }

    /**
     * wrapper for private method updateRowsWhereAndReturnKeys
     *
     * @param string $table_name
     * @param array $data
     * @param array $where
     * @return int rows affected
     */
    public function updateColumnsWhere(string $table_name, array $data, array $where): int
    {
        return count($this->updateRowsWhereAndReturnKeys($table_name, $data, $where));
    }

    /**
     * Wrapper for private method updateRowAndReturnSuccess
     *
     * @param string $table_name
     * @param array $data
     * @param $key
     * @return bool success
     */
    public function updateColumns(string $table_name, array $data, $key): bool
    {
        return $this->updateRowAndReturnSuccess($table_name, $data, $key);
    }

    /**
     * @param string $table_name
     * @param array $data
     * @param bool $is_bulk when true prevents caching adding to history
     * @return int|string|null
     * @since 0.6.2, wrapper for insertRowAndReturnLastId
     */
    public function insertRowAndReturnKey(string $table_name, array $data, bool $is_bulk = false): int|string|null
    {
        return $this->insertRowAndReturnLastId($table_name, $data, $is_bulk);
    }

    private function insertRowAndReturnLastId(string $table_name, array $col_val = null, bool $is_bulk = false): int|string|null
    {
        $table = new Table($this->getTableInfo($table_name));
        // reCacheWithWarmup the slug, maybe used for a search page
        if (isset($col_val['slug'])) {
            $new_slug = $this->clearSlug($col_val['slug']); // (INSERT) needs to be unique for any entry
            $col_val['slug'] = $new_slug;
        }
        $data = $table->formatColumnsAndData($col_val, true);
        if (0 === ($column_count = count($data['columns']))) return null;
        // maybe (though unlikely) the slug was provided in $col_val but not actually in the table
        if (true === in_array('slug', $data['discarded'])) unset($new_slug);
        $column_list = implode(',', $data['columns']);
        $in_placeholders = str_repeat('?,', $column_count - 1); // NOTE you need one more ? at the end of this
        // update
        try {
            $primary_key_column_name = $table->getInfo()->getPrimaryKeyColumn()->getName();
            // prepare and execute
            $statement = $this->conn->prepare("INSERT INTO $table_name ($column_list) 
                VALUES ($in_placeholders?) RETURNING $primary_key_column_name;");
            $statement->execute($data['values']);
            $rows = $statement->fetchAll(5);
            $statement = null;
            if (1 === count($rows)) {
                $row_id = $rows[0]->{$primary_key_column_name};

                if (false === $is_bulk) {
                    if (isset($new_slug)) $this->reCacheWithWarmup($new_slug);
                    $this->addToHistory($table, $row_id, $col_val, true);
                }

                return $row_id;
            } elseif (0 === count($rows)) {
                return null;
            } else { // this should be impossible
                $this->handleErrorAndStop(new \Exception("Found more than one primary key for $table_name"),
                    __('Database error.', 'peatcms'));

                return null;
            }
        } catch (\Exception $e) {
            $this->addError($e->getMessage());

            if ($e instanceof \PDOException && '23505' === $e->getCode()) {
                $this->healTable($table_name);
            }

            return null;
        }
    }

    /**
     * @param string $table_name
     * @param array $col_val
     * @param $key
     * @return bool
     */
    private function updateRowAndReturnSuccess(string $table_name, array $col_val, $key): bool
    {
        $table_info = $this->getTableInfo($table_name); // fatal error is thrown when table_name is wrong
        $table = new Table($table_info);
        $key_column_name = $table_info->getPrimaryKeyColumn()->getName();
        // reCacheWithWarmup the old slug, clear this slug and reCacheWithWarmup the new slug as well (as it may be used for a search page)
        if (isset($col_val['slug'])) {
            //$data['slug'] = $this->clearSlug($data['slug']); // (INSERT) needs to be unique for any entry
            $new_slug = $this->clearSlug($col_val['slug'], $table->getType(), (int)$key); // (UPDATE) needs to be unique to this entry
            $col_val['slug'] = $new_slug;
        }
        if (isset($col_val['date_updated'])) unset($col_val['date_updated']);
        $data = $table->formatColumnsAndData($col_val, true);
        // maybe (though unlikely) the slug was provided in the $data but not actually in the table
        if (true === in_array('slug', $data['discarded'])) unset($new_slug);
        // check if there are any columns going to be updated, else return already
        if (count($data['parameterized']) === 0) {
            $this->addError('No columns to update');

            return false;
        }
        // push current entry to history
        $old_row = $this->addToHistory($table, $key, $col_val); // returns the copied (now old) row, can be null
        // update entry
        $columns_list = implode(', ', $data['parameterized']);
        $columns_date = ($table_info->hasStandardColumns() ? ', date_updated = NOW()' : '');
        $statement = $this->conn->prepare(
            "UPDATE $table_name SET $columns_list $columns_date WHERE $key_column_name = ?;"
        );
        $data['values'][] = $key; // key must be added as last value for the where clause
        try {
            $statement->execute($data['values']);
        } catch (\PDOException $e) {
            $this->addError($e->getMessage());
        }
        $row_count = $statement->rowCount();
        // ok done
        $statement = null;
        if (1 === $row_count) {
            // TODO this can be more solid, but test it thoroughly...
            // reCacheWithWarmup the new slug (as it may already be used for a search page)
            if (isset($new_slug)) {
                $this->reCacheWithWarmup($new_slug);
            }
            // reCacheWithWarmup the current slug always for any change, if history did not return a row, get it from main
            if (null === $old_row) $old_row = $this->fetchRow($table_name, array('slug'), array($key_column_name => $key));
            if (null !== $old_row and isset($old_row->slug)) {
                if (isset($new_slug)) {
                    $this->deleteFromCache($old_row->slug);
                    $this->markStaleTheParents($old_row->slug);
                } else {
                    $this->reCacheWithWarmup($old_row->slug);
                }
            }
            $table = null;
            $old_row = null;

            return true;
        } else {
            $this->addError("DB->updateRowAndReturnSuccess resulted in a rowcount of $row_count for key $key on table $table_name");
        }
        $table = null;
        $old_row = null;

        return false;
    }

    private function updateRowsWhereAndReturnKeys(string $table_name, array $data, array $where): array
    {
        $table_info = $this->getTableInfo($table_name);
        $table = new Table($table_info);
        $key_column_name = $table_info->getPrimaryKeyColumn()->getName();
        $where = $table->formatColumnsAndData($where, true);
        $where_statement = implode(' AND ', $where['parameterized']);
        if ('' !== $where_statement) $where_statement = "WHERE $where_statement";
        $statement = $this->conn->prepare("SELECT $key_column_name FROM $table_name $where_statement");
        $statement->execute($where['values']);
        $rows = $statement->fetchAll(5);
        $statement = null;
        $return_keys = array();
        foreach ($rows as $key => $row) {
            if ($this->updateRowAndReturnSuccess($table_name, $data, $row->{$key_column_name})) {
                $return_keys[] = $row->{$key_column_name};
            }
        }

        return $return_keys;
    }

    /**
     * @param string $table_name
     * @param $key
     * @return bool
     */
    private function deleteRowAndReturnSuccess(string $table_name, $key): bool
    {
        $table_info = $this->getTableInfo($table_name); // throws fatal error when table_name is wrong
        // push current entry to history
        $old_row = $this->addToHistory(new Table($table_info), $key, array('deleted' => true)); // returns current row
        $primary_key_column_name = $table_info->getPrimaryKeyColumn()->getName();
        // delete the row
        if ($table_info->hasStandardColumns()) { // if the deleted column exists, use that
            $statement = $this->conn->prepare("UPDATE $table_name SET date_updated = NOW(), deleted = TRUE WHERE $primary_key_column_name = ?;");
        } else {
            $statement = $this->conn->prepare("DELETE FROM $table_name WHERE $primary_key_column_name = ?;");
        }
        // execute the update
        $statement->execute(array($key));
        $row_count = $statement->rowCount();
        $statement = null;
        // uncache the slug
        if (null !== $old_row and isset($old_row->slug)) {
            $this->reCacheWithWarmup($old_row->slug);
        }
        unset($old_row);

        return (1 === $row_count);
    }

    /**
     * @param $table_name
     * @param array $where
     * @param array $where_not
     * @return int
     */
    private function deleteRowWhereAndReturnAffected($table_name, array $where, array $where_not = array()): int
    {
        $table_info = $this->getTableInfo($table_name); // throws fatal error when table_name is wrong
        $primary_key_column = $table_info->getPrimaryKeyColumn()->getName();
        $columns_to_select = array_merge(array($primary_key_column), array_keys($where_not));
        $rows = $this->fetchRows($table_name, $columns_to_select, $where);
        $rows_affected = 0;
        if (count($rows) > 0) {
            foreach ($rows as $key => $row) {
                foreach ($where_not as $where => $not) {
                    if (isset($row->{$where}) && $row->{$where} === $not) continue 2;
                }
                if ($this->deleteRowAndReturnSuccess($table_name, $row->{$primary_key_column}) === true) ++$rows_affected;
            }
        }

        return $rows_affected;
    }

    /**
     * This bypasses all caching and soft delete systems, so pay attention using it
     *
     * @param $table_name
     * @param $id
     * @return bool whether the row with this id was deleted or did not exist at all, should be true
     */
    public function deleteRowImmediately($table_name, $id): bool
    {
        $table_info = $this->getTableInfo($table_name); // throws error if it does not exist
        $id_column_name = $table_info->getIdColumn()->getName();
        $statement = $this->conn->prepare("DELETE FROM $table_name WHERE $id_column_name = ?;");
        $statement->execute(array($id));
        if ($statement->rowCount() > 1) {
            $this->handleErrorAndStop("deleteRowImmediately deleted {$statement->rowCount()} rows!");
        }

        return true;
    }

    public function rowExists(string $table_name, array $where): bool
    {
        $table = new Table($this->getTableInfo($table_name)); // throws fatal error when table_name is wrong
        $where = $table->formatColumnsAndData($where, true);
        $where_string = implode(' AND ', $where['parameterized']);
        $statement = $this->conn->prepare("SELECT EXISTS(SELECT 1 FROM $table_name WHERE $where_string);");
        $statement->execute($where['values']);
        $return_value = (bool)$statement->fetchColumn(0);
        $statement = null;

        return $return_value;
    }

    public function idExists(string $id_column, int $id_value): bool
    {
        if (isset($this->id_exists[$id_column][$id_value])) return $this->id_exists[$id_column][$id_value];
        $type = new Type(substr($id_column, 0, -3)); // strip the standard _id from the column name
        $statement = $this->conn->prepare("SELECT EXISTS(SELECT 1 FROM {$type->tableName()} WHERE {$id_column} = ?);");
        $statement->execute(array($id_value));
        $this->id_exists[$id_column][$id_value] = ($exists = (bool)$statement->fetchColumn(0));
        $statement = null;

        return $exists;
    }

    /**
     * Used by upgrade mechanism to sync history database
     *
     * @return array
     * @since 0.1.0
     */
    public function getAllTables(): array
    {
        $statement = $this->conn->prepare('SELECT table_name, is_insertable_into FROM information_schema.tables WHERE table_schema = ?;');
        $statement->execute(array($this->db_schema));
        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * This also caches the table info for the request
     *
     * @param string $table_name
     * @return TableInfo
     * @since 0.1.0
     */
    public function getTableInfo(string $table_name): TableInfo
    {
        return $this->withApplicationCaching($table_name, function () use ($table_name) {
            return $this->fetchTableInfo($table_name);
        });
    }

    public function jobFetchCrossTables(): array
    {
        return $this->withApplicationCaching('var.cross_tables', function () {
            // get all tables with _x_ in the name
            $statement = $this->conn->prepare("
                SELECT t.table_name FROM information_schema.tables t
                WHERE t.table_schema = :schema AND t.table_type = 'BASE TABLE'
                AND t.table_name LIKE '%_x_%'
            ");
            $statement->bindValue(':schema', $this->db_schema);
            $statement->execute();
            $rows = $statement->fetchAll(5);
            $statement = null;

            return $rows;
        });
    }

    public function queryAllRows(string $table_name, array $columns = array('*'), ?int $instance_id = null): \PDOStatement
    {
        if (0 === count($columns)) $this->handleErrorAndStop('No columns to query');
        if (null === $instance_id) {
            $where = '';
        } else {
            $where = " WHERE instance_id = $instance_id";
        }
        $table_info = $this->getTableInfo($table_name);
        if ('*' === $columns[0]) {
            $columns_str = '*';
        } else {
            $columns_str = implode(',', $columns);
        }
        $statement = $this->conn->prepare("SELECT {$table_info->getIdColumn()->getName()} as id, $columns_str FROM $table_name$where;");
        $statement->execute();

        return $statement;
    }

    public function querySitemap(int $instance_id): \PDOStatement
    {
        $skip = array('image', 'file', 'menu', 'comment'); //todo make user configurable
        ob_start();
        foreach ($this->getTablesWithSlugs() as $index => $row) {
            if (true === in_array(str_replace('cms_', '', $row->table_name), $skip)) continue;
            echo "SELECT slug, date_updated FROM $row->table_name WHERE online = TRUE AND deleted = FALSE AND instance_id = :instance_id\n";
            echo "UNION ALL\n";
        }
        echo "SELECT slug, since AS date_updated FROM _cache WHERE slug LIKE '%/%' AND instance_id = :instance_id";
        echo ' AND type_name NOT IN(\'', implode('\', \'', $skip), '\');';
        $statement = $this->conn->prepare(ob_get_clean());
        $statement->bindValue(':instance_id', $instance_id);
        $statement->execute();
        if ($statement->rowCount() > 40000) {
            $this->addError("Sitemap for instance $instance_id reaching 50.000 pages.");
        }

        return $statement;
    }

    public function queryImagesForImport(): \PDOStatement
    {
        $statement = $this->conn->prepare('
            SELECT image_id, instance_id, slug, src_tiny, src_small, src_medium, src_large, src_huge, static_root
            FROM cms_image WHERE filename_saved = \'IMPORT\' AND static_root IS NOT NULL;
        ');
        $statement->execute();

        return $statement;
    }

    /**
     * Fetches the columns with their types and defaults and the primary key column of a table for use in queries
     *
     * @param string $table_name the name to fetch info for
     * @return TableInfo class representing the info for a table
     * @since 0.1.0
     */
    private function fetchTableInfo(string $table_name): TableInfo
    {
        $statement = $this->conn->prepare('SELECT column_name AS name, data_type AS type, column_default AS default, 
            is_nullable AS nullable, character_octet_length / 4 AS length FROM information_schema.columns 
            WHERE table_schema = :schema_name AND table_name = :table_name;');
        $statement->bindValue(':schema_name', $this->db_schema);
        $statement->bindValue(':table_name', $table_name);
        $statement->execute();
        $info = new TableInfo($table_name, $statement->fetchAll(5));
        // add the primary key column:
        $statement = $this->conn->prepare('SELECT a.attname FROM pg_index i 
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) 
            WHERE i.indrelid = :table_name::regclass AND i.indisprimary;');
        $statement->bindValue(':table_name', $table_name);
        $statement->execute();
        $rows = $statement->fetchAll(3);
        $statement = null;
        if (0 < count($rows)) {
            $info->setPrimaryKeyColumn($rows[0][0]);
        }

        return $info;
    }

    /**
     * Returns the current slug based on an old slug by looking in the redirect and history tables
     *
     * @param string $slug the slug to search for in redirect table and history
     * @return \stdClass|null the typeName + id of the corresponding element or null when nothing is found
     */
    public function fetchElementIdAndTypeByAncientSlug(string $slug): ?\stdClass
    {
        $slug = mb_strtolower($slug);
        // @since 0.8.1: use the redirect table for specific slugs (you probably need to clear cache to pick them up)
        $statement = $this->conn->prepare('
            SELECT to_slug FROM _redirect
            WHERE term = :term AND deleted = FALSE AND instance_id = :instance_id;
        ');
        $statement->bindValue(':term', $slug);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        if (1 === count(($rows = $statement->fetchAll(3)))) {
            $statement = null;

            return $this->fetchElementIdAndTypeBySlug($rows[0][0]);
        }
        // no need to look further if the slug is not a real slug
        if ($slug !== Help::slugify($slug)) return null;
        // @since 0.17.0 look in _history
        $statement = $this->conn->prepare('SELECT key, table_name FROM _history WHERE instance_id = :instance_id AND table_column = \'slug\' AND value = :value ORDER BY date_created DESC LIMIT 1;');
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':value', $slug);
        $statement->execute();
        if (($rows = $statement->fetchAll(5))) {
            $statement = null;
            $row = $rows[0];
            $table_name = $row->table_name;
            if (true === str_starts_with($table_name, 'cms_')) {
                $type_name = substr($table_name, 4);
                if (true === $this->rowExists($table_name, array("{$type_name}_id" => $row->key))) {
                    // you know it might be offline or deleted, but you handle that after the redirect.
                    return (object)array(
                        'id' => $row->key,
                        'type_name' => $type_name,
                    );
                }
            }
        }

        return null; // bypass history database for now (0.23.0)
        // TODO remove the following logic except 'return null' when _history is filled
        // look in the history database, get all tables that contain a slug column
        // those tables must also have the standard columns (data_updated, etc.)
        $statement = Setup::getHistoryDatabaseConnection()->prepare("
            SELECT t . table_name FROM information_schema . tables t
            INNER JOIN information_schema . columns c ON c . table_name = t . table_name and c . table_schema = :schema
            WHERE c . column_name = 'slug' and t . table_schema = :schema and t . table_type = 'BASE TABLE'
        ");
        $statement->bindValue(':schema', $this->db_schema);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;
        // construct a sql statement inner joining all the entries, ordered by date_updated so you only get the most recent entry
        ob_start(); // will hold sql statement
        foreach ($rows as $key => $row) {
            if (false === str_starts_with($row->table_name, 'cms_')) continue; // only handle cms_ tables containing elements
            if (ob_get_length()) echo 'UNION ALL ';
            $type_name = substr($row->table_name, 4);//str_replace('cms_', '', $row->table_name);
            echo "SELECT {$type_name}_id as id, '$type_name' as type_name, date_updated 
                FROM cms_$type_name WHERE slug = :slug and instance_id = :instance_id and deleted = false ";
        }
        echo 'ORDER BY date_updated DESC LIMIT 1;';
        $statement = Setup::getHistoryDatabaseConnection()->prepare(ob_get_clean());
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        if ($rows = $statement->fetchAll(5)) {
            // take the element and id to select the current slug in the live database
            $row = $rows[0];
            if ($obj = $this->fetchRow("cms_{$row->type_name}", array('slug'), array("{$row->type_name}_id" => $row->id))) {
                // auto-migrate: register this change in the current _history table
                $statement = $this->conn->prepare('
                    INSERT INTO _history (admin_name, admin_id, user_name, user_id, instance_id, table_name, table_column, key, value)
                    VALUES(\'system\', 0, \'-\', 0, :instance_id, :table_name, \'slug\', :key, :value)
                ');
                $statement->bindValue(':instance_id', Setup::$instance_id);
                $statement->bindValue(':table_name', "cms_{$row->type_name}");
                $statement->bindValue(':key', $row->id);
                $statement->bindValue(':value', $slug);
                $statement->execute();
                if (extension_loaded('newrelic')) {
                    $migrated = 1 === $statement->rowCount() ? 'migrated' : 'looked up';
                    newrelic_notice_error("slug $slug $migrated in history for " . Setup::$PRESENTATION_INSTANCE);
                }
                //
                return $obj->slug; // you know it might be offline or deleted, but you handle that after the redirect.
            }
        }
        $statement = null;

        return null;
    }

    /**
     * Cache elements including the linked elements, you MUST get the row complete before caching.
     * Row will be cached by slug (for each instance), the only unique element.
     *
     * @param \stdClass $row the row to cache
     * @param string $type_name
     * @param int $id
     * @param int $variant_page
     * @since 0.5.4
     */
    public function cache(\stdClass $row, string $type_name, int $id, int $variant_page = 1): void
    {
        if ('' === (string)($slug = $row->__ref)) return;
        $json = json_encode($row);
        $statement = $this->conn->prepare('
            INSERT INTO _cache (instance_id, slug, row_as_json, type_name, id, variant_page) 
            VALUES (:instance_id, :slug, :row_as_json, :type_name, :id, :variant_page);
        ');

        $statement->bindValue(':row_as_json', $json);
        $statement->bindValue(':type_name', $type_name);
        $statement->bindValue(':id', $id);
        $statement->bindValue(':variant_page', $variant_page);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':slug', $slug);
        $statement->execute();
        $json = null;
        $statement = null;
    }

    /**
     * Set paging / pages in cache row for the slug
     * @param string $slug
     * @param string $json properly formatted json containing all the variant_pages for this slug
     * @return int
     * @since 0.8.5
     */
    public function updateVariantPageJsonInCache(string $slug, string $json): int
    {
        $statement = $this->conn->prepare(
            'UPDATE _cache SET variant_page_json = :json WHERE slug = :slug AND instance_id = :instance_id;'
        );
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':json', $json);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    /**
     * Cached will return the row object of a cached item by slug for this instance, or null when not present
     *
     * @param string $slug the unique slug to look for this instance
     * @param int $variant_page
     * @return \stdClass|null the row object found or null when not cached
     * @since 0.5.4
     */
    public function cached(string $slug, int $variant_page = 1): ?\stdClass
    {
        $statement = $this->conn->prepare('SELECT row_as_json, since, variant_page_json FROM _cache 
                    WHERE instance_id = :instance_id AND slug = :slug AND variant_page = :variant_page
                    ORDER BY since DESC LIMIT 1;');
        $statement->bindValue(':instance_id', (Setup::$instance_id));
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':variant_page', $variant_page);
        $statement->execute();
        if (0 === $statement->rowCount()) {
            $statement = null;
            return null;
        }
        $row = $statement->fetchAll(5)[0];
        $statement = null;

        $obj = json_decode($row->row_as_json);
        $obj->x_cache_timestamp = strtotime($row->since); // @since 0.8.2
        if (true === isset($row->variant_page_json)) {
            $obj->__variant_pages__ = json_decode($row->variant_page_json);
        } else {
            $obj->__variant_pages__ = array(); // @since 0.8.6
        }
        $row = null;

        return $obj;
    }

    /**
     * Checks if the cache for $slug was updated since $in_browser_timestamp
     * @param string $slug
     * @param int $in_browser_timestamp
     * @return bool true when the supplied timestamp (cache in the browser) is not older than the ‘since’ in the table
     * @since 0.8.2
     */
    public function cachex(string $slug, int $in_browser_timestamp): bool
    {
        $statement = $this->conn->prepare('SELECT since FROM _cache WHERE instance_id = :instance_id AND slug = :slug LIMIT 1;');
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':slug', $slug);
        $statement->execute();
        if (0 === $statement->rowCount()) {
            $statement = null;

            return false;
        }
        $timestamp = strtotime($statement->fetchColumn(0));
        $statement = null;

        return ($in_browser_timestamp >= $timestamp);
    }

    /**
     * The item will be deleted from cache, the parents will be marked stale so other pages the slug appears on can be updated as well
     *
     * @param string $slug the slug to invalidate cache for
     * @return bool success
     * @since 0.5.4
     */
    public function reCacheWithWarmup(string $slug): bool // success
    {
        // @since 0.8.2: warmup is used instead of delete
        if (false === (new Warmup())->Warmup($slug, Setup::$instance_id)) {
            $this->addError(sprintf(__('Warmup failed for %s', 'peatcms'), $slug));

            return false;
        }

        // @since 0.8.2 always mark stale, it can always be part of something else
        return $this->markStaleTheParents($slug);
    }

    /**
     * Deletes supplied slug immediately from cache without warmup
     * Except when the variant_page > 1, it will only remove the _cache rows of that page and higher
     * without further consequences
     * @param string $slug
     * @param int $from_variant_page_upwards
     * @return bool success value
     * @since 0.8.3
     */
    public function deleteFromCache(string $slug, int $from_variant_page_upwards = 1): bool //success
    {
        $statement = $this->conn->prepare('
            DELETE FROM _cache WHERE instance_id = :instance_id AND slug = :slug 
            AND variant_page >= :variant_page;
        ');
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':variant_page', $from_variant_page_upwards);
        $statement->execute();
        $statement = null;

        return true;
    }

    /**
     * Relations of the supplied slug are put in the _stale table so the job that warms up cache can pick them up
     * @param string $slug
     * @return bool
     * @since 0.8.8
     * TODO move to element / baseelement or something, not here
     */
    public function markStaleTheParents(string $slug): bool
    {
        // get all the slugs we need to warmup for this element
        $id_and_type = $this->fetchElementIdAndTypeBySlug($slug);
        if (null === $id_and_type) return false;
        // you must mark stale also for deleted elements, so get this element also when deleted
        $peat_type = new Type($id_and_type->type_name);
        $element_row = Help::getDB()->fetchRow(
            $peat_type->tableName(),
            array('*'),
            array($peat_type->idColumn() => $id_and_type->id, 'deleted' => null)
        );
        $element = $peat_type->getElement($element_row);
        $element_row = null;
        if (null === $element) return false;
        $linked_types = $element->getLinkedTypes();
        $linked = $element->getLinked();
        foreach ($linked_types as $type => $relation) {
            if ('properties' === $relation) {
                $arr = $linked[$type];
                foreach ($arr as $index => $row) {
                    if (false === is_int($index)) continue;
                    $this->markStale($row->property_slug);
                    $this->markStale($row->slug); // meaning property value slug
                }
            } elseif (in_array($relation, array('direct_child', 'cross_child', 'cross_parent'))) {
                $arr = $linked[$type];
                foreach ($arr as $index => $row) {
                    if (false === is_int($index)) continue;
                    $this->markStale($row->__ref ?? $row->slug);
                }
            }
        }
        // special case: pages that are parent of this page... TODO make this not such a special case
        if ('page' === $element->getTypeName()) {
            // travel up the chain...
            $statement = $this->conn->prepare('
                SELECT slug FROM cms_page_x_page x INNER JOIN cms_page p ON p.page_id = x.page_id WHERE sub_page_id = :id;
            ');
            $statement->bindValue(':id', $element->getId());
            $statement->execute();
            if ($statement->rowCount() > 0) {
                $rows = $statement->fetchAll(5);
                foreach ($rows as $index => $row) {
                    $this->markStale($row->slug);
                }
            }
        }

        return true;
    }

    /**
     * Supplied slug is put in the _stale table so the cron job that warms up cache can pick it up
     * @param string $slug
     * @return void
     * @since 0.8.3
     */
    private function markStale(string $slug): void
    {
        if (isset($this->stale_slugs[$slug])) {
            return;
        } // mark stale only once
        $this->stale_slugs[$slug] = true;
        $statement = $this->conn->prepare('SELECT EXISTS (SELECT 1 FROM _stale WHERE instance_id = ? AND slug = ?);');
        $statement->execute(array(Setup::$instance_id, $slug));
        $exists = (bool)$statement->fetchColumn(0);
        if (false === $exists) {
            $statement = $this->conn->prepare('INSERT INTO _stale (instance_id, slug) VALUES (?, ?);');
            $statement->execute(array(Setup::$instance_id, $slug));
        }
        $statement = null;
    }

    public function markStaleFrom(string $slug, string $date_from): bool
    {
        if (null === (Date::getDate($date_from))) return false;
        // delete from stale table
        $statement = $this->conn->prepare('DELETE FROM _stale WHERE instance_id = ? AND slug = ?;');
        $statement->execute(array(Setup::$instance_id, $slug));
        // insert into stale table with the correct since date
        $statement = $this->conn->prepare('INSERT INTO _stale (instance_id, slug, since) VALUES (?, ?, ?);');
        $statement->execute(array(Setup::$instance_id, $slug, $date_from));
        $this->stale_slugs[$slug] = true;
        $statement = null;

        return true;
    }

    /**
     * It could happen that identical rows are inserted in the cache table, these are deleted immediately by this method
     * @return int the amount of rows that were removed
     * @since 0.8.3
     */
    public function removeDuplicatesFromCache(): int
    {
        // https://stackoverflow.com/a/12963112
        // delete (older) duplicates from cache table
        $statement = $this->conn->prepare('
            DELETE FROM _cache c1 USING (
              SELECT MAX(since) as since, slug, instance_id, variant_page
                FROM _cache
                GROUP BY slug, instance_id, variant_page HAVING COUNT(*) > 1
              ) c2
              WHERE c1.slug = c2.slug 
                AND c1.variant_page = c2.variant_page
                AND c1.instance_id = c2.instance_id
                AND c1.since <> c2.since;
        ');
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    public function removeDuplicatesFromCiAi(): int
    {
        // https://stackoverflow.com/a/12963112
        $statement = $this->conn->prepare('
            DELETE FROM _ci_ai c1 USING (
              SELECT MAX(ci_ai_id) as ci_ai_id, type_name, id
                FROM _ci_ai
                GROUP BY type_name, id HAVING COUNT(*) > 1
              ) c2
              WHERE c1.id = c2.id 
                AND c1.type_name = c2.type_name
                AND c1.ci_ai_id <> c2.ci_ai_id;
        ');
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    /**
     * @param int $limit
     * @return array the rows
     * @since 0.8.0 @since 0.8.19 ‘since’ can be in the future, for pages that need to be published just then
     */
    public function jobStaleCacheRows(int $limit = 60): array
    {
        // collect all the stale slugs
        $statement = $this->conn->prepare("
            SELECT DISTINCT s.slug, s.instance_id, s.since, c.slug in_cache FROM _stale s
            LEFT OUTER JOIN _cache c ON c.slug = s.slug
            WHERE s.since <= NOW()
            ORDER BY s.since DESC LIMIT $limit;");
        $statement->execute();
        if ($statement->rowCount() > 0) {
            $rows = $statement->fetchAll(5);
        } else {
            $rows = array();
        }
        $statement = null;

        return $rows;
    }

    /**
     * @param string $slug
     * @param int $instance_id
     * @return bool success
     */
    public function deleteFromStale(string $slug, int $instance_id): bool
    {
        $statement = $this->conn->prepare('DELETE FROM _stale WHERE slug = :slug AND instance_id = :instance_id;');
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':instance_id', $instance_id);
        $success = $statement->execute();
        $statement = null;

        return $success;
    }

    /**
     * Warms up existing cache rows that are old, handles a maximum of $limit rows each time called
     * @param int $interval in minutes that is considered ‘old’, default 600 (10 hours)
     * @param int $limit default 60 batch size
     * @return array the rows
     */
    public function jobOldCacheRows(int $interval = 600, int $limit = 60): array
    {
        $statement = $this->conn->prepare("SELECT DISTINCT slug, instance_id, since FROM _cache 
            WHERE since < NOW() - interval '$interval minutes' ORDER BY since LIMIT $limit;");
        $statement->execute();
        if ($statement->rowCount() > 0) {
            $rows = $statement->fetchAll(5);
        } else {
            $rows = array();
        }
        $statement = null;

        return $rows;
    }

    /**
     * Clears the entire cache for a specific instance including the stale records
     *
     * @param int $instance_id the instance to clear cache from, perform access control for the admin beforehand
     * @return int rows affected, the number of items deleted from cache
     * @since 0.5.5
     */
    public function clear_cache_for_instance(int $instance_id): int
    {
        // used to be cache_conn
        $statement = $this->conn->prepare('DELETE FROM _cache WHERE instance_id = ?;');
        $statement->execute(array($instance_id));
        $row_count = $statement->rowCount();
        $statement = $this->conn->prepare('DELETE FROM _stale WHERE instance_id = ?;');
        $statement->execute(array($instance_id));
        $statement = null;

        return $row_count;
    }

    public function deleteForInstance(string $table_name, int $instance_id): int
    {
        if ('_instance' === $table_name) return 0; // you cannot delete the instance itself this way

        $info = $this->getTableInfo($table_name);
        if (false === $info->hasColumn('instance_id')) return 0;

        $statement = $this->conn->prepare("DELETE FROM $table_name WHERE instance_id = ?");
        $statement->execute(array($instance_id));
        $row_count = $statement->rowCount();
        $statement = null;

        return $row_count;
    }

    public function insertHistoryEntry(\stdClass $row): bool
    {
        if (true === isset($row->user_id, $row->user_name, $row->table_name, $row->table_column, $row->key, $row->value, $row->date_created)) {
            $statement = $this->conn->prepare('
                INSERT INTO _history (instance_id, admin_id, user_id, admin_name, user_name, table_name, table_column, key, value, date_created) 
                VALUES (:instance_id, :admin_id, :user_id, :admin_name, :user_name, :table_name, :table_column, :key, :value, :date_created);
            ');
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->bindValue(':admin_id', 0);
            $statement->bindValue(':admin_name', 'IMPORT');
            $statement->bindValue(':user_id', $row->user_id);
            $statement->bindValue(':user_name', $row->user_name);
            $statement->bindValue(':table_name', $row->table_name);
            $statement->bindValue(':table_column', $row->table_column);
            $statement->bindValue(':key', (int)$row->key);
            $statement->bindValue(':value', $row->value);
            $statement->bindValue(':date_created', $row->date_created);
            $success = $statement->execute();
            $statement = null;

            return $success;
        } else {
            return false;
        }
    }

    public function updateHistoryKey(int $key, array $where): void
    {
        if (0 === count($where)) {
            $this->addError('updateHistoryKey() does not work with empty where');
            return;
        }
        $table_info = $this->getTableInfo('_history');
        $table = new Table($table_info);
        $where = $table->formatColumnsAndData($where, true);
        $where_statement = implode(' AND ', $where['parameterized']);
        $statement = $this->conn->prepare("UPDATE _history SET key = $key WHERE $where_statement");
        $statement->execute($where['values']);
    }

    public function healTable(string $table_name): bool
    {
        $this->resetConnection();
        $this->addError("Healing table $table_name");
        $info = $this->getTableInfo($table_name);
        if ($info->hasIdColumn()) {
            $id_column = $info->getIdColumn()->getName();
            $statement = $this->conn->prepare("SELECT pg_get_serial_sequence(:table_name, :id_column);");
            $statement->bindValue(':table_name', "public.$table_name");
            $statement->bindValue(':id_column', $id_column);
            $statement->execute();
            $sequencer = $statement->fetchColumn(0);
            if ('' === $sequencer) {
                $this->addError("No sequencer found for $table_name");
                $statement = null;
                return false;
            }
            // get max id
            $statement = $this->conn->prepare("SELECT MAX($id_column) FROM $table_name;");
            $statement->execute();
            $current_id = $statement->fetchColumn(0);
            // get next in sequence (without actually updating it)
            $statement = $this->conn->prepare("
                SELECT last_value + i.inc AS next_value FROM $sequencer,
                    (SELECT seqincrement AS inc FROM pg_sequence
                        WHERE seqrelid = '$sequencer'::regclass::oid) AS i;
            ");
            $statement->execute();
            $next_value = $statement->fetchColumn(0);

            // if the next in sequence is not higher than the current max id, we should update it
            if (false === ($next_value > $current_id)) {
                $this->addError("Sequence out of sync for $table_name.");
                $statement = $this->conn->prepare("
                BEGIN;
                LOCK TABLE $table_name IN SHARE MODE;
                SELECT setval('$sequencer', COALESCE((SELECT MAX($id_column)+1 FROM $table_name), 1), false);
                COMMIT;
            ");
                if (false === $statement->execute()) {
                    $this->addError('Could not fix sequence');
                }
            }
        }

        $statement = $this->conn->prepare("REINDEX TABLE $table_name;");
        $statement->execute();

        $statement = null;

        return true;
    }

    public function fetchHistoryFrom(int $timestamp, int $user_id, bool $is_admin): array
    {
        $sql_date = date('Y-m-d H:i:s', $timestamp);
        $query = 'SELECT DISTINCT table_name, key FROM _history WHERE date_created >= :date AND instance_id = :instance_id';
        if (true === $is_admin) {
            $statement = $this->conn->prepare("$query;");
        } else {
            $statement = $this->conn->prepare("$query AND user_id = :user_id;");
            $statement->bindValue(':user_id', $user_id);
        }
        $statement->bindValue(':date', $sql_date); //$timestamp
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();

        $rows = $statement->fetchAll(5);
        $statement = null;

        return $rows;
    }

    /**
     * Records a change in the _history table for undo and redirect purposes
     *
     * @param Table $table the table object from the live database for reference
     * @param mixed $key the key of the primary key column of the row to move to history
     * @param array $col_val the column => value pairs that are updated
     * @return \stdClass|null the old row or null when not copied (@since 0.5.x)
     * @since 0.0.0
     */
    private function addToHistory(Table $table, $key, array $col_val, bool $is_insert = false): ?\stdClass
    {
        $table_info = $table->getInfo();
        // abort if there’s no history
        if (in_array(($table_name = $table_info->getTableName()), self::TABLES_WITHOUT_HISTORY)) return null;
        // select the row from the live database
        if (true === $is_insert) {
            $row = null;
        } else {
            $row = $this->fetchRow($table_name, array('*'), array(
                'deleted' => null, // null means either value is good
                'instance_id' => null, // will be overwritten by next line if the $key column is actually instance_id
                $table_info->getPrimaryKeyColumn()->getName() => $key,
            ));
        }
        // @since 0.17.0 use _history
        $now = Setup::getNow(); // use the same timestamp for all columns in this update
        foreach ($col_val as $column_name => $value) {
            if (false === $table_info->hasColumn($column_name)) continue;
            if (null !== $row && $row->{$column_name} === $value) continue; // no need to add to history when the value is the same
            // booleans get butchered in $statement->execute(), interestingly, NULL values don't
            if (is_bool($value)) {
                $value = ($value ? '1' : '0');
            } elseif (in_array($column_name, $this::REDACTED_COLUMN_NAMES)) {
                $value = 'REDACTED';
            } else {
                $value = (string)$value;
                if ('NOW()' === $value && str_starts_with($column_name, 'date_')) {
                    $value = date('Y-m-d H:i:s', $now);
                }
            }
            $admin_id = 0;
            $admin_email = '-';
            $user_id = 0;
            $user_email = '-';
            if (isset(Help::$session)) {
                if (($admin = Help::$session->getAdmin())) {
                    $admin_id = $admin->getId();
                    $admin_email = $admin->getRow()->email;
                }
                if (($user = Help::$session->getUser())) {
                    $user_id = $user->getId();
                    $user_email = $user->getRow()->email;
                }
            }
            $statement = $this->conn->prepare('
                INSERT INTO _history (instance_id, admin_id, user_id, admin_name, user_name, table_name, table_column, key, value) 
                VALUES (:instance_id, :admin_id, :user_id, :admin_name, :user_name, :table_name, :table_column, :key, :value);
            ');
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->bindValue(':admin_id', $admin_id);
            $statement->bindValue(':admin_name', $admin_email);
            $statement->bindValue(':user_id', $user_id);
            $statement->bindValue(':user_name', $user_email);
            $statement->bindValue(':table_name', $table_name);
            $statement->bindValue(':table_column', $column_name);
            $statement->bindValue(':key', (int)$key);
            $statement->bindValue(':value', $value);
            if (false === $statement->execute()) {
                $this->addError(sprintf('addToHistory could not insert into _history for %1$s with %2$s = %3$s',
                    $table_name, $column_name, $value));
            }
        }

        return $row;
    }

    /**
     * Array returned contains named array with column names, with column_name both as key and value
     *
     * @param string $table_name the table you want to get the columns from in the history table
     * @return array|null return an array with the column names or null when the table does not exist
     */
    public function historyTableColumns(string $table_name): ?array
    {
        // check if the table exists
        $statement = Setup::getHistoryDatabaseConnection()->prepare('SELECT EXISTS (
            SELECT 1
            FROM   information_schema.tables 
            WHERE table_schema = :schema_name
            AND table_name = :table_name
            );'
        );
        $statement->bindValue(':schema_name', $this->db_schema);
        $statement->bindValue(':table_name', $table_name);
        $statement->execute();
        // if not exists return null
        if ($statement->fetchColumn(0) === false) {
            $statement = null;

            return null;
        }
        // else if exists, return columns
        $statement = Setup::getHistoryDatabaseConnection()->prepare('
            SELECT column_name FROM information_schema.columns
            WHERE table_schema = :schema_name AND table_name = :table_name;
        ');
        $statement->bindValue(':schema_name', $this->db_schema);
        $statement->bindValue(':table_name', $table_name);
        $statement->execute();
        $rows = $statement->fetchAll(5);
        $statement = null;
        $columns = array();
        foreach ($rows as $key => $value) {
            //$columns[$key] = (string)$columns[$key][0];
            $columns[$value->column_name] = $value->column_name; // so you can use in_array($column_name) as well as unset($column_name) :-D
        }

        return $columns;
    }

    /**
     * This function runs sql unchecked against the history database, used by the upgrade mechanism
     *
     * @param string $sql A valid sql statement to run against the history database
     * @return false|int returns the result of ->exec()
     */
    public function historyRun(string $sql)
    {
        // TODO check the sql? Can it be injected somewhere?
        return Setup::getHistoryDatabaseConnection()->exec($sql);
    }

    private function getMeaningfulSearchString(\stdClass $out): string
    {
        if (isset($out->__ref)) $out = $GLOBALS['slugs']->{$out->__ref};
        ob_start();
        if (true === isset($out->title)) {
            echo $out->title, ' ';
        }
        if (true === isset($out->__x_values__)) {
            foreach ($out->__x_values__ as $key => $x) {
                if (false === is_int($key)) continue; // not a row
                echo $x->title, ' ', $x->x_value, ' ';
            }
        }
        if (true === isset($out->__products__)) {
            foreach ($out->__products__ as $key => $x) {
                if (false === is_int($key)) continue; // not a row
                if (isset($x->__ref)) $x = $GLOBALS['slugs']->{$x->__ref};
                echo $x->title, ' ';
            }
        }
        if (true === isset($out->excerpt)) {
            echo $out->excerpt, ' ';
        }
        if (true === isset($out->content)) {
            echo $out->content, ' ';
        }
        if (true === isset($out->description)) {
            echo $out->description, ' ';
        }

        // replace any pipe character that is not escaped (negative lookbehind for backslash)
        return preg_replace('/(?<!\\\\)\\|/', '', ob_get_clean());
    }

    /**
     * Will cache the value returned by $callback using the $key
     *  - for this request (= very fast when reused, no effect when not)
     *  - supposing opcache is faster than file caching, pending testing
     * @param string $key
     * @param callable $callback
     * @return mixed the original value returned by $callback
     */
    private function withApplicationCaching(string $key, callable $callback): mixed
    {
        if (false === isset($this->cache_keys[$key])) {
            $version_key = Setup::$VERSION . ".$key";
            $val = $this->appCacheGet($version_key);
            if (false === $val) {
                $val = $callback();
                $this->appCacheSet($version_key, $val);
            }
            $this->cache_keys[$key] = $val;
        }
        return $this->cache_keys[$key];
    }

    /**
     * @param string $key
     * @param $val
     * @return bool whether it worked
     */
    public function appCacheSet(string $key, $val): bool
    {
        $val = base64_encode(serialize($val));
        // Write to temp file first to ensure atomicity
        $tmp = "$this->cache_folder$key." . uniqid('', true) . '.tmp';
        $prm = "$this->cache_folder$key.serialized";
        return
            false !== file_put_contents($tmp, "<?php \$val = unserialize(base64_decode('$val'));", LOCK_EX)
            && rename($tmp, $prm)
            && opcache_invalidate($prm, true) // to be clear about it (templates need this)
            && opcache_compile_file($prm);
    }

    /**
     * @param string $key
     * @return mixed|false the original value put in cache for $key, or false when not yet cached
     */
    public function appCacheGet(string $key): mixed
    {
        @include "$this->cache_folder$key.serialized";
        return $val ?? false;
    }
}
