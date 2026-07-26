# DATAPOINT Invoicing System — Technology Stack

## Languages & Runtime
- **Python 3.11** (pinned in `runtime.txt`)
- **HTML/CSS/JavaScript** (frontend templates)

## Backend Framework
- **FastAPI** `>=0.104.0` — async web framework, automatic OpenAPI docs
- **uvicorn[standard]** `>=0.24.0` — ASGI server (dev and production)
- **gunicorn** `>=21.2.0` — process manager for production deployments

## Database
- **SQLAlchemy** `>=2.0.0` — ORM with declarative Base
- **Alembic** `>=1.13.0` — schema migrations
- **SQLite** — default dev database (`dptech.db`)
- **PostgreSQL / Aurora** — production (via `psycopg2-binary>=2.9.9`)
- Connection pooling: `QueuePool` (pool_size=5, max_overflow=10, pool_recycle=1800) for PostgreSQL; `check_same_thread=False` for SQLite

## Authentication & Security
- **python-jose[cryptography]** `>=3.3.0` — JWT token creation and verification
- **passlib[bcrypt]** `>=1.7.4` — password hashing
- Algorithm: `HS256`, token expiry: 1440 minutes (24h, configurable)
- Tokens stored as HTTP cookies (`access_token`)

## Configuration
- **pydantic-settings** `>=2.1.0` — typed settings from `.env` file
- **python-dotenv** `>=1.0.0` — `.env` file loading
- Settings class: `app.config.Settings` (singleton `settings` instance)

## Frontend
- **Jinja2** `>=3.1.0` — server-side HTML templating
- **TailwindCSS** — loaded via CDN (no build step)
- **jQuery** — AJAX calls and DOM manipulation
- **aiofiles** `>=23.0.0` — async static file serving

## PDF Generation
- **ReportLab** `>=4.0.0` — programmatic PDF creation for invoices and estimates
- Output directory: `./pdf_output/` (configurable via `PDF_OUTPUT_DIR`)

## AI Agent
- **OpenAI API** — via `requests` HTTP calls (no openai SDK dependency)
- Configured via `OPENAI_API_KEY` env var

## Communication
- **Email** — SMTP via Python `smtplib` (optional: `EMAIL_HOST`, `EMAIL_PORT`, `EMAIL_USER`, `EMAIL_PASS`)
- **WhatsApp** — Meta WhatsApp Business API via `requests` (optional: `WHATSAPP_API_KEY`, `WHATSAPP_PHONE_NUMBER_ID`)

## HTTP Client
- **requests** `>=2.31.0` — synchronous HTTP for OpenAI and WhatsApp API calls

## Form Handling
- **python-multipart** `>=0.0.6` — multipart form data parsing for FastAPI

## Development Commands
```bash
# Install dependencies
pip install -r requirements.txt

# Run development server
python run.py                          # starts on http://localhost:8000

# Database migrations
alembic revision --autogenerate -m "description"
alembic upgrade head

# Production server (direct)
uvicorn app.main:app --host 0.0.0.0 --port 8000

# Generate SECRET_KEY
python3.11 -c "import secrets; print(secrets.token_urlsafe(50))"
```

## Environment Variables (key ones)
| Variable | Default | Notes |
|---|---|---|
| `DATABASE_URL` | `sqlite:///./dptech.db` | Use `postgresql://` in production |
| `SECRET_KEY` | required | JWT signing key |
| `SALES_TAX_RATE` | `17.0` | Percentage |
| `COMPANY_NAME` | `DATAPOINT Technologies` | Appears on PDFs |
| `COMPANY_NTN` | `7178396-5` | Pakistani tax number |
| `OPENAI_API_KEY` | optional | Enables AI agent |
| `PORT` | `8000` | Auto-set by cloud platforms |

## Deployment Targets
- **Local/Dev**: SQLite + `python run.py`
- **Cloud PaaS**: Railway, Render, Heroku — `Procfile` + env vars
- **AWS EC2**: systemd service + Nginx reverse proxy (configs in `deploy/`)
- **Database**: Aurora PostgreSQL (recommended) or PostgreSQL on EC2
- **SSL**: Let's Encrypt via certbot + certbot-nginx
