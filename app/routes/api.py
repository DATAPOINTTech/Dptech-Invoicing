from fastapi import APIRouter, Depends, HTTPException, status, Query
from sqlalchemy import func
from sqlalchemy.orm import Session
from typing import List, Optional
from datetime import date, datetime
from app.database import get_db
from app.models.user import User, UserRole, DEFAULT_PERMISSIONS
from app.models.client import Client
from app.models.supplier import Supplier
from app.models.product import Product, ProductCategory
from app.models.purchase import PurchaseInvoice, PurchaseItem, PurchasePayment, PurchaseStatus
from app.models.inventory import Inventory, StockMovement, MovementType
from app.models.expense import Expense, ExpenseCategory
from app.models.estimate import Estimate, EstimateItem, EstimateStatus
from app.models.invoice import Invoice, InvoiceItem, InvoiceStatus
from app.models.project import Project, ProjectStatus
from app.models.pricelist import PriceList
from app.services.auth import get_current_user, get_password_hash, authenticate_user, create_access_token, require_role, require_permission
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
    if not user.is_active:
        raise HTTPException(status_code=401, detail="Account is deactivated")
    token = create_access_token({"sub": user.username, "role": user.role.value})
    return {"access_token": token, "token_type": "bearer", "user": {"id": user.id, "name": user.full_name, "role": user.role.value}}

# --- User Management Routes (Admin Only) ---
@router.get("/users")
def list_users(db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN]))):
    users = db.query(User).order_by(User.created_at.desc()).all()
    return [{
        "id": u.id, "username": u.username, "email": u.email,
        "full_name": u.full_name, "phone": u.phone,
        "role": u.role.value, "is_active": u.is_active,
        "created_at": u.created_at
    } for u in users]

@router.get("/users/{user_id}")
def get_user(user_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN]))):
    user = db.query(User).filter(User.id == user_id).first()
    if not user:
        raise HTTPException(404, "User not found")
    return {
        "id": user.id, "username": user.username, "email": user.email,
        "full_name": user.full_name, "phone": user.phone,
        "role": user.role.value, "is_active": user.is_active,
        "created_at": user.created_at
    }

@router.put("/users/{user_id}")
def update_user(user_id: int, user: UserCreate, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN]))):
    db_user = db.query(User).filter(User.id == user_id).first()
    if not db_user:
        raise HTTPException(404, "User not found")
    db_user.username = user.username
    db_user.email = user.email
    db_user.full_name = user.full_name
    db_user.phone = user.phone
    db_user.role = UserRole(user.role) if user.role in [e.value for e in UserRole] else UserRole.STAFF
    if user.password:
        db_user.hashed_password = get_password_hash(user.password)
    db.commit()
    db.refresh(db_user)
    return {"message": "User updated"}

class UserStatusUpdate(BaseModel):
    is_active: bool

class UserPermissionsUpdate(BaseModel):
    permissions: dict

@router.get("/users/{user_id}/permissions")
def get_user_permissions(user_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN]))):
    user = db.query(User).filter(User.id == user_id).first()
    if not user:
        raise HTTPException(404, "User not found")
    return {"user_id": user_id, "role": user.role.value, "permissions": user.get_permissions()}

@router.put("/users/{user_id}/permissions")
def update_user_permissions(user_id: int, body: UserPermissionsUpdate, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN]))):
    user = db.query(User).filter(User.id == user_id).first()
    if not user:
        raise HTTPException(404, "User not found")
    if user.role == UserRole.ADMIN:
        raise HTTPException(400, "Cannot override permissions for admin users")
    valid_actions = {"view", "create", "edit", "delete"}
    merged = {mod: dict(DEFAULT_PERMISSIONS[mod]) for mod in DEFAULT_PERMISSIONS}
    for module, actions in body.permissions.items():
        if module not in merged:
            raise HTTPException(400, f"Unknown module: {module}")
        for action, val in actions.items():
            if action not in valid_actions:
                raise HTTPException(400, f"Unknown action: {action}")
            merged[module][action] = bool(val)
    user.permissions = merged
    db.commit()
    return {"message": "Permissions updated", "permissions": merged}

@router.put("/users/{user_id}/status")
def toggle_user_status(user_id: int, status: UserStatusUpdate, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN]))):
    if user_id == current_user.id:
        raise HTTPException(400, "Cannot deactivate yourself")
    db_user = db.query(User).filter(User.id == user_id).first()
    if not db_user:
        raise HTTPException(404, "User not found")
    db_user.is_active = status.is_active
    db.commit()
    return {"message": "User status updated"}

# --- Dashboard ---
@router.get("/dashboard")
def get_dashboard(db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    total_clients = db.query(Client).count()
    active_projects_count = db.query(Project).filter(Project.status.in_([ProjectStatus.PLANNING, ProjectStatus.IN_PROGRESS, ProjectStatus.ON_HOLD])).count()
    pending_invoices = db.query(Invoice).filter(Invoice.status.in_([InvoiceStatus.DRAFT, InvoiceStatus.SENT])).count()
    total_receivables = db.query(Invoice).filter(Invoice.status.in_([InvoiceStatus.SENT, InvoiceStatus.PARTIALLY_PAID])).with_entities(Invoice.balance_due).all()
    total_due = sum(r[0] for r in total_receivables if r[0]) if total_receivables else 0
    low_stock = get_low_stock_products(db)
    recent_invoices = db.query(Invoice).order_by(Invoice.created_at.desc()).limit(5).all()
    recent_estimates = db.query(Estimate).order_by(Estimate.created_at.desc()).limit(5).all()

    now = datetime.now()
    month_start = date(now.year, now.month, 1)
    if now.month == 1:
        last_month_start = date(now.year - 1, 12, 1)
        last_month_end = date(now.year, 1, 1)
    else:
        last_month_start = date(now.year, now.month - 1, 1)
        last_month_end = month_start

    month_expenses = db.query(func.coalesce(func.sum(Expense.total_amount), 0)).filter(Expense.expense_date >= month_start).scalar()
    last_month_expenses = db.query(func.coalesce(func.sum(Expense.total_amount), 0)).filter(Expense.expense_date >= last_month_start, Expense.expense_date < last_month_end).scalar()
    total_expenses = db.query(func.coalesce(func.sum(Expense.total_amount), 0)).scalar()
    expense_count = db.query(Expense).count()

    expense_categories = db.query(Expense.category, func.coalesce(func.sum(Expense.total_amount), 0)).group_by(Expense.category).all()
    total_cat = sum(c[1] for c in expense_categories) or 1
    expenses_by_category = [{"category": cat.value if hasattr(cat, 'value') else cat, "amount": round(amt, 2), "percentage": round(amt / total_cat * 100, 1)} for cat, amt in expense_categories]

    recent_expenses = db.query(Expense).order_by(Expense.expense_date.desc()).limit(5).all()

    return {
        "total_clients": total_clients,
        "recent_clients": [{"id": c.id, "name": c.name, "company": c.company, "phone": c.phone, "email": c.email} for c in db.query(Client).filter(Client.is_active == True).order_by(Client.created_at.desc()).limit(5).all()],
        "active_projects": active_projects_count,
        "pending_invoices": pending_invoices,
        "total_receivables": round(total_due, 2),
        "low_stock_count": len(low_stock),
        "low_stock_items": [{"id": p.id, "name": p.name} for p in low_stock],
        "recent_invoices": [{"id": i.id, "no": i.invoice_no, "client": i.client.name if i.client else "", "status": i.status.value, "total": i.total_amount} for i in recent_invoices],
        "recent_estimates": [{"id": e.id, "no": e.estimate_no, "client": e.client.name if e.client else "", "status": e.status.value, "total": e.total_amount} for e in recent_estimates],
        "total_expenses": round(total_expenses, 2),
        "expense_count": expense_count,
        "month_expenses": round(month_expenses, 2),
        "last_month_expenses": round(last_month_expenses, 2),
        "expenses_by_category": expenses_by_category,
        "recent_expenses": [{"id": e.id, "no": e.expense_no, "description": e.description, "category": e.category.value if e.category else "other", "amount": e.total_amount, "date": str(e.expense_date)} for e in recent_expenses]
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
def create_client(client: ClientCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("clients", "create"))):
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
def update_client(client_id: int, client: ClientCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("clients", "edit"))):
    db_client = db.query(Client).filter(Client.id == client_id).first()
    if not db_client:
        raise HTTPException(404, "Client not found")
    for key, value in client.model_dump(exclude_unset=True).items():
        setattr(db_client, key, value)
    db.commit()
    db.refresh(db_client)
    return db_client

@router.delete("/clients/{client_id}")
def delete_client(client_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("clients", "delete"))):
    db_client = db.query(Client).filter(Client.id == client_id).first()
    if not db_client:
        raise HTTPException(404, "Client not found")
    db_client.is_active = False
    db.commit()
    return {"message": "Client deactivated"}

# --- Supplier Routes ---
class SupplierCreate(BaseModel):
    name: str
    contact_person: Optional[str] = None
    email: Optional[str] = None
    phone: Optional[str] = None
    mobile: Optional[str] = None
    address: Optional[str] = None
    city: Optional[str] = None
    ntn: Optional[str] = None
    strn: Optional[str] = None

@router.get("/suppliers")
def list_suppliers(search: Optional[str] = None, db: Session = Depends(get_db)):
    query = db.query(Supplier).filter(Supplier.is_active == True)
    if search:
        query = query.filter(Supplier.name.ilike(f"%{search}%"))
    return query.order_by(Supplier.name).all()

@router.post("/suppliers")
def create_supplier(supplier: SupplierCreate, db: Session = Depends(get_db)):
    db_supplier = Supplier(**supplier.model_dump())
    db.add(db_supplier)
    db.commit()
    db.refresh(db_supplier)
    return db_supplier

def _resolve_or_create_supplier(db, supplier_name: str, supplier_ntn: str = None, supplier_address: str = None) -> int:
    existing = db.query(Supplier).filter(
        Supplier.name.ilike(supplier_name.strip())
    ).first()
    if existing:
        return existing.id
    new_supplier = Supplier(
        name=supplier_name.strip(),
        ntn=supplier_ntn,
        address=supplier_address,
        is_active=True
    )
    db.add(new_supplier)
    db.flush()
    return new_supplier.id

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
    max_stock_level: float = 0

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
            "max_stock_level": p.max_stock_level,
            "stock_qty": inv.quantity if inv else 0
        })
    return result

@router.post("/products")
def create_product(product: ProductCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "create"))):
    db_product = Product(**product.model_dump())
    db.add(db_product)
    db.flush()
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
        "max_stock_level": product.max_stock_level,
        "stock_qty": inv.quantity if inv else 0
    }

@router.put("/products/{product_id}")
def update_product(product_id: int, product: ProductCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "edit"))):
    db_product = db.query(Product).filter(Product.id == product_id).first()
    if not db_product:
        raise HTTPException(404, "Product not found")
    for key, value in product.model_dump(exclude_unset=True).items():
        setattr(db_product, key, value)
    db.commit()
    db.refresh(db_product)
    return db_product

@router.delete("/products/{product_id}")
def delete_product(product_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "delete"))):
    db_product = db.query(Product).filter(Product.id == product_id).first()
    if not db_product:
        raise HTTPException(404, "Product not found")
    db_product.is_active = False
    db.commit()
    return {"message": "Product deactivated"}

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
    purchases = db.query(PurchaseInvoice).order_by(PurchaseInvoice.created_at.desc()).all()
    result = []
    for p in purchases:
        items = db.query(PurchaseItem).filter(PurchaseItem.purchase_id == p.id).all()
        result.append({
            "id": p.id, "invoice_no": p.invoice_no,
            "supplier_name": p.supplier_name,
            "supplier_ntn": p.supplier_ntn,
            "invoice_date": p.invoice_date,
            "received_date": p.received_date,
            "status": p.status.value,
            "subtotal": p.subtotal,
            "tax_amount": p.tax_amount,
            "total_amount": p.total_amount,
            "amount_paid": p.amount_paid,
            "balance_due": p.balance_due,
            "notes": p.notes,
            "created_at": p.created_at,
            "items": [{
                "product_name": i.product_name,
                "quantity": i.quantity,
                "unit": i.unit,
                "unit_price": i.unit_price,
                "total_price": i.total_price
            } for i in items]
        })
    return result

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
def create_purchase(purchase: PurchaseCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("purchases", "create"))):
    inv_no = generate_purchase_number(db)
    subtotal = sum(item.unit_price * item.quantity for item in purchase.items)
    tax_amount = sum(item.unit_price * item.quantity * (item.tax_rate / 100) for item in purchase.items)
    total = subtotal + tax_amount

    supplier_id = _resolve_or_create_supplier(db, purchase.supplier_name, purchase.supplier_ntn, purchase.supplier_address)

    total_rounded = round(total, 2)
    db_purchase = PurchaseInvoice(
        invoice_no=inv_no,
        supplier_id=supplier_id,
        supplier_name=purchase.supplier_name,
        supplier_ntn=purchase.supplier_ntn,
        supplier_address=purchase.supplier_address,
        invoice_date=purchase.invoice_date,
        received_date=purchase.received_date,
        subtotal=round(subtotal, 2),
        tax_amount=round(tax_amount, 2),
        total_amount=total_rounded,
        amount_paid=0,
        balance_due=total_rounded,
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

        supplier_id = _resolve_or_create_supplier(db, purchase.supplier_name, purchase.supplier_ntn, purchase.supplier_address)

        total_rounded = round(total, 2)
        db_purchase = PurchaseInvoice(
            invoice_no=inv_no,
            supplier_id=supplier_id,
            supplier_name=purchase.supplier_name,
            supplier_ntn=purchase.supplier_ntn,
            supplier_address=purchase.supplier_address,
            invoice_date=purchase.invoice_date,
            received_date=purchase.received_date,
            subtotal=round(subtotal, 2),
            tax_amount=round(tax_amount, 2),
            total_amount=total_rounded,
            amount_paid=0,
            balance_due=total_rounded,
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

@router.get("/purchases/{purchase_id}")
def get_purchase(purchase_id: int, db: Session = Depends(get_db)):
    purchase = db.query(PurchaseInvoice).filter(PurchaseInvoice.id == purchase_id).first()
    if not purchase:
        raise HTTPException(404, "Purchase not found")
    items = db.query(PurchaseItem).filter(PurchaseItem.purchase_id == purchase_id).all()
    return {
        "id": purchase.id, "invoice_no": purchase.invoice_no,
        "supplier_name": purchase.supplier_name,
        "supplier_ntn": purchase.supplier_ntn,
        "supplier_address": purchase.supplier_address,
        "invoice_date": purchase.invoice_date,
        "received_date": purchase.received_date,
        "status": purchase.status.value,
        "subtotal": purchase.subtotal,
        "tax_amount": purchase.tax_amount,
        "tax_rate": purchase.tax_rate,
        "total_amount": purchase.total_amount,
        "amount_paid": purchase.amount_paid,
        "balance_due": purchase.balance_due,
        "notes": purchase.notes,
        "items": [{
            "id": i.id, "product_id": i.product_id,
            "product_name": i.product_name,
            "description": i.description,
            "quantity": i.quantity,
            "unit": i.unit,
            "unit_price": i.unit_price,
            "tax_rate": i.tax_rate,
            "tax_amount": i.tax_amount,
            "total_price": i.total_price
        } for i in items]
    }

@router.post("/purchases/{purchase_id}/pay")
def record_purchase_payment(purchase_id: int, amount: float = Query(...), payment_date: date = Query(default=None), payment_method: str = Query(default="cash"), reference_no: str = Query(default=None), notes: str = Query(default=None), db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    purchase = db.query(PurchaseInvoice).filter(PurchaseInvoice.id == purchase_id).first()
    if not purchase:
        raise HTTPException(404, "Purchase not found")
    if purchase.status == PurchaseStatus.CANCELLED:
        raise HTTPException(400, "Cannot pay a cancelled purchase")
    if amount <= 0:
        raise HTTPException(400, "Payment amount must be positive")
    pay_date = payment_date or date.today()
    payment = PurchasePayment(
        purchase_id=purchase_id, amount=amount,
        payment_date=pay_date, payment_method=payment_method,
        reference_no=reference_no, notes=notes,
        created_by=current_user.id
    )
    db.add(payment)
    purchase.amount_paid = (purchase.amount_paid or 0) + amount
    purchase.balance_due = max(0, purchase.total_amount - purchase.amount_paid)
    if purchase.balance_due <= 0:
        purchase.status = PurchaseStatus.PAID
        purchase.balance_due = 0
    else:
        purchase.status = PurchaseStatus.PARTIALLY_PAID
    db.commit()
    db.refresh(purchase)
    return {"message": "Payment recorded", "amount_paid": purchase.amount_paid, "balance_due": purchase.balance_due}

@router.get("/purchases/{purchase_id}/payments")
def list_purchase_payments(purchase_id: int, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    purchase = db.query(PurchaseInvoice).filter(PurchaseInvoice.id == purchase_id).first()
    if not purchase:
        raise HTTPException(404, "Purchase not found")
    payments = db.query(PurchasePayment).filter(PurchasePayment.purchase_id == purchase_id).order_by(PurchasePayment.payment_date.desc()).all()
    return [{
        "id": pm.id, "amount": pm.amount,
        "payment_date": pm.payment_date,
        "payment_method": pm.payment_method,
        "reference_no": pm.reference_no,
        "notes": pm.notes,
        "created_at": pm.created_at
    } for pm in payments]

@router.post("/purchases/{purchase_id}/cancel")
def cancel_purchase(purchase_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN, UserRole.MANAGER]))):
    db_purchase = db.query(PurchaseInvoice).filter(PurchaseInvoice.id == purchase_id).first()
    if not db_purchase:
        raise HTTPException(404, "Purchase not found")
    if db_purchase.status == PurchaseStatus.CANCELLED:
        raise HTTPException(400, "Purchase is already cancelled")
    for old_item in db_purchase.items:
        if old_item.product_id:
            inv = get_or_create_inventory(db, old_item.product_id)
            inv.quantity -= old_item.quantity
            movement = StockMovement(
                product_id=old_item.product_id, quantity=-old_item.quantity,
                movement_type=MovementType.ADJUSTMENT,
                reference_type="purchase_cancel", reference_id=db_purchase.id,
                notes=f"Reversal for cancelled purchase {db_purchase.invoice_no}",
                created_by=current_user.id
            )
            db.add(movement)
    db_purchase.status = PurchaseStatus.CANCELLED
    db.commit()
    return {"message": "Purchase cancelled"}

@router.delete("/purchases/{purchase_id}")
def delete_purchase(purchase_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN, UserRole.MANAGER]))):
    db_purchase = db.query(PurchaseInvoice).filter(PurchaseInvoice.id == purchase_id).first()
    if not db_purchase:
        raise HTTPException(404, "Purchase not found")
    if db_purchase.status != PurchaseStatus.CANCELLED:
        raise HTTPException(400, "Only cancelled purchases can be deleted")
    db.query(PurchaseItem).filter(PurchaseItem.purchase_id == purchase_id).delete()
    db.delete(db_purchase)
    db.commit()
    return {"message": "Purchase deleted"}

@router.put("/purchases/{purchase_id}")
def update_purchase(purchase_id: int, purchase: PurchaseCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("purchases", "edit"))):
    db_purchase = db.query(PurchaseInvoice).filter(PurchaseInvoice.id == purchase_id).first()
    if not db_purchase:
        raise HTTPException(404, "Purchase not found")

    for old_item in db_purchase.items:
        if old_item.product_id:
            inv = get_or_create_inventory(db, old_item.product_id)
            inv.quantity -= old_item.quantity
            movement = StockMovement(
                product_id=old_item.product_id,
                quantity=-old_item.quantity,
                movement_type=MovementType.ADJUSTMENT,
                reference_type="purchase_edit",
                reference_id=db_purchase.id,
                notes=f"Reversal for edited purchase {db_purchase.invoice_no}",
                created_by=current_user.id
            )
            db.add(movement)

    db.query(PurchaseItem).filter(PurchaseItem.purchase_id == purchase_id).delete()

    supplier_id = _resolve_or_create_supplier(db, purchase.supplier_name, purchase.supplier_ntn, purchase.supplier_address)
    db_purchase.supplier_id = supplier_id
    db_purchase.supplier_name = purchase.supplier_name
    db_purchase.supplier_ntn = purchase.supplier_ntn
    db_purchase.supplier_address = purchase.supplier_address
    db_purchase.invoice_date = purchase.invoice_date
    db_purchase.received_date = purchase.received_date
    db_purchase.notes = purchase.notes

    subtotal = sum(item.unit_price * item.quantity for item in purchase.items)
    tax_amount = sum(item.unit_price * item.quantity * (item.tax_rate / 100) for item in purchase.items)
    total = subtotal + tax_amount
    db_purchase.subtotal = round(subtotal, 2)
    db_purchase.tax_amount = round(tax_amount, 2)
    db_purchase.total_amount = round(total, 2)
    db_purchase.balance_due = max(0, round(total, 2) - (db_purchase.amount_paid or 0))

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

        update_stock(db, product_id, item.quantity, MovementType.PURCHASE_IN,
                    "purchase", db_purchase.id, f"Purchase {db_purchase.invoice_no}", current_user.id)

    db.commit()
    db.refresh(db_purchase)
    return db_purchase

# --- Inventory Routes ---
@router.get("/inventory")
def list_inventory(low_stock: bool = False, show_all: bool = False, db: Session = Depends(get_db)):
    query = db.query(Inventory, Product).join(Product)
    if not show_all:
        query = query.filter(Inventory.quantity > 0)
    if low_stock:
        query = query.filter(Inventory.quantity <= Product.min_stock_level)
    results = []
    for inv, prod in query.all():
        results.append({
            "product_id": prod.id, "product_name": prod.name, "sku": prod.sku,
            "category": prod.category.value if prod.category else None,
            "unit": prod.unit, "quantity": inv.quantity,
            "unit_price": prod.unit_price, "min_stock": prod.min_stock_level,
            "max_stock": prod.max_stock_level,
            "low_stock": inv.quantity <= prod.min_stock_level if prod.min_stock_level else False,
            "warehouse": inv.warehouse
        })
    return results

@router.get("/inventory/movements")
def list_movements(product_id: Optional[int] = None, limit: int = 100, db: Session = Depends(get_db)):
    query = db.query(StockMovement).order_by(StockMovement.created_at.desc())
    if product_id:
        query = query.filter(StockMovement.product_id == product_id)
    movements = query.limit(limit).all()
    result = []
    for m in movements:
        p = db.query(Product).filter(Product.id == m.product_id).first()
        result.append({
            "id": m.id, "product_id": m.product_id,
            "product_name": p.name if p else "Deleted",
            "quantity": m.quantity,
            "movement_type": m.movement_type.value if hasattr(m.movement_type, 'value') else m.movement_type,
            "reference_type": m.reference_type,
            "reference_id": m.reference_id,
            "notes": m.notes,
            "created_by": m.created_by,
            "created_at": m.created_at
        })
    return result

class StockMovementUpdate(BaseModel):
    quantity: Optional[float] = None
    movement_type: Optional[str] = None
    notes: Optional[str] = None

@router.put("/inventory/movements/{movement_id}")
def update_movement(movement_id: int, update: StockMovementUpdate, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN, UserRole.MANAGER]))):
    movement = db.query(StockMovement).filter(StockMovement.id == movement_id).first()
    if not movement:
        raise HTTPException(404, "Movement not found")

    old_qty = movement.quantity
    old_type = movement.movement_type

    if update.quantity is not None:
        movement.quantity = update.quantity
    if update.movement_type is not None:
        movement.movement_type = MovementType(update.movement_type)
    if update.notes is not None:
        movement.notes = update.notes

    inv = get_or_create_inventory(db, movement.product_id)
    old_effect = old_qty if old_type in (MovementType.PURCHASE_IN, MovementType.ADJUSTMENT, MovementType.RETURN_IN) else -abs(old_qty)
    new_qty = movement.quantity
    new_type = movement.movement_type
    new_effect = new_qty if new_type in (MovementType.PURCHASE_IN, MovementType.ADJUSTMENT, MovementType.RETURN_IN) else -abs(new_qty)
    delta = new_effect - old_effect
    inv.quantity += delta

    db.commit()
    db.refresh(movement)
    return {"message": "Movement updated", "id": movement.id}

@router.delete("/inventory/movements/{movement_id}")
def delete_movement(movement_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN, UserRole.MANAGER]))):
    movement = db.query(StockMovement).filter(StockMovement.id == movement_id).first()
    if not movement:
        raise HTTPException(404, "Movement not found")

    inv = get_or_create_inventory(db, movement.product_id)
    if movement.movement_type in (MovementType.SALE_OUT, MovementType.RETURN_OUT, MovementType.TRANSFER):
        inv.quantity += abs(movement.quantity)
    else:
        inv.quantity -= abs(movement.quantity)

    db.delete(movement)
    db.commit()
    return {"message": "Movement deleted"}

class StockIssueRequest(BaseModel):
    product_id: int
    quantity: float
    notes: Optional[str] = None

@router.post("/inventory/issue")
def issue_stock(issue: StockIssueRequest, db: Session = Depends(get_db), current_user: User = Depends(require_role([UserRole.ADMIN, UserRole.MANAGER]))):
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
def create_expense(expense: ExpenseCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("expenses", "create"))):
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

@router.get("/expenses/{expense_id}")
def get_expense(expense_id: int, db: Session = Depends(get_db)):
    expense = db.query(Expense).filter(Expense.id == expense_id).first()
    if not expense:
        raise HTTPException(404, "Expense not found")
    return expense

@router.put("/expenses/{expense_id}")
def update_expense(expense_id: int, expense: ExpenseCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("expenses", "edit"))):
    db_expense = db.query(Expense).filter(Expense.id == expense_id).first()
    if not db_expense:
        raise HTTPException(404, "Expense not found")
    for key, value in expense.model_dump(exclude_unset=True).items():
        setattr(db_expense, key, value)
    db_expense.total_amount = round(db_expense.amount + db_expense.tax_amount, 2)
    if expense.category:
        db_expense.category = ExpenseCategory(expense.category) if expense.category in [e.value for e in ExpenseCategory] else ExpenseCategory.OTHER
    db.commit()
    db.refresh(db_expense)
    return db_expense

@router.delete("/expenses/{expense_id}")
def delete_expense(expense_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("expenses", "delete"))):
    db_expense = db.query(Expense).filter(Expense.id == expense_id).first()
    if not db_expense:
        raise HTTPException(404, "Expense not found")
    db.delete(db_expense)
    db.commit()
    return {"message": "Expense deleted"}

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

@router.get("/projects/{project_id}")
def get_project(project_id: int, db: Session = Depends(get_db)):
    project = db.query(Project).filter(Project.id == project_id).first()
    if not project:
        raise HTTPException(404, "Project not found")
    return project

@router.put("/projects/{project_id}")
def update_project(project_id: int, project: ProjectCreate, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_project = db.query(Project).filter(Project.id == project_id).first()
    if not db_project:
        raise HTTPException(404, "Project not found")
    for key, value in project.model_dump(exclude_unset=True).items():
        setattr(db_project, key, value)
    db.commit()
    db.refresh(db_project)
    return db_project

@router.delete("/projects/{project_id}")
def delete_project(project_id: int, db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    db_project = db.query(Project).filter(Project.id == project_id).first()
    if not db_project:
        raise HTTPException(404, "Project not found")
    db_project.status = ProjectStatus.CANCELLED
    db.commit()
    return {"message": "Project cancelled"}

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
    tax_rate: float = 18.0
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

@router.put("/estimates/{estimate_id}")
def update_estimate(estimate_id: int, estimate: EstimateCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("estimates", "edit"))):
    db_estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not db_estimate:
        raise HTTPException(404, "Estimate not found")
    if db_estimate.status in [EstimateStatus.APPROVED, EstimateStatus.CONVERTED, EstimateStatus.REJECTED]:
        raise HTTPException(400, f"Cannot edit estimate with status {db_estimate.status.value}")

    db.query(EstimateItem).filter(EstimateItem.estimate_id == estimate_id).delete()

    tr = estimate.tax_rate
    calculated_items = [calculate_item_tax(it.unit_price, it.quantity, tr, tax_inclusive=False) for it in estimate.items]
    totals = calculate_invoice_tax(calculated_items, estimate.discount_percent, tax_rate=tr)

    db_estimate.client_id = estimate.client_id
    db_estimate.project_id = estimate.project_id
    db_estimate.title = estimate.title
    db_estimate.estimate_date = estimate.estimate_date
    db_estimate.valid_until = estimate.valid_until
    db_estimate.subtotal = totals["subtotal"]
    db_estimate.discount_percent = estimate.discount_percent
    db_estimate.discount_amount = totals["discount_amount"]
    db_estimate.tax_rate = totals["tax_rate"]
    db_estimate.tax_amount = totals["tax_amount"]
    db_estimate.total_amount = totals["total_amount"]
    db_estimate.terms_conditions = estimate.terms_conditions
    db_estimate.notes = estimate.notes

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

@router.post("/estimates/{estimate_id}/reject")
def reject_estimate(estimate_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("estimates", "edit"))):
    estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not estimate:
        raise HTTPException(404, "Estimate not found")
    if estimate.status in [EstimateStatus.CONVERTED]:
        raise HTTPException(400, "Cannot reject a converted estimate")
    estimate.status = EstimateStatus.REJECTED
    db.commit()
    return {"message": "Estimate rejected"}

@router.delete("/estimates/{estimate_id}")
def delete_estimate(estimate_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("estimates", "delete"))):
    db_estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not db_estimate:
        raise HTTPException(404, "Estimate not found")
    if db_estimate.status != EstimateStatus.REJECTED:
        raise HTTPException(400, "Only rejected estimates can be deleted")
    db.delete(db_estimate)
    db.commit()
    return {"message": "Estimate deleted"}

@router.post("/estimates")
def create_estimate(estimate: EstimateCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("estimates", "create"))):
    est_no = generate_estimate_number(db)
    tr = estimate.tax_rate
    calculated_items = [calculate_item_tax(it.unit_price, it.quantity, tr, tax_inclusive=False) for it in estimate.items]
    totals = calculate_invoice_tax(calculated_items, estimate.discount_percent, tax_rate=tr)

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
def approve_estimate(estimate_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("estimates", "edit"))):
    estimate = db.query(Estimate).filter(Estimate.id == estimate_id).first()
    if not estimate:
        raise HTTPException(404, "Estimate not found")
    if estimate.status != EstimateStatus.SENT:
        raise HTTPException(400, f"Cannot approve estimate with status {estimate.status.value}")
    estimate.status = EstimateStatus.APPROVED
    db.commit()
    return {"message": "Estimate approved", "estimate_no": estimate.estimate_no}

@router.post("/estimates/{estimate_id}/convert")
def convert_estimate_to_invoice(estimate_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("invoices", "create"))):
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
        if item.product_id:
            try:
                update_stock(db, item.product_id, item.quantity, MovementType.SALE_OUT,
                            "invoice", db_invoice.id, f"Invoice {inv_no}", current_user.id)
            except ValueError as e:
                db.rollback()
                raise HTTPException(400, f"Insufficient stock for '{item.description}': {e}")

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
        "client_address": estimate.client.address if estimate.client and estimate.client.address else "",
        "client_ntn": estimate.client.ntn if estimate.client else "",
        "items": items_data,
        "subtotal": estimate.subtotal, "discount_amount": estimate.discount_amount,
        "tax_amount": estimate.tax_amount, "tax_rate": estimate.tax_rate,
        "total_amount": estimate.total_amount,
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
    tax_rate: float = 18.0
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
def create_invoice(invoice: InvoiceCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("invoices", "create"))):
    inv_no = generate_invoice_number(db)
    tr = invoice.tax_rate
    calculated_items = [calculate_item_tax(it.unit_price, it.quantity, tr) for it in invoice.items]
    totals = calculate_invoice_tax(calculated_items, invoice.discount_percent, tax_rate=tr,
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

    for item in invoice.items:
        if item.product_id:
            try:
                update_stock(db, item.product_id, item.quantity, MovementType.SALE_OUT,
                            "invoice", db_invoice.id, f"Invoice {inv_no}", current_user.id)
            except ValueError as e:
                db.rollback()
                raise HTTPException(400, f"Insufficient stock for '{item.description}': {e}")

    db.commit()
    db.refresh(db_invoice)
    return db_invoice

@router.put("/invoices/{invoice_id}")
def update_invoice(invoice_id: int, invoice: InvoiceCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("invoices", "edit"))):
    db_invoice = db.query(Invoice).filter(Invoice.id == invoice_id).first()
    if not db_invoice:
        raise HTTPException(404, "Invoice not found")
    if db_invoice.status in [InvoiceStatus.PAID, InvoiceStatus.CANCELLED]:
        raise HTTPException(400, f"Cannot edit invoice with status {db_invoice.status.value}")

    for old_item in db_invoice.items:
        if old_item.product_id:
            inv = get_or_create_inventory(db, old_item.product_id)
            inv.quantity += old_item.quantity
            movement = StockMovement(
                product_id=old_item.product_id, quantity=old_item.quantity,
                movement_type=MovementType.ADJUSTMENT,
                reference_type="invoice_edit", reference_id=db_invoice.id,
                notes=f"Reversal for edited invoice {db_invoice.invoice_no}",
                created_by=current_user.id
            )
            db.add(movement)

    db.query(InvoiceItem).filter(InvoiceItem.invoice_id == invoice_id).delete()

    tr = invoice.tax_rate
    calculated_items = [calculate_item_tax(it.unit_price, it.quantity, tr) for it in invoice.items]
    totals = calculate_invoice_tax(calculated_items, invoice.discount_percent, tax_rate=tr,
                                  apply_wht=invoice.apply_wht, apply_fed=invoice.apply_fed)
    due = invoice.due_date or invoice.invoice_date

    db_invoice.client_id = invoice.client_id
    db_invoice.estimate_id = invoice.estimate_id
    db_invoice.project_id = invoice.project_id
    db_invoice.invoice_date = invoice.invoice_date
    db_invoice.due_date = due
    db_invoice.subtotal = totals["subtotal"]
    db_invoice.discount_percent = invoice.discount_percent
    db_invoice.discount_amount = totals["discount_amount"]
    db_invoice.tax_rate = totals["tax_rate"]
    db_invoice.tax_amount = totals["tax_amount"]
    db_invoice.withholding_tax_rate = totals["wht_rate"]
    db_invoice.withholding_tax_amount = totals["wht_amount"]
    db_invoice.fed_rate = totals["fed_rate"]
    db_invoice.fed_amount = totals["fed_amount"]
    db_invoice.total_amount = totals["total_amount"]
    db_invoice.balance_due = totals["total_amount"] - db_invoice.amount_paid
    db_invoice.payment_terms = invoice.payment_terms
    db_invoice.notes = invoice.notes
    db_invoice.terms_conditions = invoice.terms_conditions

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
        if item.product_id:
            try:
                update_stock(db, item.product_id, item.quantity, MovementType.SALE_OUT,
                            "invoice", db_invoice.id, f"Invoice {db_invoice.invoice_no}", current_user.id)
            except ValueError as e:
                db.rollback()
                raise HTTPException(400, f"Insufficient stock for '{item.description}': {e}")

    db.commit()
    db.refresh(db_invoice)
    return db_invoice

@router.delete("/invoices/{invoice_id}")
def delete_invoice(invoice_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("invoices", "delete"))):
    db_invoice = db.query(Invoice).filter(Invoice.id == invoice_id).first()
    if not db_invoice:
        raise HTTPException(404, "Invoice not found")
    for old_item in db_invoice.items:
        if old_item.product_id:
            inv = get_or_create_inventory(db, old_item.product_id)
            inv.quantity += old_item.quantity
            movement = StockMovement(
                product_id=old_item.product_id, quantity=old_item.quantity,
                movement_type=MovementType.ADJUSTMENT,
                reference_type="invoice_delete", reference_id=db_invoice.id,
                notes=f"Restock for deleted invoice {db_invoice.invoice_no}",
                created_by=current_user.id
            )
            db.add(movement)
    db_invoice.status = InvoiceStatus.CANCELLED
    db.commit()
    return {"message": "Invoice cancelled"}

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
        "tax_amount": invoice.tax_amount, "tax_rate": invoice.tax_rate,
        "wht_amount": invoice.withholding_tax_amount,
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
async def send_invoice(invoice_id: int, send_req: SendInvoiceRequest, db: Session = Depends(get_db), current_user: User = Depends(require_permission("invoices", "edit"))):
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
        "tax_amount": invoice.tax_amount, "tax_rate": invoice.tax_rate,
        "wht_amount": invoice.withholding_tax_amount,
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
        msg = send_req.message or f"Dear {invoice.client.name if invoice.client else 'Client'}, your invoice #{invoice.invoice_no} for PKR {invoice.total_amount:,.2f} is attached. - DATAPOINT Technologies"
        wa_res = await send_whatsapp_message(
            send_req.whatsapp, msg,
            pdf_bytes=pdf_buf.getvalue(),
            pdf_filename=f"invoice_{invoice.invoice_no}.pdf"
        )
        results["whatsapp_sent"] = wa_res["success"]
        if wa_res.get("error"):
            results["whatsapp_error"] = wa_res["error"]
    if results.get("email_sent") or results.get("whatsapp_sent"):
        invoice.status = InvoiceStatus.SENT
        db.commit()
    return results

# --- Price List Routes ---
import csv, io, requests as http_requests

class PriceItemCreate(BaseModel):
    name: str
    description: Optional[str] = None
    category: str = "general"
    unit: str = "pcs"
    unit_price: float
    currency: str = "PKR"
    effective_date: date
    source: Optional[str] = None
    image_url: Optional[str] = None

@router.get("/prices")
def list_prices(
    search: Optional[str] = None,
    category: Optional[str] = None,
    effective_date: Optional[date] = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(get_current_user)
):
    q = db.query(PriceList).filter(PriceList.is_active == True)
    if search:
        q = q.filter(PriceList.name.ilike(f"%{search}%"))
    if category:
        q = q.filter(PriceList.category.ilike(category))
    if effective_date:
        q = q.filter(PriceList.effective_date == effective_date)
    return q.order_by(PriceList.effective_date.desc(), PriceList.name).all()

@router.post("/prices")
def create_price(item: PriceItemCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "create"))):
    db_item = PriceList(**item.model_dump())
    db.add(db_item)
    db.commit()
    db.refresh(db_item)
    return db_item

@router.put("/prices/{price_id}")
def update_price(price_id: int, item: PriceItemCreate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "edit"))):
    db_item = db.query(PriceList).filter(PriceList.id == price_id).first()
    if not db_item:
        raise HTTPException(404, "Price item not found")
    for k, v in item.model_dump(exclude_unset=True).items():
        setattr(db_item, k, v)
    db.commit()
    db.refresh(db_item)
    return db_item

class PriceItemUpdate(BaseModel):
    name: Optional[str] = None
    description: Optional[str] = None
    category: Optional[str] = None
    unit: Optional[str] = None
    unit_price: Optional[float] = None
    currency: Optional[str] = None
    effective_date: Optional[date] = None
    source: Optional[str] = None

@router.patch("/prices/{price_id}")
def patch_price(price_id: int, item: PriceItemUpdate, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "edit"))):
    db_item = db.query(PriceList).filter(PriceList.id == price_id).first()
    if not db_item:
        raise HTTPException(404, "Price item not found")
    for k, v in item.model_dump(exclude_unset=True).items():
        setattr(db_item, k, v)
    db.commit()
    db.refresh(db_item)
    return db_item

@router.delete("/prices/{price_id}")
def delete_price(price_id: int, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "delete"))):
    db_item = db.query(PriceList).filter(PriceList.id == price_id).first()
    if not db_item:
        raise HTTPException(404, "Price item not found")
    db_item.is_active = False
    db.commit()
    return {"message": "Price item removed"}

@router.get("/prices/categories")
def list_price_categories(db: Session = Depends(get_db), current_user: User = Depends(get_current_user)):
    rows = db.query(PriceList.category).filter(PriceList.is_active == True).distinct().all()
    return sorted([r[0] for r in rows if r[0]])

class PriceImportURL(BaseModel):
    url: str
    effective_date: date
    source_name: Optional[str] = None

def _parse_csv_rows(text: str, effective_date: date, source: str, db: Session) -> dict:
    reader = csv.DictReader(io.StringIO(text.strip()))
    # normalize headers: lowercase + strip
    rows = [{k.strip().lower(): v.strip() for k, v in row.items()} for row in reader]
    if not rows:
        raise HTTPException(400, "CSV is empty or has no valid rows")
    # detect required columns flexibly
    sample = rows[0]
    name_col = next((k for k in sample if k in ("name", "item", "product", "description", "title")), None)
    price_col = next((k for k in sample if k in ("price", "unit_price", "rate", "amount", "cost")), None)
    if not name_col or not price_col:
        raise HTTPException(400, f"CSV must have name and price columns. Found: {list(sample.keys())}")
    unit_col = next((k for k in sample if k in ("unit", "uom")), None)
    cat_col = next((k for k in sample if k in ("category", "cat", "type")), None)
    desc_col = next((k for k in sample if k in ("description", "desc", "details") and k != name_col), None)

    created = 0
    for row in rows:
        name = row.get(name_col, "").strip()
        try:
            price = float(row.get(price_col, 0) or 0)
        except ValueError:
            continue
        if not name or price <= 0:
            continue
        db_item = PriceList(
            name=name,
            description=row.get(desc_col, "") if desc_col else "",
            category=row.get(cat_col, "general") if cat_col else "general",
            unit=row.get(unit_col, "pcs") if unit_col else "pcs",
            unit_price=price,
            effective_date=effective_date,
            source=source,
        )
        db.add(db_item)
        created += 1
    db.commit()
    return {"message": f"{created} price items imported", "count": created}

@router.post("/prices/import/url")
def import_prices_from_url(body: PriceImportURL, db: Session = Depends(get_db), current_user: User = Depends(require_permission("products", "create"))):
    try:
        resp = http_requests.get(body.url, timeout=15)
        resp.raise_for_status()
    except Exception as e:
        raise HTTPException(400, f"Failed to fetch URL: {e}")
    source = body.source_name or body.url
    return _parse_csv_rows(resp.text, body.effective_date, source, db)

from fastapi import UploadFile, File

@router.post("/prices/import/csv")
async def import_prices_from_csv(
    file: UploadFile = File(...),
    effective_date: date = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permission("products", "create"))
):
    if not file.filename.endswith(".csv"):
        raise HTTPException(400, "Only .csv files are supported")
    content = await file.read()
    text = content.decode("utf-8-sig")  # handle BOM
    ed = effective_date or date.today()
    return _parse_csv_rows(text, ed, f"csv:{file.filename}", db)

# --- Reports Routes ---
@router.get("/reports")
def get_reports(
    from_date: Optional[date] = None,
    to_date: Optional[date] = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(get_current_user)
):
    from sqlalchemy import extract
    fd = from_date or date(date.today().year, 1, 1)
    td = to_date or date.today()

    # Revenue by month
    invoices_in_range = db.query(Invoice).filter(
        Invoice.invoice_date >= fd,
        Invoice.invoice_date <= td,
        Invoice.status != InvoiceStatus.CANCELLED
    ).all()

    monthly = {}
    for inv in invoices_in_range:
        key = inv.invoice_date.strftime("%Y-%m")
        if key not in monthly:
            monthly[key] = {"month": inv.invoice_date.strftime("%b %Y"), "revenue": 0, "tax": 0, "invoices": 0}
        monthly[key]["revenue"] += inv.total_amount or 0
        monthly[key]["tax"] += inv.tax_amount or 0
        monthly[key]["invoices"] += 1
    revenue_by_month = [monthly[k] for k in sorted(monthly.keys())]

    # Invoice status breakdown
    status_counts = {}
    for inv in invoices_in_range:
        s = inv.status.value
        status_counts[s] = status_counts.get(s, 0) + 1

    # Top clients by revenue
    client_revenue = {}
    for inv in invoices_in_range:
        name = inv.client.name if inv.client else "Unknown"
        client_revenue[name] = client_revenue.get(name, 0) + (inv.total_amount or 0)
    top_clients = sorted([{"name": k, "revenue": round(v, 2)} for k, v in client_revenue.items()], key=lambda x: -x["revenue"])[:10]

    # Expenses by category in range
    expenses_in_range = db.query(Expense).filter(
        Expense.expense_date >= fd,
        Expense.expense_date <= td
    ).all()
    exp_by_cat = {}
    for e in expenses_in_range:
        cat = e.category.value if e.category else "other"
        exp_by_cat[cat] = exp_by_cat.get(cat, 0) + (e.total_amount or 0)
    expenses_by_category = [{"category": k, "amount": round(v, 2)} for k, v in sorted(exp_by_cat.items(), key=lambda x: -x[1])]

    # Tax summary
    total_gst = sum(inv.tax_amount or 0 for inv in invoices_in_range)
    total_wht = sum(inv.withholding_tax_amount or 0 for inv in invoices_in_range)
    total_fed = sum(inv.fed_amount or 0 for inv in invoices_in_range)

    # Receivables aging
    today = date.today()
    aging = {"current": 0, "1_30": 0, "31_60": 0, "61_90": 0, "over_90": 0}
    unpaid = db.query(Invoice).filter(
        Invoice.status.in_([InvoiceStatus.SENT, InvoiceStatus.PARTIALLY_PAID])
    ).all()
    for inv in unpaid:
        if not inv.due_date:
            aging["current"] += inv.balance_due or 0
            continue
        days = (today - inv.due_date).days
        if days <= 0: aging["current"] += inv.balance_due or 0
        elif days <= 30: aging["1_30"] += inv.balance_due or 0
        elif days <= 60: aging["31_60"] += inv.balance_due or 0
        elif days <= 90: aging["61_90"] += inv.balance_due or 0
        else: aging["over_90"] += inv.balance_due or 0
    aging = {k: round(v, 2) for k, v in aging.items()}

    # Summary KPIs
    total_revenue = sum(inv.total_amount or 0 for inv in invoices_in_range)
    total_paid = sum(inv.amount_paid or 0 for inv in invoices_in_range)
    total_outstanding = sum(inv.balance_due or 0 for inv in invoices_in_range)
    total_expenses = sum(e.total_amount or 0 for e in expenses_in_range)

    return {
        "period": {"from": str(fd), "to": str(td)},
        "kpis": {
            "total_revenue": round(total_revenue, 2),
            "total_paid": round(total_paid, 2),
            "total_outstanding": round(total_outstanding, 2),
            "total_expenses": round(total_expenses, 2),
            "net_profit": round(total_revenue - total_expenses, 2),
            "invoice_count": len(invoices_in_range),
        },
        "revenue_by_month": revenue_by_month,
        "invoice_status": status_counts,
        "top_clients": top_clients,
        "expenses_by_category": expenses_by_category,
        "tax_summary": {"gst": round(total_gst, 2), "wht": round(total_wht, 2), "fed": round(total_fed, 2)},
        "receivables_aging": aging,
    }

# --- Agent Routes ---
class AgentQuery(BaseModel):
    message: str

@router.post("/agent/chat")
def agent_chat(query: AgentQuery, db: Session = Depends(get_db)):
    response = support_agent.get_response(query.message, db=db)
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
