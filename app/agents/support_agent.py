from typing import List, Dict, Optional


class SupportAgent:
    def __init__(self):
        self.context = {
            "company": "DATAPOINT Technologies",
            "address": "G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh",
            "phone": "+92-316-7788990",
            "email": "info@datapointtechnology.com",
            "sales_email": "sales@datapointtechnology.com",
            "website": "http://datapointtechnology.com",
            "hours": "Monday–Saturday, 9:00 AM – 7:00 PM",
        }
        self.knowledge_base = self._load_knowledge_base()

    def _load_knowledge_base(self) -> Dict:
        return {
            "greeting": {
                "keywords": ["hi", "hello", "hey", "salam", "assalam", "good morning", "good afternoon", "good evening", "start", "help"],
                "response": (
                    "👋 Assalam-o-Alaikum! Welcome to DATAPOINT Technologies Support.\n\n"
                    "I can help you with:\n"
                    "• 🖥️ Products & Pricing (Laptops, Desktops, Networking, CCTV, Accessories)\n"
                    "• 🛠️ Services (Software Dev, Networking, Web Development, IT Support)\n"
                    "• 📄 Invoices, Estimates & Quotations\n"
                    "• 💳 Payments & Tax Information\n"
                    "• 🚚 Delivery & Warranty Policies\n"
                    "• 📞 Contact & Support\n\n"
                    "Just type your question and I'll guide you!"
                )
            },

            "laptops": {
                "keywords": ["laptop", "laptops", "notebook", "portable computer", "dell laptop", "hp laptop", "lenovo laptop", "macbook"],
                "response": (
                    "💻 **Laptops — Available Brands & Categories:**\n\n"
                    "🔹 **Dell** — Inspiron, Latitude, XPS series\n"
                    "   • Core i3 (Basic): PKR 55,000–75,000\n"
                    "   • Core i5 (Mid-range): PKR 80,000–120,000\n"
                    "   • Core i7 (High-end): PKR 130,000–200,000+\n\n"
                    "🔹 **HP** — 15s, ProBook, EliteBook series\n"
                    "   • Core i3: PKR 52,000–70,000\n"
                    "   • Core i5: PKR 78,000–115,000\n"
                    "   • Core i7: PKR 125,000–190,000+\n\n"
                    "🔹 **Lenovo** — IdeaPad, ThinkPad series\n"
                    "   • Core i3: PKR 50,000–68,000\n"
                    "   • Core i5: PKR 75,000–110,000\n"
                    "   • Core i7: PKR 120,000–185,000+\n\n"
                    "✅ All laptops include manufacturer warranty.\n"
                    "📦 Delivery: 2–5 working days.\n"
                    "📞 For exact pricing & availability, contact: +92-316-7788990"
                )
            },

            "desktops": {
                "keywords": ["desktop", "pc", "computer", "tower", "workstation", "all in one", "aio"],
                "response": (
                    "🖥️ **Desktop Computers & Workstations:**\n\n"
                    "🔹 **Assembled PCs (Custom Build)**\n"
                    "   • Basic Office PC (Core i3, 8GB RAM, 256GB SSD): PKR 45,000–55,000\n"
                    "   • Mid-range PC (Core i5, 16GB RAM, 512GB SSD): PKR 65,000–85,000\n"
                    "   • High-end Workstation (Core i7/i9, 32GB RAM, 1TB SSD): PKR 110,000–180,000\n\n"
                    "🔹 **Branded Desktops**\n"
                    "   • Dell OptiPlex / HP ProDesk: PKR 70,000–130,000\n"
                    "   • All-in-One PCs: PKR 85,000–150,000\n\n"
                    "✅ Custom builds available as per your requirements.\n"
                    "📞 Contact sales for bulk orders & corporate pricing."
                )
            },

            "networking": {
                "keywords": ["network", "networking", "router", "switch", "wifi", "wireless", "access point", "firewall", "mikrotik", "cisco", "tp-link", "ubiquiti", "lan", "wan", "internet"],
                "response": (
                    "🌐 **Networking Solutions & Equipment:**\n\n"
                    "🔹 **Routers & Firewalls**\n"
                    "   • TP-Link Home/Office Routers: PKR 3,500–15,000\n"
                    "   • MikroTik Routers (RB series): PKR 8,000–45,000\n"
                    "   • Cisco Routers: PKR 25,000–150,000+\n\n"
                    "🔹 **Switches**\n"
                    "   • Unmanaged Switches (8/16/24 port): PKR 4,000–18,000\n"
                    "   • Managed Switches: PKR 15,000–80,000\n\n"
                    "🔹 **Wireless Access Points**\n"
                    "   • TP-Link EAP series: PKR 8,000–25,000\n"
                    "   • Ubiquiti UniFi: PKR 18,000–55,000\n\n"
                    "🔹 **Network Services**\n"
                    "   • Network design & installation\n"
                    "   • Structured cabling (CAT6/Fiber)\n"
                    "   • VPN setup & configuration\n"
                    "   • Network troubleshooting & maintenance\n\n"
                    "📞 For site survey & custom network design: +92-316-7788990"
                )
            },

            "cctv": {
                "keywords": ["cctv", "camera", "security camera", "surveillance", "dvr", "nvr", "hikvision", "dahua", "ip camera", "security system"],
                "response": (
                    "📷 **CCTV & Security Systems:**\n\n"
                    "🔹 **Camera Types**\n"
                    "   • Analog HD Cameras (2MP/5MP): PKR 3,500–8,000 each\n"
                    "   • IP Network Cameras (4MP/8MP): PKR 8,000–20,000 each\n"
                    "   • PTZ Cameras: PKR 25,000–80,000 each\n\n"
                    "🔹 **Recorders**\n"
                    "   • DVR (4/8/16 channel): PKR 12,000–35,000\n"
                    "   • NVR (4/8/16 channel): PKR 15,000–45,000\n\n"
                    "🔹 **Complete Packages**\n"
                    "   • 4-Camera Home Package: PKR 35,000–55,000\n"
                    "   • 8-Camera Office Package: PKR 65,000–95,000\n"
                    "   • 16-Camera Enterprise Package: PKR 120,000–200,000\n\n"
                    "✅ Brands: Hikvision, Dahua, CP Plus\n"
                    "✅ Includes installation, configuration & training\n"
                    "📞 Free site survey available — call +92-316-7788990"
                )
            },

            "accessories": {
                "keywords": ["accessories", "keyboard", "mouse", "monitor", "headset", "printer", "scanner", "ups", "hard drive", "ssd", "ram", "pendrive", "usb", "cable", "webcam"],
                "response": (
                    "🖱️ **IT Accessories & Peripherals:**\n\n"
                    "🔹 **Input Devices**\n"
                    "   • Keyboards: PKR 800–5,000\n"
                    "   • Mouse: PKR 500–4,000\n"
                    "   • Webcams: PKR 3,000–12,000\n\n"
                    "🔹 **Displays**\n"
                    "   • Monitors 19\"–24\" (FHD): PKR 18,000–40,000\n"
                    "   • Monitors 27\"+ (QHD/4K): PKR 45,000–90,000\n\n"
                    "🔹 **Storage**\n"
                    "   • SSD (256GB–1TB): PKR 5,000–18,000\n"
                    "   • HDD (1TB–4TB): PKR 8,000–22,000\n"
                    "   • USB Flash Drives: PKR 500–3,000\n\n"
                    "🔹 **Power**\n"
                    "   • UPS (600VA–2000VA): PKR 8,000–25,000\n\n"
                    "🔹 **Printers & Scanners**\n"
                    "   • Inkjet Printers: PKR 12,000–35,000\n"
                    "   • Laser Printers: PKR 25,000–70,000\n\n"
                    "📞 Contact us for bulk pricing & availability."
                )
            },

            "software_services": {
                "keywords": ["software", "development", "app", "application", "website", "web", "system", "erp", "crm", "custom", "mobile app", "android", "ios"],
                "response": (
                    "💻 **Software Development Services:**\n\n"
                    "🔹 **Web Development**\n"
                    "   • Business websites: PKR 25,000–80,000\n"
                    "   • E-commerce stores: PKR 50,000–150,000\n"
                    "   • Web applications & portals: PKR 80,000–500,000+\n\n"
                    "🔹 **Custom Software**\n"
                    "   • Invoicing & Accounting systems\n"
                    "   • Inventory management systems\n"
                    "   • ERP & CRM solutions\n"
                    "   • POS systems\n\n"
                    "🔹 **Mobile Applications**\n"
                    "   • Android & iOS apps: PKR 80,000–300,000+\n\n"
                    "🔹 **Technology Stack**\n"
                    "   • Python, FastAPI, Django, React, Flutter\n\n"
                    "✅ Free consultation & requirement analysis\n"
                    "✅ Post-delivery support & maintenance available\n"
                    "📧 Share your requirements: sales@datapointtechnology.com"
                )
            },

            "it_support": {
                "keywords": ["support", "repair", "maintenance", "troubleshoot", "fix", "broken", "not working", "slow", "virus", "format", "install", "windows", "amc"],
                "response": (
                    "🛠️ **IT Support & Maintenance Services:**\n\n"
                    "🔹 **On-site Support**\n"
                    "   • Hardware repair & replacement\n"
                    "   • OS installation & configuration (Windows/Linux)\n"
                    "   • Software installation & troubleshooting\n"
                    "   • Virus removal & system cleanup\n\n"
                    "🔹 **Remote Support**\n"
                    "   • Remote desktop assistance\n"
                    "   • Software configuration\n"
                    "   • Network troubleshooting\n\n"
                    "🔹 **AMC (Annual Maintenance Contract)**\n"
                    "   • Scheduled preventive maintenance\n"
                    "   • Priority support response\n"
                    "   • Discounted repair rates\n"
                    "   • Monthly system health reports\n\n"
                    "📞 For urgent support: +92-316-7788990\n"
                    "📧 Log a support ticket: info@datapointtechnology.com"
                )
            },

            "payment_methods": {
                "keywords": ["payment", "pay", "bank", "transfer", "jazzcash", "easypaisa", "cheque", "cash", "how to pay", "payment method"],
                "response": (
                    "💳 **Payment Methods:**\n\n"
                    "We accept the following:\n"
                    "1. 🏦 Bank Transfer (preferred for large orders)\n"
                    "2. 📝 Crossed Cheque (payable to DATAPOINT Technologies)\n"
                    "3. 💵 Cash (in-office payments)\n"
                    "4. 📱 JazzCash\n"
                    "5. 📱 Easypaisa\n\n"
                    "📌 Bank details are printed on every invoice.\n"
                    "📌 Always use your Invoice # as payment reference.\n"
                    "📌 Payment terms: Net 30 days (corporate clients).\n"
                    "📌 Late payment fee: 2% per month on overdue amounts."
                )
            },

            "tax_query": {
                "keywords": ["tax", "gst", "sales tax", "ntn", "strn", "withholding", "wht", "fed", "fbr", "tax invoice"],
                "response": (
                    "🧾 **Tax Information:**\n\n"
                    "• All invoices include **17% General Sales Tax (GST)** as per Pakistan Sales Tax Act 1990.\n"
                    "• Our **NTN** and **STRN** are printed on all tax invoices.\n"
                    "• **Withholding Tax (WHT)** at 4% is applied where applicable as per FBR rules.\n"
                    "• **FED (Federal Excise Duty)** at 5% applied on applicable services.\n"
                    "• Tax invoices are issued for all registered business clients.\n\n"
                    "📌 For tax exemption certificates or special tax treatment, contact our accounts team."
                )
            },

            "invoice_query": {
                "keywords": ["invoice", "bill", "receipt", "statement", "invoice status", "invoice copy"],
                "response": (
                    "📄 **Invoice Information:**\n\n"
                    "• Invoices are generated after approval of estimates/quotations.\n"
                    "• You will receive your invoice via **email** and **WhatsApp**.\n"
                    "• PDF copies are always available on request.\n"
                    "• Invoice statuses: Draft → Sent → Partially Paid → Paid\n\n"
                    "📌 To check your invoice status, provide your Invoice # to our accounts team.\n"
                    "📌 For duplicate invoice copies: sales@datapointtechnology.com\n"
                    "📌 Payment due date is printed on every invoice."
                )
            },

            "estimate_query": {
                "keywords": ["estimate", "quotation", "quote", "proposal", "price list", "how much", "cost", "rate"],
                "response": (
                    "📋 **Estimates & Quotations:**\n\n"
                    "• We provide **free estimates** for all products and services.\n"
                    "• Estimates are valid for **15 days** from issue date.\n"
                    "• Once approved by client, estimates are converted to sales invoices.\n"
                    "• Estimates include itemized pricing with GST breakdown.\n\n"
                    "📌 To request a quotation:\n"
                    "   1. Tell us what products/services you need\n"
                    "   2. Specify quantities\n"
                    "   3. We'll prepare and send the estimate within 24 hours\n\n"
                    "📧 Email: sales@datapointtechnology.com\n"
                    "📞 Call: +92-316-7788990"
                )
            },

            "delivery_query": {
                "keywords": ["delivery", "shipping", "dispatch", "when", "how long", "timeline", "days"],
                "response": (
                    "🚚 **Delivery Information:**\n\n"
                    "🔹 **Hardware & Products**\n"
                    "   • In-stock items: 1–3 working days\n"
                    "   • Import/order items: 7–14 working days\n"
                    "   • Bulk orders: Timeline discussed at order time\n\n"
                    "🔹 **Services**\n"
                    "   • IT Support (on-site): Same day or next day\n"
                    "   • Network installation: 1–5 days depending on scope\n"
                    "   • CCTV installation: 1–3 days\n"
                    "   • Software projects: As per agreed project timeline\n\n"
                    "📌 You will be notified via SMS/WhatsApp once your order is dispatched.\n"
                    "📌 Delivery charges may apply outside Hyderabad."
                )
            },

            "warranty_return": {
                "keywords": ["warranty", "return", "refund", "replace", "replacement", "defective", "damaged", "broken", "guarantee"],
                "response": (
                    "🔄 **Warranty & Return Policy:**\n\n"
                    "🔹 **Hardware Products**\n"
                    "   • Dead on arrival (DOA): Replacement within 48 hours\n"
                    "   • Defective items: 7-day return/replacement policy\n"
                    "   • Manufacturer warranty: As per brand (1–3 years)\n\n"
                    "🔹 **Assembled PCs & Custom Builds**\n"
                    "   • 6-month local warranty on assembly & components\n\n"
                    "🔹 **Software & Services**\n"
                    "   • Non-refundable once delivered\n"
                    "   • Bug fixes & support included post-delivery\n\n"
                    "🔹 **CCTV & Networking Equipment**\n"
                    "   • 1-year warranty on equipment\n"
                    "   • Installation warranty: 3 months\n\n"
                    "📌 To initiate a return, contact us with your Invoice # and issue description.\n"
                    "📞 +92-316-7788990"
                )
            },

            "contact_query": {
                "keywords": ["contact", "address", "location", "office", "reach", "call", "visit", "where", "find"],
                "response": (
                    "📞 **Contact DATAPOINT Technologies:**\n\n"
                    "📍 G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh\n"
                    "📞 +92-316-7788990\n"
                    "📧 info@datapointtechnology.com\n"
                    "📧 sales@datapointtechnology.com (for orders & quotes)\n"
                    "🌐 http://datapointtechnology.com\n\n"
                    "🕐 Business Hours: Monday–Saturday, 9:00 AM – 7:00 PM\n\n"
                    "📌 For urgent support, call directly.\n"
                    "📌 For quotes & orders, email sales team."
                )
            },

            "bulk_corporate": {
                "keywords": ["bulk", "corporate", "company", "business", "wholesale", "large order", "tender", "government", "ngo", "organization"],
                "response": (
                    "🏢 **Corporate & Bulk Orders:**\n\n"
                    "We offer special pricing for corporate and bulk purchases:\n\n"
                    "✅ Volume discounts on hardware orders (5+ units)\n"
                    "✅ Dedicated account manager for corporate clients\n"
                    "✅ Credit terms available (Net 30/60 days)\n"
                    "✅ Customized AMC packages\n"
                    "✅ Government & NGO procurement support\n"
                    "✅ Tender documentation assistance\n\n"
                    "📧 Corporate inquiries: sales@datapointtechnology.com\n"
                    "📞 +92-316-7788990"
                )
            },

            "about": {
                "keywords": ["about", "who are you", "company", "datapoint", "background", "experience", "history", "profile"],
                "response": (
                    "🏢 **About DATAPOINT Technologies:**\n\n"
                    "DATAPOINT Technologies is a leading IT solutions provider based in Hyderabad, Sindh.\n\n"
                    "🔹 **What We Do:**\n"
                    "   • Supply of computers, laptops & IT hardware\n"
                    "   • Networking & infrastructure solutions\n"
                    "   • CCTV & security systems\n"
                    "   • Custom software & web development\n"
                    "   • IT support & maintenance services\n\n"
                    "🔹 **Why Choose Us:**\n"
                    "   • Authorized dealers for major brands\n"
                    "   • Experienced technical team\n"
                    "   • After-sales support & AMC\n"
                    "   • Competitive pricing\n"
                    "   • Serving businesses across Sindh\n\n"
                    "📞 +92-316-7788990 | 🌐 datapointtechnology.com"
                )
            }
        }

    def get_response(self, user_message: str) -> str:
        msg = user_message.lower().strip()
        if not msg:
            return "Please type your query. I'm here to help!"

        best_match = None
        max_score = 0
        for category, data in self.knowledge_base.items():
            score = sum(1 for kw in data["keywords"] if kw in msg)
            if score > max_score:
                max_score = score
                best_match = data["response"]

        if best_match:
            return best_match

        return (
            "Thank you for your query! I'm not sure I have a specific answer for that.\n\n"
            "You can ask me about:\n"
            "• Laptops, Desktops, Networking, CCTV, Accessories\n"
            "• Software Development, IT Support, Web Development\n"
            "• Invoices, Payments, Estimates, Delivery, Warranty\n\n"
            f"Or contact us directly:\n"
            f"📞 {self.context['phone']}\n"
            f"📧 {self.context['email']}"
        )


support_agent = SupportAgent()


class VoiceQuotationAgent:
    def __init__(self):
        self.products = []

    def process_voice_input(self, audio_text: str) -> Dict:
        text = audio_text.lower()
        intent = self._detect_intent(text)
        return self._generate_response(intent, text)

    def _detect_intent(self, text: str) -> str:
        if any(w in text for w in ["quote", "quotation", "estimate", "price", "cost", "rate", "how much"]):
            return "quotation"
        elif any(w in text for w in ["buy", "order", "purchase", "want", "need", "get"]):
            return "order"
        elif any(w in text for w in ["invoice", "bill", "payment", "paid", "due"]):
            return "billing"
        elif any(w in text for w in ["hello", "hi", "salam", "help", "start"]):
            return "greeting"
        elif any(w in text for w in ["laptop", "desktop", "pc", "computer", "router", "switch", "cctv", "camera", "printer", "ups"]):
            return "product_inquiry"
        elif any(w in text for w in ["support", "repair", "fix", "broken", "not working", "issue", "problem"]):
            return "support"
        else:
            return "general"

    def _generate_response(self, intent: str, text: str) -> Dict:
        responses = {
            "quotation": {
                "type": "quotation_request",
                "message": (
                    "I'd be happy to prepare a quotation for you! 📋\n\n"
                    "Please tell me:\n"
                    "1. What products or services do you need?\n"
                    "2. Quantities required\n"
                    "3. Any specific brand or model preference\n\n"
                    "We'll prepare a detailed estimate with GST breakdown within 24 hours."
                ),
                "action": "collect_details"
            },
            "order": {
                "type": "order_request",
                "message": (
                    "Great! Let's get your order started. 🛒\n\n"
                    "Please specify:\n"
                    "1. Product name & model\n"
                    "2. Quantity\n"
                    "3. Delivery address\n\n"
                    "We'll confirm availability and send you a proforma invoice."
                ),
                "action": "create_order"
            },
            "billing": {
                "type": "billing_inquiry",
                "message": (
                    "For billing inquiries, I can help! 💳\n\n"
                    "Please provide:\n"
                    "• Your Invoice # (e.g. INV-202501-00001)\n"
                    "• Or your company/client name\n\n"
                    "Our accounts team will verify and update you on payment status."
                ),
                "action": "check_billing"
            },
            "greeting": {
                "type": "greeting",
                "message": (
                    "Assalam-o-Alaikum! 👋 Welcome to DATAPOINT Technologies.\n\n"
                    "I'm your assistant. I can help you with:\n"
                    "• Product pricing & availability\n"
                    "• Quotations & estimates\n"
                    "• Order placement\n"
                    "• Billing & invoice queries\n"
                    "• Technical support\n\n"
                    "What can I help you with today?"
                ),
                "action": "none"
            },
            "product_inquiry": {
                "type": "product_inquiry",
                "message": (
                    "We carry a wide range of IT products! 🖥️\n\n"
                    "• Laptops (Dell, HP, Lenovo) — from PKR 50,000\n"
                    "• Desktop PCs & Workstations — from PKR 45,000\n"
                    "• Networking (MikroTik, Cisco, TP-Link, Ubiquiti)\n"
                    "• CCTV Systems (Hikvision, Dahua)\n"
                    "• Printers, UPS, Accessories\n\n"
                    "Tell me which product you're interested in for detailed pricing!"
                ),
                "action": "show_products"
            },
            "support": {
                "type": "support_request",
                "message": (
                    "I'm sorry to hear you're having an issue. 🛠️\n\n"
                    "For technical support:\n"
                    "📞 Call us: +92-316-7788990\n"
                    "📧 Email: info@datapointtechnology.com\n\n"
                    "Please describe your issue and we'll assign a technician.\n"
                    "On-site support available in Hyderabad & surrounding areas."
                ),
                "action": "log_support"
            },
            "general": {
                "type": "general",
                "message": (
                    "I'm here to help! You can ask me about:\n"
                    "• Products & pricing\n"
                    "• Quotations & orders\n"
                    "• Invoices & payments\n"
                    "• Technical support\n\n"
                    "Or call us directly at +92-316-7788990."
                ),
                "action": "clarify"
            }
        }
        return responses.get(intent, responses["general"])

    def generate_quotation_from_text(self, orders: List[Dict]) -> Dict:
        items = []
        subtotal = 0
        for order in orders:
            qty = order.get("quantity", 1)
            price = order.get("estimated_price", 0)
            total = qty * price
            items.append({
                "description": order.get("product_name", "Item"),
                "quantity": qty,
                "unit_price": price,
                "total": total
            })
            subtotal += total
        tax = subtotal * 0.17
        grand_total = subtotal + tax
        return {
            "items": items,
            "subtotal": round(subtotal, 2),
            "tax": round(tax, 2),
            "total": round(grand_total, 2),
            "message": "Here's your estimated quotation. Would you like to approve this and convert to an invoice?"
        }


voice_agent = VoiceQuotationAgent()
