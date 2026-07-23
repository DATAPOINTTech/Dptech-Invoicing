from fastapi import FastAPI, Request
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.middleware.cors import CORSMiddleware
import os
from app.database import init_db
from app.routes.api import router as api_router
from app.config import settings

app = FastAPI(title=f"{settings.COMPANY_NAME} - Business Management System")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

templates = Jinja2Templates(directory=os.path.join(os.path.dirname(__file__), "templates"))
static_dir = os.path.join(os.path.dirname(__file__), "static")
if os.path.exists(static_dir):
    app.mount("/static", StaticFiles(directory=static_dir), name="static")

app.include_router(api_router)

@app.on_event("startup")
def on_startup():
    init_db()
    pdf_dir = settings.PDF_OUTPUT_DIR
    if not os.path.exists(pdf_dir):
        os.makedirs(pdf_dir)

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

@app.get("/purchases", response_class=HTMLResponse)
def purchases_page(request: Request):
    return templates.TemplateResponse(request, "purchases/list.html", {"company": settings.COMPANY_NAME})

@app.get("/purchases/new", response_class=HTMLResponse)
def purchase_new(request: Request):
    return templates.TemplateResponse(request, "purchases/form.html", {"company": settings.COMPANY_NAME, "purchase_id": None})

@app.get("/purchases/{purchase_id}", response_class=HTMLResponse)
def purchase_detail(request: Request, purchase_id: int):
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
