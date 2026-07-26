# DATAPOINT Invoicing System — Development Guidelines

## Code Quality Standards

### Naming Conventions
- **Files**: `snake_case` for all Python files (`pdf_service.py`, `support_agent.py`)
- **Classes**: `PascalCase` (`InvoiceStatus`, `SupportAgent`, `VoiceQuotationAgent`)
- **Functions/variables**: `snake_case` (`get_current_user`, `calculate_item_tax`)
- **Private helpers**: prefix with `_` (`_header_footer`, `_build_item_table`, `_resolve_or_create_supplier`, `_seed_admin`)
- **Constants**: `UPPER_SNAKE_CASE` (`USABLE_WIDTH`, `LEFT_MARGIN`, `RIGHT_MARGIN`)
- **Pydantic request models**: `EntityCreate` suffix (`InvoiceCreate`, `ClientCreate`, `UserCreate`)
- **Status enums**: `EntityStatus` suffix (`InvoiceStatus`, `EstimateStatus`, `PurchaseStatus`)

### Enum Pattern
All status/category fields use `str, enum.Enum` for JSON serialization compatibility:
```python
class InvoiceStatus(str, enum.Enum):
    DRAFT = "draft"
    SENT = "sent"
    PAID = "paid"
```
Access enum value with `.value` when serializing: `status.value`

### Model Structure (SQLAlchemy)
Every model follows this column order:
1. `id` — primary key with index
2. Business identifier (e.g., `invoice_no`) — unique + indexed
3. Foreign keys
4. Business date fields
5. Status enum column
6. Financial/numeric fields
7. Text/notes fields
8. Audit fields: `created_by`, `created_at`, `updated_at`
9. Relationships at the bottom

```python
created_at = Column(DateTime(timezone=True), server_default=func.now())
updated_at = Column(DateTime(timezone=True), onupdate=func.now())
```

### Soft Delete Pattern
Records are never hard-deleted by default — use `is_active = False`:
```python
db_client.is_active = False
db.commit()
return {"message": "Client deactivated"}
```
Exceptions: expenses and items with explicit delete endpoints require status `CANCELLED`/`REJECTED` first.

---

## API Route Patterns

### Route Organization
All routes live in `app/routes/api.py` under `router = APIRouter(prefix="/api")`. Group routes with comment headers:
```python
# --- Invoice Routes ---
# --- Client Routes ---
```

### Standard CRUD Pattern
Every entity follows: `GET /entities`, `POST /entities`, `GET /entities/{id}`, `PUT /entities/{id}`, `DELETE /entities/{id}`

### Dependency Injection
- `db: Session = Depends(get_db)` — always first dependency
- `current_user: User = Depends(get_current_user)` — for authenticated routes
- `current_user: User = Depends(require_role([UserRole.ADMIN]))` — for role-restricted routes

Role hierarchy: `ADMIN` > `MANAGER` > `STAFF`
- Read operations: `get_current_user` (any authenticated user)
- Create/update: `require_role([UserRole.ADMIN, UserRole.MANAGER])`
- Delete/cancel: `require_role([UserRole.ADMIN])` or `require_role([UserRole.ADMIN, UserRole.MANAGER])`

### 404 Pattern
```python
record = db.query(Model).filter(Model.id == record_id).first()
if not record:
    raise HTTPException(404, "Record not found")
```

### Update Pattern
Use `model_dump(exclude_unset=True)` with `setattr` for partial updates:
```python
for key, value in data.model_dump(exclude_unset=True).items():
    setattr(db_record, key, value)
db.commit()
db.refresh(db_record)
return db_record
```

### Status Guard Pattern
Check status before mutating:
```python
if db_invoice.status in [InvoiceStatus.PAID, InvoiceStatus.CANCELLED]:
    raise HTTPException(400, f"Cannot edit invoice with status {db_invoice.status.value}")
```

### PDF Download Pattern
Return PDFs as `StreamingResponse` from `BytesIO`:
```python
pdf_buf = generate_invoice_pdf(invoice_data)
return StreamingResponse(pdf_buf, media_type="application/pdf",
    headers={"Content-Disposition": f"attachment; filename=invoice_{no}.pdf"})
```

### Resolve-or-Create Pattern
For supplier/product auto-creation from free-text input:
```python
def _resolve_or_create_supplier(db, supplier_name, ...) -> int:
    existing = db.query(Supplier).filter(Supplier.name.ilike(supplier_name.strip())).first()
    if existing:
        return existing.id
    new_supplier = Supplier(name=supplier_name.strip(), ...)
    db.add(new_supplier)
    db.flush()  # flush to get ID before commit
    return new_supplier.id
```

### Stock Update Error Handling
Wrap `update_stock` calls in try/except, rollback on failure:
```python
try:
    update_stock(db, product_id, qty, MovementType.SALE_OUT, ...)
except ValueError as e:
    db.rollback()
    raise HTTPException(400, f"Insufficient stock for '{item.description}': {e}")
```

---

## Service Layer Patterns

### PDF Service
- Private helper functions prefixed with `_` for reusable PDF building blocks
- All measurements in `mm` units via ReportLab (`15*mm`, `50*mm`)
- Company info always sourced from `settings` (never hardcoded)
- `generate_*_pdf(data: dict) -> BytesIO` — accepts plain dict, returns buffer
- `doc.build(elements, onFirstPage=_header_footer, onLaterPages=_header_footer)` — always attach header/footer
- `buf.seek(0)` before returning buffer

### Agent Service
- Keyword-scoring approach for intent matching (no ML dependency):
  ```python
  score = sum(1 for kw in data["keywords"] if kw in msg)
  ```
- Module-level singleton instances: `support_agent = SupportAgent()`, `voice_agent = VoiceQuotationAgent()`
- Response dicts include `type`, `message`, and `action` keys for structured agent responses

### Tax Calculations
- Always delegate to `calculate_item_tax()` and `calculate_invoice_tax()` from `app.services.taxation`
- Tax rate shorthand: `tr = 17 if apply_tax else 0`
- Round all financial values to 2 decimal places: `round(value, 2)`

---

## Page Route Pattern (main.py)
HTML page routes are in `app/main.py`, API routes in `app/routes/api.py`. Page routes always pass `company` context:
```python
@app.get("/invoices", response_class=HTMLResponse)
def invoices_page(request: Request):
    return templates.TemplateResponse(request, "invoices/list.html", {"company": settings.COMPANY_NAME})
```
Detail/edit pages pass the entity ID for JavaScript to fetch via API:
```python
return templates.TemplateResponse(request, "invoices/form.html",
    {"company": settings.COMPANY_NAME, "invoice_id": invoice_id})
```

---

## Database Patterns

### Session Management
Always use `get_db` dependency — never create sessions manually in routes. For startup code (outside request context), use `SessionLocal()` with explicit `try/finally db.close()`.

### flush() vs commit()
- `db.flush()` — use when you need the generated ID before committing (e.g., creating parent then children in same transaction)
- `db.commit()` + `db.refresh(record)` — always at end of write operations before returning

### Inventory Stock Reversal
When editing/deleting records that affect stock, always reverse old stock movements first:
```python
for old_item in db_invoice.items:
    if old_item.product_id:
        inv = get_or_create_inventory(db, old_item.product_id)
        inv.quantity += old_item.quantity  # reverse
        movement = StockMovement(..., movement_type=MovementType.ADJUSTMENT,
            reference_type="invoice_edit", notes=f"Reversal for edited invoice {no}")
        db.add(movement)
```

---

## Configuration Pattern
All settings accessed via the singleton `settings` from `app.config`:
```python
from app.config import settings
settings.COMPANY_NAME
settings.SALES_TAX_RATE
settings.DATABASE_URL
```
Never hardcode company info, tax rates, or connection strings — always use `settings`.

---

## Startup & Initialization
App startup in `main.py` `on_startup` event:
1. `init_db()` — create tables
2. Create `pdf_output` directory if missing
3. `_seed_admin()` — create default admin only if no users exist (idempotent)
