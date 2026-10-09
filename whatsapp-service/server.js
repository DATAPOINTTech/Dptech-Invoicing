const express = require('express');
const cors = require('cors');
const qrcode = require('qrcode');
const pino = require('pino');
const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');

// Simple zero-dependency .env loader
function loadEnvFile(envPath) {
    if (!fs.existsSync(envPath)) return;
    try {
        const content = fs.readFileSync(envPath, 'utf8');
        content.split(/\r?\n/).forEach(line => {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) return;
            const [key, ...rest] = trimmed.split('=');
            const k = key.trim();
            let val = rest.join('=').trim();
            if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
                val = val.slice(1, -1);
            }
            if (k && process.env[k] === undefined) {
                process.env[k] = val;
            }
        });
    } catch (e) {}
}

loadEnvFile(path.join(__dirname, '..', '.env'));
loadEnvFile(path.join(__dirname, '.env'));

// Ignore EPIPE errors on stdout/stderr if parent process closed the pipe
if (process.stdout && typeof process.stdout.on === 'function') {
    process.stdout.on('error', (err) => {
        if (err.code === 'EPIPE') return;
    });
}
if (process.stderr && typeof process.stderr.on === 'function') {
    process.stderr.on('error', (err) => {
        if (err.code === 'EPIPE') return;
    });
}
process.on('uncaughtException', (err) => {
    if (err && err.code === 'EPIPE') return;
    console.error('Uncaught Exception:', err);
});

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion
} = require('@whiskeysockets/baileys');

const app = express();
app.use(cors());
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

const PORT = process.env.WHATSAPP_PORT || process.env.PORT || 3001;
const AUTH_DIR = path.join(__dirname, 'auth_info');

// Ensure auth dir exists
if (!fs.existsSync(AUTH_DIR)) {
    fs.mkdirSync(AUTH_DIR, { recursive: true });
}

// Global state
let sock = null;
let connectionState = 'disconnected'; // 'disconnected' | 'connecting' | 'scan_qr' | 'connected'
let currentQR = null;
let currentQRDataUrl = null;
let connectedUser = null;
let salesAgentEnabled = true;
let connectionAttempts = 0;
const messageStore = new Map();
const msgRetryCounterCache = new Map();

const logger = pino({ level: 'silent' });

function cleanPhoneNumber(number) {
    if (!number) return '';
    let digits = String(number).replace(/\D+/g, '');
    if (digits.startsWith('00')) {
        digits = digits.substring(2);
    }
    if (digits.startsWith('0') && digits.length === 11) {
        // Pakistan 03xx xxx xxxx -> 923xx xxx xxxx
        digits = '92' + digits.substring(1);
    }
    return digits;
}

function formatJid(number) {
    const cleaned = cleanPhoneNumber(number);
    if (!cleaned) return null;
    return cleaned.includes('@') ? cleaned : `${cleaned}@s.whatsapp.net`;
}

function extractMessageContent(msg) {
    if (!msg || !msg.message) return { text: '', raw: null };
    let m = msg.message;

    // Recursively unwrap ephemeral or view-once wrappers
    if (m.ephemeralMessage?.message) m = m.ephemeralMessage.message;
    if (m.viewOnceMessage?.message) m = m.viewOnceMessage.message;
    if (m.viewOnceMessageV2?.message) m = m.viewOnceMessageV2.message;
    if (m.documentWithCaptionMessage?.message) m = m.documentWithCaptionMessage.message;

    const text = (
        m.conversation ||
        m.extendedTextMessage?.text ||
        m.imageMessage?.caption ||
        m.videoMessage?.caption ||
        m.documentMessage?.caption ||
        m.buttonsResponseMessage?.selectedButtonId ||
        m.buttonsResponseMessage?.selectedDisplayText ||
        m.templateButtonReplyMessage?.selectedId ||
        m.templateButtonReplyMessage?.selectedDisplayText ||
        m.listResponseMessage?.singleSelectReply?.selectedRowId ||
        m.listResponseMessage?.title ||
        m.interactiveResponseMessage?.body?.text ||
        ''
    ).trim();

    return { text, raw: m };
}

function cleanSessionFiles() {
    try {
        if (!fs.existsSync(AUTH_DIR)) return 0;
        const files = fs.readdirSync(AUTH_DIR);
        let count = 0;
        for (const file of files) {
            if (file.startsWith('session-') && file.endsWith('.json')) {
                fs.unlinkSync(path.join(AUTH_DIR, file));
                count++;
            }
        }
        if (count > 0) {
            console.log(`[WhatsApp Auth] Cleaned ${count} stale session files to prevent Bad MAC errors.`);
        }
        return count;
    } catch (e) {
        console.error('[WhatsApp Auth] Error cleaning session files:', e);
        return 0;
    }
}

async function callPhpAgentBridge(senderPhone, senderName, messageText) {
    return new Promise((resolve) => {
        const postData = JSON.stringify({
            sender_phone: senderPhone,
            sender_name: senderName,
            message: messageText
        });

        const backendBase = process.env.PHP_BACKEND_URL || process.env.APP_URL || process.env.COMPANY_WEBSITE || 'http://127.0.0.1:8000';
        let parsedUrl;
        try {
            parsedUrl = new URL(backendBase);
        } catch (e) {
            parsedUrl = new URL('http://127.0.0.1:8000');
        }

        const isHttps = parsedUrl.protocol === 'https:';
        const client = isHttps ? https : http;
        const basePath = parsedUrl.pathname.replace(/\/$/, '');

        const options = {
            hostname: parsedUrl.hostname,
            port: parsedUrl.port || (isHttps ? 443 : 80),
            path: (basePath || '') + '/api/whatsapp/agent-bridge',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData)
            },
            timeout: 15000
        };

        const req = client.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                try {
                    const parsed = JSON.parse(data);
                    resolve(parsed);
                } catch (e) {
                    console.error('[Bridge] Failed to parse PHP response:', e, data);
                    resolve(null);
                }
            });
        });

        req.on('error', (err) => {
            console.error('[Bridge] Request error connecting to PHP backend:', err.message);
            resolve(null);
        });

        req.on('timeout', () => {
            req.destroy();
            resolve(null);
        });

        req.write(postData);
        req.end();
    });
}

async function startWhatsApp() {
    try {
        connectionState = 'connecting';
        const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
        const { version, isLatest } = await fetchLatestBaileysVersion().catch(() => ({ version: [2, 3000, 1015901307], isLatest: true }));

        console.log(`Starting Baileys WhatsApp client (v${version.join('.')}, latest: ${isLatest})...`);

        sock = makeWASocket({
            version,
            logger,
            printQRInTerminal: false,
            auth: state,
            browser: ['DPTech Invoicing', 'Chrome', '1.0.0'],
            generateHighQualityLinkPreview: true,
            syncFullHistory: false,
            msgRetryCounterCache,
            getMessage: async (key) => {
                return messageStore.get(key.id) || undefined;
            }
        });

        sock.ev.on('creds.update', saveCreds);

        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                currentQR = qr;
                try {
                    currentQRDataUrl = await qrcode.toDataURL(qr, { margin: 2, scale: 7 });
                    connectionState = 'scan_qr';
                    console.log('WhatsApp QR code generated and ready for scanning.');
                } catch (err) {
                    console.error('Failed to generate QR data URL:', err);
                }
            }

            if (connection === 'close') {
                const statusCode = lastDisconnect?.error?.output?.statusCode;
                const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
                console.log(`WhatsApp connection closed (code: ${statusCode}, reconnect: ${shouldReconnect}).`);

                connectionState = 'disconnected';
                currentQR = null;
                currentQRDataUrl = null;
                connectedUser = null;

                if (statusCode === DisconnectReason.loggedOut) {
                    console.log('User logged out. Cleaning up auth session...');
                    try {
                        fs.rmSync(AUTH_DIR, { recursive: true, force: true });
                        fs.mkdirSync(AUTH_DIR, { recursive: true });
                    } catch (e) {
                        console.error('Error clearing auth dir:', e);
                    }
                    setTimeout(startWhatsApp, 2000);
                } else if (shouldReconnect) {
                    connectionAttempts++;
                    const delay = Math.min(connectionAttempts * 2000, 10000);
                    console.log(`Reconnecting WhatsApp in ${delay / 1000}s...`);
                    setTimeout(startWhatsApp, delay);
                }
            } else if (connection === 'open') {
                connectionAttempts = 0;
                connectionState = 'connected';
                currentQR = null;
                currentQRDataUrl = null;
                
                const userJid = sock.user?.id || '';
                const userPhone = userJid.split(':')[0] || userJid.split('@')[0];
                connectedUser = {
                    id: userJid,
                    phone: userPhone,
                    name: sock.user?.name || 'DATAPOINT Technologies'
                };
                console.log(`✓ WhatsApp Connected successfully as +${userPhone} (${connectedUser.name})`);
            }
        });

        // Sales Agent Auto-responder for incoming messages connected to AI Agent & DB
        sock.ev.on('messages.upsert', async ({ messages, type }) => {
            if (type !== 'notify' || !salesAgentEnabled) return;

            for (const msg of messages) {
                try {
                    if (msg.key?.id && msg.message) {
                        messageStore.set(msg.key.id, msg.message);
                        if (messageStore.size > 1000) {
                            const firstKey = messageStore.keys().next().value;
                            messageStore.delete(firstKey);
                        }
                    }

                    // Ignore messages sent by ourselves or group chats or status broadcasts
                    if (!msg.message || msg.key.fromMe) continue;
                    const remoteJid = msg.key.remoteJid;
                    if (!remoteJid || remoteJid.endsWith('@g.us') || remoteJid === 'status@broadcast') continue;

                    // Extract text message content with full unwrap
                    const { text } = extractMessageContent(msg);
                    if (!text) continue;

                    const senderPhone = cleanPhoneNumber(remoteJid);
                    const senderName = msg.pushName || '';

                    console.log(`[WhatsApp Agent] Inbound from +${senderPhone} (${senderName}): "${text}"`);

                    // Indicate typing presence
                    try {
                        await sock.sendPresenceUpdate('composing', remoteJid);
                    } catch (e) {}

                    // Query the AI Support Agent PHP bridge
                    const bridgeResult = await callPhpAgentBridge(senderPhone, senderName, text);

                    // Pause presence
                    try {
                        await sock.sendPresenceUpdate('paused', remoteJid);
                    } catch (e) {}

                    if (bridgeResult && bridgeResult.success) {
                        if (bridgeResult.reply_type === 'document' && bridgeResult.file_base64) {
                            const docBuffer = Buffer.from(bridgeResult.file_base64, 'base64');
                            const cleanFilename = (bridgeResult.filename || 'Document.pdf').replace(/[^\w\.\-]/g, '_');

                            console.log(`[WhatsApp Agent] Dispatching document (${cleanFilename}, ${docBuffer.length} bytes) to ${remoteJid}...`);
                            await sock.sendMessage(remoteJid, {
                                document: docBuffer,
                                mimetype: 'application/pdf',
                                fileName: cleanFilename,
                                caption: bridgeResult.caption || ''
                            }, { quoted: msg });
                            console.log(`[WhatsApp Agent] Document successfully sent to ${remoteJid}`);
                        } else if (bridgeResult.reply_text) {
                            await sock.sendMessage(remoteJid, { text: bridgeResult.reply_text }, { quoted: msg });
                            console.log(`[WhatsApp Agent] Text reply sent to ${remoteJid}`);
                        }
                    } else {
                        // Resilient fallback menu if bridge is unreachable
                        const fallback = `👋 *DATAPOINT Technologies - Automated Sales Assistant*\n\n`
                            + `How can we help you today?\n`
                            + `1️⃣ Reply *1* for Products & Live Rates\n`
                            + `2️⃣ Reply *2* to Request Quotation\n`
                            + `3️⃣ Reply with your *Invoice #* for PDF copy\n`
                            + `4️⃣ Reply *4* to contact sales directly\n\n`
                            + `📞 Helpline: +92 316 7788990`;
                        await sock.sendMessage(remoteJid, { text: fallback }, { quoted: msg });
                    }
                } catch (e) {
                    console.error('[WhatsApp Agent] Error handling incoming message:', e);
                }
            }
        });

    } catch (err) {
        console.error('Error starting WhatsApp Baileys socket:', err);
        connectionState = 'disconnected';
        setTimeout(startWhatsApp, 5000);
    }
}

// ---------------- REST API ROUTES ----------------

// 1. Connection Status & QR code
app.get('/status', (req, res) => {
    res.json({
        success: true,
        state: connectionState, // 'disconnected' | 'connecting' | 'scan_qr' | 'connected'
        qr: currentQRDataUrl,
        user: connectedUser,
        salesAgentEnabled: salesAgentEnabled
    });
});

// 2. Raw QR Code image
app.get('/qr', (req, res) => {
    if (connectionState === 'connected') {
        return res.status(200).json({ success: true, message: 'Already connected', state: 'connected' });
    }
    if (!currentQRDataUrl) {
        return res.status(404).json({ success: false, message: 'QR code not ready or not generated yet', state: connectionState });
    }
    res.json({ success: true, qr: currentQRDataUrl, state: connectionState });
});

// 3. Disconnect / Unlink account
app.post('/disconnect', async (req, res) => {
    try {
        console.log('Disconnecting WhatsApp account requested...');
        if (sock) {
            try {
                await sock.logout();
            } catch (e) {
                console.log('Socket logout error (ignoring):', e.message);
            }
            try {
                sock.end(undefined);
            } catch (e) {}
        }
        try {
            fs.rmSync(AUTH_DIR, { recursive: true, force: true });
            fs.mkdirSync(AUTH_DIR, { recursive: true });
        } catch (e) {}

        connectionState = 'disconnected';
        currentQR = null;
        currentQRDataUrl = null;
        connectedUser = null;

        // Restart to produce a fresh QR code
        setTimeout(startWhatsApp, 1000);

        res.json({ success: true, message: 'WhatsApp account disconnected. Refreshing QR code...' });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

// 4. Toggle Sales Agent auto-responder
app.post('/toggle-agent', (req, res) => {
    if (typeof req.body.enabled === 'boolean') {
        salesAgentEnabled = req.body.enabled;
    } else {
        salesAgentEnabled = !salesAgentEnabled;
    }
    console.log(`[WhatsApp Agent] Sales agent toggled: ${salesAgentEnabled ? 'ON' : 'OFF'}`);
    res.json({ success: true, salesAgentEnabled });
});

// 5. Send Text Message
app.post('/send-message', async (req, res) => {
    try {
        if (connectionState !== 'connected' || !sock) {
            return res.status(400).json({ success: false, error: 'WhatsApp is not connected. Please scan the QR code first.' });
        }

        const { to, message } = req.body;
        if (!to || !message) {
            return res.status(422).json({ success: false, error: 'Missing recipient "to" or "message" text.' });
        }

        const jid = formatJid(to);
        if (!jid) {
            return res.status(422).json({ success: false, error: 'Invalid recipient phone number.' });
        }

        console.log(`Sending WhatsApp text message to ${jid}...`);
        const sent = await sock.sendMessage(jid, { text: message });
        res.json({ success: true, messageId: sent?.key?.id || null });
    } catch (err) {
        console.error('Error sending WhatsApp message:', err);
        res.status(500).json({ success: false, error: err.message });
    }
});

// 6. Send Document / PDF (Invoice or Quotation)
app.post('/send-document', async (req, res) => {
    try {
        if (connectionState !== 'connected' || !sock) {
            return res.status(400).json({ success: false, error: 'WhatsApp is not connected. Please link your WhatsApp account by scanning the QR code on the main page.' });
        }

        const { to, caption, filename, fileBase64, mimeType } = req.body;
        if (!to || !fileBase64) {
            return res.status(422).json({ success: false, error: 'Missing "to" phone number or "fileBase64" document data.' });
        }

        const jid = formatJid(to);
        if (!jid) {
            return res.status(422).json({ success: false, error: 'Invalid recipient phone number.' });
        }

        const cleanFilename = (filename || 'Document.pdf').replace(/[^\w\.\-]/g, '_');
        const buffer = Buffer.from(fileBase64, 'base64');
        const docMime = mimeType || 'application/pdf';

        console.log(`Sending WhatsApp document (${cleanFilename}, ${buffer.length} bytes) to ${jid}...`);

        const sent = await sock.sendMessage(jid, {
            document: buffer,
            mimetype: docMime,
            fileName: cleanFilename,
            caption: caption || ''
        });

        res.json({
            success: true,
            message: `Document sent successfully to +${cleanPhoneNumber(to)}`,
            messageId: sent?.key?.id || null
        });
    } catch (err) {
        console.error('Error sending WhatsApp document:', err);
        res.status(500).json({ success: false, error: err.message });
    }
});

// 7. Clean stale session files (fixes Bad MAC without logout)
app.post('/clean-sessions', (req, res) => {
    try {
        const count = cleanSessionFiles();
        res.json({ success: true, count, message: `Cleaned ${count} stale session files.` });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

// Start Express server and Baileys
const isNumericPort = typeof PORT === 'number' || /^\d+$/.test(String(PORT));
const serverCallback = () => {
    console.log(`========================================================`);
    console.log(`  DATAPOINT WhatsApp Baileys Service running on port/pipe ${PORT}`);
    console.log(`========================================================`);
    startWhatsApp();
};

if (isNumericPort) {
    app.listen(Number(PORT), '127.0.0.1', serverCallback);
} else {
    app.listen(PORT, serverCallback);
}

