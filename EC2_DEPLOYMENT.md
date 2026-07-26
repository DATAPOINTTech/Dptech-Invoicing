# EC2 Deployment Guide — DATAPOINT Invoicing System

## Prerequisites
- AWS account with EC2 and RDS access
- A `.pem` key pair file downloaded from AWS
- Git installed on your local machine
- Your code pushed to a GitHub repository

---

## Step 1 — Push Code to GitHub

On your local Windows machine:

```bash
cd f:\AgenticAi\Projects\Dptech-Invoicing

git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/DATAPOINTTech/dptech-invoicing.git
git push -u origin main
```

---

## Step 2 — Create RDS Aurora PostgreSQL Database

### 2.1 Create the Database

1. Open **AWS Console → RDS → Create database**
2. Choose **Standard create**
3. Engine: **Amazon Aurora — PostgreSQL Compatible**
4. Version: **Aurora PostgreSQL 15.x**
5. Template: **Production** (or Dev/Test for lower cost)
6. Capacity type: **Serverless v2**
   - Minimum ACU: `0.5`
   - Maximum ACU: `4`
7. Cluster identifier: `dptech-invoicing-db`
8. Master username: `postgres`
9. Master password: set a strong password and **save it**
10. Initial database name: `dptech_db`
11. VPC: use the **default VPC** (same as your EC2)
12. Public access: **No**
13. Click **Create database** and wait ~5 minutes

### 2.2 Note the Writer Endpoint

Once created, go to the cluster and copy the **Writer endpoint** — it looks like:

```
dptech-invoicing-db.cluster-xxxxxxxxxxxx.us-east-1.rds.amazonaws.com
```

---

## Step 3 — Launch EC2 Instance

### 3.1 Create the Instance

1. Open **AWS Console → EC2 → Launch Instance**
2. Name: `dptech-invoicing`
3. AMI: **Amazon Linux 2023** (free tier eligible)
4. Instance type: `t3.medium` (production) or `t2.micro` (free tier / testing)
5. Key pair: **Create new key pair**
   - Name: `dptech-key`
   - Type: RSA
   - Format: `.pem`
   - Download and save it securely
6. Storage: `20 GB gp3`
7. Click **Launch Instance**

### 3.2 Configure Security Group

Go to **EC2 → Security Groups → find the group attached to your instance → Edit inbound rules**:

| Type       | Protocol | Port | Source            |
|------------|----------|------|-------------------|
| SSH        | TCP      | 22   | My IP             |
| HTTP       | TCP      | 80   | 0.0.0.0/0         |
| HTTPS      | TCP      | 443  | 0.0.0.0/0         |

### 3.3 Allow EC2 to Connect to RDS

1. Go to **RDS → your cluster → Connectivity & security → VPC security group**
2. Click the security group → **Edit inbound rules → Add rule**:

| Type       | Protocol | Port | Source                        |
|------------|----------|------|-------------------------------|
| PostgreSQL | TCP      | 5432 | EC2 security group ID (sg-xxx)|

---

## Step 4 — Connect to EC2

### Windows (PowerShell or Git Bash)

```bash
# Fix key permissions (Git Bash)
chmod 400 dptech-key.pem

# Connect
ssh -i "dptech-key.pem" ec2-user@<your-ec2-public-ip>
```

### Windows (PuTTY)

1. Convert `.pem` to `.ppk` using **PuTTYgen** (Load → Save private key)
2. Open PuTTY → Host: `ec2-user@<your-ec2-public-ip>` → Port: `22`
3. Connection → SSH → Auth → Browse to your `.ppk` file
4. Click **Open**

---

## Step 5 — Server Setup on EC2

Run these commands on the EC2 instance:

### 5.1 Update system and install dependencies

```bash
sudo dnf update -y
sudo dnf install -y git nginx python3.11 python3.11-pip postgresql15
```

### 5.2 Clone the repository

```bash
cd ~
git clone https://github.com/<your-username>/dptech-invoicing.git
cd dptech-invoicing
```

### 5.3 Create Python virtual environment

```bash
python3.11 -m venv ~/venv
source ~/venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
```

---

## Step 6 — Configure Environment Variables

```bash
cp .env.example .env
nano .env
```

Set the following values:

```env
# Database — use your RDS writer endpoint
DATABASE_URL=postgresql://dptech:<your-db-password>@<rds-writer-endpoint>:5432/dptech_db
DB_SSL_REQUIRED=true

# Generate a strong secret key
SECRET_KEY=<see command below>

# Company info
COMPANY_NAME=DATAPOINT Technologies
COMPANY_NTN=XXXXXXXXXXXXX
COMPANY_STRN=XXXXXXXXXXXXX
```

**Generate SECRET_KEY:**

```bash
python3.11 -c "import secrets; print(secrets.token_urlsafe(50))"
```

Copy the output and paste it as the `SECRET_KEY` value.

Save and exit nano: `Ctrl+O` → `Enter` → `Ctrl+X`

---

## Step 7 — Set Up RDS Database User

Connect to RDS from EC2 and run the setup SQL:

```bash
psql -h <rds-writer-endpoint> -U postgres -d postgres -W
```

Enter the master password when prompted, then run:

```sql
CREATE USER dptech WITH PASSWORD '<your-db-password>';
GRANT ALL PRIVILEGES ON DATABASE dptech_db TO dptech;
\c dptech_db
GRANT ALL ON SCHEMA public TO dptech;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO dptech;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO dptech;
\q
```

---

## Step 8 — Run Database Migrations

```bash
cd ~/dptech-invoicing
source ~/venv/bin/activate
alembic upgrade head
```

---

## Step 9 — Test the Application

```bash
source ~/venv/bin/activate
python run.py
```

Open in browser: `http://<your-ec2-public-ip>:8000`

You should see the login page. Check the terminal for:

```
WARNING  Default admin created — username: admin  password: admin123
```

Press `Ctrl+C` to stop after confirming it works.

---

## Step 10 — Set Up systemd Service (Auto-start)

### 10.1 Create log directory

```bash
sudo mkdir -p /var/log/dptech
sudo chown ec2-user:ec2-user /var/log/dptech
```

### 10.2 Install the service

```bash
sudo cp ~/dptech-invoicing/deploy/dptech.service /etc/systemd/system/dptech.service
sudo systemctl daemon-reload
sudo systemctl enable dptech
sudo systemctl start dptech
```

### 10.3 Verify it is running

```bash
sudo systemctl status dptech
```

You should see `Active: active (running)`.

---

## Step 11 — Configure Nginx Reverse Proxy

### 11.1 Install the config

```bash
sudo cp ~/dptech-invoicing/deploy/nginx.conf /etc/nginx/conf.d/dptech.conf
sudo rm -f /etc/nginx/conf.d/default.conf
```

### 11.2 Update server_name (optional — if you have a domain)

```bash
sudo nano /etc/nginx/conf.d/dptech.conf
```

Replace `server_name _;` with your domain or leave as `_` for IP-only access.

### 11.3 Test and start Nginx

```bash
sudo nginx -t
sudo systemctl enable nginx
sudo systemctl start nginx
```

### 11.4 Verify

```bash
curl http://localhost/health
# Expected: {"status":"ok"}
```

Open in browser: `http://<your-ec2-public-ip>`

---

## Step 12 — SSL with Let's Encrypt (HTTPS) — Optional

> Requires a domain name pointed to your EC2 public IP.

```bash
sudo dnf install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

Follow the prompts. Certbot will auto-update your Nginx config and set up auto-renewal.

Test renewal:

```bash
sudo certbot renew --dry-run
```

---

## Step 13 — Change Default Admin Password

1. Open `http://<your-ec2-public-ip>` in your browser
2. Log in with `admin` / `admin123`
3. Go to **Users** in the sidebar
4. Edit the admin user and set a strong password

---

## Useful Commands

```bash
# View live app logs
sudo journalctl -u dptech -f

# Restart app after code changes
sudo systemctl restart dptech

# View nginx access logs
sudo tail -f /var/log/nginx/access.log

# View nginx error logs
sudo tail -f /var/log/nginx/error.log

# View app-specific logs
tail -f /var/log/dptech/error.log

# Check app status
sudo systemctl status dptech

# Pull latest code and restart
cd ~/dptech-invoicing
git pull origin main
source ~/venv/bin/activate
pip install -r requirements.txt
alembic upgrade head
sudo systemctl restart dptech
```

---

## Troubleshooting

| Problem | Likely Cause | Fix |
|---------|-------------|-----|
| 401 on login | No users in DB | Check startup log for default admin warning |
| 502 Bad Gateway | App not running | `sudo systemctl restart dptech` |
| Cannot connect to RDS | Security group | Add EC2 SG to RDS inbound rules on port 5432 |
| `alembic upgrade head` fails | Wrong DATABASE_URL | Check `.env` — verify endpoint and password |
| Static files not loading | Nginx path wrong | Verify `alias` path in `nginx.conf` matches actual path |
| App crashes on start | Missing SECRET_KEY | Set `SECRET_KEY` in `.env` |

---

## Architecture Overview

```
Internet
    │
    ▼
[EC2 - Amazon Linux 2023]
    │  Nginx :80/:443
    │  └── proxy_pass → Gunicorn :8000
    │       └── FastAPI app (2 uvicorn workers)
    │
    ▼
[RDS Aurora PostgreSQL]
    └── dptech_db (private subnet, no public access)
```

---

*Generated for DATAPOINT Invoicing System — see `deploy/` folder for all config files.*
