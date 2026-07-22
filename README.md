# DATAPOINT Invoicing System

Business management system with invoicing, estimates, inventory, expense tracking, and AI support agent.

## Quick Start

```bash
pip install -r requirements.txt
python run.py
```

Visit `http://localhost:8000` — register an account on the login page to get started.

## Deploy to Cloud

### Railway / Render / Heroku

1. Push this repo to GitHub
2. Connect your cloud provider to the repo
3. Set these environment variables:

```
SECRET_KEY=<generate-a-random-key>
DATABASE_URL=postgresql://user:pass@host:5432/dbname
```

The app uses `uvicorn` via the `Procfile` — no additional config needed.

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `DATABASE_URL` | No | `sqlite:///./dptech.db` | Use PostgreSQL in production |
| `SECRET_KEY` | No | dev-only key | JWT signing key (change in production) |
| `PORT` | No | `8000` | Server port (set automatically by cloud) |
| `COMPANY_NAME` | No | DATAPOINT Technologies | Company name on invoices |
| `EMAIL_HOST` | No | - | SMTP server for email sending |
| `WHATSAPP_API_KEY` | No | - | Meta WhatsApp API key |

See `.env.example` for all options.

## Tech Stack

- **Backend:** FastAPI + SQLAlchemy
- **Frontend:** Jinja2 + TailwindCSS + jQuery
- **Database:** SQLite (dev) / PostgreSQL (production)
- **Auth:** JWT with bcrypt
- **PDF:** ReportLab
