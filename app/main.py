from fastapi import FastAPI, Request
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from fastapi.responses import HTMLResponse, RedirectResponse, FileResponse
from fastapi.middleware.cors import CORSMiddleware
import os
from app.database import init_db
from app.routes.api import router as api_router
from app.config import settings
import app.models.pricelist  # register PriceList with Base

app = FastAPI(title=f"{settings.COMPANY_NAME} - Business Management System")

# CORS — restrict to explicit origins in production. Empty => no cross-origin access.
cors_origins = [o.strip() for o in settings.CORS_ORIGINS.split(",") if o.strip()] if settings.CORS_ORIGINS else []
app.add_middleware(
    CORSMiddleware,
    allow_origins=cors_origins or ["*"],
    allow_credentials=bool(cors_origins),
    allow_methods=["*"],
    allow_headers=["*"],
)

templates = Jinja2Templates(directory=os.path.join(os.path.dirname(__file__), "templates"))
static_dir = os.path.join(os.path.dirname(__file__), "static")
if os.path.exists(static_dir):
    app.mount("/static", StaticFiles(directory=static_dir), name="static")

app.include_router(api_router)

@app.get("/health", include_in_schema=False)
def health_check():
    return {"status": "ok"}

@app.get("/favicon.ico", include_in_schema=False)
def favicon():
    return FileResponse(os.path.join(static_dir, "img", "favicon.svg"), media_type="image/svg+xml")

@app.on_event("startup")
def on_startup():
    init_db()
    pdf_dir = settings.PDF_OUTPUT_DIR
    if not os.path.exists(pdf_dir):
        os.makedirs(pdf_dir)
    _seed_admin()

def _seed_admin():
    """Create the first admin account from environment variables when the DB is empty.

    Never creates a hardcoded default credential. If ADMIN_USERNAME/ADMIN_EMAIL/
    ADMIN_PASSWORD are not provided, no admin is created and the operator is warned.
    """
    import logging
    logger = logging.getLogger(__name__)
    from app.database import SessionLocal
    from app.models.user import User, UserRole
    from app.services.auth import get_password_hash

    if not (settings.ADMIN_USERNAME and settings.ADMIN_EMAIL and settings.ADMIN_PASSWORD):
        logger.warning(
            "No admin bootstrapped: set ADMIN_USERNAME, ADMIN_EMAIL and ADMIN_PASSWORD "
            "in the environment to create the first admin account."
        )
        return

    db = SessionLocal()
    try:
        if db.query(User).count() > 0:
            return
        password = settings.ADMIN_PASSWORD
        if len(password) < 8:
            logger.warning("ADMIN_PASSWORD is weak (< 8 characters). Change it after first login.")
        admin = User(
            username=settings.ADMIN_USERNAME,
            email=settings.ADMIN_EMAIL,
            hashed_password=get_password_hash(password),
            full_name="Administrator",
            role=UserRole.ADMIN,
            is_active=True,
        )
        db.add(admin)
        db.commit()
        logger.info("Admin account '%s' created from environment configuration.", settings.ADMIN_USERNAME)
    finally:
        db.close()

@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    return templates.TemplateResponse(request, "login.html", {"company": settings.COMPANY_NAME})

@app.get("/login", response_class=HTMLResponse)
def login_page(request: Request):
    return templates.TemplateResponse(request, "login.html", {"company": settings.COMPANY_NAME})

@app.get("/dashboard", response_class=HTMLResponse)
def dashboard(request: Request):
    return templates.TemplateResponse(request, "dashboard.html", {"company": settings.COMPANY_NAME})

@app.get("/clients", response_class=HTMLResponse)
def clients_page(request: Request):
    return templates.TemplateResponse(request, "clients/list.html", {"company": settings.COMPANY_NAME})

@app.get("/clients/new", response_class=HTMLResponse)
def client_new(request: Request):
    return templates.TemplateResponse(request, "clients/form.html", {"company": settings.COMPANY_NAME})

@app.get("/products", response_class=HTMLResponse)
def products_page(request: Request):
    return templates.TemplateResponse(request, "inventory/products.html", {"company": settings.COMPANY_NAME})

@app.get("/products/new", response_class=HTMLResponse)
def product_new(request: Request):
    return templates.TemplateResponse(request, "inventory/product_form.html", {"company": settings.COMPANY_NAME})

@app.get("/inventory", response_class=HTMLResponse)
def inventory_page(request: Request):
    return templates.TemplateResponse(request, "inventory/list.html", {"company": settings.COMPANY_NAME})

@app.get("/inventory/movements", response_class=HTMLResponse)
def movements_page(request: Request):
    return templates.TemplateResponse(request, "inventory/movements.html", {"company": settings.COMPANY_NAME})

@app.get("/purchases", response_class=HTMLResponse)
def purchases_page(request: Request):
    return templates.TemplateResponse(request, "purchases/list.html", {"company": settings.COMPANY_NAME})

@app.get("/purchases/new", response_class=HTMLResponse)
def purchase_new(request: Request):
    return templates.TemplateResponse(request, "purchases/form.html", {"company": settings.COMPANY_NAME, "purchase_id": None})

@app.get("/purchases/{purchase_id}", response_class=HTMLResponse)
def purchase_detail(request: Request, purchase_id: int):
    return templates.TemplateResponse(request, "purchases/detail.html", {"company": settings.COMPANY_NAME, "purchase_id": purchase_id})

@app.get("/purchases/{purchase_id}/edit", response_class=HTMLResponse)
def purchase_edit(request: Request, purchase_id: int):
    return templates.TemplateResponse(request, "purchases/form.html", {"company": settings.COMPANY_NAME, "purchase_id": purchase_id})

@app.get("/expenses", response_class=HTMLResponse)
def expenses_page(request: Request):
    return templates.TemplateResponse(request, "expenses/list.html", {"company": settings.COMPANY_NAME})

@app.get("/expenses/new", response_class=HTMLResponse)
def expense_new(request: Request):
    return templates.TemplateResponse(request, "expenses/form.html", {"company": settings.COMPANY_NAME, "expense_id": None})

@app.get("/expenses/{expense_id}", response_class=HTMLResponse)
def expense_detail(request: Request, expense_id: int):
    return templates.TemplateResponse(request, "expenses/form.html", {"company": settings.COMPANY_NAME, "expense_id": expense_id})

@app.get("/estimates", response_class=HTMLResponse)
def estimates_page(request: Request):
    return templates.TemplateResponse(request, "estimates/list.html", {"company": settings.COMPANY_NAME})

@app.get("/estimates/new", response_class=HTMLResponse)
def estimate_new(request: Request):
    return templates.TemplateResponse(request, "estimates/form.html", {"company": settings.COMPANY_NAME, "estimate_id": None})

@app.get("/estimates/{estimate_id}/edit", response_class=HTMLResponse)
def estimate_edit(request: Request, estimate_id: int):
    return templates.TemplateResponse(request, "estimates/form.html", {"company": settings.COMPANY_NAME, "estimate_id": estimate_id})

@app.get("/estimates/{estimate_id}", response_class=HTMLResponse)
def estimate_detail(request: Request, estimate_id: int):
    return templates.TemplateResponse(request, "estimates/detail.html", {"company": settings.COMPANY_NAME, "estimate_id": estimate_id})

@app.get("/invoices", response_class=HTMLResponse)
def invoices_page(request: Request):
    return templates.TemplateResponse(request, "invoices/list.html", {"company": settings.COMPANY_NAME})

@app.get("/invoices/new", response_class=HTMLResponse)
def invoice_new(request: Request):
    return templates.TemplateResponse(request, "invoices/form.html", {"company": settings.COMPANY_NAME, "invoice_id": None})

@app.get("/invoices/{invoice_id}/edit", response_class=HTMLResponse)
def invoice_edit(request: Request, invoice_id: int):
    return templates.TemplateResponse(request, "invoices/form.html", {"company": settings.COMPANY_NAME, "invoice_id": invoice_id})

@app.get("/invoices/{invoice_id}", response_class=HTMLResponse)
def invoice_detail(request: Request, invoice_id: int):
    return templates.TemplateResponse(request, "invoices/detail.html", {"company": settings.COMPANY_NAME, "invoice_id": invoice_id})

@app.get("/agent", response_class=HTMLResponse)
def agent_page(request: Request):
    return templates.TemplateResponse(request, "agent/chat.html", {"company": settings.COMPANY_NAME})

@app.get("/projects", response_class=HTMLResponse)
def projects_page(request: Request):
    return templates.TemplateResponse(request, "projects.html", {"company": settings.COMPANY_NAME})

@app.get("/reports", response_class=HTMLResponse)
def reports_page(request: Request):
    return templates.TemplateResponse(request, "reports.html", {"company": settings.COMPANY_NAME})

@app.get("/prices", response_class=HTMLResponse)
def prices_page(request: Request):
    return templates.TemplateResponse(request, "prices.html", {"company": settings.COMPANY_NAME})

@app.get("/users", response_class=HTMLResponse)
def users_page(request: Request):
    return templates.TemplateResponse(request, "users/list.html", {"company": settings.COMPANY_NAME})
