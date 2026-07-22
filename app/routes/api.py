from fastapi import APIRouter, Depends, HTTPException, status, Query
from sqlalchemy.orm import Session
from typing import List, Optional
from datetime import date, datetime
from app.database import get_db
from app.models.user import User, UserRole
from app.models.client import Client
from app.models.product import Product, ProductCategory
from app.models.purchase import PurchaseInvoice, PurchaseItem, PurchaseStatus
from app.models.inventory import Inventory, StockMovement, MovementType
from app.models.expense import Expense, ExpenseCategory
from app.models.estimate import Estimate, EstimateItem, EstimateStatus
from app.models.invoice import Invoice, InvoiceItem, InvoiceStatus
from app.models.project import Project, ProjectStatus
from app.services.auth import get_current_user, get_password_hash, authenticate_user, create_access_token
from app.services.taxation import calculate_item_tax, calculate_invoice_tax, generate_invoice_number, generate_estimate_number, generate_purchase_number, generate_expense_number
from app.services.inventory_service import update_stock, get_stock_level, get_low_stock_products, get_or_create_inventory
from app.services.pdf_service import generate_invoice_pdf, generate_estimate_pdf
from app.services.communication import send_email_pdf, send_whatsapp_message
from app.agents.support_agent import support_agent, voice_agent
from pydantic import BaseModel
from fastapi.security import OAuth2PasswordRequestForm
from fastapi.responses import StreamingResponse
import io

router = APIRouter(prefix="/api")

# --- Auth Routes ---
class UserCreate(BaseModel):
    username: str
    email: str
    password: str
    full_name: str
    phone: Optional[str] = None
    role: str = "staff"

@router.post("/auth/register")
def register(user: UserCreate, db: Session = Depends(get_db)):
    existing = db.query(User).filter((User.username == user.username) | (User.email == user.email)).first()
    if existing:
        raise HTTPException(status_code=400, detail="Username or email already exists")
    db_user = User(
        username=user.username,
        email=user.email,
        hashed_password=get_password_hash(user.password),
        full_name=user.full_name,
        phone=user.phone,
        role=UserRole(user.role) if user.role in [e.value for e in UserRole] else UserRole.STAFF
    )
    db.add(db_user)
    db.commit()
    db.refresh(db_user)
    return {"message": "User created", "id": db_user.id}

@router.post("/auth/login")
def login(form_data: OAuth2PasswordRequestForm = Depends(), db: Session = Depends(get_db)):
    user = authenticate_user(db, form_data.username, form_data.password)
    if not user:
        raise HTTPException(status_code=401, detail="Invalid credentials")
    token = create_access_token({"sub": user.username, "role": user.role.value})
    return {"access_token": token, "token_type": "bearer", "user": {"id": user.id, "name": user.full_name, "role": user.role.value}}

# --- Dashboard ---
@router.get("/dashboard")
def get_dashboard(db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    total_clients = db.query(Client).count()
    active_projects = db.query(Project).filter(Project.status == ProjectStatus.IN_PROGRESS).count()
    pending_invoices = db.query(Invoice).filter(Invoice.status.in_([InvoiceStatus.DRAFT, InvoiceStatus.SENT])).count()
    total_receivables = db.query(Invoice).filter(Invoice.status.in_([InvoiceStatus.SENT, InvoiceStatus.PARTIALLY_PAID])).with_entities(Invoice.balance_due).all()
    total_due = sum(r[0] for r in total_receivables if r[0]) if total_receivables else 0
    low_stock = get_low_stock_products(db)
    recent_invoices = db.query(Invoice).order_by(Invoice.created_at.desc()).limit(5).all()
    recent_estimates = db.query(Estimate).order_by(Estimate.created_at.desc()).limit(5).all()
    return {
        "total_clients": total_clients,
        "recent_clients": [{"id": c.id, "name": c.name, "company": c.company, "phone": c.phone, "email": c.email} for c in db.query(Client).filter(Client.is_active == True).order_by(Client.created_at.desc()).limit(5).all()],
        "active_projects": active_projects,
        "pending_invoices": pending_invoices,
        "total_receivables": round(total_due, 2),
        "low_stock_count": len(low_stock),
        "low_stock_items": [{"id": p.id, "name": p.name} for p in low_stock],
        "recent_invoices": [{"id": i.id, "no": i.invoice_no, "client": i.client.name if i.client else "", "status": i.status.value, "total": i.total_amount} for i in recent_invoices],
        "recent_estimates": [{"id": e.id, "no": e.estimate_no, "client": e.client.name if e.client else "", "status": e.status.value, "total": e.total_amount} for e in recent_estimates]
    }

# --- Client Routes ---
class ClientCreate(BaseModel):
    name: str
    company: Optional[str] = None
    email: Optional[str] = None
    phone: Optional[str] = None
    mobile: Optional[str] = None
    address: Optional[str] = None
    city: Optional[str] = None
    province: Optional[str] = None
    ntn: Optional[str] = None
    strn: Optional[str] = None
    notes: Optional[str] = None

@router.get("/clients")
def list_clients(search: Optional[str] = None, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    query = db.query(Client).filter(Client.is_active == True)
    if search:
        query = query.filter(Client.name.ilike(f"%{search}%") | Client.company.ilike(f"%{search}%"))
    return query.order_by(Client.name).all()

@router.post("/clients")
def create_client(client: ClientCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_client = Client(**client.model_dump())
    db.add(db_client)
    db.commit()
    db.refresh(db_client)
    return db_client

@router.get("/clients/{client_id}")
def get_client(client_id: int, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    client = db.query(Client).filter(Client.id == client_id).first()
    if not client:
        raise HTTPException(404, "Client not found")
    return client

@router.put("/clients/{client_id}")
def update_client(client_id: int, client: ClientCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_client = db.query(Client).filter(Client.id == client_id).first()
    if not db_client:
        raise HTTPException(404, "Client not found")
    for key, value in client.model_dump(exclude_unset=True).items():
        setattr(db_client, key, value)
    db.commit()
    db.refresh(db_client)
    return db_client

@router.delete("/clients/{client_id}")
def delete_client(client_id: int, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_client = db.query(Client).filter(Client.id == client_id).first()
    if not db_client:
        raise HTTPException(404, "Client not found")
    db_client.is_active = False
    db.commit()
    return {"message": "Client deactivated"}

# --- Product Routes ---
class ProductCreate(BaseModel):
    name: str
    description: Optional[str] = None
    category: str = "other"
    sku: Optional[str] = None
    unit_price: float = 0
    cost_price: float = 0
    unit: str = "pcs"
    tax_rate: float = 17.0
    tax_inclusive: bool = True
    hs_code: Optional[str] = None
    min_stock_level: float = 0

@router.get("/products")
def list_products(search: Optional[str] = None, category: Optional[str] = None, db: Session = Depends(get_db)):
    query = db.query(Product).filter(Product.is_active == True)
    if search:
        query = query.filter(Product.name.ilike(f"%{search}%") | Product.sku.ilike(f"%{search}%"))
    if category:
        query = query.filter(Product.category == category)
    products = query.order_by(Product.name).all()
    result = []
    for p in products:
        inv = db.query(Inventory).filter(Inventory.product_id == p.id).first()
        result.append({
            "id": p.id, "name": p.name, "description": p.description,
            "category": p.category.value if p.category else None, "sku": p.sku,
            "unit_price": p.unit_price, "cost_price": p.cost_price, "unit": p.unit,
            "tax_rate": p.tax_rate, "tax_inclusive": p.tax_inclusive,
            "hs_code": p.hs_code, "min_stock_level": p.min_stock_level,
            "stock_qty": inv.quantity if inv else 0
        })
    return result

@router.post("/products")
def create_product(product: ProductCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_product = Product(**product.model_dump())
    db.add(db_product)
    db.flush()
    from app.services.inventory_service import get_or_create_inventory
    get_or_create_inventory(db, db_product.id)
    db.commit()
    db.refresh(db_product)
    return db_product

@router.get("/products/{product_id}")
def get_product(product_id: int, db: Session = Depends(get_db)):
    product = db.query(Product).filter(Product.id == product_id).first()
    if not product:
        raise HTTPException(404, "Product not found")
    inv = db.query(Inventory).filter(Inventory.product_id == product_id).first()
    return {
        "id": product.id, "name": product.name, "description": product.description,
        "category": product.category, "sku": product.sku,
        "unit_price": product.unit_price, "cost_price": product.cost_price,
        "unit": product.unit, "tax_rate": product.tax_rate,
        "tax_inclusive": product.tax_inclusive, "hs_code": product.hs_code,
        "min_stock_level": product.min_stock_level,
        "stock_qty": inv.quantity if inv else 0
    }

@router.put("/products/{product_id}")
def update_product(product_id: int, product: ProductCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_product = db.query(Product).filter(Product.id == product_id).first()
    if not db_product:
        raise HTTPException(404, "Product not found")
    for key, value in product.model_dump(exclude_unset=True).items():
        setattr(db_product, key, value)
    db.commit()
    db.refresh(db_product)
    return db_product

# --- Purchase Routes ---
class PurchaseItemCreate(BaseModel):
    product_id: Optional[int] = None
    product_name: str
    description: Optional[str] = None
    quantity: float
    unit: str = "pcs"
    unit_price: float
    tax_rate: float = 0.0

class PurchaseCreate(BaseModel):
    supplier_name: str
    supplier_ntn: Optional[str] = None
    supplier_address: Optional[str] = None
    invoice_date: date
    received_date: Optional[date] = None
    notes: Optional[str] = None
    items: List[PurchaseItemCreate]

@router.get("/purchases")
def list_purchases(db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    return db.query(PurchaseInvoice).order_by(PurchaseInvoice.created_at.desc()).all()

def _resolve_or_create_product(db, item) -> int:
    if item.product_id:
        prod = db.query(Product).filter(Product.id == item.product_id).first()
        if prod:
            return prod.id
    existing = db.query(Product).filter(
        Product.name.ilike(item.product_name.strip())
    ).first()
    if existing:
        return existing.id
    new_prod = Product(
        name=item.product_name.strip(),
        description=item.description or "",
        unit_price=item.unit_price,
        cost_price=item.unit_price,
        unit=item.unit,
        category=ProductCategory.OTHER,
        is_active=True
    )
    db.add(new_prod)
    db.flush()
    get_or_create_inventory(db, new_prod.id)
    return new_prod.id

@router.post("/purchases")
def create_purchase(purchase: PurchaseCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    inv_no = generate_purchase_number(db)
    subtotal = sum(item.unit_price * item.quantity for item in purchase.items)
    tax_amount = sum(item.unit_price * item.quantity * (item.tax_rate / 100) for item in purchase.items)
    total = subtotal + tax_amount

    db_purchase = PurchaseInvoice(
        invoice_no=inv_no,
        supplier_name=purchase.supplier_name,
        supplier_ntn=purchase.supplier_ntn,
        supplier_address=purchase.supplier_address,
        invoice_date=purchase.invoice_date,
        received_date=purchase.received_date,
        subtotal=round(subtotal, 2),
        tax_amount=round(tax_amount, 2),
        total_amount=round(total, 2),
        notes=purchase.notes,
        created_by=current_user.id,
        status=PurchaseStatus.RECEIVED
    )
    db.add(db_purchase)
    db.flush()

    for item in purchase.items:
        product_id = _resolve_or_create_product(db, item)
        item_total = item.unit_price * item.quantity
        item_tax = item_total * (item.tax_rate / 100)
        db_item = PurchaseItem(
            purchase_id=db_purchase.id,
            product_id=product_id,
            product_name=item.product_name,
            description=item.description,
            quantity=item.quantity,
            unit=item.unit,
            unit_price=item.unit_price,
            tax_rate=item.tax_rate,
            tax_amount=round(item_tax, 2),
            total_price=round(item_total + item_tax, 2)
        )
        db.add(db_item)

        try:
            update_stock(db, product_id, item.quantity, MovementType.PURCHASE_IN,
                        "purchase", db_purchase.id, f"Purchase {inv_no}", current_user.id)
        except ValueError as e:
            print(f"Stock update warning: {e}")
    db.commit()
    db.refresh(db_purchase)
    return db_purchase

@router.post("/purchases/import")
def import_purchases(purchases: List[PurchaseCreate], db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    created = []
    for purchase in purchases:
        inv_no = generate_purchase_number(db)
        subtotal = sum(item.unit_price * item.quantity for item in purchase.items)
        tax_amount = sum(item.unit_price * item.quantity * (item.tax_rate / 100) for item in purchase.items)
        total = subtotal + tax_amount

        db_purchase = PurchaseInvoice(
            invoice_no=inv_no,
            supplier_name=purchase.supplier_name,
            supplier_ntn=purchase.supplier_ntn,
            supplier_address=purchase.supplier_address,
            invoice_date=purchase.invoice_date,
            received_date=purchase.received_date,
            subtotal=round(subtotal, 2),
            tax_amount=round(tax_amount, 2),
            total_amount=round(total, 2),
            notes=purchase.notes,
            created_by=current_user.id,
            status=PurchaseStatus.RECEIVED
        )
        db.add(db_purchase)
        db.flush()

        for item in purchase.items:
            product_id = _resolve_or_create_product(db, item)
            item_total = item.unit_price * item.quantity
            item_tax = item_total * (item.tax_rate / 100)
            db_item = PurchaseItem(
                purchase_id=db_purchase.id,
                product_id=product_id,
                product_name=item.product_name,
                description=item.description,
                quantity=item.quantity,
                unit=item.unit,
                unit_price=item.unit_price,
                tax_rate=item.tax_rate,
                tax_amount=round(item_tax, 2),
                total_price=round(item_total + item_tax, 2)
            )
            db.add(db_item)
            try:
                update_stock(db, product_id, item.quantity, MovementType.PURCHASE_IN,
                            "purchase", db_purchase.id, f"Purchase {inv_no}", current_user.id)
            except ValueError as e:
                print(f"Stock update warning: {e}")

        db.commit()
        db.refresh(db_purchase)
        created.append(db_purchase)
    return {"message": f"{len(created)} purchase(s) imported", "purchases": created}

# --- Inventory Routes ---
@router.get("/inventory")
def list_inventory(low_stock: bool = False, db: Session = Depends(get_db)):
    query = db.query(Inventory, Product).join(Product)
    if low_stock:
        query = query.filter(Inventory.quantity <= Product.min_stock_level)
    results = []
    for inv, prod in query.all():
        results.append({
            "product_id": prod.id, "product_name": prod.name, "sku": prod.sku,
            "category": prod.category.value if prod.category else None,
            "unit": prod.unit, "quantity": inv.quantity,
            "unit_price": prod.unit_price, "min_stock": prod.min_stock_level,
            "low_stock": inv.quantity <= prod.min_stock_level if prod.min_stock_level else False,
            "warehouse": inv.warehouse
        })
    return results

@router.get("/inventory/movements")
def list_movements(product_id: Optional[int] = None, limit: int = 50, db: Session = Depends(get_db)):
    query = db.query(StockMovement).order_by(StockMovement.created_at.desc())
    if product_id:
        query = query.filter(StockMovement.product_id == product_id)
    return query.limit(limit).all()

class StockIssueRequest(BaseModel):
    product_id: int
    quantity: float
    notes: Optional[str] = None

@router.post("/inventory/issue")
def issue_stock(issue: StockIssueRequest, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    try:
        inv = update_stock(db, issue.product_id, issue.quantity, MovementType.SALE_OUT,
                          "manual_issue", None, issue.notes, current_user.id)
        db.commit()
        product = db.query(Product).filter(Product.id == issue.product_id).first()
        return {"message": "Stock issued", "product": product.name, "remaining": inv.quantity}
    except ValueError as e:
        raise HTTPException(400, str(e))

# --- Expense Routes ---
class ExpenseCreate(BaseModel):
    category: str = "other"
    description: str
    amount: float
    tax_amount: float = 0
    expense_date: date
    payment_method: Optional[str] = None
    vendor_name: Optional[str] = None
    receipt_ref: Optional[str] = None
    project_id: Optional[int] = None
    notes: Optional[str] = None

@router.get("/expenses")
def list_expenses(category: Optional[str] = None, from_date: Optional[date] = None, to_date: Optional[date] = None, db: Session = Depends(get_db)):
    query = db.query(Expense).order_by(Expense.expense_date.desc())
    if category:
        query = query.filter(Expense.category == category)
    if from_date:
        query = query.filter(Expense.expense_date >= from_date)
    if to_date:
        query = query.filter(Expense.expense_date <= to_date)
    return query.all()

@router.post("/expenses")
def create_expense(expense: ExpenseCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    exp_no = generate_expense_number(db)
    total = expense.amount + expense.tax_amount
    db_expense = Expense(
        expense_no=exp_no,
        category=ExpenseCategory(expense.category) if expense.category in [e.value for e in ExpenseCategory] else ExpenseCategory.OTHER,
        description=expense.description,
        amount=expense.amount,
        tax_amount=expense.tax_amount,
        total_amount=round(total, 2),
        expense_date=expense.expense_date,
        payment_method=expense.payment_method,
        vendor_name=expense.vendor_name,
        receipt_ref=expense.receipt_ref,
        project_id=expense.project_id,
        notes=expense.notes,
        created_by=current_user.id
    )
    db.add(db_expense)
    db.commit()
    db.refresh(db_expense)
    return db_expense

@router.get("/expenses/summary")
def expense_summary(from_date: Optional[date] = None, to_date: Optional[date] = None, db: Session = Depends(get_db)):
    query = db.query(Expense)
    if from_date:
        query = query.filter(Expense.expense_date >= from_date)
    if to_date:
        query = query.filter(Expense.expense_date <= to_date)
    expenses = query.all()
    total = sum(e.total_amount for e in expenses)
    by_category = {}
    for e in expenses:
        cat = e.category.value if e.category else "other"
        by_category[cat] = by_category.get(cat, 0) + e.total_amount
    return {"total_expenses": round(total, 2), "count": len(expenses), "by_category": by_category}

# --- Project Routes ---
class ProjectCreate(BaseModel):
    name: str
    description: Optional[str] = None
    client_id: Optional[int] = None
    start_date: Optional[date] = None
    end_date: Optional[date] = None
    budget: float = 0
    notes: Optional[str] = None

@router.get("/projects")
def list_projects(db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    return db.query(Project).order_by(Project.created_at.desc()).all()

@router.post("/projects")
def create_project(project: ProjectCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_project = Project(**project.model_dump(), created_by=current_user.id)
    db.add(db_project)
    db.commit()
    db.refresh(db_project)
    return db_project

# --- Estimate Routes ---
class EstimateItemCreate(BaseModel):
    product_id: Optional[int] = None
    description: str
    quantity: float
    unit: str = "pcs"
    unit_price: float
    tax_rate: float = 17.0

class EstimateCreate(BaseModel):
    client_id: int
    project_id: Optional[int] = None
    title: Optional[str] = None
    estimate_date: date
    valid_until: Optional[date] = None
    discount_percent: float = 0
    terms_conditions: Optional[str] = None
    notes: Optional[str] = None
    items: List[EstimateItemCreate]

@router.get("/estimates")
def list_estimates(status: Optional[str] = None, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    query = db.query(Estimate).order_by(Estimate.created_at.desc())
    if status:
        query = query.filter(Estimate.status == status)
    estimates = query.all()
    result = []
    for e in estimates:
        result.append({
            "id": e.id, "estimate_no": e.estimate_no, "title": e.title,
            "client_name": e.client.name if e.client else "",
            "client_id": e.client_id,
            "estimate_date": e.estimate_date, "valid_until": e.valid_until,
            "status": e.status.value, "subtotal": e.subtotal,
            "discount_percent": e.discount_percent, "tax_amount": e.tax_amount,
            "total_amount": e.total_amount, "items_count": len(e.items)
        })
    return result

@router.post("/estimates")
def create_estimate(estimate: EstimateCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    est_no = generate_estimate_number(db)
    calculated_items = [calculate_item_tax(it.unit_price, it.quantity, it.tax_rate) for it in estimate.items]
    totals = calculate_invoice_tax(calculated_items, estimate.discount_percent)

    db_estimate = Estimate(
        estimate_no=est_no, client_id=estimate.client_id,
        project_id=estimate.project_id, title=estimate.title,
        estimate_date=estimate.estimate_date, valid_until=estimate.valid_until,
        subtotal=totals["subtotal"], discount_percent=estimate.discount_percent,
        discount_amount=totals["discount_amount"],
        tax_rate=totals["tax_rate"], tax_amount=totals["tax_amount"],
        total_amount=totals["total_amount"],
        terms_conditions=estimate.terms_conditions, notes=estimate.notes,
        created_by=current_user.id
    )
    db.add(db_estimate)
    db.flush()

    for i, item in enumerate(estimate.items):
        calc = calculated_items[i]
        db_item = EstimateItem(
            estimate_id=db_estimate.id, product_id=item.product_id,
            description=item.description, quantity=item.quantity,
            unit=item.unit, unit_price=item.unit_price,
            tax_rate=item.tax_rate, tax_amount=calc["tax_amount"],
            total_price=calc["total"]
        )
        db.add(db_item)
    db.commit()
    db.refresh(db_estimate)
    return db_estimate

@router.get("/estimates/{estimate_id}")
def get_estimate(estimate_id: int, db: Session = Depends(get_db)):
    estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not estimate:
        raise HTTPException(404, "Estimate not found")
    items = db.query(EstimateItem).filter(EstimateItem.estimate_id == estimate_id).all()
    return {
        "id": estimate.id, "estimate_no": estimate.estimate_no,
        "client": estimate.client, "title": estimate.title,
        "estimate_date": estimate.estimate_date, "valid_until": estimate.valid_until,
        "status": estimate.status.value, "subtotal": estimate.subtotal,
        "discount_percent": estimate.discount_percent,
        "discount_amount": estimate.discount_amount,
        "tax_rate": estimate.tax_rate, "tax_amount": estimate.tax_amount,
        "total_amount": estimate.total_amount,
        "terms_conditions": estimate.terms_conditions, "notes": estimate.notes,
        "items": items,
        "can_approve": estimate.status == EstimateStatus.SENT,
        "can_convert": estimate.status == EstimateStatus.APPROVED
    }

@router.post("/estimates/{estimate_id}/approve")
def approve_estimate(estimate_id: int, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not estimate:
        raise HTTPException(404, "Estimate not found")
    if estimate.status != EstimateStatus.SENT:
        raise HTTPException(400, f"Cannot approve estimate with status {estimate.status.value}")
    estimate.status = EstimateStatus.APPROVED
    db.commit()
    return {"message": "Estimate approved", "estimate_no": estimate.estimate_no}

@router.post("/estimates/{estimate_id}/convert")
def convert_estimate_to_invoice(estimate_id: int, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not estimate:
        raise HTTPException(404, "Estimate not found")
    if estimate.status != EstimateStatus.APPROVED:
        raise HTTPException(400, f"Cannot convert estimate with status {estimate.status.value}. Must be approved first.")

    inv_no = generate_invoice_number(db)
    items = db.query(EstimateItem).filter(EstimateItem.estimate_id == estimate_id).all()

    db_invoice = Invoice(
        invoice_no=inv_no, client_id=estimate.client_id,
        estimate_id=estimate_id, project_id=estimate.project_id,
        invoice_date=date.today(), due_date=date.today(),
        subtotal=estimate.subtotal, discount_percent=estimate.discount_percent,
        discount_amount=estimate.discount_amount, tax_rate=estimate.tax_rate,
        tax_amount=estimate.tax_amount, total_amount=estimate.total_amount,
        balance_due=estimate.total_amount,
        terms_conditions=estimate.terms_conditions, notes=estimate.notes,
        created_by=current_user.id
    )
    db.add(db_invoice)
    db.flush()

    for item in items:
        db_item = InvoiceItem(
            invoice_id=db_invoice.id, product_id=item.product_id,
            description=item.description, quantity=item.quantity,
            unit=item.unit, unit_price=item.unit_price,
            tax_rate=item.tax_rate, tax_amount=item.tax_amount,
            total_price=item.total_price
        )
        db.add(db_item)

    estimate.status = EstimateStatus.CONVERTED
    db.commit()
    db.refresh(db_invoice)
    return {"message": "Invoice created", "invoice_id": db_invoice.id, "invoice_no": db_invoice.invoice_no}

@router.get("/estimates/{estimate_id}/pdf")
def download_estimate_pdf(estimate_id: int, db: Session = Depends(get_db)):
    estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not estimate:
        raise HTTPException(404, "Estimate not found")
    items = db.query(EstimateItem).filter(EstimateItem.estimate_id == estimate_id).all()
    items_data = [{
        "description": i.description, "quantity": i.quantity,
        "unit": i.unit, "unit_price": i.unit_price,
        "tax_rate": i.tax_rate, "tax_amount": i.tax_amount,
        "total_price": i.total_price
    } for i in items]
    estimate_data = {
        "estimate_no": estimate.estimate_no,
        "estimate_date": str(estimate.estimate_date),
        "valid_until": str(estimate.valid_until) if estimate.valid_until else "",
        "client_name": estimate.client.name if estimate.client else "",
        "items": items_data,
        "subtotal": estimate.subtotal, "discount_amount": estimate.discount_amount,
        "tax_amount": estimate.tax_amount, "total_amount": estimate.total_amount,
        "terms": estimate.terms_conditions or ""
    }
    pdf_buf = generate_estimate_pdf(estimate_data)
    return StreamingResponse(pdf_buf, media_type="application/pdf",
                            headers={"Content-Disposition": f"attachment; filename=estimate_{estimate.estimate_no}.pdf"})

# --- Invoice Routes ---
class InvoiceCreate(BaseModel):
    client_id: int
    estimate_id: Optional[int] = None
    project_id: Optional[int] = None
    invoice_date: date
    due_date: Optional[date] = None
    discount_percent: float = 0
    apply_wht: bool = False
    apply_fed: bool = False
    payment_terms: Optional[str] = None
    notes: Optional[str] = None
    terms_conditions: Optional[str] = None
    items: List[EstimateItemCreate]

@router.get("/invoices")
def list_invoices(status: Optional[str] = None, client_id: Optional[int] = None, db: Session = Depends(get_db)):
    query = db.query(Invoice).order_by(Invoice.created_at.desc())
    if status:
        query = query.filter(Invoice.status == status)
    if client_id:
        query = query.filter(Invoice.client_id == client_id)
    invoices = query.all()
    result = []
    for inv in invoices:
        result.append({
            "id": inv.id, "invoice_no": inv.invoice_no,
            "client_name": inv.client.name if inv.client else "",
            "client_id": inv.client_id,
            "invoice_date": inv.invoice_date, "due_date": inv.due_date,
            "status": inv.status.value, "subtotal": inv.subtotal,
            "discount_amount": inv.discount_amount, "tax_amount": inv.tax_amount,
            "total_amount": inv.total_amount, "amount_paid": inv.amount_paid,
            "balance_due": inv.balance_due
        })
    return result

@router.post("/invoices")
def create_invoice(invoice: InvoiceCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    inv_no = generate_invoice_number(db)
    calculated_items = [calculate_item_tax(it.unit_price, it.quantity, it.tax_rate) for it in invoice.items]
    totals = calculate_invoice_tax(calculated_items, invoice.discount_percent,
                                  apply_wht=invoice.apply_wht, apply_fed=invoice.apply_fed)
    due = invoice.due_date or invoice.invoice_date

    db_invoice = Invoice(
        invoice_no=inv_no, client_id=invoice.client_id,
        estimate_id=invoice.estimate_id, project_id=invoice.project_id,
        invoice_date=invoice.invoice_date, due_date=due,
        subtotal=totals["subtotal"], discount_percent=invoice.discount_percent,
        discount_amount=totals["discount_amount"],
        tax_rate=totals["tax_rate"], tax_amount=totals["tax_amount"],
        withholding_tax_rate=totals["wht_rate"], withholding_tax_amount=totals["wht_amount"],
        fed_rate=totals["fed_rate"], fed_amount=totals["fed_amount"],
        total_amount=totals["total_amount"], balance_due=totals["total_amount"],
        payment_terms=invoice.payment_terms, notes=invoice.notes,
        terms_conditions=invoice.terms_conditions, created_by=current_user.id
    )
    db.add(db_invoice)
    db.flush()

    for i, item in enumerate(invoice.items):
        calc = calculated_items[i]
        db_item = InvoiceItem(
            invoice_id=db_invoice.id, product_id=item.product_id,
            description=item.description, quantity=item.quantity,
            unit=item.unit, unit_price=item.unit_price,
            tax_rate=item.tax_rate, tax_amount=calc["tax_amount"],
            total_price=calc["total"]
        )
        db.add(db_item)

    db.commit()
    db.refresh(db_invoice)
    return db_invoice

@router.get("/invoices/{invoice_id}")
def get_invoice(invoice_id: int, db: Session = Depends(get_db)):
    invoice = db.query(Invoice).filter(Invoice.id == invoice_id).first()
    if not invoice:
        raise HTTPException(404, "Invoice not found")
    items = db.query(InvoiceItem).filter(InvoiceItem.invoice_id == invoice_id).all()
    return {
        "id": invoice.id, "invoice_no": invoice.invoice_no,
        "client": invoice.client,
        "invoice_date": invoice.invoice_date, "due_date": invoice.due_date,
        "status": invoice.status.value, "subtotal": invoice.subtotal,
        "discount_percent": invoice.discount_percent,
        "discount_amount": invoice.discount_amount,
        "tax_rate": invoice.tax_rate, "tax_amount": invoice.tax_amount,
        "wht_rate": invoice.withholding_tax_rate,
        "wht_amount": invoice.withholding_tax_amount,
        "fed_rate": invoice.fed_rate, "fed_amount": invoice.fed_amount,
        "total_amount": invoice.total_amount,
        "amount_paid": invoice.amount_paid, "balance_due": invoice.balance_due,
        "payment_terms": invoice.payment_terms, "notes": invoice.notes,
        "terms_conditions": invoice.terms_conditions,
        "items": items
    }

@router.post("/invoices/{invoice_id}/pay")
def record_payment(invoice_id: int, amount: float = Query(...), db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    invoice = db.query(Invoice).filter(Invoice.id == invoice_id).first()
    if not invoice:
        raise HTTPException(404, "Invoice not found")
    invoice.amount_paid = (invoice.amount_paid or 0) + amount
    invoice.balance_due = invoice.total_amount - invoice.amount_paid
    if invoice.balance_due <= 0:
        invoice.status = InvoiceStatus.PAID
        invoice.balance_due = 0
    else:
        invoice.status = InvoiceStatus.PARTIALLY_PAID
    db.commit()
    return {"message": "Payment recorded", "amount_paid": invoice.amount_paid, "balance_due": invoice.balance_due}

@router.get("/invoices/{invoice_id}/pdf")
def download_invoice_pdf(invoice_id: int, db: Session = Depends(get_db)):
    invoice = db.query(Invoice).filter(Invoice.id == invoice_id).first()
    if not invoice:
        raise HTTPException(404, "Invoice not found")
    items = db.query(InvoiceItem).filter(InvoiceItem.invoice_id == invoice_id).all()
    items_data = [{
        "description": i.description, "quantity": i.quantity,
        "unit": i.unit, "unit_price": i.unit_price,
        "tax_rate": i.tax_rate, "tax_amount": i.tax_amount,
        "total_price": i.total_price
    } for i in items]
    invoice_data = {
        "invoice_no": invoice.invoice_no,
        "invoice_date": str(invoice.invoice_date),
        "due_date": str(invoice.due_date) if invoice.due_date else "",
        "client_name": invoice.client.name if invoice.client else "",
        "client_address": invoice.client.address if invoice.client and invoice.client.address else "",
        "client_ntn": invoice.client.ntn if invoice.client else "",
        "items": items_data,
        "subtotal": invoice.subtotal, "discount_amount": invoice.discount_amount,
        "tax_amount": invoice.tax_amount, "wht_amount": invoice.withholding_tax_amount,
        "fed_amount": invoice.fed_amount, "total_amount": invoice.total_amount,
        "terms": invoice.terms_conditions or "", "notes": invoice.notes or ""
    }
    pdf_buf = generate_invoice_pdf(invoice_data)
    return StreamingResponse(pdf_buf, media_type="application/pdf",
                            headers={"Content-Disposition": f"attachment; filename=invoice_{invoice.invoice_no}.pdf"})

class SendInvoiceRequest(BaseModel):
    email: Optional[str] = None
    whatsapp: Optional[str] = None
    message: Optional[str] = None

@router.post("/invoices/{invoice_id}/send")
async def send_invoice(invoice_id: int, send_req: SendInvoiceRequest, db: Session = Depends(get_db)):
    invoice = db.query(Invoice).filter(Invoice.id == invoice_id).first()
    if not invoice:
        raise HTTPException(404, "Invoice not found")
    items = db.query(InvoiceItem).filter(InvoiceItem.invoice_id == invoice_id).all()
    items_data = [{
        "description": i.description, "quantity": i.quantity,
        "unit": i.unit, "unit_price": i.unit_price,
        "tax_rate": i.tax_rate, "tax_amount": i.tax_amount,
        "total_price": i.total_price
    } for i in items]
    invoice_data = {
        "invoice_no": invoice.invoice_no,
        "invoice_date": str(invoice.invoice_date),
        "due_date": str(invoice.due_date) if invoice.due_date else "",
        "client_name": invoice.client.name if invoice.client else "",
        "client_address": invoice.client.address if invoice.client else "",
        "client_ntn": invoice.client.ntn if invoice.client else "",
        "items": items_data,
        "subtotal": invoice.subtotal, "discount_amount": invoice.discount_amount,
        "tax_amount": invoice.tax_amount, "wht_amount": invoice.withholding_tax_amount,
        "fed_amount": invoice.fed_amount, "total_amount": invoice.total_amount,
        "terms": invoice.terms_conditions or "", "notes": invoice.notes or ""
    }
    pdf_buf = generate_invoice_pdf(invoice_data)
    results = {}
    if send_req.email:
        subject = f"Invoice {invoice.invoice_no} from DATAPOINT Technologies"
        body = send_req.message or f"Dear {invoice.client.name if invoice.client else 'Client'},\n\nPlease find attached invoice #{invoice.invoice_no} for {invoice.total_amount:,.2f} PKR.\n\nThank you for your business!\n\nDATAPOINT Technologies"
        email_ok = await send_email_pdf(send_req.email, subject, body, pdf_buf.getvalue(), f"invoice_{invoice.invoice_no}.pdf")
        results["email_sent"] = email_ok
    if send_req.whatsapp:
        msg = send_req.message or f"Dear {invoice.client.name if invoice.client else 'Client'}, your invoice #{invoice.invoice_no} for PKR {invoice.total_amount:,.2f} is ready. Please check your email for PDF. - DATAPOINT Technologies"
        wa_ok = await send_whatsapp_message(send_req.whatsapp, msg)
        results["whatsapp_sent"] = wa_ok
    if results.get("email_sent") or results.get("whatsapp_sent"):
        invoice.status = InvoiceStatus.SENT
        db.commit()
    return results

# --- Agent Routes ---
class AgentQuery(BaseModel):
    message: str

@router.post("/agent/chat")
def agent_chat(query: AgentQuery):
    response = support_agent.get_response(query.message)
    return {"response": response, "agent": "DATAPOINT Support"}

class AudioInput(BaseModel):
    text: str

@router.post("/agent/voice-quotation")
def voice_quotation(audio: AudioInput):
    result = voice_agent.process_voice_input(audio.text)
    return result

@router.get("/agent/help")
def agent_help():
    return {
        "agent_name": "DATAPOINT Support Agent",
        "capabilities": [
            "Answer billing and invoice queries",
            "Product information and pricing",
            "Payment methods and tax information",
            "Delivery and return policies",
            "Company contact information",
            "Voice-based quotation generation"
        ],
        "commands": {
            "/chat <message>": "Ask the support agent",
            "/voice <text>": "Use voice quotation agent",
            "/help": "Show this help"
        }
    }
