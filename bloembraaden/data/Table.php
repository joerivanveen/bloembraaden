<?php

declare(strict_types=1);

namespace Bloembraaden;

class Type extends Base
{
    private array $peatcms_types = array(
        'page' => 'cms_page',
        'embed' => 'cms_embed',
        'image' => 'cms_image',
        'instagram_image' => '_instagram_media',
        'file' => 'cms_file',
        'menu' => 'cms_menu',
        'menu_item' => 'cms_menu_item',
        'brand' => 'cms_brand',
        'serie' => 'cms_serie',
        'product' => 'cms_product',
        'variant' => 'cms_variant',
        'property' => 'cms_property',
        'property_value' => 'cms_property_value',
        'comment' => 'cms_comment',
        'search' => '_search_settings',
        'client' => '_client',
        'instance' => '_instance',
        'user' => '_user',
        'address' => '_address',
        'admin' => '_admin',
        'template' => '_template',
        'shoppinglist' => '_shoppinglist',
        'order' => '_order',
        'payment_service_provider' => '_payment_service_provider',
    );
    private string $type_name, $table_name;

    /**
     * Type constructor.
     * @param string $type_name supply the correct typename (lower case), but the table_name is also fine (lower case)
     */
    public function __construct(string $type_name)
    {
        parent::__construct();
        if (isset($this->peatcms_types[$type_name])) {
            $this->type_name = $type_name;
            $this->table_name = $this->peatcms_types[$type_name];
        } elseif (($new_type_name = array_search($type_name, $this->peatcms_types))) {
            $this->type_name = $new_type_name;
            $this->table_name = $type_name; // the supplied string for $type_name turns out to be the table_name
        } else {
            $this->handleErrorAndStop(
                "$type_name is not a recognized type",
                sprintf(__('%s is not a recognized type', 'peatcms'), $type_name)
            );
        }
    }

    public function __toString(): string
    {
        return $this->type_name;
    }

    public function tableName(): string
    {
        return $this->table_name;
    }

    public function typeName(): string
    {
        return $this->type_name;
    }

    public function idColumn(): string
    {
        if (true === str_starts_with(($table_name = $this->tableName()), 'cms_')) {
            return substr("{$table_name}_id", 4); // convention: table name except the cms_
        } else {
            return substr("{$table_name}_id", 1); // convention: table name except the first _
        }
    }

    public function className(): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $this->type_name)));
    }

    public function getElement(\stdClass $row = null): BaseElement
    {
        $class_name = "\\Bloembraaden\\{$this->className()}";

        return new $class_name($row);
    }
}

class Table extends Base
{
    private TableInfo $info;
    private ?Type $type;

    public function __construct(TableInfo $info)
    {
        parent::__construct();
        $this->info = $info;
    }

    public function formatColumnsAndData(array $cols, bool $for_update = false): array
    {
        $return_value = array(
            'columns' => array(),
            'parameterized' => array(),
            'values' => array(),
            'discarded' => array(),
        );
        if (false === $for_update) {
            // select per instance only, only works when INSTANCEID is defined
            if (false === array_key_exists('instance_id', $cols)) {
                if ($this->info->perInstance()) {
                    $cols['instance_id'] = Setup::$instance_id;
                }
            } elseif (null === $cols['instance_id']) { // e.g. an admin can be created by session also if he has instance_id 0
                unset($cols['instance_id']);
            }
            // select only non-deleted rows standard, an admin may have provided the deleted column however
            // array_key_exists is slower than isset, but otherwise we can't detect the null value
            if ($this->info->hasStandardColumns()) {
                if (false === array_key_exists('deleted', $cols)) {
                    $cols['deleted'] = false;
                } elseif (null === $cols['deleted']) { // null means either value is good
                    // TODO set this up for online column as well
                    unset($cols['deleted']);
                }
            }
        }
        // format for (where / update) statement
        foreach ($cols as $column_name => $value) {
            if (($col = $this->getInfo()->getColumnByName($column_name))) {
                // validate before save
                if (true === $for_update && false === $this->isValid($col, $value)) {
                    $return_value['discarded'][] = $column_name;
                    continue;
                }
                $like = false;
                $lower = false;
                $return_value['columns'][] = $column_name;
                if (in_array($col->getType(), array('boolean', 'smallint', 'integer', 'bigint'))) {
                    $value = (int)$value;
                    $return_value['values'][] = $value; // booleans must be 0 or 1, or postgresql will reject them
                } else {
                    $return_value['values'][] = $value;
                    if (false === $for_update) {
                        // if the value starts and / or ends with %, treat this as a LIKE statement
                        if (0 === strpos($value, '%') || strrpos($value, '%') === strlen($value) - 1) $like = true;
                        // ci_ai and token (_session) are already lower, prevent the table scan here
                        if ('ci_ai' !== $column_name && 'token' !== $column_name) {
                            $column_name = "lower($column_name)";
                            $lower = true;
                        }
                    }
                }
                $like = ($like === false) ? ' = ' : ' LIKE ';
                $lower = ($lower === false) ? '?' : 'lower(?)';
                $return_value['parameterized'][] = "$column_name$like$lower";
            } else {
                $return_value['discarded'][] = $column_name;
            }
        }
        if (Setup::$VERBOSE && isset($return_value['discarded']) && count($return_value['discarded']) > 0) {
            $this->addError('Discarded columns in statement: ' . var_export($return_value['discarded'], true));
        }

        return $return_value;
    }

    public function getColumnsByName(array $cols): array
    {
        // TODO maybe cache this if it's requested more than once sometimes?
        $r = ['columns', 'names'];
        $info = $this->info;
        // add standard columns
        if ($info->hasStandardColumns()) {
            $cols[] = 'date_created';
            $cols[] = 'date_updated';
            $cols[] = 'online'; // will be skipped if not present
        }
        // format
        foreach ($cols as $key => $name) {
            if ($column = $info->getColumnByName($name)) {// skip invalid columns
                $r['columns'][$key] = $column;
                $r['names'][] = $name;
            }
        }

        return $r;
    }

    public function getInfo(): TableInfo
    {
        return $this->info;
    }

    public function getType(): Type
    {
        if (!isset($this->type)) {
            $this->type = new Type($this->info->getTableName());
        }

        return $this->type;
    }

    private function isValid(Column $col, $value): bool
    {
        if (null === $value) {
            return $col->isNullable();
        }
        $col_type = $col->getType();
        $col_name = $col->getName();
        if ($col_type === 'boolean') {
            if (true !== $value and false !== $value) {
                $this->addValidationError($col_name, var_export($value, true), __('must be boolean', 'peatcms'));

                return false;
            }
        } elseif (in_array($col_type, array('smallint', 'integer', 'bigint'))) {
            if (null === Help::asInteger($value)) {
                $this->addValidationError($col_name, var_export($value, true), __('must be integer', 'peatcms'));

                return false;
            }
        } elseif (in_array($col_type, array('price', 'price_from', 'float', 'double'))) {
            if (null === Help::asFloat($value)) {
                $this->addValidationError($col_name, var_export($value, true), __('must be a number (float, decimal, integer)', 'peatcns'));

                return false;
            }
        } elseif ($col_type === 'timestamp') {
            if ($value !== 'NOW()') {
                if (null === Date::getDate($value)) {
                    $this->addValidationError($col_name, var_export($value, true), 'must be YYYY-MM-DD (HH:MM:SS) or ‘NOW()’');

                    return false;
                }
                return true;
            }
        } elseif (strlen((string)$value) > $col->getLength()) {
            $this->addValidationError($col_name, var_export($value, true), 'would be truncated');

            return false;
        }

        return true;
    }

    private function addValidationError(string $col_name, string $value, string $message)
    {
        $this->addError(sprintf('Value %1$s rejected for %2$s', htmlentities(strip_tags($value)), $col_name));
        if (function_exists('__')) {
            $this->addMessage(sprintf(__('Value rejected for %s', 'peatcms'), $col_name) . ', ' . $message, 'warn');
        }
    }
}

class TableInfo
{
    private array $columns = array(), $names = array();
    private string $table_name, $primary_key_column, $id_column;
    private bool $has_standard_columns, $has_ci_ai_column = false, $per_instance;

    public function __construct(string $table_name, array $columns) // these columns must be the actual columns that are in the table
    {
        $this->table_name = $table_name;
        $count_standard_columns = 0;
        $this->per_instance = false;
        foreach ($columns as $index => $column) {
            $column_name = (string)$column->name;
            // hidden columns that are never output
            if ('ci_ai' === $column_name) {
                $this->has_ci_ai_column = true;
                continue;
            }
            //
            $this->names[] = $column_name;
            $column->editable = true; // default value
            $this->columns[$column_name] = new Column($column_name, $column);
            // setup some extra info for this table / column
            if (in_array($column_name, ['date_created', 'date_updated', 'deleted'], true)) {
                ++$count_standard_columns;
                $column->editable = false;
            }
            // TODO, which columns are uneditable? Make a mechanism (taxonomy_id is only temporarily uneditable)
            if (in_array($column_name, array(
                'filename_original',
                'src_tiny',
                'src_small',
                'src_medium',
                'src_large',
                'src_huge',
                'taxonomy_id',
                'json_prepared', // processed html in _template
            ), true)
            ) {
                $column->editable = false;
            }
            if ($this->getColumnByName($column_name)->getDefault() === 'serial') {
                $this->id_column = $column_name;
                $column->editable = false;
            } elseif ('instance_id' === $column_name) {
                // instance_id only means "per instance" if it's not the id column, because that would be table _instance
                $this->per_instance = true;
                $column->editable = false;
            }
            if ($column->editable === false) $this->getColumnByName($column_name)->setEditable(false);
        }
        $this->has_standard_columns = (3 === $count_standard_columns);
    }

    /**
     * @param string $name , set the info which column holds the primary key
     */
    public function setPrimaryKeyColumn(string $name): void
    {
        $this->primary_key_column = $name;
        // @since 0.7.3 a table can have a key column without the serial datatype
        if (false === isset($this->id_column)) $this->id_column = $name;
    }

    /**
     * @return Column the column that is the primary key, most likely the id column but not necessarily
     */
    public function getPrimaryKeyColumn(): Column
    {
        return $this->getColumnByName($this->primary_key_column);
    }

    /**
     * @return Column the column that has a Serial datatype to it, often the same as the primary key but not necessarily
     */
    public function getIdColumn(): Column
    {
        return $this->getColumnByName($this->id_column);
    }

    public function hasIdColumn(): bool
    {
        return isset($this->id_column);
    }

    public function getColumnNames(): array
    {
        return $this->names;
    }

    /**
     * @param string $name the name of the column
     * @return Column | null Returns the requested column as Column object, or null when not found
     */
    public function getColumnByName(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    /**
     * @return bool true when the table has the following standard columns: date_created, date_updated, online, deleted
     */
    public function hasStandardColumns(): bool
    {
        return $this->has_standard_columns;
    }

    public function hasCiAiColumn(): bool
    {
        return $this->has_ci_ai_column;
    }

    public function getTableName(): string
    {
        return $this->table_name;
    }

    /**
     * @return bool true when data in the table is confined to an instance, false if it's not
     */
    public function perInstance(): bool
    {
        return $this->per_instance;
    }
}

class Column
{
    private string $name, $type;
    private ?string $default;
    private ?int $length;
    private bool $nullable, $editable;

    public function __construct(string $name, \stdClass $column)
    {
        $this->name = $name;
        $this->type = explode(' ', $column->type)[0];
        $this->default = (false === strpos($column->default ?? '', 'nextval')) ? $column->default : 'serial';
        $this->nullable = (0 !== strpos($column->nullable ?? '', 'NO'));
        $this->length = $column->length; // for character type columns the maximum number of characters
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDefault(): ?string
    {
        return $this->default;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * This function is necessary to legitimate $editable, which is necessary to have non-editable columns in the admin interface
     * @return bool
     */
    public function isEditable(): bool
    {
        return $this->editable;
    }

    public function setEditable(bool $bool): void
    {
        $this->editable = $bool;
    }
}