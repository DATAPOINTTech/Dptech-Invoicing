from app.config import settings
from datetime import datetime

PAKISTAN_STANDARD_GST_RATE = 17.0
PAKISTAN_WHT_RATE = 4.0
PAKISTAN_FED_RATE = 5.0

EXEMPTED_CATEGORIES = ["books", "newspaper", "basic_food_items"]
ZERO_RATED_CATEGORIES = ["export"]

def calculate_item_tax(unit_price: float, quantity: float, tax_rate: float, tax_inclusive: bool = True):
    line_total = unit_price * quantity
    if tax_inclusive:
        tax_amount = line_total * (tax_rate / (100 + tax_rate))
        subtotal = line_total - tax_amount
    else:
        subtotal = line_total
        tax_amount = line_total * (tax_rate / 100)
    total = subtotal + tax_amount
    return {
        "subtotal": round(subtotal, 2),
        "tax_amount": round(tax_amount, 2),
        "tax_rate": tax_rate,
        "total": round(total, 2)
    }

def calculate_invoice_tax(items: list, discount_percent: float = 0,
                         tax_rate: float = None, apply_wht: bool = False,
                         apply_fed: bool = False):
    if tax_rate is None:
        tax_rate = PAKISTAN_STANDARD_GST_RATE

    subtotal = sum(item.get("subtotal", 0) for item in items)
    discount_amount = subtotal * (discount_percent / 100) if discount_percent > 0 else 0
    taxable_amount = subtotal - discount_amount
    tax_amount = taxable_amount * (tax_rate / 100)
    wht_amount = taxable_amount * (PAKISTAN_WHT_RATE / 100) if apply_wht else 0
    fed_amount = taxable_amount * (PAKISTAN_FED_RATE / 100) if apply_fed else 0
    total_amount = taxable_amount + tax_amount + fed_amount - wht_amount

    return {
        "subtotal": round(subtotal, 2),
        "discount_percent": discount_percent,
        "discount_amount": round(discount_amount, 2),
        "taxable_amount": round(taxable_amount, 2),
        "tax_rate": tax_rate,
        "tax_amount": round(tax_amount, 2),
        "wht_rate": PAKISTAN_WHT_RATE if apply_wht else 0,
        "wht_amount": round(wht_amount, 2),
        "fed_rate": PAKISTAN_FED_RATE if apply_fed else 0,
        "fed_amount": round(fed_amount, 2),
        "total_amount": round(total_amount, 2)
    }

def generate_invoice_number(db_session, prefix: str = "INV") -> str:
    from sqlalchemy import func
    from app.models.invoice import Invoice
    last = db_session.query(func.max(Invoice.id)).scalar() or 0
    return f"{prefix}-{datetime.now().strftime('%Y%m')}-{last + 1:05d}"

def generate_estimate_number(db_session) -> str:
    from sqlalchemy import func
    from app.models.estimate import Estimate
    last = db_session.query(func.max(Estimate.id)).scalar() or 0
    return f"EST-{datetime.now().strftime('%Y%m')}-{last + 1:05d}"

def generate_purchase_number(db_session) -> str:
    from sqlalchemy import func
    from app.models.purchase import PurchaseInvoice
    last = db_session.query(func.max(PurchaseInvoice.id)).scalar() or 0
    return f"PO-{datetime.now().strftime('%Y%m')}-{last + 1:05d}"

def generate_expense_number(db_session) -> str:
    from sqlalchemy import func
    from app.models.expense import Expense
    last = db_session.query(func.max(Expense.id)).scalar() or 0
    return f"EXP-{datetime.now().strftime('%Y%m')}-{last + 1:05d}"
