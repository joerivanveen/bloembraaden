<?php

declare(strict_types=1);

namespace Bloembraaden;

class Order extends BaseElement
{
    private array $rows, $payments;

    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
        $this->type_name = 'order';
    }

    public function getRows(): array
    {
        if (isset($this->rows)) return $this->rows;
        $this->rows = Help::getDB()->fetchOrderRows($this->getId());

        return $this->rows;
    }

    public function getOrderNumber(): string
    {
        return $this->row->order_number;
    }

    public function getPayments(): array
    {
        if (isset($this->payments)) return $this->payments;
        $this->payments = Help::getDB()->fetchOrderPayments($this->getId());

        return $this->payments;
    }

    public function getGrandTotal(): ?float
    {
        if (isset($this->row->__items__)) return Help::asFloat($this->row->amount_grand_total);
        if (isset($this->row->amount_grand_total)) return $this->row->amount_grand_total / 100.0;

        return null;
    }

    /**
     * @return string|null the payment tracking id or null when not present
     * @since 0.6.16
     */
    public function getPaymentTrackingId(): ?string
    {
        if (isset($this->row->payment_tracking_id)
            && '' !== ($tracking_id = trim($this->row->payment_tracking_id))
        ) {
            return $tracking_id;
        }

        return null;
    }

    public function create(?bool $online = true): ?int
    {
        return null; // orders are created by DB->placeOrder();
    }

    /**
     * @param $html
     * @return bool @since 0.9.0
     * @since 0.5.16
     */
    public function updateHTML($html): bool
    {
        // todo some error checking maybe or something
        return Help::getDB()->updateColumns('_order', array('html' => $html), $this->getId());
    }

    public function completeRowForOutput(): void
    {
        $row =& $this->row;
        $order_number = $row->order_number;
        // slug is mandatory for an element
        $row->slug = "__order__/$order_number";
        // get the template_id (if present)
        $row->template_id = Help::getDB()->getDefaultTemplateIdFor('order');
        // get the rows as well
        $list_rows = $this->getRows();
        $amount_row_total = 0;
        $item_count = 0;
        $vat = array(); // amounts are recorded per percentage
        foreach ($list_rows as $index => $list_row) {
            $quantity = $list_row->quantity;
            $row_price = Help::asFloat($list_row->price);
            $row_price_from = Help::asFloat($list_row->price_from);
            $row_total = $quantity * $row_price;
            $percentage_index = (string)$list_row->vat_percentage;
            $list_row->total = Help::asMoney($row_total);
            if ($row_price_from > $row_price) {
                $list_row->price_from = Help::asMoney($row_price_from);
            } else {
                $list_row->price_from = '';
            }
            // To keep calculating in line with DB process for EU companies
            // calculate ex-vat price for 1 item, then subtract it from the
            // inc vat price to get vat amount.
            // Also convert between money and float to stay consistent.
            $price_ex_vat = Help::asMoney(100 * $row_price / (100 + $list_row->vat_percentage));
            // this way prevents rounding discrepancies with db routine
            $vat_amount = Help::asFloat(Help::asMoney($quantity * ($row_price - Help::asFloat($price_ex_vat))));
            if (isset($vat[$percentage_index])) {
                $vat[$percentage_index] += $vat_amount;
            } else {
                $vat[$percentage_index] = $vat_amount;
            }
            $amount_row_total += $row_total;
            $item_count += $quantity;
            // register the complicated calculation on the row, for use further down the line (e.g. myparcel export)
            $list_row->price_ex_vat = $price_ex_vat;
            $list_row->vat_amount = Help::asMoney($vat_amount);
        }
        $row->__items__ = $list_rows;
        unset($list_rows);
        // get the payments as well
        $row->__payments__ = $this->getPayments();
        //
        $row->amount_row_total = Help::asMoney($amount_row_total);
        $row->item_count = $item_count;
        // add the shippingcosts and make the grandtotal
        $shipping_costs = Help::asFloat($row->shipping_costs) / 100;
        if ($shipping_costs === 0.0) {
            $row->shipping_costs = Help::asMoney(0);
            $row->shipping_costs_ex_vat = Help::asMoney(0);
            $row->shipping_costs_vat_amount = Help::asMoney(0);
        } else {
            $highest_vat = 0;
            foreach ($vat as $percentage_index => $amount) {
                $percentage = Help::asFloat($percentage_index);
                if ($percentage > $highest_vat) $highest_vat = $percentage;
            }
            $price_ex_vat = 100 * $shipping_costs / (100 + $highest_vat);
            $vat_amount = $shipping_costs - $price_ex_vat;
            $vat[(string)$highest_vat] += $vat_amount;
            // format the shippingcosts in the output object
            $row->shipping_costs = Help::asMoney($shipping_costs);
            $row->shipping_costs_ex_vat = Help::asMoney($price_ex_vat);
            $row->shipping_costs_vat_amount = Help::asMoney($vat_amount);
        }
        $amount_grand_total = $amount_row_total + $shipping_costs;
        $row->amount_grand_total = Help::asMoney($amount_grand_total);
        // format order number:
        $row->order_number_human = wordwrap($order_number, 4, ' ', true);
        // the real VAT @since 0.9.0
        // @since 0.23.0 make a more logical vat-rows property
        $row->vat_rows = array();
        ksort($vat); // make vat appear from lower to higher percentages
        foreach ($vat as $percentage_index => $vat_amount) {
            $amount_grand_total -= $vat_amount;
            $vat_amount_display = Help::asMoney($vat_amount);
            // old style (DEPRECATED) todo remove when petit clos uses new style
            $percentage_tag = "vat_percentage_$percentage_index";
            $row->{$percentage_tag} = $vat_amount_display;
            // new style:
            $row->vat_rows[] = (object)array(
                'percentage' => $percentage_index,
                'amount' => $vat_amount_display,
            );
        }
        $row->amount_grand_total_ex_vat = Help::asMoney($amount_grand_total);
        // @since 0.18.1 remove session_id, not really a secret, but no need to leak it either
        unset($row->session_id);
        // @since 0.27.0 have deprecated remarks_user in output todo remove when no longer in use
        $row->remarks_user = $row->shipping_remarks;
    }
}