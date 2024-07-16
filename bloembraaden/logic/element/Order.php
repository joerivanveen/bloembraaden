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
        if (isset($this->row->__items__)) return Help::getAsFloat($this->row->amount_grand_total);
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
            $row_price = Help::getAsFloat($list_row->price);
            $row_price_from = Help::getAsFloat($list_row->price_from);
            $row_total = $list_row->quantity * $row_price;
            $percentage_index = (string)$list_row->vat_percentage;
            $list_row->total = Help::asMoney($row_total);
            $list_row->price = Help::asMoney($row_price);
            if ($row_price_from > $row_price) {
                $list_row->price_from = Help::asMoney($row_price_from);
            } else {
                $list_row->price_from = '';
            }
            $vat[$percentage_index] = $row_total + ($vat[$percentage_index] ?? 0);
            $amount_row_total += $row_total;
            $item_count += $list_row->quantity;
        }
        $row->__items__ = $list_rows;
        unset($list_rows);
        // get the payments as well
        $row->__payments__ = $this->getPayments();
        //
        $row->amount_row_total = Help::asMoney($amount_row_total);
        $row->item_count = $item_count;
        // add the shippingcosts and make the grandtotal
        $shipping_costs = (Help::getAsFloat($row->shipping_costs) / 100.0);
        if ($shipping_costs !== 0.0) {
            $highest_vat = 0;
            foreach ($vat as $percentage_index => $amount) {
                $percentage = Help::getAsFloat($percentage_index);
                if ($percentage > $highest_vat) $highest_vat = $percentage;
            }
            $vat[(string)$highest_vat] += $shipping_costs;
        }
        $amount_grand_total = $amount_row_total + $shipping_costs;
        $row->amount_grand_total = Help::asMoney($amount_grand_total);
        // format the shippingcosts in the output object
        $row->shipping_costs = Help::asMoney($shipping_costs);
        // format order number:
        $row->order_number_human = wordwrap($order_number, 4, ' ', true);
        // the real VAT @since 0.9.0
        foreach ($vat as $percentage_index => $amount) {
            $vat_amount = $amount - ($amount / (1 + ($percentage_index / 100)));
            $amount_grand_total -= $vat_amount;
            $percentage_tag = "vat_percentage_$percentage_index";
            $row->{$percentage_tag} = Help::asMoney($vat_amount);
        }
        $row->amount_grand_total_ex_vat = Help::asMoney($amount_grand_total);
        // @since 0.18.1 remove session_id, not really a secret, but no need to leak it either
        unset($row->session_id);
    }
}