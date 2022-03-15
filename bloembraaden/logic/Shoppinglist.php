<?php

namespace Peat;
class Shoppinglist extends BaseLogic
{
    // TODO it's a bit wonky, with the 'rows' variable holding the rows
    private string $name, $state;
    private array $rows;
    private Session $session;

    public function __construct(string $name, Session $session)
    {
        parent::__construct();
        $this->name = $name;
        $this->session = $session;
        $this->type_name = 'shoppinglist';
        // get the list from db
        $this->row = $this->getDB()->getShoppingList(
            $name, $session->getId(), (null === ($user = $session->getUser()))?0:$user->getId());
        $this->rows = $this->getDB()->getShoppingListRows($this->getId());
        // remember the state so you can update the db on __shutdown
        $this->setState($this->getStateCurrent()); // WARNING state is for the rows only now
        register_shutdown_function(array(&$this, '__shutdown'));
    }

    /**
     * shutdown function is called when php execution ends, so it can update the list in the database when changed
     */
    public function __shutdown()
    {
        if (true === $this->hasChanged()) {
            $this->getDB()->upsertShoppingListRows($this->getId(), $this->rows);
        }
    }

    /**
     * Add a certain variant to this list with the supplied quantity
     *
     * @param Variant $variant
     * @param int $quantity
     * @return bool success
     * @since 0.5.1
     */
    public function addVariant(Variant $variant, int $quantity): bool
    {
        // if the variant is in the list update the quantity, else add it
        $rows = $this->rows;
        $variant_id = $variant->getId();
        foreach ($rows as $index => $row) {
            if ($row->variant_id === $variant_id) {
                $this->rows[$index]->quantity += $quantity;
                // prices should always be updated
                $this->rows[$index]->price = $variant->getPrice();
                $this->rows[$index]->price_from = $variant->getPriceFrom();

                return true;
            }
        }
        $this->rows[] = (object)array(
            'variant_id' => $variant_id,
            'quantity' => $quantity,
            'price_from' => $variant->getPriceFrom(),
            'price' => $variant->getPrice(),
            'o' => count($this->rows) + 1,
            'deleted' => false,
        );

        return true;
    }

    /**
     * Remove the supplied variant from the list
     *
     * @param Variant $variant the variant to remove
     * @return bool success (also returns false if the variant is not in the list)
     * @since 0.5.2
     */
    public function removeVariant(Variant $variant): bool
    {
        // todo make generic functionality to get the right row and update it
        $rows = $this->rows;
        $variant_id = $variant->getId();
        foreach ($rows as $index => $row) {
            if ($row->variant_id === $variant_id) {
                unset($this->rows[$index]);

                return true;
            }
        }

        return false;
    }

    /**
     * Update the quantity to the set quantity for this variant
     * @param Variant $variant the variant for which you want to update the quantity
     * @param int $quantity the quantity to add to the list
     * @return bool success
     * @since 0.7.6 will add the variant if not yet in the list, with the supplied quantity
     *
     * @since 0.5.2
     */
    public function updateQuantity(Variant $variant, int $quantity): bool
    {
        $rows = $this->rows;
        $variant_id = $variant->getId();
        foreach ($rows as $index => $row) {
            if ($row->variant_id === $variant_id) {
                $this->rows[$index]->quantity = $quantity;

                return true;
            }
        }

        // @since 0.7.6 add row when not yet present
        return $this->addVariant($variant, $quantity);
    }

    public function completeRowForOutput(): void
    {
        $output_object = $this->row;
        // slug is mandatory for an element
        $output_object->slug = '__shoppinglist__/' . $this->name;
        // as is path
        $output_object->path = '__shoppinglist__/' . $this->name;
        // enrich each row with its variant
        $list_rows = $this->rows; // it's detached now, you can format prices for output in this array
        $amount_row_total = 0;
        $item_count = 0;
        $row_count = 0; // @since 0.7.6. also count the rows
        // @since 0.5.9 take into account changed variants as well
        foreach ($list_rows as $index => $list_row) {
            $variant = new Variant;
            if (null === $variant->fetchById($list_row->variant_id)
                // @since 0.7.6 items that are not online are also considered unavailable
                || false === ($variant_out = $variant->getOutputFull())->online
                || false === $variant_out->for_sale // @since 0.8.14 for sale can be switched off
            ) {
                $list_row->deleted = true;
                $this->addMessage(sprintf(
                    __('An item in %s is no longer available and has been removed', 'peatcms'),
                    $this->name), 'note'
                );
                continue;
            } else if (false === Setup::$NOT_IN_STOCK_CAN_BE_ORDERED && false === $variant_out->in_stock) {
                // @since 0.7.6 items that are out of stock will be set to 0, a message can be shown using {{in_stock::not:MESSAGE}}
                if ($list_row->quantity > 0) {
                    $this->updateQuantity($variant, 0);
                    $this->addMessage(sprintf(
                        __('An item in %s is out of stock', 'peatcms'),
                        $this->name), 'note'
                    );
                }
            }
            $list_row->__variants__ = array($variant_out);
            $row_price = Help::getAsFloat($list_row->price);
            $row_price_from = Help::getAsFloat($list_row->price_from);
            $variant_price = Help::getAsFloat($variant_out->price);
            if ($variant_price !== $row_price) {
                if ($variant_price > $row_price) {
                    $this->addMessage(sprintf(
                    // #TRANSLATORS: 1 = name of shoppinglist, 2 = title of variant, 3 = currency symbol, 4 = amount
                        __('Price change in %1$s: %2$s is now %3$s %4$s', 'peatcms'),
                        $this->name, $variant_out->title, '€', Help::asMoney($variant_price)
                    ), 'note');
                } else {
                    $this->addMessage(sprintf(
                    // #TRANSLATORS: 1 = name of shoppinglist, 2 = title of variant, 3 = currency symbol, 4 = amount
                        __('Price drop in %1$s: %2$s is now %3$s %4$s', 'peatcms'),
                        $this->name, $variant_out->title, '€', Help::asMoney($variant_price)
                    ), 'note');
                }
                $row_price = $variant_price;
            }
            $row_total = $list_row->quantity * $row_price;
            $list_row->total = Help::asMoney($row_total);
            $list_row->price = Help::asMoney($row_price);
            if ($row_price_from > $row_price) {
                $list_row->price_from = Help::asMoney($row_price_from);
            } else {
                $list_row->price_from = '';
            }
            $amount_row_total += $row_total;
            $item_count += $list_row->quantity;
            $row_count += 1;
        }
        unset($variant);
        $output_object->rows = $list_rows;
        unset($list_rows);
        $output_object->amount_row_total = Help::asMoney($amount_row_total);
        $output_object->item_count = $item_count;
        $output_object->row_count = $row_count;
        // @since 0.5.12 get the shippingcosts if a shipping country is known
        $amount_grand_total = $amount_row_total;
        if (($country_id = $this->session->getValue('shipping_country_id'))) {
            if (($country = $this->getDB()->getCountryById($country_id))) {
                if ($amount_grand_total < Help::getAsFloat($country->shipping_free_from)) {
                    $shipping_costs = Help::getAsFloat($country->shipping_costs);
                    $output_object->shipping_costs = Help::asMoney($shipping_costs);
                    $amount_grand_total += $shipping_costs;
                }
            }
        }
        $output_object->amount_grand_total = Help::asMoney($amount_grand_total);
        // set template_id to default template, if it exists
        $this->row->template_id = $this->getDB()->getDefaultTemplateIdFor('shoppinglist');

        $this->row = $output_object;
    }

    /**
     * @return mixed
     * @since 0.7.9
     */
    public function getRows() {
        //return $this->getOutput()->rows;
        return $this->rows;
    }

    /**
     * @return bool whether the list has changed during the request
     */
    public function hasChanged(): bool
    {
        return ($this->getStateCurrent() !== $this->getStateSaved());
    }

    private function getStateCurrent(): ?string
    {
        if (isset($this->rows)) {
            return md5(var_export($this->rows, true));
        } else {
            return null;
        }
    }

    private function getStateSaved(): ?string
    {
        if (isset($this->state)) {
            return $this->state;
        } else {
            return null;
        }
    }

    private function setState(string $md5)
    {
        // expects valid $md5 string based on the vars
        $this->state = $md5;
    }
}

