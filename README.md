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

---

### AWS EC2 (Manual Deployment)

#### 1. Launch EC2 Instance

- **AMI:** Amazon Linux 2023 (or Ubuntu 22.04)
- **Instance type:** `t2.micro` (free tier) or `t3.medium` for production
- **Storage:** 20+ GB gp3
- **Security group rules:**

| Type | Protocol | Port | Source |
|------|----------|------|--------|
| SSH | TCP | 22 | Your IP |
| HTTP | TCP | 80 | 0.0.0.0/0 |
| HTTPS | TCP | 443 | 0.0.0.0/0 |

#### 2. Connect & Update

```bash
ssh -i your-key.pem ec2-user@<public-ip>

sudo dnf update -y                                    # Amazon Linux
sudo dnf install -y git nginx postgresql15-server     # PostgreSQL optional
```

#### 3. Install Python & Dependencies

```bash
sudo dnf install -y python3.11 python3.11-pip
python3.11 -m venv /home/ec2-user/venv
```

#### 4. Set Up Database

Choose **one** of the following options:

<details>
<summary><b>Option A — Aurora RDS (Recommended for Production)</b></summary>

Create an Aurora PostgreSQL cluster via AWS Console:

1. Go to **RDS** → **Create database**
2. Engine: **Amazon Aurora (PostgreSQL Compatible)**
3. Capacity: **Provisioned** (`db.t3.medium`) or **Serverless v2** (min 0.5, max 2 ACU)
4. Cluster identifier: `dptech-invoicing-db`
5. Master username: `postgres`, set a strong password
6. Initial database name: `dptech_db`
7. VPC security group: create new or use existing

Add inbound rule to the security group:

| Type | Protocol | Port | Source |
|------|----------|------|--------|
| PostgreSQL | TCP | 5432 | EC2 security group ID (e.g., `sg-xxxxx`) |

Get the **writer endpoint** from the RDS console, then connect from EC2 to create the app user:

```bash
sudo dnf install -y postgresql15
psql -h <writer-endpoint> -U postgres -d dptech_db -W
```

```sql
CREATE USER dptech WITH PASSWORD 'StrongP@ss123';
GRANT ALL PRIVILEGES ON DATABASE dptech_db TO dptech;
GRANT ALL ON SCHEMA public TO dptech;
\q
```

Connection string for `.env`:
```
DATABASE_URL=postgresql://dptech:StrongP@ss123@<writer-endpoint>:5432/dptech_db
```

</details>

<details>
<summary><b>Option B — PostgreSQL on EC2 (Simpler/Cheaper)</b></summary>

```bash
sudo dnf install -y postgresql15-server
sudo postgresql-setup --initdb
sudo systemctl start postgresql
sudo systemctl enable postgresql

# Create database and user
sudo -u postgres psql -c "CREATE USER dptech WITH PASSWORD 'StrongP@ss123';"
sudo -u postgres psql -c "CREATE DATABASE dptech_db OWNER dptech;"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE dptech_db TO dptech;"
```

</details>

#### 5. Clone the Repository

```bash
cd /home/ec2-user
git clone https://github.com/your-org/dptech-invoicing.git
cd dptech-invoicing
```

#### 6. Configure Environment

```bash
cp .env.example .env
```

Edit `.env` — **at minimum** set:

```
SECRET_KEY=<your-generated-random-key>
DATABASE_URL=<your-database-connection-string>
COMPANY_NAME=DATAPOINT Technologies
COMPANY_NTN=XXXXXXXXXXXXX
COMPANY_STRN=XXXXXXXXXXXXX
SALES_TAX_RATE=18.0
```

> **Database connection string:**
> - **Aurora RDS:** `postgresql://dptech:StrongP@ss123@<writer-endpoint>:5432/dptech_db`
> - **Local on EC2:** `postgresql://dptech:StrongP@ss123@localhost:5432/dptech_db`

> Generate a strong `SECRET_KEY`:
> ```bash
> python3.11 -c "import secrets; print(secrets.token_urlsafe(50))"
> ```

#### 7. Install Python Packages

```bash
source /home/ec2-user/venv/bin/activate
pip install -r requirements.txt
pip install gunicorn uvicorn          # Production server
```

#### 8. Test the App

```bash
python run.py
```

Visit `http://<public-ip>:8000`. Press `Ctrl+C` to stop.

#### 9. Run with systemd (Auto-start on Boot)

Create service file:

```bash
sudo nano /etc/systemd/system/dptech.service
```

```ini
[Unit]
Description=DATAPOINT Invoicing API
After=network.target postgresql.service

[Service]
User=ec2-user
WorkingDirectory=/home/ec2-user/dptech-invoicing
Environment="PATH=/home/ec2-user/venv/bin"
ExecStart=/home/ec2-user/venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable dptech
sudo systemctl start dptech
sudo systemctl status dptech          # Verify
```

#### 10. Set Up Nginx Reverse Proxy

```bash
sudo nano /etc/nginx/conf.d/dptech.conf
```

```nginx
server {
    listen 80;
    server_name your-domain.com <public-ip>;

    client_max_body_size 10M;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /static/ {
        alias /home/ec2-user/dptech-invoicing/app/static/;
        expires 30d;
    }
}
```

Remove default Nginx config and restart:

```bash
sudo rm -f /etc/nginx/conf.d/default.conf
sudo nginx -t                    # Test config
sudo systemctl enable nginx
sudo systemctl restart nginx
```

#### 11. SSL with Let's Encrypt (HTTPS)

```bash
sudo dnf install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

Certbot auto-updates the Nginx config. Certificates renew automatically via systemd timer.

#### 12. Health Check

```bash
curl http://localhost:8000/        # From EC2
curl https://your-domain.com/      # From anywhere
```

Check logs:

```bash
sudo journalctl -u dptech -f       # App logs
sudo tail -f /var/log/nginx/access.log
```

---

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
