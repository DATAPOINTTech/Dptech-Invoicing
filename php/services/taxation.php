<?php

declare(strict_types=1);

const PAKISTAN_STANDARD_GST_RATE = 18.0;
const PAKISTAN_WHT_RATE = 4.0;
const PAKISTAN_FED_RATE = 5.0;

const EXEMPTED_CATEGORIES = ['books', 'newspaper', 'basic_food_items'];
const ZERO_RATED_CATEGORIES = ['export'];

function calculate_item_tax(float $unit_price, float $quantity, float $tax_rate = 18.0, bool $tax_inclusive = false): array
{
    $line_total = round($unit_price * $quantity, 2);

    if ($tax_inclusive && $tax_rate > 0) {
        $tax_amount = round($line_total * ($tax_rate / (100 + $tax_rate)), 2);
        $subtotal = round($line_total - $tax_amount, 2);
        $total = $line_total;
    } else {
        // Tax 18% is added to total amount of estimate/invoice, NOT on individual items
        $subtotal = $line_total;
        $tax_amount = 0.0;
        $total = $line_total;
    }

    return [
        'subtotal' => round($subtotal, 2),
        'tax_amount' => round($tax_amount, 2),
        'tax_rate' => $tax_rate,
        'total' => round($total, 2),
    ];
}

function calculateItemTax(float $unitPrice, float $quantity, float $taxRate = 18.0, bool $taxInclusive = false): array
{
    return calculate_item_tax($unitPrice, $quantity, $taxRate, $taxInclusive);
}

function calculate_invoice_tax(array $items, float $discount_percent = 0.0, ?float $tax_rate = null, bool $apply_wht = false, bool $apply_fed = false): array
{
    $tax_rate ??= PAKISTAN_STANDARD_GST_RATE;

    $subtotal = 0.0;
    foreach ($items as $item) {
        if (isset($item['subtotal'])) {
            $subtotal += (float) $item['subtotal'];
        } elseif (isset($item['quantity'], $item['unit_price'])) {
            $subtotal += round(((float)$item['quantity']) * ((float)$item['unit_price']), 2);
        } elseif (isset($item['total_price'])) {
            $subtotal += (float) $item['total_price'];
        }
    }
    $subtotal = round($subtotal, 2);

    $discount_amount = $discount_percent > 0 ? round($subtotal * ($discount_percent / 100), 2) : 0.0;
    $taxable_amount = max(0.0, round($subtotal - $discount_amount, 2));
    $tax_amount = round($taxable_amount * ($tax_rate / 100), 2);
    $wht_amount = $apply_wht ? round($taxable_amount * (PAKISTAN_WHT_RATE / 100), 2) : 0.0;
    $fed_amount = $apply_fed ? round($taxable_amount * (PAKISTAN_FED_RATE / 100), 2) : 0.0;
    $total_amount = round($taxable_amount + $tax_amount + $fed_amount - $wht_amount, 2);

    return [
        'subtotal' => $subtotal,
        'discount_percent' => $discount_percent,
        'discount_amount' => $discount_amount,
        'taxable_amount' => $taxable_amount,
        'tax_rate' => $tax_rate,
        'tax_amount' => $tax_amount,
        'wht_rate' => $apply_wht ? PAKISTAN_WHT_RATE : 0,
        'wht_amount' => $wht_amount,
        'fed_rate' => $apply_fed ? PAKISTAN_FED_RATE : 0,
        'fed_amount' => $fed_amount,
        'total_amount' => $total_amount,
    ];
}

function calculateInvoiceTax(array $items, float $discountPercent = 0.0, ?float $taxRate = null, bool $applyWht = false, bool $applyFed = false): array
{
    return calculate_invoice_tax($items, $discountPercent, $taxRate, $applyWht, $applyFed);
}

function generate_sequence_number($db_session, string $prefix, string $table_name): string
{
    $last = 0;

    if (is_object($db_session)) {
        try {
            if (method_exists($db_session, 'query')) {
                $result = $db_session->query(sprintf('SELECT MAX(id) AS max_id FROM %s', $table_name));

                if (is_object($result) && method_exists($result, 'scalar')) {
                    $last = (int) $result->scalar();
                } elseif (is_object($result) && method_exists($result, 'fetch')) {
                    $row = $result->fetch();
                    $last = (int) ($row['max_id'] ?? 0);
                } elseif (is_object($result) && method_exists($result, 'fetchColumn')) {
                    $last = (int) $result->fetchColumn();
                }
            }

            if ($db_session instanceof PDO) {
                $statement = $db_session->query(sprintf('SELECT MAX(id) AS max_id FROM %s', $table_name));
                if ($statement !== false) {
                    $row = $statement->fetch(PDO::FETCH_ASSOC);
                    $last = (int) ($row['max_id'] ?? 0);
                }
            }
        } catch (Throwable $exception) {
            $last = 0;
        }
    }

    return sprintf('%s-%s-%05d', $prefix, date('Ym'), $last + 1);
}

function generate_invoice_number($db_session, string $prefix = 'INV'): string
{
    return generate_sequence_number($db_session, $prefix, 'invoice');
}

function generateInvoiceNumber($dbSession, string $prefix = 'INV'): string
{
    return generate_invoice_number($dbSession, $prefix);
}

function generate_estimate_number($db_session): string
{
    return generate_sequence_number($db_session, 'EST', 'estimate');
}

function generateEstimateNumber($dbSession): string
{
    return generate_estimate_number($dbSession);
}

function generate_purchase_number($db_session): string
{
    return generate_sequence_number($db_session, 'PO', 'purchase_invoice');
}

function generatePurchaseNumber($dbSession): string
{
    return generate_purchase_number($dbSession);
}

function generate_expense_number($db_session): string
{
    return generate_sequence_number($db_session, 'EXP', 'expense');
}

function generateExpenseNumber($dbSession): string
{
    return generate_expense_number($dbSession);
}
