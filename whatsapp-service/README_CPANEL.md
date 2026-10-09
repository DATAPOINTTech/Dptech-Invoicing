# How to Run WhatsApp Baileys Agent on cPanel Hosting

The DATAPOINT Invoicing system includes a Node.js Baileys microservice (`whatsapp-service`) that:
1. Maintains a persistent WebSocket link to WhatsApp Web.
2. Generates QR codes for phone pairing in the web dashboard.
3. Automatically responds to incoming customer inquiries using the sales assistant agent.
4. Dispatches invoices and estimates with PDF attachments directly via WhatsApp.

---

## Architecture Overview

```
[Browser / Phone]
       │
       ▼
 [PHP Application]  ──(HTTP localhost:3001)──>  [Node.js Baileys Service]
 (cPanel public_html)                            (Runs 24/7 background process)
       │                                                      │
       ▼                                                      ▼
[SQLite / MySQL DB]                                    [WhatsApp Servers]
                                                       (WebSocket wss://)
```

---

## Prerequisites
- Node.js version **18.x or 20.x+** installed on cPanel (Standard on CloudLinux / cPanel).
- Ensure your `.env` in `public_html` has:
  ```env
  WHATSAPP_SERVICE_URL=http://127.0.0.1:3001
  WHATSAPP_PORT=3001
  PHP_BACKEND_URL=http://127.0.0.1
  ```
  *(Note: If your cPanel host uses HTTPS, you can set `PHP_BACKEND_URL=https://yourdomain.com`)*.

---

## Method 1: Using cPanel Terminal / SSH with PM2 (Recommended for 24/7 Connection)

Because WhatsApp Baileys requires a constant WebSocket connection, **PM2** is the best and most stable process manager.

### Step 1: Open Terminal in cPanel
1. Log into your cPanel account.
2. In the **Advanced** section, click **Terminal** (or connect via SSH).

### Step 2: Navigate to the WhatsApp service directory
```bash
cd ~/public_html/whatsapp-service
```
*(If installed in a subfolder or another directory, adjust the path accordingly, e.g. `cd ~/public_html/invoicing/whatsapp-service`)*.

### Step 3: Install Node.js dependencies
```bash
npm install --production
```
This installs `@whiskeysockets/baileys`, `express`, `cors`, `qrcode`, and `pino`.

### Step 4: Start the service with PM2
```bash
# Start the process in the background
npx pm2 start server.js --name "whatsapp-agent"

# Save the PM2 process list so it persists across server restarts
npx pm2 save
```

### Useful PM2 Management Commands:
```bash
# Check status
npx pm2 status

# View live logs
npx pm2 logs whatsapp-agent

# Restart service
npx pm2 restart whatsapp-agent

# Stop service
npx pm2 stop whatsapp-agent
```

---

## Method 2: Using cPanel "Setup Node.js App" (Graphical UI, No SSH Required)

If your cPanel hosting does not offer Terminal / SSH access:

1. In cPanel, navigate to the **Software** section and click **Setup Node.js App**.
2. Click **Create Application**.
3. Fill in the details:
   - **Node.js version:** Choose **18.x** or **20.x**.
   - **Application mode:** `Production`
   - **Application root:** `public_html/whatsapp-service`
   - **Application URL:** e.g. `whatsapp-api`
   - **Application startup file:** `server.js`
4. Click **Create** (top right).
5. Once created, look for **Detected configuration files** showing `package.json`. Click **Run NPM Install**.
6. Under **Environment variables**, you can add:
   - `WHATSAPP_PORT` = `3001`
   - `PHP_BACKEND_URL` = `https://yourdomain.com`
7. Click **Restart Application**.

---

## Method 3: Keep-Alive Watchdog via cPanel Cron Job

To guarantee that the WhatsApp service never stays down if the host server reboots or restarts:

1. In cPanel, open **Cron Jobs**.
2. Set Common Settings to: **Once Every 10 Minutes** (`*/10 * * * *`).
3. Enter the command (replace `username` with your cPanel username):
   ```bash
   pgrep -f "whatsapp-service/server.js" > /dev/null || (cd /home/username/public_html/whatsapp-service && nohup node server.js >> whatsapp.log 2>&1 &)
   ```
4. Click **Add New Cron Job**.

---

## Pairing WhatsApp with Your Phone

1. Open your hosted Invoicing application: `https://yourdomain.com/dashboard`.
2. Navigate to **WhatsApp / Communication Settings**.
3. You will see a QR code generated on screen.
4. On your mobile phone:
   - Open WhatsApp -> **Linked Devices** -> **Link a Device**.
   - Scan the QR code displayed in the dashboard.
5. Within 5–10 seconds, the dashboard will show **Status: Connected** with your phone number.
6. The auto-responder sales agent will immediately start handling inquiries!
