<?php

declare(strict_types=1);

const PAKISTAN_STANDARD_GST_RATE = 17.0;
const PAKISTAN_WHT_RATE = 4.0;
const PAKISTAN_FED_RATE = 5.0;

const EXEMPTED_CATEGORIES = ['books', 'newspaper', 'basic_food_items'];
const ZERO_RATED_CATEGORIES = ['export'];

function calculate_item_tax(float $unit_price, float $quantity, float $tax_rate, bool $tax_inclusive = true): array
{
    $line_total = $unit_price * $quantity;

    if ($tax_inclusive) {
        $tax_amount = $line_total * ($tax_rate / (100 + $tax_rate));
        $subtotal = $line_total - $tax_amount;
    } else {
        $subtotal = $line_total;
        $tax_amount = $line_total * ($tax_rate / 100);
    }

    $total = $subtotal + $tax_amount;

    return [
        'subtotal' => round($subtotal, 2),
        'tax_amount' => round($tax_amount, 2),
        'tax_rate' => $tax_rate,
        'total' => round($total, 2),
    ];
}

function calculateItemTax(float $unitPrice, float $quantity, float $taxRate, bool $taxInclusive = true): array
{
    return calculate_item_tax($unitPrice, $quantity, $taxRate, $taxInclusive);
}

function calculate_invoice_tax(array $items, float $discount_percent = 0.0, ?float $tax_rate = null, bool $apply_wht = false, bool $apply_fed = false): array
{
    $tax_rate ??= PAKISTAN_STANDARD_GST_RATE;

    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) ($item['subtotal'] ?? 0);
    }

    $discount_amount = $discount_percent > 0 ? $subtotal * ($discount_percent / 100) : 0.0;
    $taxable_amount = $subtotal - $discount_amount;
    $tax_amount = $taxable_amount * ($tax_rate / 100);
    $wht_amount = $apply_wht ? $taxable_amount * (PAKISTAN_WHT_RATE / 100) : 0.0;
    $fed_amount = $apply_fed ? $taxable_amount * (PAKISTAN_FED_RATE / 100) : 0.0;
    $total_amount = $taxable_amount + $tax_amount + $fed_amount - $wht_amount;

    return [
        'subtotal' => round($subtotal, 2),
        'discount_percent' => $discount_percent,
        'discount_amount' => round($discount_amount, 2),
        'taxable_amount' => round($taxable_amount, 2),
        'tax_rate' => $tax_rate,
        'tax_amount' => round($tax_amount, 2),
        'wht_rate' => $apply_wht ? PAKISTAN_WHT_RATE : 0,
        'wht_amount' => round($wht_amount, 2),
        'fed_rate' => $apply_fed ? PAKISTAN_FED_RATE : 0,
        'fed_amount' => round($fed_amount, 2),
        'total_amount' => round($total_amount, 2),
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
