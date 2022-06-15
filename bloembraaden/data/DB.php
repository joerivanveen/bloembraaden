<?php
declare(strict_types=1);

namespace Peat;
class DB extends Base
{
    private string $version, $db_schema;
    private ?\PDO $conn;
    private array $table_infos, $stale_slugs;
    public array $tables_without_history = array(
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
        '_instagram_auth',
        '_instagram_feed',
        '_instagram_media',
    );

    public function __construct()
    {
        parent::__construct();
        // setup constants for this instance
        if (!isset(Setup::$instance_id)) Setup::$instance_id = -1;
        $this->conn = Setup::getMainDatabaseConnection();
        $this->db_schema = 'public';
    }

    /**
     * These are tables linked by crosslink tables (_x_-tables), returns the relations both ways:
     * element_name=>'child' or element_name=>'parent'
     * @param $type Type the element you want to get the cross linked tables for
     * @return \stdClass array containing named arrays: element_name=>relation (parent or child), array has no keys when no tables are linked
     */
    public function getLinkTables(Type $type): \stdClass
    {
        $type_name = $type->typeName();
        $arr = array();
        if ('variant' === $type_name) {
            $arr = array('x_value' => 'properties', 'image' => 'cross_parent', 'embed' => 'cross_parent',
                'file' => 'cross_parent', 'product' => 'direct_child', 'serie' => 'direct_child',
                'brand' => 'direct_child', 'page' => 'cross_child');
        } elseif ('page' === $type_name) {
            $arr = array('x_value' => 'properties', 'page' => 'cross_parent', 'image' => 'cross_parent',
                'embed' => 'cross_parent', 'file' => 'cross_parent', 'variant' => 'cross_parent');
        } elseif ('file' === $type_name) {
            $arr = array('image' => 'cross_parent', 'page' => 'cross_child', 'brand' => 'cross_child',
                'serie' => 'cross_child', 'product' => 'cross_child', 'variant' => 'cross_child');
        } elseif ('image' === $type_name) {
            $arr = array('page' => 'cross_child', 'brand' => 'cross_child', 'serie' => 'cross_child',
                'product' => 'cross_child', 'variant' => 'cross_child', 'embed' => 'cross_child');
        } elseif ('embed' === $type_name) {
            $arr = array('image' => 'cross_parent', 'page' => 'cross_child', 'brand' => 'cross_child',
                'serie' => 'cross_child', 'product' => 'cross_child', 'variant' => 'cross_child');
        } elseif ('product' === $type_name) {
            $arr = array('image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent',
                'serie' => 'direct_child', 'brand' => 'direct_child', 'variant' => 'direct_parent');
        } elseif ('serie' === $type_name) {
            $arr = array('image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent',
                'brand' => 'direct_child', 'product' => 'direct_parent', 'variant' => 'direct_parent');
        } elseif ('brand' === $type_name) {
            $arr = array('image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent',
                'serie' => 'direct_parent', 'product' => 'direct_parent', 'variant' => 'direct_parent');
        } elseif ('property_value' === $type_name) {
            $arr = array('property' => 'cross_parent', 'x_value' => array('variant', 'page'),
                'image' => 'cross_parent', 'embed' => 'cross_parent', 'file' => 'cross_parent');
        } elseif ('property' === $type_name) {
            $arr = array('property_value' => 'cross_child', 'x_value' => array('variant', 'page'));
        }

        return (object)$arr; // convert to object for json which does not accept named arrays
    }

    /**
     * Returns the children element names in direct chains: tables that should be updated when it changes
     *
     * @param $type Type the element you want the children names of
     * @return array|null returns the names of the children this element has, or null when it has none
     */
    public function getReferencingTables(Type $type): ?array
    {
        $type_name = $type->typeName();
        // if element = product reference = variant, if element = serie references are product as well as variant,
        // if element = brand you need to update serie, product and variant when you update the element itself
        if ($type_name === 'product') {
            return array('variant');
        } elseif ($type_name === 'serie') {
            return array('product', 'variant');
        } elseif ($type_name === 'brand') {
            return array('serie', 'product', 'variant');
        } else {
            return null;
        }
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
    public function getVersion(): string
    {
        if (!isset($this->version)) {
            $statement = $this->conn->prepare('SELECT version FROM _system');
            $statement->execute();
            $this->version = $statement->fetchColumn(0);
            $statement = null;
        }

        return $this->version;
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
            ), []);
    }

    /**
     * put expiring info in a locker, receive a key to open it once with emptyLocker
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
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError(json_last_error_msg());
            $info_as_json = '{}'; // empty object
        }
        $statement = $this->conn->prepare('INSERT INTO _locker (key, instance_id, information, valid_until)' .
            'VALUES (:key, :instance_id, :information, NOW() + (:expires_after  * interval \'1 second\'));');
        $statement->bindValue(':instance_id', Setup::$instance_id); // the original instance that filled this locker
        $statement->bindValue(':information', $info_as_json);
        $statement->bindValue(':expires_after', $expires_after);
        $key = ''; // for php storm
        $tries = 10; // don’t go on indefinitely because something else is probably wrong
        while ($statement->rowCount() !== 1) {
            if ($tries-- === 0) {
                $this->addMessage(__('Could not set key', 'peatcms'), 'warn');

                return null;
            }
            $key = Help::randomString(30);
            $statement->bindValue(':key', $key);
            try {
                $statement->execute();
            } catch (\Exception $e) {
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
            $row = $this->normalizeRow($statement->fetchAll()[0]);
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
     * @param array $terms
     * @return array
     * @since 0.6.x
     */
    public function findTitles(array $terms): array
    {
        $all = array(); // todo cache these results? it’s terrible to have to fetch these all the time
        foreach (array('cms_variant', 'cms_page', 'cms_product') as $table_index => $table_name) {
            $arr = array(); // collect the results (by slug) so you can intersect them, leaving only results with all the terms in them
            $price_column = ($table_name === 'cms_variant') ? 'price' : 'NULL'; // @since 0.7.9 get the price as well if applicable
            $order_column = 'date_updated';
            if ($table_name === 'cms_variant') $order_column = 'date_popvote';
            if ($table_name === 'cms_page') $order_column = 'date_published';
            $statement = $this->conn->prepare('SELECT slug, title, \'' . $table_name . '\' AS table_name, ' .
                $price_column . ' AS price FROM ' . $table_name .
                ' WHERE instance_id = :instance_id AND deleted = FALSE AND online = TRUE AND ci_ai LIKE :term' .
                ' ORDER BY ' . $order_column . ' DESC LIMIT 8;');
            $statement->bindValue(':instance_id', Setup::$instance_id);
            foreach ($terms as $index => $term) {
                if ('' === $term) continue;
                $rows = array();
                $term = '%' . Help::removeAccents($this->toLower($term)) . '%';
                $statement->bindValue(':term', $term);
                $statement->execute();
                foreach ($temp = $statement->fetchAll() as $index_row => $row) {
                    $rows[$row['slug']] = $row;
                }
                unset ($temp);
                $arr[$term] = $rows;
            }
            $intersected = null;
            foreach ($arr as $index => $term) {
                if (null === $intersected) {
                    $intersected = $term;
                } else {
                    $intersected = array_intersect_key($term, $intersected);
                }
            }
            $all = array_merge($all, $intersected);
            $statement = null;
        }
        if (count($all) > 8) array_splice($all, 8);

        return $this->normalizeRows(array_values($all));
    }

    /**
     * @param array $properties
     * @param string $cms_type_name
     * @return array sub queries that filter the type based on the properties
     * @since 0.9.0
     */
    private function filterProperties(array $properties, string $cms_type_name): array
    {
        if (false === in_array($cms_type_name, array('variant', 'page'))) return array();
        // get all the relevant property_value_id s from $properties, and select only items that also have those in their x-table
        $sub_queries = array();
        $property_value_ids = array();
        foreach ($properties as $property_name => $property_values) {
            if ('price_min' === $property_name && 'variant' === $cms_type_name) {
                $sub_queries[] = sprintf(
                    'AND (peat_parse_float(price, \'%1$s\', \'%2$s\') > %3$s) ',
                    Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$property_values[0]
                );
            } elseif ('price_max' === $property_name && 'variant' === $cms_type_name) {
                $sub_queries[] = sprintf(
                    'AND (peat_parse_float(price, \'%1$s\', \'%2$s\') <= %3$s) ',
                    Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$property_values[0]
                );
            } else {
                foreach ($property_values as $index => $value) {
                    if (($row = $this->fetchElementIdAndTypeBySlug($value))) {
                        if ('property_value' === $row->type) {
                            $property_value_ids[] = (int)$row->id;
                        }
                    }
                }
            }
        }
        $sub_query_format = 'AND ' . $cms_type_name . '_id IN (SELECT ' .
            $cms_type_name . '_id FROM cms_' . $cms_type_name . '_x_properties' .
            ' WHERE property_value_id = %d) ';
        foreach ($property_value_ids as $index => $property_value_id) {
            $sub_queries[] = sprintf($sub_query_format, $property_value_id);
        }

        return $sub_queries;
    }

    /**
     * @param Type $type
     * @param array $terms
     * @param array $properties @since 0.8.10
     * @return array
     * @since 0.6
     */
    public function findElements(Type $type, array $terms, array $properties = array()): array
    {
        if (0 === count($terms)) return array();
        $type_name = $type->typeName();
        // TODO make a real search functionality, for now it's simple LIKE stuff
        $sub_queries = $this->filterProperties($properties, $type_name);
        // collect the results (by slug) so you can intersect them, leaving only results with all the terms in them
        $arr = array();
        if ('variant' === $type_name) { // TODO integrate variant search better
            // NOTE you need the deleted = FALSE when searching, or else elements may be cached by getOutput while deleted
            $statement = $this->conn->prepare(
                'SELECT *, variant_id AS id, \'cms_variant\' AS table_name FROM cms_variant ' .
                'WHERE (instance_id = :instance_id AND deleted = FALSE AND ci_ai LIKE :term ' .
                'OR product_id IN (SELECT product_id FROM cms_product WHERE deleted = FALSE AND instance_id = :instance_id AND ci_ai LIKE :term))' .
                implode(' ', $sub_queries) . ';'
            );
            $statement->bindValue(':instance_id', Setup::$instance_id);
            //var_dump($statement->queryString);
            foreach ($terms as $index => $term) {
                $rows = array();
                if ('price_from' === $term) { // select only variants that have a from price
                    $statement2 = $this->conn->prepare('SELECT *, variant_id AS id, \'cms_variant\' AS table_name FROM cms_variant ' .
                        'WHERE instance_id = :instance_id AND deleted = FALSE AND price_from <> \'\'' .
                        implode(' ', $sub_queries) . ';'
                    );
                    $statement2->bindValue(':instance_id', Setup::$instance_id);
                    $statement2->execute();
                    $temp = $statement2->fetchAll();
                    foreach ($temp as $index_row => $row) {
                        $rows[$row['slug']] = $this->normalizeRow($row);
                    }
                    $temp = null;
                    $statement2 = null;
                    $arr['price_from'] = $rows;
                    continue;
                } else if ('not_online' === $term) { // select only variants that are not online (for admins...)
                    $statement2 = $this->conn->prepare('SELECT *, variant_id AS id, \'cms_variant\' AS table_name FROM cms_variant ' .
                        'WHERE instance_id = :instance_id AND deleted = FALSE AND online = FALSE ' .
                        implode(' ', $sub_queries) . ';'
                    );
                    $statement2->bindValue(':instance_id', Setup::$instance_id);
                    $statement2->execute();
                    $temp = $statement2->fetchAll();
                    foreach ($temp as $index_row => $row) {
                        $rows[$row['slug']] = $this->normalizeRow($row);
                    }
                    $temp = null;
                    $statement2 = null;
                    $arr['not_online'] = $rows;
                    continue;
                }
                //Help::removeAccents($term)
                $term = '%' . Help::removeAccents($this->toLower($term)) . '%';
                $statement->bindValue(':term', $term);
                $statement->execute();
                $temp = $statement->fetchAll();
                foreach ($temp as $index_row => $row) {
                    $rows[$row['slug']] = $this->normalizeRow($row);
                }
                $temp = null;
                $arr[$term] = $rows;
            }
            $intersected = null;
            $statement = null;
            foreach ($arr as $index => $term) {
                if (null === $intersected) {
                    $intersected = $term;
                } else {
                    $intersected = array_intersect_key($term, $intersected);
                }
            }
            // order by pop_vote / date_popvote
            usort($intersected, function ($a, $b) {
                //$a_date = \DateTime::createFromFormat('Y-m-d H:i:s.uP', $a['date_popvote']);
                //$b_date = \DateTime::createFromFormat('Y-m-d H:i:s.uP', $b['date_popvote']);
                return ($a->date_popvote < $b->date_popvote);
            });

            // now make it a regular numerically indexed array again and return it as rows
            return array_values($intersected);
        }
        // NOTE you need the deleted = FALSE when searching, or else elements may be cached by getOutput while deleted
        $statement = $this->conn->prepare(
            'SELECT *, ' . $type_name . '_id AS id, \'cms_' . $type_name . '\' AS table_name FROM cms_' . $type_name . ' ' .
            'WHERE (instance_id = :instance_id AND deleted = FALSE AND ci_ai LIKE :term) ' .
            implode(' ', $sub_queries) . ';'
        );
        $statement->bindValue(':instance_id', Setup::$instance_id);
        //var_dump($statement->queryString);
        foreach ($terms as $index => $term) {
            $rows = array();
            $term = '%' . Help::removeAccents($this->toLower($term)) . '%';
            $statement->bindValue(':term', $term);
            $statement->execute();
            $temp = $statement->fetchAll();
            foreach ($temp as $index_row => $row) {
                $rows[$row['slug']] = $this->normalizeRow($row);
            }
            $temp = null;
            $arr[$term] = $rows;
        }
        $intersected = null;
        $statement = null;
        foreach ($arr as $index => $term) {
            if (null === $intersected) {
                $intersected = $term;
            } else {
                $intersected = array_intersect_key($term, $intersected);
            }
        }
        // order by date published
        usort($intersected, function ($a, $b) {
            if (!isset($a->date_published)) return false;

            return ($a->date_published < $b->date_published);
        });

        // now make it a regular numerically indexed array again and return it as rows
        return array_values($intersected);
    }

    /**
     * @param int $search_settings_id
     * @param int $limit
     * @return array
     * @since 0.7.0
     */
    public function fetchSearchLog(int $search_settings_id, int $limit = 500): array
    {
        $statement = $this->conn->prepare('SELECT * FROM _search_log WHERE instance_id = :instance_id' .
            ' AND search_settings_id = :search_settings_id AND deleted = FALSE ORDER BY date_updated DESC LIMIT ' . $limit);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':search_settings_id', $search_settings_id);
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * @param string $slug slug to look for
     * @return \stdClass
     * @since 0.7.0
     */
    public function fetchSearchSettingsBySlug(string $slug): \stdClass
    {
        // to be certain make $slug lower in database
        $slug = $this->toLower($slug);
        // get it from search settings
        $statement = $this->conn->prepare('SELECT * FROM _search_settings WHERE slug = :slug AND instance_id = :instance_id AND deleted = FALSE;');
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;
        if (count($rows) === 1) {
            return $this->normalizeRow($rows[0])->search_settings_id;
        } else { // get default TODO this is not very optimal, all the db round trips
            $statement = $this->conn->prepare('SELECT * FROM _search_settings WHERE instance_id = :instance_id AND deleted = FALSE ORDER BY "name" DESC LIMIT 1;');
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute();
            $rows = $statement->fetchAll();
            $statement = null;
            if (count($rows) === 1) {
                return $this->normalizeRow($rows[0]);
            } else { // no search settings available
                return (object)array('search_settings_id' => 0);
            }
        }
    }

    /**
     * @param string $path
     * @return \stdClass|null
     * @since 0.8.11
     */
    public function fetchElementIdAndTypeFromCache(string $path): ?\stdClass
    {
        $statement = $this->conn->prepare('SELECT id, type_name as type FROM _cache WHERE slug = :slug AND instance_id = :instance_id LIMIT 1;');
        $statement->bindValue(':slug', $path);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute(); // error handling necessary?
        $rows = $statement->fetchAll();
        $statement = null;
        if (count($rows) === 0) {
            return null;
        }

        return $this->normalizeRow($rows[0]);
    }

    /**
     * @param $slug string the slug to find an element by
     * @param $no_cache bool default false, set to true if you want to ignore cache when getting the type and id
     * @return \stdClass|null returns a stdClass (normalized row) with ->type and ->id, or null when not found
     * @since 0.0.0
     */
    public function fetchElementIdAndTypeBySlug(string $slug, bool $no_cache = false): ?\stdClass
    {
        // @since 0.7.5 if the slug is not in the format of a slug, no need to go look for it
        if ($slug !== Help::slugify($slug)) return null;
        // to be certain make $slug lower in database
        $slug = $this->toLower($slug);
        // find appropriate item in database
        // @since 0.6.0 check the cache first
        if (false === $no_cache) {
            $statement = $this->conn->prepare('SELECT id, type_name as type FROM _cache WHERE slug = :slug AND instance_id = :instance_id LIMIT 1;');
            $statement->bindValue(':slug', $slug);
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute(); // error handling necessary?
            $rows = $statement->fetchAll();
        }
        if (count($rows ?? []) === 0) {
            $statement = $this->conn->prepare('
                SELECT page_id AS id, \'page\' AS type FROM cms_page WHERE slug = :slug AND instance_id = :instance_id
                UNION ALL 
                SELECT image_id AS id, \'image\' AS type FROM cms_image WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT embed_id AS id, \'embed\' AS type FROM cms_embed WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT file_id AS id, \'file\' AS type FROM cms_file WHERE slug = :slug AND instance_id = :instance_id
                UNION ALL 
                SELECT menu_id AS id, \'menu\' AS type FROM cms_menu WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT brand_id AS id, \'brand\' AS type FROM cms_brand WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT serie_id AS id, \'serie\' AS type FROM cms_serie WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT product_id AS id, \'product\' AS type FROM cms_product WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT variant_id AS id, \'variant\' AS type FROM cms_variant WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT property_id AS id, \'property\' AS type FROM cms_property WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT property_value_id AS id, \'property_value\' AS type FROM cms_property_value WHERE slug = :slug AND instance_id = :instance_id 
                UNION ALL 
                SELECT search_settings_id AS id, \'search\' AS type FROM _search_settings WHERE slug = :slug AND instance_id = :instance_id 
                ;
            ');
            $statement->bindValue(':slug', $slug);
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute(); // error handling necessary?
            $rows = $statement->fetchAll();
        }
        $statement = null;
        if (count($rows) === 1) {
            return $this->normalizeRow($rows[0]);
        } else {
            if (count($rows) > 1) {
                $this->addError(sprintf('DB->fetchElementIdAndTypeBySlug: ‘%1$s’ returned %2$d rows', $slug, count($rows)));
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
        $r = new \stdClass;
        // TODO make this use the search functionality to return better results
        $r->rows = $this->fetchRows($peat_type->tableName(),
            array($peat_type->idColumn(), 'title', 'slug'),
            array('title' => '%' . $src . '%'));
        $r->element = $peat_type->typeName();
        $r->src = $src;

        return $r;
    }

    /**
     * @param string $src
     * @return \stdClass
     * @since 0.8.0
     */
    public function fetchPropertiesRowSuggestions(string $src): \stdClass
    {
        $r = new \stdClass;
        $src = Help::removeAccents($this->toLower($src));
        $statement = $this->conn->prepare('SELECT p.property_id, pv.property_value_id, CONCAT(p.title, \': \', v.title) AS title, 
v.slug, (v.online AND p.online) AS online FROM cms_property p INNER JOIN cms_property_x_property_value pv
ON p.property_id = pv.sub_property_id INNER JOIN cms_property_value v ON v.property_value_id = pv.property_value_id WHERE
pv.deleted = FALSE AND p.deleted = FALSE AND v.deleted = FALSE AND p.instance_id = ' . Setup::$instance_id .
            ' AND (LEFT(p.ci_ai, 20) LIKE :src OR v.ci_ai LIKE :src) ORDER BY v.date_updated DESC');
        $statement->bindValue(':src', '%' . $src . '%');
        $statement->execute();
        $r->rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        /*$r->rows = $this->fetchRows('cms_property_value',
            array('property_value_id', 'title', 'slug'),
            array('title' => '%' . $src . '%'));*/
        $r->element = 'x_value';
        $r->src = $src;

        return $r;
    }

    /**
     * @param string $for either ‘variant’ or ‘page’, the only elements with properties
     * @return array
     * @since 0.8.0
     */
    public function fetchProperties(string $for): array
    {
        if ('page' !== $for) $for = 'variant';
        $statement = $this->conn->prepare(
            'SELECT DISTINCT p.slug property_slug, p.title property_title, pv.slug, pv.title, pxv.sub_o ' .
            'FROM cms_property p ' .
            'INNER JOIN cms_' . $for . '_x_properties xp ON p.property_id = xp.property_id ' .
            'INNER JOIN cms_property_value pv ON pv.property_value_id = xp.property_value_id ' .
            'INNER JOIN cms_property_x_property_value pxv ON pxv.property_value_id = xp.property_value_id ' .
            'WHERE p.instance_id = :instance_id AND p.deleted = FALSE AND pv.deleted = FALSE AND pxv.deleted = FALSE ' .
            'ORDER BY p.title, pxv.sub_o, pv.title'
        );
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        $return_arr = array();
        $current_prop = '';
        foreach ($rows as $index => $row) {
            if ($row->property_slug !== $current_prop) {
                $current_prop = $row->property_slug;
                $return_arr[$current_prop] = array();
            }
            $return_arr[$current_prop][$row->slug] = $row->title;
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
        $rows = $this->normalizeRows($statement->fetchAll());
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
        $key = $this->insertRowAndReturnKey(
            $peat_type->tableName() . '_x_properties',
            array(
                $peat_type->typeName() . '_id' => $id,
                'property_id' => $property_id,
                'property_value_id' => $property_value_id
            )
        );

        return $this->selectRow($peat_type->tableName() . '_x_properties', $key);
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
        if (($affected = $this->deleteRowWhereAndReturnAffected(
                $peat_type->tableName() . '_x_properties',
                array(
                    $peat_type->typeName() . '_id' => $id,
                    $peat_type->typeName() . '_x_properties_id' => $x_value_id,
                )
            )) === 1) {
            return true;
        } else {
            $this->handleErrorAndStop(sprintf('->deleteXValueLink affected %s rows', $affected));
        }

        return false;
    }

    /**
     * @return array
     * @since 0.8.0
     */
    public function fetchPropertySlugArrayById(): array
    {
        $statement = $this->conn->prepare(
            'SELECT property_id, slug FROM cms_property WHERE instance_id = ' . Setup::$instance_id
        );
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        $return = array();
        foreach ($rows as $index => $row) {
            $return[$row->property_id] = $row->slug;
        }
        $rows = null;

        return $return;
    }

    /**
     * Standard way to list variants ordered by popvote desc, the expected order
     *
     * @param int $limit the max number of variants returned, 0 for all
     * @param array $exclude_ids array with variant_ids that will not be selected
     * @return array
     * @since 0.5.15
     */
    public function listVariants(int $limit = 8, array $exclude_ids = array()): array
    {
        // todo refactor all these with the arrays to in/exclude stuff and that use the ONLINE
        // todo column depending on admin into a generic fetchRows‘Where’ variant or something
        $limit_clause = ($limit === 0) ? '' : ' LIMIT ' . $limit;
        $not_in_clause = (count($exclude_ids) === 0) ? '' :
            ' AND variant_id NOT IN (' . str_repeat('?,', count($exclude_ids) - 1) . '?)';
        // TODO when admin chosen ONLINE value should be taken into account
        $and_where_online = (false === ADMIN) ? ' AND el.online = TRUE' : '';
        // build statement
        $statement = $this->conn->prepare('SELECT el.*, variant_id AS id, \'cms_variant\' AS table_name FROM cms_variant el WHERE el.deleted = FALSE ' .
            $and_where_online . ' AND el.instance_id = ' . Setup::$instance_id .
            $not_in_clause . ' ORDER BY date_popvote DESC ' . $limit_clause . ';');
        $statement->execute($exclude_ids);
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * @param int $variant_id
     * @return array Indexed array containing the variant ids collected
     * @since 0.5.15
     */
    public function fetchRelatedVariantIds(int $variant_id): array
    {
        // TODO refactor to a better name
        // fetch some variant_id’s based on other orders that include the supplied variant_id
        // you don’t need to check instance id because in each order variants are necessarily of the same instance
        $statement = $this->conn->prepare('SELECT variant_id FROM _order_variant WHERE order_id IN (SELECT order_id FROM _order_variant WHERE variant_id = :variant_id);');
        $statement->bindValue(':variant_id', $variant_id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;
        foreach ($rows as $index => $row) {
            $rows[$index] = $row[0];
        }

        return $rows;
    }

    /**
     * Return the variant_ids that have all the provided property values attached to them as
     * well as the property->id or property_value->id that is the first two arguments
     * @param string $type_name literal ‘property’ or ‘property_value’
     * @param int $id the id of the property or property_value that is mandatory for the variant_ids
     * @param array $properties
     * @return array indexed array with all the variant_ids
     * @since 0.8.12
     */
    public function fetchAllVariantIdsFor(string $type_name, int $id, array $properties): array
    {
        if ('property_value' !== $type_name && 'property' !== $type_name) return array();
        $type = new Type($type_name);
        $sub_queries = array();
        // get all the relevant property_value_id s from $properties, and select only items that also have those in their x-table
        $property_value_ids = array();
        foreach ($properties as $property_name => $property_values) {
            if ('price_min' === $property_name) {
                $sub_queries[] = sprintf(
                    'AND (peat_parse_float(price, \'%1$s\', \'%2$s\') > %3$s) ',
                    Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$property_values[0]
                );
            } elseif ('price_max' === $property_name) {
                $sub_queries[] = sprintf(
                    'AND (peat_parse_float(price, \'%1$s\', \'%2$s\') <= %3$s) ',
                    Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$property_values[0]
                );
            } else {
                foreach ($property_values as $index => $value) {
                    if (($row = $this->fetchElementIdAndTypeBySlug($value))) {
                        if ('property_value' === $row->type) {
                            $property_value_ids[] = (int)$row->id;
                        }
                    }
                }
            }
        }
        foreach ($property_value_ids as $index => $property_value_id) {
            $sub_queries[] = sprintf(
                'AND x.variant_id IN (SELECT variant_id FROM cms_variant_x_properties' .
                ' WHERE property_value_id = %d) ',
                $property_value_id
            );
        }
        /*if (0 !== count($property_values)) {
            $statement = $this->conn->prepare('SELECT property_value_id FROM cms_property_value WHERE slug = :slug');
            foreach ($property_values as $index => $slug) {
                $statement->bindValue(':slug', $slug);
                $statement->execute();
                if (($row = $statement->fetchAll())) {
                    $sub_queries[] = ' AND variant_id IN (SELECT variant_id FROM cms_variant_x_properties WHERE property_value_id = ' . $row[0][0] . ')';
                }
            }
        }*/
        $statement = $this->conn->prepare('
            SELECT DISTINCT x.variant_id FROM cms_variant_x_properties x
            INNER JOIN cms_variant v ON v.variant_id = x.variant_id
            WHERE x.' . $type->idColumn() . ' = :id AND x.deleted = FALSE AND v.deleted = FALSE ' .
            implode(' ', $sub_queries)
        );
        $statement->bindValue(':id', $id);
        $statement->execute();
        $fetched_array = $statement->fetchAll();
        //echo 'executed sql string: ' . str_replace(':id', $id, $statement->queryString);
        $statement = null;
        $return_array = array();
        // todo make this a helper function or something?
        foreach ($fetched_array as $index => $row) {
            $return_array[] = $row[0];
        }

        return $return_array;
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
        $rows = $statement->fetchAll();
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
        $statement = $this->conn->prepare('
            SELECT DISTINCT v.slug FROM cms_variant_x_properties x
            INNER JOIN cms_property_value v ON x.property_value_id = v.property_value_id
            WHERE x.variant_id IN (' . implode(',', $variant_ids) . ');
        ');
        $statement->execute();
        $fetched_array = $statement->fetchAll();
        //echo 'executed sql string: ' . str_replace(':id', $id, $statement->queryString);
        $statement = null;
        $return_array = array();
        // todo make this a helper function or something?
        foreach ($fetched_array as $index => $row) {
            $return_array[] = $row[0];
        }

        return $return_array;
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
        $and_where = ' ';
        if ($table_info->hasStandardColumns()) {
            $sorting = 'ORDER BY date_created DESC';
            $and_where = ' AND deleted = FALSE ';
        } else {
            $sorting = '';
        }
        // preferred sorting:
        if ($table_info->getColumnByName('date_published')) {
            $sorting = 'ORDER BY date_published DESC';
        } elseif ($table_info->getColumnByName('date_popvote')) {
            $sorting = 'ORDER BY date_popvote DESC';
        }
        $statement = $this->conn->prepare('SELECT * FROM ' . $table_name .
            ' WHERE instance_id = ' . Setup::$instance_id . $and_where .
            $sorting . ' LIMIT ' . $page_size . ' OFFSET ' . $offset . ';');
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * To help the fetchElementRowsPage functionality have paging controls
     *
     * @param Type $peat_type
     * @param int $page_size default 400 use the same as fetchElementRowsPage obviously to get the right amount returned
     * @return array
     */
    public function fetchElementRowsPageNumbers(Type $peat_type, int $page_size = 400): array
    {
        $table_name = $peat_type->tableName();
        $table_info = $this->getTableInfo($table_name);
        $sql = 'SELECT COUNT(1) FROM ' . $table_name . ' WHERE instance_id = ' . Setup::$instance_id;
        if ($table_info->hasStandardColumns()) {
            $sql .= ' AND deleted = FALSE';
        }
        $statement = $this->conn->prepare($sql);
        $statement->execute();
        $number_of_pages = ceil($statement->fetchAll()[0][0] / $page_size);
        $statement = null;
        $return = array();
        for ($i = 1; $i <= $number_of_pages; ++$i) {
            $return[] = array('page_number' => $i);
        }

        return $this->normalizeRows($return);
    }

    /**
     * @param Type $peat_type
     * @param string $column_name
     * @param array $in indexed array holding id’s, when empty this method ALWAYS returns an empty array
     * @param boolean $exclude default false, when true ‘NOT IN’ is used rather than ‘IN’
     * @param int $limit default 1000 limits the number of rows
     * @return array indexed array holding plain row objects that are online (non-admin only) and not deleted
     * @since 0.5.15
     */
    public function fetchElementRowsWhereIn(Type $peat_type, string $column_name, array $in, bool $exclude = false, int $limit = 1000): array
    {
        if (count($in) === 0) return array();
        $table_name = $peat_type->tableName();
        $table_info = $this->getTableInfo($table_name);
        $sorting = '';
        if ($table_info->getColumnByName('date_published')) {
            $sorting = 'ORDER BY date_published DESC';
        } elseif ($table_info->getColumnByName('date_popvote')) {
            $sorting = 'ORDER BY date_popvote DESC';
        } elseif ($table_info->getColumnByName('o')) {
            $sorting = 'ORDER BY o ASC';
        }
        if ($table_info->getColumnByName($column_name)) {
            // TODO when admin chosen ONLINE value should be taken into account
            $and_where_online = (false === ADMIN) ? ' AND el.online = TRUE' : '';
            $statement = $this->conn->prepare('SELECT el.*, ' .
                $table_info->getIdColumn() . ' AS id, \'' . $table_name . '\' AS table_name FROM ' . $table_name .
                ' el WHERE el.deleted = FALSE AND instance_id = ' . Setup::$instance_id . ' ' .
                $and_where_online . ' AND el.' . $column_name . ($exclude ? ' NOT ' : '') .
                ' IN (' . str_repeat('?,', count($in) - 1) . '?) ' .
                $sorting . ' LIMIT ' . $limit . ';');
            $statement->execute($in);
            $rows = $statement->fetchAll();
            $statement = null;

            return $this->normalizeRows($rows);
        } else { // column doesn't exist
            $this->addError(sprintf('Column ‘%s’ does not exist', $column_name));

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
        $link_table = $table_name . '_x_properties';
        $statement = $this->conn->prepare(
            'SELECT ' . $type_name . '_x_properties_id x_value_id, x.o, x.x_value, ' .
            'p.slug property_slug, p.title property_title, v.slug, v.title, (p.online AND v.online) online, ' .
            'EXISTS(SELECT 1 FROM cms_' . $type_name . '_x_properties ' .
            ' WHERE property_value_id = x.property_value_id AND TRIM(x_value) <> \'\' ) property_value_uses_x_value FROM '
            . $link_table . ' x INNER JOIN cms_property p ON p.property_id = x.property_id INNER JOIN ' .
            ' cms_property_value v ON v.property_value_id = x.property_value_id WHERE x.' .
            $id_column . ' = :id AND v.deleted = FALSE AND x.deleted = FALSE ORDER BY x.o;'
        );
        $statement->bindValue(':id', $id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
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
     */
    public function fetchElementRowsLinkedX(Type $peat_type, int $id, Type $linked_type, int $variant_page_size, int $variant_page, ?array $properties): array
    {
        // gets the specified $linked_type through the appropriate x_value cross table by $peat_type $id
        $table_name = $linked_type->tableName();
        $id_column = $linked_type->idColumn();
        $x_table = $table_name . '_x_properties';
        $sub_queries = array();
        // properties
        if (isset($properties)) { // we need to filter the linked items
            // get all the relevant property_value_id s from $properties, and select only items that also have those in their x-table
            $property_value_ids = array();
            foreach ($properties as $property_name => $property) {
                foreach ($property as $index => $value) {
                    if ('price_min' === $property_name) {
                        if ('cms_variant' === $table_name) {
                            $sub_queries[] = sprintf(
                                'AND (peat_parse_float(el.price, \'%1$s\', \'%2$s\') > %3$s) ',
                                Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$value
                            );
                        }
                    } elseif ('price_max' === $property_name) {
                        if ('cms_variant' === $table_name) {
                            $sub_queries[] = sprintf(
                                'AND (peat_parse_float(el.price, \'%1$s\', \'%2$s\') <= %3$s) ',
                                Setup::$DECIMAL_SEPARATOR, Setup::$RADIX, (int)$value
                            );
                        }
                    } elseif (($row = $this->fetchElementIdAndTypeBySlug($value))) {
                        if ('property_value' === $row->type) {
                            $property_value_ids[] = (int)$row->id;
                        }
                    }
                }
            }
            // index exists on ...x_properties tables on property_value_id
            foreach ($property_value_ids as $index => $property_value_id) {
                $sub_queries[] = sprintf(
                    'AND el.' . $id_column . ' IN (SELECT ' . $id_column . ' FROM ' . $x_table .
                    ' WHERE property_value_id = %d) ',
                    $property_value_id
                );
            }
        }
        // sorting...
        $sorting = ' ORDER BY date_created DESC ';
        $paging = '';
        if ($table_name === 'cms_variant') {
            $sorting = ' AND el.online = TRUE ORDER BY date_popvote DESC '; // @since 0.8.15 no variants that are not online, because of paging
            $paging = ' LIMIT ' . $variant_page_size . ' OFFSET ' . ($variant_page_size * ($variant_page - 1));
        }
        if ($table_name === 'cms_page') {
            $sorting = ' ORDER BY date_published DESC ';
            $sub_queries[] = 'AND date_published < NOW() - INTERVAL \'5 minutes\''; // allow a few minutes for the cache to update
        }
        $statement = $this->conn->prepare('SELECT DISTINCT el.* FROM ' . $x_table . ' x INNER JOIN ' .
            $table_name . ' el ON el.' . $id_column . ' = x.' . $id_column .
            ' WHERE x.' . $peat_type->idColumn() . ' = :id AND x.deleted = FALSE AND el.deleted = FALSE ' .
            implode(' ', $sub_queries) . $sorting . $paging . ';');
        $statement->bindValue(':id', $id);
        $statement->execute();
//var_dump(str_replace(':id', $id, $statement->queryString));
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * Fetches the linked items as specified by $linked_type according to the structure of the
     * cross-links tables respecting the order therein as well as the ordering within the types themselves when relevant
     * In case of variants, it will use the page size and page number supplied
     * @param Type $peat_type
     * @param int $id
     * @param Type $linked_type
     * @param string $relation
     * @param int $variant_page_size
     * @param int $variant_page
     * @return array
     */
    public function fetchElementRowsLinked(Type $peat_type, int $id, Type $linked_type, string $relation, int $variant_page_size, int $variant_page): array
    {
        $peat_type_name = $peat_type->typeName();
        $linked_type_name = $linked_type->typeName();
        if ($linked_type_name === 'variant') {
            $paging = ' LIMIT ' . $variant_page_size . ' OFFSET ' . ($variant_page_size * ($variant_page - 1));
        } else {
            $paging = '';
        }
        if ($relation === 'cross_parent') { // switch $type and $linked_type around
            $link_table = 'cms_' . $linked_type_name . '_x_' . $peat_type_name;
            $statement = $this->conn->prepare(
                'SELECT el.*, x.o FROM cms_' . $linked_type_name . ' el INNER JOIN ' .
                $link_table . ' x ON x.sub_' . $linked_type_name . '_id = el.' . $linked_type_name . '_id WHERE x.' .
                $peat_type_name . '_id = :id AND el.deleted = FALSE AND x.deleted = FALSE ORDER BY x.o ' . $paging . ';'
            );
        } elseif ($relation === 'cross_child') {
            $link_table = 'cms_' . $peat_type_name . '_x_' . $linked_type_name;
            //echo '<h1>' . $relation . ': ' . $type . '_x_' . $linked_type . '</h1>';
            $statement = $this->conn->prepare(
                'SELECT el.*, x.sub_o FROM cms_' . $linked_type_name . ' el INNER JOIN ' .
                $link_table . ' x ON x.' . $linked_type_name . '_id = el.' . $linked_type_name . '_id WHERE x.sub_' .
                $peat_type_name . '_id = :id AND el.deleted = FALSE AND x.deleted = FALSE ORDER BY x.sub_o' . $paging . ';'
            );
            // direct relations: eg type = product, linked_type = variant, relation = direct_parent
        } elseif ($relation === 'direct_parent') { // get the e-commerce element this table is a direct parent of
            // honor popvote sorting...
            $sorting = ($linked_type_name === 'variant') ? ' ORDER BY date_popvote DESC' : '';
            $statement = $this->conn->prepare(
                'SELECT el.* FROM cms_' . $linked_type_name . ' el WHERE el.' .
                $peat_type_name . '_id = :id AND el.deleted = FALSE' . $sorting . ' ' . $paging . ';'
            );
        } elseif ($relation === 'direct_child') { // relation must be direct_child
            $statement = $this->conn->prepare(
                'SELECT el.* FROM cms_' . $linked_type_name . ' el INNER JOIN cms_' . $peat_type_name . ' t ON t.' .
                $linked_type_name . '_id = el.' . $linked_type_name . '_id WHERE t.' . $peat_type_name .
                '_id = :id AND el.deleted = FALSE ' . $paging . ';'
            );
        } else {
            $statement = 'SELECT :id;';
        }
        $statement->bindValue(':id', $id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * Fetches the slug of the homepage for the specified instance by instance_id
     * @param int $instance_id
     * @return string
     * @since 0.8.3
     */
    public function fetchHomeSlug(int $instance_id): string
    {
        $statement = $this->conn->prepare(
            'SELECT slug FROM cms_page WHERE page_id = (SELECT homepage_id FROM _instance WHERE instance_id = :instance_id);'
        );
        $statement->bindValue(':instance_id', $instance_id);
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
    public function updatePopVote(string $element_name, int $id, bool $down_vote = false): float
    {
        $type = new Type($element_name);
        if (false === $down_vote) {
            $statement = $this->conn->prepare(
                'UPDATE ' . $type->tableName() . ' SET date_popvote = NOW() WHERE ' .
                $type->typeName() . '_id = ? AND instance_id = ?');
            $statement->execute(array($id, Setup::$instance_id));
            if ($statement->rowCount() === 1) {
                $this->reCacheWithWarmup($this->fetchElementRow($type, $id)->slug);

                return 0; // this is now the highest voted
            } else {
                $this->addError(sprintf('updatePopVote error for ‘%1$s’ with id %2$s', $element_name, $id));
            }
        } else { // downvote..., move approx one down on every vote by decreasing the date_popvote by random 1 - 20 minutes
            // or 8 hours when this is already the lowest
            $statement = $this->conn->prepare('UPDATE ' . $type->tableName() .
                ' SET date_popvote = COALESCE((SELECT date_popvote - CAST(floor(random() * 20 + 1) ||  \' minutes\' as INTERVAL) FROM ' .
                $type->tableName() . ' WHERE date_popvote < (SELECT date_popvote FROM ' . $type->tableName() . ' WHERE ' . $type->typeName() .
                '_id = :id AND instance_id = :instance_id AND deleted = FALSE) ORDER BY date_popvote DESC LIMIT 1), ' .
                'date_popvote - CAST(8 ||  \' hours\' as INTERVAL))  WHERE ' .
                $type->typeName() . '_id = :id AND instance_id = :instance_id;');
            $statement->bindValue(':id', $id);
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute();
            $count = $statement->rowCount();
            $statement = null;
            if ($count === 1) {
                $this->reCacheWithWarmup($this->fetchElementRow($type, $id)->slug);

                return $this->getPopVote($element_name, $id);
            } else {
                $this->addError(sprintf('updatePopVote returned %s rows affected...', $count));
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
        // select the relative position of this specific id in all the records
        $statement = $this->conn->prepare('SELECT COUNT(' . $type->typeName() . '_id) FROM ' . $type->tableName() .
            ' WHERE deleted = FALSE AND instance_id = :instance_id AND date_popvote > (SELECT date_popvote FROM ' .
            $type->tableName() . ' WHERE ' . $type->typeName() . '_id = :id);');
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':id', $id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;
        $position = (count($rows) > 0) ? (float)$rows[0][0] : 0; // the returned count or 0 (apparently it’s the first one)
        if ($position > 0) { // get it relative to the total number of rows
            $statement = $this->conn->prepare('SELECT COUNT(' . $type->typeName() . '_id) FROM ' . $type->tableName() .
                ' WHERE deleted = FALSE AND instance_id = :instance_id');
            $statement->bindValue(':instance_id', Setup::$instance_id);
            $statement->execute();
            $rows = $statement->fetchAll();
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
        $rows = $statement->fetchAll();
        $statement = null;
        if (count($rows) === 1) {
            return \intval($rows[0][0]);
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
     * Returns the search settings that belong to a specific instance
     * @param int $instance_id defaults to the current instance
     * @return array|null indexed array of \stdClass (row) objects
     * @since 0.7.0
     */
    public function getSearchSettings(int $instance_id = -1): ?array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->fetchRows(
            '_search_settings',
            array('search_settings_id', 'name', 'slug', 'use_fts', 'fts_language', 'template_id', 'o'),
            array('instance_id' => $instance_id)
        );
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
        $statement = $this->conn->prepare(
            'SELECT * FROM _redirect WHERE instance_id = :instance_id AND deleted = FALSE ORDER BY term ASC;');
        $statement->bindValue(':instance_id', $instance_id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * @param string $feed_name the name to get the feed by
     * @return \stdClass|null with ->feed_name, ->instagram_username, ->instagram_hashtag and ->feed which is __media__
     * since 0.7.3
     */
    public function getInstagramFeed(string $feed_name): ?\stdClass
    { // can only get feed for this instance
        $statement = $this->conn->prepare('SELECT * FROM _instagram_feed WHERE feed_name = :feed_name AND instance_id = :instance_id AND deleted = FALSE;');
        $statement->bindValue(':feed_name', $feed_name);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;
        $return_value = count($rows) > 0 ? $this->normalizeRow($rows[0]) : null;
        $rows = null;

        return $return_value;
    }

    /**
     * Get appropriate media entries to load in a feed (cache)
     * @param int $instance_id
     * @param string|null $username
     * @param string|null $hashtag
     * @param int $limit
     * @param string $cdnroot
     * @return array
     * @since 0.7.3
     */
    public function fetchInstagramMediaForFeed(int $instance_id, ?string $username, ?string $hashtag, int $limit, string $cdnroot): array
    {
        if (null === $username) $username = '';
        if (null === $hashtag) $hashtag = '';
        // if a certain username is requested, only get their media, otherwise combine the media of all users
        // users MUST be AUTHORIZED FOR THIS INSTANCE
        $sql = 'SELECT caption, media_type, src, media_url, permalink, user_id, instagram_username AS username, instagram_timestamp AS timestamp';
        // @since 0.10.0 include the processed images TODO don’t include the cdnroot hardcoded in the feed
        $sql .= ', concat(cast(:cdnroot AS text), src_tiny) AS src_tiny, width_tiny, height_tiny';
        $sql .= ', concat(cast(:cdnroot AS text), src_small) AS src_small, width_small, height_small';
        $sql .= ', concat(cast(:cdnroot AS text), src_medium) AS src_medium, width_medium, height_medium';
        $sql .= ', concat(cast(:cdnroot AS text), src_large) AS src_large, width_large, height_large';
        $sql .= ' FROM _instagram_media';
        $sql .= ' WHERE user_id IN (SELECT user_id FROM _instagram_auth WHERE instance_id = :instance_id AND deleted = FALSE)';
        if ('' !== $username) $sql .= ' AND instagram_username = :username';
        if ('' !== $hashtag) $sql .= ' AND caption LIKE :hashtag';
        $sql .= ' AND NOT instagram_timestamp IS NULL ORDER BY instagram_timestamp DESC LIMIT ' . $limit . ';';
        $statement = $this->conn->prepare($sql);
        $statement->bindValue(':instance_id', $instance_id);
        $statement->bindValue(':cdnroot', $cdnroot);
        if ('' !== $username) $statement->bindValue(':username', $username);
        if ('' !== $hashtag) $statement->bindValue(':hashtag', '%#' . $hashtag . '%');
        //select * from _instagram_media where username = and caption like hashtag order by instagram_timestamp desc limit quantity
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    public function fetchInstagramDeauthorized()
    {
        return $this->fetchRows(
            '_instagram_auth',
            array('instagram_auth_id', 'user_id'),
            array('deauthorized' => true, 'deleted' => false, 'instance_id' => null) // deleted=>false is standard but included here for clarity
        // a deauthorized instagram_auth row will be set to deleted after the process, so no need to select deleted rows
        );
    }

    /**
     * Gets instagram feeds (specs) that may contain media by the provided user id
     * @param int $user_id
     * @return array|null
     * @since 0.7.3
     */
    public function getInstagramFeedSpecsByUserId(int $user_id): ?array
    {
        $statement = $this->conn->prepare('SELECT * FROM _instagram_feed WHERE instance_id IN 
                                    (SELECT instance_id FROM _instagram_auth WHERE user_id = :user_id);');
        $statement->bindValue(':user_id', $user_id);
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    /**
     * @param int $user_id
     * @return int the number of instagram feeds that were invalidated
     * @since 0.7.4
     */
    public function invalidateInstagramFeedSpecsByUserId(int $user_id): int
    {
        $statement = $this->conn->prepare('UPDATE _instagram_feed SET date_updated = NOW()
            WHERE instagram_feed_id IN (SELECT instagram_feed_id FROM _instagram_feed WHERE instance_id IN 
            (SELECT instance_id FROM _instagram_auth WHERE user_id = :user_id));');
        $statement->bindValue(':user_id', $user_id);
        $statement->execute();
        $rows_affected = $statement->rowCount();
        $statement = null;

        return $rows_affected;
    }

    /**
     * @param int $user_id
     * @return int
     * @since 0.8.16
     */
    public function deleteInstagramMediaByUserId(int $user_id): int
    {
        $statement = $this->conn->prepare('UPDATE _instagram_media SET deleted = TRUE WHERE user_id = :user_id;');
        $statement->bindValue(':user_id', $user_id);
        $statement->execute();
        $rows_affected = $statement->rowCount();
        $statement = null;

        return $rows_affected;
    }

    /**
     * Gets feeds (specs) that were updated after their feed was gotten
     * @return array|null
     * @since 0.7.3
     */
    public function getInstagramFeedSpecsOutdated(): ?array
    {
        $statement = $this->conn->prepare('SELECT * FROM _instagram_feed WHERE date_updated > feed_updated;');
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    /**
     * @param int $instance_id
     * @return array|null
     * @since 0.7.3
     */
    public function getInstagramFeedSpecs(int $instance_id = -1): ?array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->fetchRows('_instagram_feed', array('*'), array('instance_id' => $instance_id));
    }

    /**
     * @param int $instance_id
     * @return array|null
     * @since 0.7.3
     */
    public function getInstagramAuthorizations(int $instance_id = -1): ?array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

        return $this->fetchRows('_instagram_auth', array(
            'instagram_auth_id',
            'user_id',
            'instagram_username',
            'access_token_expires',
            'access_granted',
            'deauthorized',
            'date_created',
            'done'
        ), array('instance_id' => $instance_id));
    }

    /**
     * NOTE use this serverside only, never reveal tokens to the client
     * @return array|null
     * @since 0.7.3
     */
    public function getInstagramUserTokenAndNext(): ?array
    {
        return $this->fetchRows('_instagram_auth', array(
            'instagram_auth_id', 'instance_id', 'user_id', 'access_token', 'next', 'done'
        ), array('instance_id' => null, 'deauthorized' => false));
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
     * @param array $vars all the vars that are explicitly needed for the ordering process, client-friendly validation must be done already
     * @return string|null
     * @since 0.5.12
     */
    public function placeOrder(Shoppinglist $shoppinglist, Session $session, array $vars): ?string
    {
        // we need specific vars for the ordering process, validation process has run, if something misses you should throw an error
        //count($order_rows = $this->getShoppingListRows($shoppinglist_id)) > 0
        if (count($order_rows = $shoppinglist->getRows()) > 0) {
            // TODO we need the session_id and the user_id (if exists) from that session as well...
            if (false === isset($vars['email'])) $this->addError('DB->placeOrder: email is not present');
            if (false === isset($vars['shipping_country_id'])) $this->addError('DB->placeOrder: shipping_country_id is not present');
            if ($this->hasError()) return null;
            // setup the necessary vars
            $country = $this->getCountryById((int)$vars['shipping_country_id']);
            $amount_row_total = 0;
            $shipping_costs = 0;
            $quantity_total = 0; // @since 0.7.6. also count the quantity, maybe there are only empty rows, you can’t order those
            $vat_categories = $this->getVatCategoriesByIdWithDefaultIn0(Setup::$instance_id);
            //$order_rows = $this->getShoppingListRows($shoppinglist_id); (already in if clause)
            foreach ($order_rows as $index => $row) {
                $amount_row_total += Help::getAsFloat($row->price) * $row->quantity;
                $quantity_total += $row->quantity;
            }
            if ($quantity_total === 0) { // @since 0.7.6 if there is nothing to order, also abandon the process
                $this->addMessage(__('There are no rows in this shoppinglist to order', 'peatcms'), 'warn');

                return null;
            }
            $amount_grand_total = $amount_row_total;
            if ($amount_grand_total < Help::getAsFloat($country->shipping_free_from)) {
                $shipping_costs = Help::getAsFloat($country->shipping_costs);
                $amount_grand_total += $shipping_costs;
            }
            // setup the order_number
            if (null === ($order_number = $this->createUniqueOrderNumber(Setup::$instance_id))) {
                $this->addMessage(__('Could not create unique order_number', 'peatcms'));
                $this->addError('Could not create unique order_number');

                return null;
            }
            // build an order array to insert into the database
            $order_fields = array(
                'instance_id' => Setup::$instance_id,
                'session_id' => $session->getId(),
                'user_id' => (($user = $session->getUser()) ? $user->getId() : 0),
                // you need float for humans here to prevent rounding errors, but I think there is more to it than that
                'amount_grand_total' => intval(100 * (float)Help::floatForHumans($amount_grand_total)),
                'amount_row_total' => intval(100 * (float)Help::floatForHumans($amount_row_total)),
                'shipping_costs' => intval(100 * (float)Help::floatForHumans($shipping_costs)),
                'user_gender' => $vars['gender'] ?? '',
                'user_email' => $vars['email'],
                'user_phone' => $vars['phone'] ?? '',
                'shipping_address_country_name' => $country->name,
                'shipping_address_country_iso2' => $country->iso2,
                'shipping_address_country_iso3' => $country->iso3,
                'order_number' => $order_number,
            );
            $order_fields = array_merge($vars, $order_fields); // last array overwrites first if the keys are the same
            try {
                $this->conn->beginTransaction();
                if (null === ($order_id = $this->insertRowAndReturnLastId('_order', $order_fields))) {
                    $this->addMessage(__('Could not create order', 'peatcms'), 'error');
                    $this->addError('Could not create order');

                    return null;
                }
                // now the rows
                foreach ($order_rows as $index => $row) {
                    $variant_row = $this->fetchElementRow(new Type('variant'), $row->variant_id);
                    $vat_row = $vat_categories[$variant_row->vat_category_id] ?? $vat_categories[0] ?? 0;
                    $vat_percentage = Help::getAsFloat($vat_row->percentage);
                    $specs = array(
                        'order_id' => $order_id,
                        'variant_id' => $row->variant_id,
                        'o' => $row->o,
                        'price_from' => $row->price_from,
                        'price' => $row->price,
                        'quantity' => $row->quantity,
                        // @since 0.5.16 enrich with some default values
                        'title' => $variant_row->title,
                        'mpn' => $variant_row->mpn,
                        'sku' => $variant_row->sku,
                        // @since 0.9.0 add vat
                        'vat_percentage' => $vat_percentage,
                    );
                    if (null === $this->insertRowAndReturnLastId('_order_variant', $specs)) {
                        $this->conn->rollBack();
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
        }

        return null;
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
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
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
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * Updates ci_ai when it is NULL (e.g. after an update)
     * @since 0.7.0
     */
    public function jobSearchUpdateIndexColumn(): void
    {
        // get all tables that have a template_id column
        $statement = $this->conn->prepare('
            SELECT t.table_name FROM information_schema.tables t
            INNER JOIN information_schema.columns c ON c.table_name = t.table_name AND c.table_schema = :schema
            WHERE c.column_name = \'ci_ai\' AND t.table_schema = :schema AND t.table_type = \'BASE TABLE\'
        ');
        $statement->bindValue(':schema', $this->db_schema);
        $statement->execute();
        $r_tables = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        foreach ($r_tables as $key => $r_table) {
            if (in_array(($table_name = $r_table->table_name), array('cms_image', 'cms_embed', 'cms_file'))) {
                $column = 'CONCAT(title, \' \', excerpt, \' \', description)';
            } else {
                $column = 'CONCAT(title, \' \', excerpt, \' \', content)';
            }
            echo "$table_name\t";
            $type = new Type($table_name);
            // now for each table get the entries that do not have their search index (ci_ai) column filled
            // NOTE (ci_ai = \'\') IS NOT FALSE checks for the ci_ai column being NULL or empty
            // https://stackoverflow.com/questions/23766084/best-way-to-check-for-empty-or-null-value
            $statement = $this->conn->prepare(
                'SELECT slug, LOWER(' . $column . ') AS search_column, ' . $type->idColumn() . ' AS id, date_updated FROM ' .
                $table_name . ' WHERE (ci_ai = \'\') IS NOT FALSE AND deleted = FALSE;');
            $statement->execute();
            echo $statement->rowCount() . ' rows eligible ';
            $rows = $this->normalizeRows($statement->fetchAll());
            // date_updated is used in case one of the columns is updated while this query is running
            // in that case you should hold off filling the ci_ai column until the next round, selecting
            // the row anew (it is actually untested, TODO how can I test this?)
            $count = 0;
            foreach ($rows as $key2 => $row) {
                $statement = $this->conn->prepare('UPDATE ' . $table_name .
                    ' SET ci_ai = :src WHERE ' . $type->idColumn() . ' = :id AND date_updated = :date;');
                $statement->bindValue(':src', Help::removeAccents($row->search_column));
                $statement->bindValue(':id', $row->id);
                $statement->bindValue(':date', $row->date_updated);
                $statement->execute();
                $count += $statement->rowCount();
            }
            echo $count . ' done' . PHP_EOL;
        }
        $statement = null;
        $r_tables = null;
        $type = null;
    }

    public function jobFetchImagesForCleanup(int $more_than_days = 365, int $how_many = 15): array
    {
        $statement = $this->conn->prepare(
            'SELECT * FROM cms_image WHERE date_processed < NOW() - ' . $more_than_days .
            '  * interval \'1 days\' AND filename_saved IS NOT NULL ORDER BY date_updated DESC LIMIT ' . $how_many
        );
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    public function jobFetchImagesForProcessing(int $how_many = 15): array
    {
        $statement = $this->conn->prepare('SELECT * FROM cms_image WHERE date_processed IS NULL AND filename_saved IS NOT NULL ORDER BY date_updated DESC LIMIT ' . $how_many);
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    public function jobFetchInstagramImagesForProcessing(int $how_many = 12): array
    {
        $statement = $this->conn->prepare(
            'SELECT * FROM _instagram_media WHERE date_processed IS NULL AND src IS NOT NULL ' .
            'AND deleted = FALSE AND flag_for_update IS FALSE ORDER BY date_updated DESC LIMIT ' . $how_many
        );
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    /**
     * Returns Instagram authorizations that are expiring in $days_in_advance days
     * @param int $days_in_advance default 5
     * @return array holding $row \stdClass objects with instagram_auth_id and access_token
     * @since 0.7.3
     */
    public function jobGetInstagramTokensForRefresh(int $days_in_advance = 5): array
    {
        $statement = $this->conn->prepare('SELECT instagram_auth_id, access_token FROM _instagram_auth WHERE access_token_expires < NOW() + ' .
            $days_in_advance . ' * interval \'1 days\';');
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    /**
     * Simply gets instagram media entries with NULL for username, indicating they were not processed yet
     * @param int $limit
     * @return array
     * @since 0.7.3
     */
    public function jobGetInstagramMediaIdsForRefresh(int $limit = 25): array
    {
        // the older entries are the newer instagram media entries since they are loaded / paged in reverse order in the api
        $statement = $this->conn->prepare('SELECT media_id, user_id FROM _instagram_media 
            WHERE (flag_for_update = TRUE OR instagram_username IS NULL) AND deleted = FALSE ORDER BY date_created ASC LIMIT ' . $limit . ';');
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    /**
     * Gets instagram media entries that went the longest time without updating
     * @param int $limit
     * @return array
     * @since 0.9.0
     */
    public function jobGetInstagramMediaIdsForRefreshByDate(int $limit = 25): array
    {
        $statement = $this->conn->prepare('SELECT media_id, user_id FROM _instagram_media 
            WHERE deleted = FALSE ORDER BY date_updated ASC LIMIT ' . $limit . ';');
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;

        return $rows;
    }

    /**
     * @param int $limit the maximum number of rows that wil be returned
     * @param bool $for_src @since 0.7.5 true if you only want entries where the src (cache location) is NULL
     * @return array the rows containing media_id, user_id and media_url
     * @since 0.7.4
     */
    public function jobGetInstagramMediaUrls(bool $for_src = false, int $limit = 100): array
    {
        $src_where = (true === $for_src) ? 'src IS NULL AND' : '';
        $statement = $this->conn->prepare('SELECT media_id, user_id, media_url
            FROM _instagram_media WHERE ' . $src_where . ' media_url IS NOT NULL 
            AND deleted = FALSE AND flag_for_update = FALSE LIMIT ' . $limit . ';');
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
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
    public function getTemplateRow(int $template_id, ?int $instance_id = -1): ?\stdClass
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;

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
     * @param string $for_element
     * @param int $instance_id
     * @return int|null
     * @since 0.5.7
     */
    public function getDefaultTemplateIdFor(string $for_element, int $instance_id = -1): ?int
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;
        $statement = $this->conn->prepare('SELECT template_id FROM _template WHERE instance_id = ? AND element = ? ORDER BY name;');
        $statement->execute(array($instance_id, $for_element));
        $rows = $statement->fetchAll();
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
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * @param int $instance_id
     * @return array
     * @since 0.5.7
     */
    public function getPartialTemplates(int $instance_id = -1): array
    {
        if ($instance_id === -1) $instance_id = Setup::$instance_id;
        $statement = $this->conn->prepare('SELECT * FROM _template WHERE instance_id = ? AND element = \'partial\' ORDER BY element, name;');
        $statement->execute(array($instance_id));
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
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
        $this->addError(sprintf(__('Template ‘%s’ not found', 'peatcms'), $template_name));

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
            $table_name = 'cms_menu_item_x_menu';
            if ($this->rowExists('cms_menu_item_x_menu_item', $where_to))
                $table_name = 'cms_menu_item_x_menu_item';
        } else { // if toggle is requested, remember if it existed
            if (false === $this->rowExists('cms_menu_item_x_menu', $where_not_deleted)) {
                if (false === $this->rowExists('cms_menu_item_x_menu_item', $where_not_deleted)) {
                    $table_name = 'cms_menu_item_x_menu';
                }
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
            if ($table_name === 'cms_menu_item_x_menu_item') {
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
                // todo make it nicer
                if ($row->sub_menu_item_id === $to_item_id or ($o === 1 and $to_item_id === 0)) { // the moved item must be inserted here
                    if (false === $this->updateRowAndReturnSuccess($table_name, array('o' => $o), $cross_table_row_id)) $success = false;
                    //if (false === $this->orderMenuItemInsert($table_name, $menu_id, $item_id, $o, $to_item_id)) $success = false;
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
        } else {
            if ($to_item_id > 0) {
                // insert into the menu items cross table
                return 0 !== $this->insertRowAndReturnLastId('cms_menu_item_x_menu_item', array_merge($where, array(
                    'menu_item_id' => $to_item_id,
                    'online' => true,
                    'deleted' => false,
                )));
            }
        }

        return (bool)$num_deleted;
    }

    public function upsertLinked(Type $type, int $id, Type $sub_type, int $sub_id, bool $delete = false, int $order = 0): bool
    {
        $type_name = $type->typeName();
        $sub_type_name = $sub_type->typeName();
        $tables = $this->getLinkTables($type);
        if (isset($tables->$sub_type_name)) {
            if ($tables->$sub_type_name === 'cross_parent') {
                $order_column = 'o';
            } else {
                Help::swapVariables($type_name, $sub_type_name);
                Help::swapVariables($id, $sub_id);
                $order_column = 'sub_o';
            }
            $link_table = 'cms_' . $sub_type_name . '_x_' . $type_name;
            $id_column = $sub_type_name . '_x_' . $type_name . '_id';
            $where = array(
                $type_name . '_id' => $id,
                'sub_' . $sub_type_name . '_id' => $sub_id,
            );
            if ($order === 0) {
                $update_array = array('deleted' => $delete);
            } else { // only update order column if required
                $update_array = array('deleted' => $delete, $order_column => $order);
            }
            if ($row = $this->fetchRow($link_table, array($id_column), $where)) { // update
                return $this->updateRowAndReturnSuccess($link_table, $update_array, $row->$id_column);
            } else { // insert
                return 0 !== $this->insertRowAndReturnLastId($link_table, array_merge($where, $update_array));
            }
        }
        $this->addError('->upsertLinked failed, no link table found for ' . $type_name . ' and ' . $sub_type_name);

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
SELECT s.session_id, s.reverse_dns, s.date_created, s.date_accessed, s.user_agent, s.deleted, i.domain, i.name, u.nickname as user_name
FROM _session s 
    LEFT OUTER JOIN _instance i 
        ON s.instance_id = i.instance_id
    LEFT OUTER JOIN _user u
        ON u.user_id = s.user_id
WHERE s.admin_id = :admin_id
');
        $statement->bindValue(':admin_id', $admin_id);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    public function fetchUserSessionCount(int $user_id): int
    {
        $statement = $this->conn->prepare('
SELECT COUNT(s.session_id)
FROM _session s 
    LEFT OUTER JOIN _user u
        ON u.user_id = s.user_id
WHERE s.user_id = :user_id AND s.deleted = FALSE
');
        $statement->bindValue(':user_id', $user_id);
        $statement->execute();
        $count = (int)$statement->fetchColumn(0);
        $statement = null;

        return $count;
    }

    public function fetchSessionsWithoutReverseDns()
    {
        $statement = $this->conn->prepare('SELECT token, ip_address FROM _session WHERE reverse_dns IS NULL');
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    public function fetchSession(string $token): ?\stdClass
    {
        // NOTE sessions are by instance_id
        if (!$row = $this->fetchRow('_session', array(
            'user_id',
            'admin_id',
            'session_id',
            'ip_address',
        ), array('token' => $token))) {
            $this->addError(sprintf('DB->fetchSession() returned nothing for token %s', $token));

            return null;
        }
        // get session vars
        if ($var_rows = $this->fetchRows('_sessionvars', array('name', 'value', 'times'), array('session_id' => $row->session_id))) {
            $vars = [];
            foreach ($var_rows as $var_key => $var_row) {
                // next line makes sure when a var occurs more than once we load the one with the highest times only
                if (isset($vars[($var_name = $var_row->name)]) and $vars[$var_name]->times > $var_row->times) continue;
                // set the var from the db into the array
                $vars[$var_name] = (object)array('value' => json_decode($var_row->value), 'times' => $var_row->times);
            }
            $row->vars = $vars;
        }

        return $row;
    }

    public function fetchForLogin(string $email, bool $fetch_admin = false): ?\stdClass
    {
        $email = $this->toLower($email);
        if (false === $fetch_admin) {
            $statement = $this->conn->prepare(
                'SELECT user_id AS id, password_hash AS hash
                FROM _user 
                WHERE instance_id = :instance_id AND email = :email AND is_account = TRUE AND deleted = FALSE;'
            );
        } else {
            $statement = $this->conn->prepare(
                'SELECT admin_id AS id, password_hash AS hash
                FROM _admin 
                WHERE (instance_id = :instance_id OR instance_id = 0) AND email = :email AND deleted = FALSE;'
            );
        }
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':email', $email);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;
        $num_rows = count($rows);
        if ($num_rows === 1) {
            return $this->normalizeRow($rows[0]);
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

    public function fetchInstancesForClient(int $client_id): array
    {
        return $this->fetchRows('_instance', array('*'), array(
            'client_id' => $client_id,
        ));
    }

    public function fetchInstanceCanonicalDomain(string $domain): ?string
    {
        // TODO SECURITY: an admin can put in a domain owned by another client, and so point it at their own site
        // TODO setup a verification mechanism, maybe a DNS query?
        $statement = $this->conn->prepare('SELECT i.domain FROM _instance i 
            INNER JOIN _instance_domain d ON d.deleted = FALSE AND i.instance_id = d.instance_id
            WHERE d.domain = ? AND d.deleted = false;');
        $statement->execute(array($domain));
        $rows = $statement->fetchAll();
        $statement = null;
        if (count($rows) > 0) {
            return $this->normalizeRow($rows[0])->domain;
        }

        return null;
    }

    // TODO regarding the menus you (also) need to take into account online and deleted flags
    public function fetchMenuItems(int $menu_or_menu_item_id, int $nested_level = 0): array
    {
        $items = array();
        // for nested_level 0 you get the subitems by menu_id, higher levels are subitems from subitems (obviously)
        if ($nested_level === 0) {
            $statement = $this->conn->prepare('SELECT i.menu_item_id, i.act, i.title, i.css_class, i.content, i.online ' .
                'FROM cms_menu_item i INNER JOIN cms_menu_item_x_menu x ON i.menu_item_id = x.sub_menu_item_id ' .
                'WHERE x.menu_id = ? AND i.deleted = FALSE AND x.deleted = FALSE ' .
                'ORDER BY x.o');
        } else { // higher levels, get the items by menu_item_id
            $statement = $this->conn->prepare('SELECT i.menu_item_id, i.act, i.title, i.css_class, i.content, i.online ' .
                'FROM cms_menu_item i INNER JOIN cms_menu_item_x_menu_item x ON i.menu_item_id = x.sub_menu_item_id ' .
                'WHERE x.menu_item_id = ? AND i.deleted = FALSE AND x.deleted = FALSE ' .
                'ORDER BY x.o');
        }
        try {
            $statement->execute(array($menu_or_menu_item_id));
        } catch (\Exception $e) {
            $statement = null;
            $this->handleErrorAndStop($e);
            /*var_dump($statement->queryString);
            var_dump($menu_or_menu_item_id);
            die($e);*/
        }
        $rows = $statement->fetchAll();
        $statement = null;
        ++$nested_level;
        foreach ($rows as $key => $row) {
            $row = $this->normalizeRow($row);
            try { // normalize the stuff from the act field
                if ($act = json_decode($row->act)) {
                    foreach ($act as $name => $prop) {
                        $row->$name = $prop;
                    }
                } else {
                    $this->addMessage(
                        sprintf(__('json_decode error on menu item %s', 'peatcms'),
                            \var_export($row->title, true)), 'warn');
                }
                //if (isset($act->slug)) $row->slug = $act->slug;
            } catch (\Exception $e) {
            }
            if ($sub_items = $this->fetchMenuItems($row->menu_item_id, $nested_level)) {
                $row->__menu__ = array('__item__' => $sub_items);
            }
            $row->nested_level = $nested_level;
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
        // TODO cache these somewhere in the db so they are retrieved as a plain text blob of json, probably much faster than going over the rows every time
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
    public function getShoppingList(string $name, int $session_id, int $user_id = 0): \stdClass
    {
        if ($user_id !== 0) $session_id = 0; // @since 0.7.9 get shoppinglist either by session or by user
        $data = array(
            'instance_id' => Setup::$instance_id,
            'session_id' => $session_id,
            'user_id' => $user_id,
            'name' => $name,
            'deleted' => false,
        );
        if (($count = count($rows = $this->fetchRows('_shoppinglist', array('*'), $data))) === 1) {
            $row = $rows[0];
            // update userid if appropriate
            if ($user_id !== 0 and $user_id !== $row->user_id) {
                $this->updateColumns('_shoppinglist', array('user_id' => $user_id), $row->shoppinglist_id);
            }
        } elseif ($count === 0) {
            $shoppinglist_id = $this->insertRowAndReturnLastId('_shoppinglist', $data);
            $row = $this->fetchRow('_shoppinglist', array('*'), array('shoppinglist_id' => $shoppinglist_id));
        } else {
            $count = $this->deleteRowWhereAndReturnAffected('_shoppinglist', $data);
            $this->handleErrorAndStop(sprintf('Deleted %s shoppinglist entries.', $count), __('Could not get shoppinglist', 'peatcms'));
            $row = null;
        }

        return $row;
    }

    /**
     * @param int $shoppinglist_id
     * @return array with \stdClass row objects containing the column values from _list_variant
     * @since 0.5.1
     */
    public function getShoppingListRows(int $shoppinglist_id): array
    {
        return $this->fetchRows('_shoppinglist_variant', array(
            'variant_id', 'quantity', 'price', 'price_from', 'o', 'deleted'
        ), array('shoppinglist_id' => $shoppinglist_id));
    }

    /**
     * @param int $shoppinglist_id
     * @param array $rows
     */
    public function upsertShoppingListRows(int $shoppinglist_id, array $rows)
    {
        // TODO how to do with locking, you don't want to lock the table, just the rows with this id, etc.
        $this->conn->beginTransaction();
        // delete the rows
        $statement = $this->conn->prepare('DELETE FROM _shoppinglist_variant WHERE shoppinglist_id = ?');
        $statement->execute(array($shoppinglist_id));
        // insert them all
        if (count($rows) > 0) {
            // insert current rows
            $placeholders = array();
            $values = array();
            foreach ($rows as $index => $row) {
                if (false === $row->deleted) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?)'; // shoppinglist_id, variant_id, quantity, price, price_from, o
                    $values[] = $shoppinglist_id;
                    $values[] = $row->variant_id;
                    $values[] = $row->quantity;
                    $values[] = $row->price;
                    $values[] = $row->price_from;
                    $values[] = $index;
                }
            }
            if (count($placeholders) > 0) {
                $statement = $this->conn->prepare(
                    'INSERT INTO _shoppinglist_variant ("shoppinglist_id", "variant_id", "quantity", "price", "price_from", "o") VALUES ' .
                    implode(', ', $placeholders));
                $statement->execute($values);
            }
        }
        // ok done
        $this->conn->commit();
        $statement = null;
    }

    /**
     * deletes shoppinglist rows (the variants) that no longer belong to a shoppinglist
     * @return int rows affected
     */
    public function jobDeleteOrphanedShoppinglistVariants(): int
    {
//        $statement = $this->conn->prepare('
//            DELETE FROM _shoppinglist_variant WHERE shoppinglist_id NOT IN (SELECT shoppinglist_id FROM _shoppinglist);
//        ');
        $statement = $this->conn->prepare('
            DELETE FROM _shoppinglist_variant v WHERE NOT EXISTS(SELECT 1 FROM _shoppinglist l WHERE l.shoppinglist_id = v.shoppinglist_id);
        ');
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
//        $statement = $this->conn->prepare('
//            DELETE FROM _sessionvars WHERE session_id NOT IN (SELECT session_id FROM _session);
//        ');
        $statement = $this->conn->prepare('
            DELETE FROM _sessionvars v WHERE NOT EXISTS(SELECT 1 FROM _session s WHERE session_id = v.session_id);
        ');
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
                        sprintf(__('There was an error in %s', 'peatcms'), $sessionlist->name)
                    );
                }
            }
        }

        return $affected;
    }

    public function insertAdmin(string $email, string $hash, int $client_id, int $instance_id = 0): ?int
    {
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
                $this->addMessage(__(sprintf('Domain ‘%s’ already taken', $domain), 'peatcms'));
            } else {
                if ($arr = $this->updateRowsWhereAndReturnKeys('_instance',
                    array('deleted' => false),
                    array('client_id' => $client_id, 'domain' => $domain),
                )) return $arr[0];
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
            if ($row->type === 'page') {
                if ($this->updateInstance($instance_id, array('homepage_id' => $row->id))) {
                    return $this->fetchInstanceById($instance_id); // return the updated instance
                } else {
                    $this->addError('Could not update instance');
                }
            } else {
                $this->addMessage(
                    sprintf(__('Only pages can be set to homepage, received: %s', 'peatcms'),
                        var_export($row->type, true)),
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
     * @param Type $which must be a valid element type
     * @param array $data must contain at least 'title' and 'content', 'slug' and 'template' will default to something
     * @return int|null the new id as integer when insert in database was successful, null otherwise
     */
    public function insertElement(Type $which, array $data): ?int
    {
        if (false === isset($data['slug'])) $data['slug'] = $which->typeName(); // default
        if (false === isset($data['instance_id'])) $data['instance_id'] = Setup::$instance_id;

        return $this->insertRowAndReturnLastId($which->tableName(), $data);
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
        $statement = $this->conn->prepare("
            SELECT t.table_name FROM information_schema.tables t
            INNER JOIN information_schema.columns c ON c.table_name = t.table_name AND c.table_schema = :schema
            WHERE c.column_name = 'deleted' AND t.table_schema = :schema AND t.table_type = 'BASE TABLE'
        ");
        $statement->bindValue(':schema', $this->db_schema);
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        $total_count = 0;
        // for all the tables, delete items that have TRUE for the 'deleted' column and have been updated > $interval minutes ago
        foreach ($rows as $key => $row) {
            $statement = $this->conn->prepare('
            DELETE FROM "' . $this->db_schema . '"."' . $row->table_name . '"
            WHERE deleted = TRUE AND date_updated < NOW() - interval \'' . $interval . ' minutes\'
            ');
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
        while (($row = $statement->fetch())) {
            $ids[$row['template_id']] = true;
        }
        // load the id’s of templates in the folder
        if (($files = scandir(Setup::$DBCACHE . 'templates/'))) {
            $files = array_diff($files, array('..', '.'));
            foreach ($files as $index => $file_name) {
                if (false === isset($ids[intval(explode('.', $file_name)[0])])) {
                    unlink(Setup::$DBCACHE . 'templates/' . $file_name);
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

    public function jobDeleteOrphanedLists(): int
    {
//        $statement = $this->conn->prepare('
//            DELETE FROM _shoppinglist WHERE user_id = 0 AND session_id NOT IN (SELECT session_id FROM _session);
//        ');
        $statement = $this->conn->prepare('
            DELETE FROM _shoppinglist l WHERE l.user_id = 0 AND NOT EXISTS(SELECT 1 FROM _session s WHERE s.session_id = l.session_id);
        ');
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
    }

    /**
     * @param Type $which
     * @param int $id
     * @return bool
     * @since 0.5.7
     */
    public function deleteElement(Type $which, int $id): bool
    {
        return $this->deleteRowAndReturnSuccess($which->tableName(), $id);
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
            $this->addError('->updateElementsWhere() cannot update column ‘slug’');

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

    public function updateSession(string $token, array $data): bool
    {
        return $this->updateRowAndReturnSuccess('_session', $data, $token);
    }

    public function registerSessionAccess(string $token, string $ip_address = null): bool
    {
        if (is_null($ip_address)) {
            $statement = $this->conn->prepare('UPDATE _session SET date_accessed = NOW() WHERE token = ?;');
            $statement->execute(array($token));
        } else {
            $statement = $this->conn->prepare(
                'UPDATE _session SET date_accessed = NOW(), date_updated = NOW(), ' .
                'ip_address = ?, reverse_dns = NULL WHERE token = ?;');
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
            $statement = $this->conn->prepare('DELETE FROM _sessionvars WHERE session_id = ? AND name = ? AND times <= ?;');
            // a newer one might have been inserted under the same name
            $statement->execute(array($session_id, $name, $var->times));
            $statement = null;

            return null; // success... $var is gone now
        }
    }

    /**
     * @param int $session_id
     * @param array $vars
     * @return int rows affected
     * @since 0.5.12 new version uses the updateSessionVar method
     */
    public function updateSessionVars(int $session_id, array $vars): int
    {
        // $vars is an array holding name=>value pairs
        foreach ($vars as $name => $var) {
            // TODO have the session manage change for each var and update ONLY the changed vars using the singular function below
            // upsert current vars
            $this->updateSessionVar($session_id, $name, $var);
        }

        return 0; // unused anyway
    }

    /**
     * Tries to clear a slug for use by an instance, slugs need to be unique
     * If it fails execution is halted
     *
     * @param ?string $slug the slug you want to try
     * @param ?Type $element optional default null the element you check the slug for
     * @param int $id optional the id of the element you're checking the slug for
     * @param int $depth optional default 0 keeps count of recursiveness, function fails at 100 tries
     * @return string the cleared slug safe to use, per instance, with an index behind it if necessary
     */
    public function clearSlug(string $slug = null, Type $element = null, int $id = 0, int $depth = 0): string
    {
        if (null === $element) $element = new Type('search');
        if (null === $slug or '' === $slug) $slug = $element->typeName() . '-' . (string)$id;
        if ($depth === 0) {
            // make it a safe slug (no special characters)
            $slug = Help::slugify($slug);
            // make it lowercase (multibyte)
            $slug = $this->toLower($slug);
        }
        // get all tables that contain a slug column, except cache of course, they are always duplicate
        $statement = $this->conn->prepare('
            SELECT t.table_name FROM information_schema.tables t
            INNER JOIN information_schema.columns c ON c.table_name = t.table_name AND c.table_schema = :schema
            WHERE c.column_name = \'slug\' AND t.table_schema = :schema AND t.table_type = \'BASE TABLE\'
            AND t.table_name <> \'_cache\' AND t.table_name <> \'_stale\'
        ');
        $statement->bindValue(':schema', $this->db_schema);
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        $found = false;
        $instance_id = Setup::$instance_id;
        // loop through the tables to see if any exist that have this slug (that are not this $element and $id)
        foreach ($rows as $key => $row) {
            if ($row->table_name !== $element->tableName()) {
                if ($this->rowExists($row->table_name, array('slug' => $slug, 'instance_id' => $instance_id))) $found = true;
            } else { // find out if this slug belongs to this id, because if it doesn't, it's also $found
                $table = new Table($this->getTableInfo($row->table_name));
                $id_column = $table->getInfo()->getIdColumn();
                $statement = $this->conn->prepare('SELECT ' .
                    $id_column->getName() . ' AS id FROM ' .
                    $row->table_name . ' WHERE slug = ? AND instance_id = ' . $instance_id . ';');
                $statement->execute(array($slug));
                $rows2 = $statement->fetchAll();
                $statement = null;
                if (count($rows2) === 1) {
                    if ($rows2[0]['id'] !== $id) $found = true; // this row is not normalised, so it's an array ['id'] in stead of ->id
                } elseif (count($rows2) > 1) {
                    $found = true;
                }
            }
            if ($found === true) break;
        }
        if ($found === true) {
            // update slug to slug-1 (slug-2 etc) and check again
            if ($depth === 0) {
                return $this->clearSlug($slug . '-1', $element, $id, 1);
            } else {
                if ($depth === 100) $this->handleErrorAndStop('Clear slug loops >100, probably error');
                // remove current trailing depth number
                $slug = substr($slug, 0, -1 * (strlen((string)$depth) + 1));
                ++$depth; // increase depth to try again

                return $this->clearSlug($slug . '-' . (string)$depth, $element, $id, $depth);
            }
        }

        // return the cleared slug, since it hasn't been $found apparently
        return $slug;
    }

    /**
     * @param $table_name string holding correct table_name, fatal error is thrown when table_name is wrong
     * @param array $columns
     * @param array $where
     * @param bool $single true when 1 return row is expected, false to return an array with every returned row (0 or more)
     * @return array|\stdClass null when failed, row object when single = true, array with row objects otherwise
     * @since 0.0.0
     */
    private function fetchRows(string $table_name, array $columns, array $where = array(), bool $single = false)
    {
        // TODO when admin chosen online must be taken into account
        // TODO use data_popvote to order desc when available
        // $columns is an indexed array holding column names as strings,
        // $where is an array with key => value pairs holding column_name => value
        $table = new Table($this->getTableInfo($table_name)); // fatal error is thrown when the table_name is wrong
        $table_info = $table->getInfo();
        // normalize / check columns and where data
        if (count($columns) !== 0) {
            if ($columns[0] === '*') { // get all the columns
                $columns = $table_info->getColumnNames();
            }
            $columns = $table->getColumnsByName($columns);
        } else {
            $columns = array('names' => array());
        }
        $columns['names'][] = $table_info->getIdColumn() . ' AS id'; // always get the id of the table, as ->id
        $columns['names'][] = '\'' . $table_name . '\' AS table_name'; // always return the table_name as well
        $where = $table->formatColumnsAndData($where);
        // @since 0.7.9 source of potential bugs, the changing of a where clause, will cause a fatal error from now on
        if (count($where['discarded']) !== 0) $this->handleErrorAndStop('->fetchRows discarded columns in where clause');
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
        } elseif ('_template' !== $table_name && in_array('date_published', $columns['names'])) {
            if (defined('ADMIN') && false === ADMIN) {
                echo ' AND date_published < NOW() - INTERVAL \'5 minutes\''; // allow a few minutes for the cache to update
            }
            echo ' ORDER BY date_published DESC';
        } elseif ('_order' !== $table_name && in_array('date_updated', $columns['names'])) {
            echo ' ORDER BY date_updated DESC';
        } elseif (in_array('date_created', $columns['names'])) {
            echo ' ORDER BY date_created DESC';
        }
        echo ' LIMIT 1000;';
        $sql = ob_get_clean();
        // prepare the statement to let it execute as fast as possible
        $statement = $this->conn->prepare($sql);
        $statement->execute($where['values']);
        $rows = $statement->fetchAll();
        $statement = null;
        if (true === $single) {
            if (($count = count($rows)) === 1) {
                return $this->normalizeRow($rows[0]);
            } else {
                if ($count > 1) {
                    $this->addError(new \Exception(sprintf('Query DB->fetchRow() returned %d rows', count($rows))));
                }

                return null;
            }
        } else {
            return $this->normalizeRows($rows);
        }
    }

    /**
     * @param $table_name string holding correct table name
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
     * Plural for normalizeRow
     * @param array $rows
     * @return array
     * @since 0.0.0
     */
    private function normalizeRows(array $rows): array
    {
        foreach ($rows as $key => $row) {
            $rows[$key] = $this->normalizeRow($row);
        }

        return $rows;
    }

    /**
     * @param array $row array row object as returned by fetchRow
     * @return \stdClass the normalized row or empty when there was none
     * @since 0.0.0
     */
    private function normalizeRow(array $row): \stdClass
    {
        // Strangely the column is returned twice in the array, under index '0' and under the name
        $r = new \stdClass();
        foreach ($row as $key => $value) {
            if (is_int($key)) continue;
            $r->$key = $value;
        }

        return $r;
    }

    public function selectRow(string $table_name, $key): ?\stdClass
    {
        $table_info = $this->getTableInfo($table_name); // fatal error is thrown when table_name is wrong
        // TODO use internal functionality, the problem is that you need to ignore instance_id sometimes
        // TODO maybe setup an admin db interface or something?
        // or we use it like this, but then with some checks
        $sql = 'SELECT * FROM ' . $table_name .
            ' WHERE ' . $table_info->getPrimaryKeyColumn()->getName() . ' = ?;';
        $statement = $this->conn->prepare($sql);
        $statement->execute(array($key));
        $rows = $statement->fetchAll();
        $statement = null;
        if (count($rows) === 1) {
            return $this->normalizeRow($rows[0]);
        }

        return null;
        //return $this->fetchRow($table_name, array('*'), array($table_info->getIdColumn()->getName() => $id));
    }

    /**
     * @param string $media_id
     * @param array $columns
     * @return \stdClass|null
     * @since 0.9.0
     */
    public function getInstagramMediaByMediaId(string $media_id, array $columns = array('*')): ?\stdClass
    {
        return $this->fetchRow('_instagram_media', $columns, array('media_id' => $media_id));
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

    // wrapper for private method updateRowAndReturnSuccess
    public function updateColumns(string $table_name, array $data, $key): bool
    {
        return $this->updateRowAndReturnSuccess($table_name, $data, $key);
    }

    /**
     * @param string $table_name
     * @param array $data
     * @return int|string|null
     * @since 0.6.2, wrapper for insertRowAndReturnLastId
     */
    public function insertRowAndReturnKey(string $table_name, array $data): int|string|null
    {
        return $this->insertRowAndReturnLastId($table_name, $data);
    }

    // TODO the return value kan be integer (mostly) or varchar (e.g. session), how to account for this?
    private function insertRowAndReturnLastId(string $table_name, array $data = null): int|string|null
    {
        $table = new Table($this->getTableInfo($table_name));
        // reCacheWithWarmup the slug, maybe used for a search page
        if (isset($data['slug'])) {
            $new_slug = $this->clearSlug($data['slug']); // (INSERT) needs to be unique for any entry
            $data['slug'] = $new_slug;
        }
        $data = $table->formatColumnsAndData($data, true);
        // maybe (though unlikely) the slug was provided in the $data but not actually in the table
        if (false !== array_search('slug', $data['discarded'])) unset($new_slug);
        // update
        try {
            $primary_key_column_name = $table->getInfo()->getPrimaryKeyColumn()->getName();
            // prepare and execute
            $statement = $this->conn->prepare('INSERT INTO ' . $table_name . ' 
                (' . implode(',', $data['columns']) . ') 
                VALUES (' . implode(',', array_fill(0, count($data['columns']), '?')) . ') 
                RETURNING ' . $primary_key_column_name . ';');
            $statement->execute($data['values']);
            $rows = $this->normalizeRows($statement->fetchAll());
            $statement = null;
            if (count($rows) === 1) {
                if (isset($new_slug)) $this->reCacheWithWarmup($new_slug);

                return $rows[0]->$primary_key_column_name;
            } elseif (count($rows) === 0) {
                return null;
            } else { // this should be impossible
                $this->handleErrorAndStop(new \Exception('Found more than one primary key for ' . $table_name),
                    __('Database error.', 'peatcms'));

                return null;
            }
        } catch (\Exception $e) {
            $this->addError($e);

            return null;
        }
    }

    private function updateRowAndReturnSuccess(string $table_name, array $data, $key): bool
    {
        $table = new Table($this->getTableInfo($table_name)); // fatal error is thrown when table_name is wrong
        $table_info = $table->getInfo();
        $key_column_name = $table_info->getPrimaryKeyColumn()->getName();
        // reCacheWithWarmup the old slug, clear this slug and reCacheWithWarmup the new slug as well (as it may be used for a search page)
        if (isset($data['slug'])) {
            //$data['slug'] = $this->clearSlug($data['slug']); // (INSERT) needs to be unique for any entry
            $new_slug = $this->clearSlug($data['slug'], $table->getType(), (int)$key); // (UPDATE) needs to be unique to this entry
            $data['slug'] = $new_slug;
        }
        $data = $table->formatColumnsAndData($data, true);
        // maybe (though unlikely) the slug was provided in the $data but not actually in the table
        if (false !== array_search('slug', $data['discarded'])) unset($new_slug);
        // check if there are any columns going to be updated, else return already
        if (count($data['parameterized']) === 0) {
            $this->addError('No columns to update');

            return false;
        }
        // push current entry to history
        $old_row = $this->copyRowToHistory($table, $key); // returns the copied (now old) row, can be null
        // update entry
        $statement = $this->conn->prepare(
            'UPDATE ' . $table_name . ' SET ' . implode(', ', $data['parameterized']) . ' 
            ' . ($table_info->hasStandardColumns() ? ', date_updated = NOW()' : '') . '
            WHERE ' . $key_column_name . ' = ?;'
        );
        $data['values'][] = $key; // key must be added as last value for the where clause
        try {
            $statement->execute($data['values']);
        } catch (\PDOException $e) {
            $this->addError($e);
        }
        $row_count = $statement->rowCount();
        // (fts) search trigger: changes to title, excerpt, content and description fields must update the ci_ai as well
        if (
            $table_info->hasCiAiColumn()
            && count(array_intersect($data['columns'], array('title', 'excerpt', 'content', 'description'))) !== 0
        ) {
            $statement = $this->conn->prepare('UPDATE ' . $table_name . ' SET ci_ai = NULL WHERE ' . $key_column_name . ' = ?;');
            try {
                $statement->execute(array($key));
            } catch (\PDOException $e) {
                $this->addError($e);
            }
        }
        // ok done
        $statement = null;
        if ($row_count === 1) {
            // TODO this can be more solid, but test it thoroughly...
            // reCacheWithWarmup the new slug (as it may be used for a search page)
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
            //var_dump($new_slug);
            //var_dump($old_row->slug);
            //die(' opa test');
            $table = null;
            $old_row = null;

            return true;
        } else {
            $this->addError(sprintf('DB->updateRowAndReturnSuccess resulted in a rowcount of %1$d for key %2$d on table %3$s',
                $row_count, $key, $table_name));
        }
        $table = null;
        $old_row = null;

        return false;
    }

    private function updateRowsWhereAndReturnKeys(string $table_name, array $data, array $where): array
    {
        $table = new Table($this->getTableInfo($table_name));
        $key_column_name = $table->getInfo()->getPrimaryKeyColumn()->getName();
        $where = $table->formatColumnsAndData($where, true);
        $sql = 'SELECT ' . $key_column_name . ' FROM ' . $table_name;
        if ('' !== ($where_statement = implode(' AND ', $where['parameterized']))) {
            $sql .= ' WHERE ' . $where_statement;
        }
        $statement = $this->conn->prepare($sql);
        $statement->execute($where['values']);
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        $return_keys = array();
        foreach ($rows as $key => $row) {
            if ($this->updateRowAndReturnSuccess($table_name, $data, $row->$key_column_name)) {
                $return_keys[] = $row->$key_column_name;
            }
        }

        return $return_keys;
    }

    private function deleteRowAndReturnSuccess(string $table_name, $key): bool
    {
        $table = new Table($this->getTableInfo($table_name)); // throws fatal error when table_name is wrong
        $table_info = $table->getInfo();
        // push current entry to history
        $old_row = $this->copyRowToHistory($table, $key); // returns current row
        // delete the row
        if ($table_info->hasStandardColumns()) { // if the deleted column exists, use that
            $statement = $this->conn->prepare('UPDATE ' . $table_name . ' SET date_updated = NOW(), deleted = TRUE WHERE ' .
                $table_info->getPrimaryKeyColumn()->getName() . ' = ?');
        } else {
            $statement = $this->conn->prepare('DELETE FROM ' . $table_name . ' WHERE ' .
                $table_info->getPrimaryKeyColumn()->getName() . ' = ?');
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

        return ($row_count === 1);
    }

    private function deleteRowWhereAndReturnAffected($table_name, array $where, array $where_not = array()): int
    {
        $table = new Table($this->getTableInfo($table_name)); // throws fatal error when table_name is wrong
        $primary_key_column = $table->getInfo()->getPrimaryKeyColumn()->getName();
        $columns_to_select = array_merge(array($primary_key_column), array_keys($where_not));
        $rows = $this->fetchRows($table_name, $columns_to_select, $where);
        $rows_affected = 0;
        if (count($rows) > 0) {
            foreach ($rows as $key => $row) {
                foreach ($where_not as $where => $not) {
                    if (isset($row->$where) && $row->$where === $not) continue 2;
                }
                if ($this->deleteRowAndReturnSuccess($table_name, $row->$primary_key_column) === true) ++$rows_affected;
            }
        }

        return $rows_affected;
    }

    private function rowExists(string $table_name, array $where): bool
    {
        $table = new Table($this->getTableInfo($table_name)); // throws fatal error when table_name is wrong
        $where = $table->formatColumnsAndData($where, true);
        $where_string = implode(' AND ', $where['parameterized']);
        $statement = $this->conn->prepare("
            SELECT EXISTS (SELECT 1 FROM $table_name WHERE $where_string);
        ");
        $statement->execute($where['values']);
        $return_value = (bool)$statement->fetchColumn(0);
        $statement = null;

        return $return_value;
    }

    /**
     * Used by upgrade mechanism to sync history database
     *
     * @return array
     * @since 0.1.0
     */
    public function getAllTables(): array
    {
        $statement = $this->conn->prepare('
            SELECT table_name, is_insertable_into FROM information_schema.tables WHERE table_schema = ?;
        ');
        $statement->execute(array($this->db_schema));
        $rows = $statement->fetchAll();
        $statement = null;

        return $this->normalizeRows($rows);
    }

    /**
     * This also caches the table info for the request TODO cache table info for the application
     *
     * @param string $table_name
     * @return TableInfo
     * @since 0.1.0
     */
    public function getTableInfo(string $table_name): TableInfo
    {
        if (false === isset($this->table_infos[$table_name])) {
            $this->table_infos[$table_name] = $this->fetchTableInfo($table_name);
        }

        return $this->table_infos[$table_name];
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
        // @since 0.7.7 the contents is cached
        $file_name = Setup::$DBCACHE . Setup::$VERSION . $table_name . '.info.serialized';
        if (file_exists($file_name)) {
            return unserialize(file_get_contents($file_name));
        }
        $statement = $this->conn->prepare('SELECT column_name AS name, data_type AS type, ' .
            'column_default AS default, is_nullable AS nullable, character_octet_length / 4 as "length" ' .
            'FROM information_schema.columns WHERE table_schema = :schema_name AND table_name = :table_name;');
        $statement->bindValue(':schema_name', $this->db_schema);
        $statement->bindValue(':table_name', $table_name);
        $statement->execute();
        $info = new TableInfo($table_name, $this->normalizeRows($statement->fetchAll()));
        // add the primary key column:
        $statement = $this->conn->prepare('SELECT a.attname AS column_name, ' .
            'format_type(a.atttypid, a.atttypmod) AS data_type FROM pg_index i ' .
            'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) ' .
            'WHERE i.indrelid = :table_name::regclass AND i.indisprimary;');
        $statement->bindValue(':table_name', $table_name);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;
        if ($rows) {
            $info->setPrimaryKeyColumn($rows[0]['column_name']);
        }
        // @since 0.7.7 cache the object if not already cached
        if (false === file_exists($file_name)) file_put_contents($file_name, serialize($info), LOCK_EX);

        return $info;
    }

    /**
     * performs a toLower action in the database, which is more accurate with UTF8 than the php version
     *
     * @param string $str A string to convert tolower
     * @return string the lowercase string
     * @since 0.1.0
     */
    public function toLower(string $str): string
    {
        $statement = $this->conn->prepare('SELECT lower(:str);');
        $statement->bindValue(':str', $str);
        $statement->execute();
        $return_value = (string)$statement->fetchColumn(0);
        $statement = null;

        return $return_value;
    }

    /**
     * Returns the current slug based on an old slug by looking for the element in the history database
     *
     * @param string $slug the slug to search for in redirect table and history
     * @return string|null the current slug or null when nothing is found
     */
    public function getCurrentSlugBySlug(string $slug): ?string
    {
        $slug = $this->toLower($slug);
        // @since 0.8.1: use the redirect table for specific slugs (you probably need to clear cache to pick them up)
        $statement = $this->conn->prepare('
            SELECT to_slug FROM _redirect
            WHERE term = :term AND deleted = FALSE AND instance_id = :instance_id;
        ');
        $statement->bindValue(':term', $slug);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        if (count($rows = $statement->fetchAll()) === 1) {
            $statement = null;

            return $rows[0][0];
        }
        // look in the history, get all tables that contain a slug column
        // those tables must also have the standard columns (data_updated, etc.)
        // TODO this seems incredibly slow but can also be somewhere else, looking up a slug from history :-(
        $statement = Setup::getHistoryDatabaseConnection()->prepare("
            SELECT t.table_name FROM information_schema.tables t
            INNER JOIN information_schema.columns c ON c.table_name = t.table_name AND c.table_schema = :schema
            WHERE c.column_name = 'slug' AND t.table_schema = :schema AND t.table_type = 'BASE TABLE'
        ");
        $statement->bindValue(':schema', $this->db_schema);
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
        $statement = null;
        // construct a sql statement inner joining all the entries, ordered by date_updated so you only get the most recent entry
        ob_start(); // will hold sql statement
        foreach ($rows as $key => $row) {
            if (false === strpos($row->table_name, 'cms_')) continue; // only handle cms_ tables containing elements
            if (ob_get_length()) echo 'UNION ALL ';
            $element_name = str_replace('cms_', '', $row->table_name);
            echo "SELECT {$element_name}_id AS id, '$element_name' AS type, date_updated 
                FROM cms_$element_name WHERE slug = :slug AND instance_id = :instance_id AND deleted = false ";
        }
        echo 'ORDER BY date_updated DESC LIMIT 1;';
        $statement = Setup::getHistoryDatabaseConnection()->prepare(ob_get_clean());
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->execute();
        if ($rows = $statement->fetchAll()) {
            $statement = null;
            // take the element and id to select the current slug in the live database
            $row = $this->normalizeRow($rows[0]);
            if ($row = $this->fetchRow('cms_' . $row->type, array('slug'), array($row->type . '_id' => $row->id))) {
                return $row->slug; // you know it might be offline or deleted, but you handle that after the redirect.
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
        // switch update or insert
        $statement = $this->conn->prepare(
            'SELECT EXISTS (SELECT 1 FROM _cache WHERE instance_id = :instance_id AND slug = :slug AND variant_page = :variant_page);'
        );
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':variant_page', $variant_page);
        $statement->execute();
        $exists = (bool)$statement->fetchColumn(0);
        if (true === $exists) { // update TODO go to an insert-only model and delete crap later
            $statement = $this->conn->prepare(
                'UPDATE _cache SET row_as_json = :row_as_json, type_name = :type_name, ' .
                'id = :id, since = now(), variant_page_json = null ' .
                'WHERE instance_id = :instance_id AND slug = :slug AND variant_page = :variant_page;');
        } else { // insert
            $statement = $this->conn->prepare(
                'INSERT INTO _cache (instance_id, slug, row_as_json, type_name, id, variant_page) ' .
                'VALUES (:instance_id, :slug, :row_as_json, :type_name, :id, :variant_page);');
        }
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
     * Cached will return the row object of a cached item by slug for this instance, or null when not present
     *
     * @param string $slug the unique slug to look for this instance
     * @param int $variant_page
     * @return \stdClass|null the row object found or null when not cached
     * @since 0.5.4
     */
    public function cached(string $slug, int $variant_page = 1): ?\stdClass
    {
        $statement = $this->conn->prepare(
            'SELECT row_as_json, since, variant_page_json FROM _cache 
                    WHERE instance_id = :instance_id AND slug = :slug AND variant_page = :variant_page;');
        $statement->bindValue(':instance_id', (Setup::$instance_id));
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':variant_page', $variant_page);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement = null;
        if (count($rows) > 0) {
            $row = $rows[0];
            $obj = json_decode($row[0]);
            $obj->x_cache_timestamp = strtotime($row[1]); // @since 0.8.2
            if (isset($row[2])) {
                $obj->__variant_pages__ = json_decode($row[2]);
            } else {
                $obj->__variant_pages__ = array(); // @since 0.8.6
            }
            $rows = null;

            return $obj;
        }
        $rows = null;

        return null;
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
        $statement = $this->conn->prepare(
            'SELECT since FROM _cache WHERE instance_id = :instance_id AND slug = :slug;'
        );
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
        // @since 0.8.2: warmup is used in stead of delete
        if (false === (new Warmup())->Warmup($slug, Setup::$instance_id)) {
            $this->addError(sprintf(__('Warmup failed for ‘%s’', 'peatcms'), $slug));
            // only when warmup fails we delete
            $this->deleteFromCache($slug);

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
        $statement = $this->conn->prepare(
            'DELETE FROM _cache WHERE instance_id = :instance_id AND slug = :slug 
                    AND variant_page >= :variant_page;'
        );
        $statement->bindValue(':instance_id', Setup::$instance_id);
        $statement->bindValue(':slug', $slug);
        $statement->bindValue(':variant_page', $from_variant_page_upwards);
        $statement->execute();
        $statement = null;

        return true;
    }

    /**
     * Parents of the supplied slug are put in the _stale table so the cron job that warms up cache can pick them up
     * @param string $slug
     * @return bool
     * @since 0.8.8
     * TODO move to element / baseelement or something, not here
     */
    public function markStaleTheParents(string $slug): bool
    {
        // from 0.8.8 we only warmup parents through their linked tables
        // children must be caught (later) in batches by jobOldCacheRows
        // get all the slugs we need to warmup for this element
        $element_row = $this->fetchElementIdAndTypeBySlug($slug);
        if (!$element_row) return false;
        $element = (new Type($element_row->type))->getElement()->fetchById($element_row->id);
        if (!$element) return false;
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
            } elseif (in_array($relation, array('direct_child', 'cross_child'))) {
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
                $rows = $this->normalizeRows($statement->fetchAll());
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
     * @return bool
     * @since 0.8.3
     */
    private function markStale(string $slug): bool
    {
        if (isset($this->stale_slugs[$slug])) return true; // mark stale only once
        $this->stale_slugs[$slug] = true;
        $statement = $this->conn->prepare(
            'SELECT EXISTS (SELECT 1 FROM _stale WHERE instance_id = ? AND slug = ?);'
        );
        $statement->execute(array(Setup::$instance_id, $slug));
        $exists = (bool)$statement->fetchColumn(0);
        if (false === $exists) {
            $statement = $this->conn->prepare('INSERT INTO _stale (instance_id, slug) VALUES (?, ?);');
            $statement->execute(array(Setup::$instance_id, $slug));
        }
        $statement = null;

        return true;
    }

    public function markStaleFrom(string $slug, string $date_from): bool
    {
        if (null === (Help::getDate($date_from))) return false;
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
        // delete (accidental) duplicates from cache table, rows that are exactly the same... should not happen but hey
        $statement = $this->conn->prepare('
            DELETE FROM _cache c1 USING (
              SELECT MIN(ctid) as ctid, slug, instance_id, variant_page
                FROM _cache
                GROUP BY slug, instance_id, variant_page HAVING COUNT(*) > 1
              ) c2
              WHERE c1.slug = c2.slug 
                AND c1.variant_page = c2.variant_page
                AND c1.instance_id = c2.instance_id
                AND c1.ctid <> c2.ctid;
        ');
        $statement->execute();
        $affected = $statement->rowCount();
        $statement = null;

        return $affected;
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
     * @param int $limit
     * @return array the rows
     * @since 0.8.0 @since 0.8.19 ‘since’ can be in the future, for pages that need to be published just then
     */
    public function jobStaleCacheRows(int $limit = 60): array
    {
        // collect all the stale slugs
        $statement = $this->conn->prepare('
            SELECT DISTINCT s.slug, s.instance_id, s.since, c.slug in_cache FROM _stale s
            LEFT OUTER JOIN _cache c ON c.slug = s.slug
            WHERE s.since <= NOW()
            ORDER BY s.since DESC LIMIT ' . $limit . ';');
        $statement->execute();
        if ($statement->rowCount() > 0) {
            $rows = $this->normalizeRows($statement->fetchAll());
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
        $statement = $this->conn->prepare('
            SELECT DISTINCT slug, instance_id, since FROM _cache WHERE since < NOW() - interval \'' .
            $interval . ' minutes\' ORDER BY since DESC LIMIT ' . $limit . ';');
        $statement->execute();
        if ($statement->rowCount() > 0) {
            $rows = $this->normalizeRows($statement->fetchAll());
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

    /**
     * Copies an entry from a live table to the history database, for undo and redirect functionality
     *
     * @param Table $table the table object from the live database for reference
     * @param $key mixed the key of the primary key column of the row to move to history
     * @return \stdClass|null the old row or null when not copied (@since 0.5.x)
     * @since 0.0.0
     */
    private function copyRowToHistory(Table $table, $key): ?\stdClass
    {
        // the history table must have the same definition / columns as the source table this is ensured by Help::upgrade()
        // copying the row inside postgres from one db to another is not supported, so you have to do it via php...
        // you could use two schemas (e.g. live + history) but I want to keep history separate entirely to be able to move it to a different server etc.
        // 1) select the row from the live database
        $table_info = $table->getInfo();
        // abort if there's no history
        if (in_array($table_name = $table_info->getTableName(), $this->tables_without_history)) return null;
        // ok continue
        $row = $this->fetchRow($table_name, array('*'), array(
            'deleted' => null, // null means either value is good
            'instance_id' => null, // will be overwritten by next line if the $key column is actually instance_id
            $table_info->getPrimaryKeyColumn()->getName() => $key,
        ));
        if ($row === null) {
            $this->addError(sprintf('->copyRowToHistory() could not get row from %1$s with %2$s = %3$s',
                $table_name, $table_info->getPrimaryKeyColumn()->getName(), (string)$key));

            return null;
        }
        // 2) insert the row into history database
        try {
            $sql = 'INSERT INTO ' . $table_name . ' (' . implode(', ', $table_info->getColumnNames()) . ') VALUES (';
            $values = array();
            $parameters = array();
            foreach ($table_info->getColumnNames() as $key => $column_name) {
                if (is_bool($row->$column_name)) { // booleans get butchered in $statement->execute(), interestingly, NULL values don't
                    $values[] = ($row->$column_name ? '1' : '0');
                } else {
                    $values[] = $row->$column_name;
                }
                $parameters[] = '?';
            }
            $sql .= implode(', ', $parameters) . ');';
            $statement = Setup::getHistoryDatabaseConnection()->prepare($sql);
            $statement->execute($values);
            $statement = null;
        } catch (\Exception $e) {
            $this->addError($e);
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
        $statement = Setup::getHistoryDatabaseConnection()->prepare('SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = :schema_name
            AND table_name = :table_name
            ;'
        );
        $statement->bindValue(':schema_name', $this->db_schema);
        $statement->bindValue(':table_name', $table_name);
        $statement->execute();
        $rows = $this->normalizeRows($statement->fetchAll());
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
}
