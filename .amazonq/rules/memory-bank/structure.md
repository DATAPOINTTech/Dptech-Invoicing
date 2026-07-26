# DATAPOINT Invoicing System — Project Structure

## Directory Layout

```
Dptech-Invoicing/
├── app/                        # Main application package
│   ├── agents/                 # AI agent integrations
│   │   └── support_agent.py    # OpenAI-powered support chat agent
│   ├── models/                 # SQLAlchemy ORM models (one file per entity)
│   │   ├── client.py
│   │   ├── estimate.py
│   │   ├── expense.py
│   │   ├── inventory.py
│   │   ├── invoice.py
│   │   ├── product.py
│   │   ├── project.py
│   │   ├── purchase.py
│   │   ├── supplier.py
│   │   └── user.py
│   ├── routes/
│   │   └── api.py              # ALL routes in a single router (pages + API endpoints)
│   ├── services/               # Business logic layer
│   │   ├── auth.py             # JWT creation/verification, password hashing
│   │   ├── communication.py    # Email and WhatsApp sending
│   │   ├── inventory_service.py# Stock adjustment logic
│   │   ├── pdf_service.py      # ReportLab PDF generation
│   │   └── taxation.py         # Tax calculation helpers
│   ├── static/
│   │   ├── css/style.css       # Custom styles (TailwindCSS via CDN + overrides)
│   │   ├── img/                # Logo and favicon
│   │   └── js/                 # Client-side JavaScript
│   ├── templates/              # Jinja2 HTML templates
│   │   ├── base.html           # Master layout with sidebar nav
│   │   ├── dashboard.html
│   │   ├── login.html
│   │   ├── agent/chat.html
│   │   ├── clients/
│   │   ├── estimates/
│   │   ├── expenses/
│   │   ├── includes/sidebar.html
│   │   ├── inventory/
│   │   ├── invoices/
│   │   ├── purchases/
│   │   └── users/
│   ├── config.py               # Pydantic Settings — all env vars with defaults
│   ├── database.py             # Engine factory, SessionLocal, Base, get_db, init_db
│   └── main.py                 # FastAPI app factory, middleware, startup event
├── alembic/                    # Database migrations
│   ├── versions/               # Migration scripts
│   └── env.py
├── deploy/                     # Deployment helpers
│   ├── deploy.sh               # EC2 automated deploy script
│   ├── dptech.service          # systemd unit file
│   ├── nginx.conf              # Nginx reverse proxy config
│   └── rds_setup.sql           # Aurora/RDS initial SQL setup
├── pdf_output/                 # Generated PDF files (gitignored)
├── .env                        # Local environment variables (gitignored)
├── .env.example                # Template for environment configuration
├── alembic.ini                 # Alembic migration config
├── Procfile                    # Cloud platform process definition
├── requirements.txt            # Python dependencies
├── run.py                      # Entry point — uvicorn launcher
└── runtime.txt                 # Python version pin for cloud platforms
```

## Core Components & Relationships

### Request Flow
```
HTTP Request → Nginx (prod) → uvicorn → FastAPI app (main.py)
                                          → JWT middleware (cookie auth)
                                          → api.py router
                                          → service layer
                                          → SQLAlchemy models → DB
                                          → Jinja2 template → HTML response
```

### Data Model Relationships
- `Invoice` → has many `InvoiceItem` → references `Client`, `Product`
- `Estimate` → has many `EstimateItem` → references `Client`, `Product`
- `PurchaseInvoice` → has many `PurchaseItem` → references `Supplier`, `Product`
- `Product` → tracked by `Inventory` (stock movements)
- `Expense` → standalone, references expense categories
- `Project` → references `Client`
- `User` → owns all created records (multi-user isolation)

### Authentication Flow
- Login POST → `auth.py` verifies bcrypt hash → issues JWT → stored as `access_token` cookie
- All protected routes read cookie → `auth.py` decodes JWT → injects current user via FastAPI dependency
- Unauthenticated requests redirect to `/login`

## Architectural Patterns

- **Monolithic single-router**: All routes live in `app/routes/api.py` — both HTML page routes and JSON API endpoints
- **Service layer separation**: Business logic (PDF, tax, inventory, auth, communication) isolated in `app/services/`
- **Config-driven**: All tunables (company info, tax rate, DB URL, API keys) via `app/config.py` + `.env`
- **Dual-database**: Same codebase runs SQLite (dev) or PostgreSQL/Aurora (prod) via `DATABASE_URL`
- **Template + AJAX hybrid**: Server-rendered Jinja2 pages with jQuery AJAX calls for dynamic interactions
