from typing import List, Dict, Optional
from datetime import date
import re
from sqlalchemy.orm import Session


class SupportAgent:
    def __init__(self):
        self.context = {
            "company": "DATAPOINT Technologies",
            "address": "G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh",
            "phone": "+92-316-7788990",
            "email": "info@datapointtechnology.com",
            "sales_email": "sales@datapointtechnology.com",
            "website": "http://datapointtechnology.com",
            "hours": "Monday\u2013Saturday, 9:00 AM \u2013 7:00 PM",
        }
        self.knowledge_base = self._load_knowledge_base()
        self._db_cache = None

    def _load_knowledge_base(self) -> Dict:
        return {
            "greeting": {
                "keywords": ["hi", "hello", "hey", "salam", "assalam", "good morning", "good afternoon", "good evening", "start", "help"],
                "response": (
                    "\U0001f44b Assalam-o-Alaikum! Welcome to DATAPOINT Technologies Support.\n\n"
                    "I can help you with:\n"
                    "\u2022 \U0001f5a5\ufe0f Products & Pricing (Laptops, Desktops, Networking, CCTV, Accessories)\n"
                    "\u2022 \U0001f6e0\ufe0f Services (Software Dev, Networking, Web Development, IT Support)\n"
                    "\u2022 \U0001f4c4 Invoices, Estimates & Quotations\n"
                    "\u2022 \U0001f4b3 Payments & Tax Information\n"
                    "\u2022 \U0001f69a Delivery & Warranty Policies\n"
                    "\u2022 \U0001f4de Contact & Support\n\n"
                    "Just type your question and I'll guide you!"
                )
            },

            "payment_methods": {
                "keywords": ["payment", "pay", "bank", "transfer", "jazzcash", "easypaisa", "cheque", "cash", "how to pay", "payment method"],
                "response": (
                    "\U0001f4b3 **Payment Methods:**\n\n"
                    "We accept the following:\n"
                    "1. \U0001f3e6 Bank Transfer (preferred for large orders)\n"
                    "2. \U0001f4dd Crossed Cheque (payable to DATAPOINT Technologies)\n"
                    "3. \U0001f4b5 Cash (in-office payments)\n"
                    "4. \U0001f4f1 JazzCash\n"
                    "5. \U0001f4f1 Easypaisa\n\n"
                    "\U0001f4cc Bank details are printed on every invoice.\n"
                    "\U0001f4cc Always use your Invoice # as payment reference.\n"
                    "\U0001f4cc Payment terms: Net 30 days (corporate clients).\n"
                    "\U0001f4cc Late payment fee: 2% per month on overdue amounts."
                )
            },

            "tax_query": {
                "keywords": ["tax", "gst", "sales tax", "ntn", "strn", "withholding", "wht", "fed", "fbr", "tax invoice"],
                "response": (
                    "\U0001f9fe **Tax Information:**\n\n"
                    "\u2022 All invoices include **17% General Sales Tax (GST)** as per Pakistan Sales Tax Act 1990.\n"
                    "\u2022 Our **NTN** and **STRN** are printed on all tax invoices.\n"
                    "\u2022 **Withholding Tax (WHT)** at 4% is applied where applicable as per FBR rules.\n"
                    "\u2022 **FED (Federal Excise Duty)** at 5% applied on applicable services.\n"
                    "\u2022 Tax invoices are issued for all registered business clients.\n\n"
                    "\U0001f4cc For tax exemption certificates or special tax treatment, contact our accounts team."
                )
            },

            "invoice_query": {
                "keywords": ["invoice", "bill", "receipt", "statement", "invoice status", "invoice copy"],
                "response": (
                    "\U0001f4c4 **Invoice Information:**\n\n"
                    "\u2022 Invoices are generated after approval of estimates/quotations.\n"
                    "\u2022 You will receive your invoice via **email** and **WhatsApp**.\n"
                    "\u2022 PDF copies are always available on request.\n"
                    "\u2022 Invoice statuses: Draft \u2192 Sent \u2192 Partially Paid \u2192 Paid\n\n"
                    "\U0001f4cc To check your invoice status, provide your Invoice # to our accounts team.\n"
                    "\U0001f4cc For duplicate invoice copies: sales@datapointtechnology.com\n"
                    "\U0001f4cc Payment due date is printed on every invoice."
                )
            },

            "estimate_query": {
                "keywords": ["estimate", "quotation", "quote", "proposal", "how much", "cost", "rate"],
                "response": (
                    "\U0001f4cb **Estimates & Quotations:**\n\n"
                    "\u2022 We provide **free estimates** for all products and services.\n"
                    "\u2022 Estimates are valid for **15 days** from issue date.\n"
                    "\u2022 Once approved by client, estimates are converted to sales invoices.\n"
                    "\u2022 Estimates include itemized pricing with GST breakdown.\n\n"
                    "\U0001f4cc To request a quotation:\n"
                    "   1. Tell us what products/services you need\n"
                    "   2. Specify quantities\n"
                    "   3. We'll prepare and send the estimate within 24 hours\n\n"
                    "\U0001f4e7 Email: sales@datapointtechnology.com\n"
                    "\U0001f4de Call: +92-316-7788990"
                )
            },

            "delivery_query": {
                "keywords": ["delivery", "shipping", "dispatch", "when", "how long", "timeline", "days"],
                "response": (
                    "\U0001f69a **Delivery Information:**\n\n"
                    "\u25aa Hardware & Products\n"
                    "   \u2022 In-stock items: 1\u20133 working days\n"
                    "   \u2022 Import/order items: 7\u201314 working days\n"
                    "   \u2022 Bulk orders: Timeline discussed at order time\n\n"
                    "\u25aa Services\n"
                    "   \u2022 IT Support (on-site): Same day or next day\n"
                    "   \u2022 Network installation: 1\u20135 days depending on scope\n"
                    "   \u2022 CCTV installation: 1\u20133 days\n"
                    "   \u2022 Software projects: As per agreed project timeline\n\n"
                    "\U0001f4cc You will be notified via SMS/WhatsApp once your order is dispatched.\n"
                    "\U0001f4cc Delivery charges may apply outside Hyderabad."
                )
            },

            "warranty_return": {
                "keywords": ["warranty", "return", "refund", "replace", "replacement", "defective", "damaged", "broken", "guarantee"],
                "response": (
                    "\U0001f504 **Warranty & Return Policy:**\n\n"
                    "\u25aa Hardware Products\n"
                    "   \u2022 Dead on arrival (DOA): Replacement within 48 hours\n"
                    "   \u2022 Defective items: 7-day return/replacement policy\n"
                    "   \u2022 Manufacturer warranty: As per brand (1\u20133 years)\n\n"
                    "\u25aa Assembled PCs & Custom Builds\n"
                    "   \u2022 6-month local warranty on assembly & components\n\n"
                    "\u25aa Software & Services\n"
                    "   \u2022 Non-refundable once delivered\n"
                    "   \u2022 Bug fixes & support included post-delivery\n\n"
                    "\u25aa CCTV & Networking Equipment\n"
                    "   \u2022 1-year warranty on equipment\n"
                    "   \u2022 Installation warranty: 3 months\n\n"
                    "\U0001f4cc To initiate a return, contact us with your Invoice # and issue description.\n"
                    "\U0001f4de +92-316-7788990"
                )
            },

            "contact_query": {
                "keywords": ["contact", "address", "location", "office", "reach", "call", "visit", "where", "find"],
                "response": (
                    "\U0001f4de **Contact DATAPOINT Technologies:**\n\n"
                    "\U0001f4cd G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh\n"
                    "\U0001f4de +92-316-7788990\n"
                    "\U0001f4e7 info@datapointtechnology.com\n"
                    "\U0001f4e7 sales@datapointtechnology.com (for orders & quotes)\n"
                    "\U0001f310 http://datapointtechnology.com\n\n"
                    "\U0001f550 Business Hours: Monday\u2013Saturday, 9:00 AM \u2013 7:00 PM\n\n"
                    "\U0001f4cc For urgent support, call directly.\n"
                    "\U0001f4cc For quotes & orders, email sales team."
                )
            },

            "bulk_corporate": {
                "keywords": ["bulk", "corporate", "company", "business", "wholesale", "large order", "tender", "government", "ngo", "organization"],
                "response": (
                    "\U0001f3e2 **Corporate & Bulk Orders:**\n\n"
                    "We offer special pricing for corporate and bulk purchases:\n\n"
                    "\u2705 Volume discounts on hardware orders (5+ units)\n"
                    "\u2705 Dedicated account manager for corporate clients\n"
                    "\u2705 Credit terms available (Net 30/60 days)\n"
                    "\u2705 Customized AMC packages\n"
                    "\u2705 Government & NGO procurement support\n"
                    "\u2705 Tender documentation assistance\n\n"
                    "\U0001f4e7 Corporate inquiries: sales@datapointtechnology.com\n"
                    "\U0001f4de +92-316-7788990"
                )
            },

            "about": {
                "keywords": ["about", "who are you", "company", "datapoint", "background", "experience", "history", "profile"],
                "response": (
                    "\U0001f3e2 **About DATAPOINT Technologies:**\n\n"
                    "DATAPOINT Technologies is a leading IT solutions provider based in Hyderabad, Sindh.\n\n"
                    "\u25aa What We Do:\n"
                    "   \u2022 Supply of computers, laptops & IT hardware\n"
                    "   \u2022 Networking & infrastructure solutions\n"
                    "   \u2022 CCTV & security systems\n"
                    "   \u2022 Custom software & web development\n"
                    "   \u2022 IT support & maintenance services\n\n"
                    "\u25aa Why Choose Us:\n"
                    "   \u2022 Authorized dealers for major brands\n"
                    "   \u2022 Experienced technical team\n"
                    "   \u2022 After-sales support & AMC\n"
                    "   \u2022 Competitive pricing\n"
                    "   \u2022 Serving businesses across Sindh\n\n"
                    "\U0001f4de +92-316-7788990 | \U0001f310 datapointtechnology.com"
                )
            }
        }

    def _query_prices(self, db: Session, search_term: str = None, category: str = None) -> str:
        from app.models.pricelist import PriceList
        q = db.query(PriceList).filter(PriceList.is_active == True)
        if search_term:
            q = q.filter(PriceList.name.ilike(f"%{search_term}%"))
        if category:
            q = q.filter(PriceList.category.ilike(category))
        items = q.order_by(PriceList.category, PriceList.name).limit(50).all()
        if not items:
            return None
        cat_groups = {}
        for item in items:
            cat = item.category or "General"
            cat_groups.setdefault(cat, []).append(item)
        lines = []
        for cat, cat_items in cat_groups.items():
            lines.append(f"\n\u25aa **{cat}**")
            for i in cat_items[:10]:
                lines.append(f"   \u2022 {i.name} \u2014 PKR {i.unit_price:,.2f}/{i.unit}")
        return "\n".join(lines)

    def detect_send_invoice(self, message: str) -> Optional[Dict]:
        """Detect a 'send invoice via WhatsApp' intent and extract the invoice reference."""
        msg = message.lower().strip()
        if not msg:
            return None
        if re.match(r"^(how|what|why|when|where|explain|can you tell)\b", msg):
            return None
        has_whatsapp = any(w in msg for w in ("whatsapp", "whats app", "watsapp", "whatsapp "))
        has_invoice = any(i in msg for i in ("invoice", "bill"))
        if not (has_whatsapp and has_invoice):
            return None
        has_send = (
            any(v in msg for v in ("send", "share", "forward"))
            or "whatsapp me" in msg
            or "on whatsapp" in msg
            or "to whatsapp" in msg
            or "via whatsapp" in msg
        )
        if not has_send:
            return None

        invoice_ref = None
        m = re.search(r"inv[\s-]*(\d{4,6})[\s-]*(\d+)", msg, re.I)
        if m:
            invoice_ref = f"INV-{m.group(1)}-{m.group(2)}"
        else:
            m = re.search(r"(?:invoice|bill)\s*#?\s*(\d+)\b", msg)
            if m:
                invoice_ref = m.group(1)
        use_latest = any(w in msg for w in ("last", "latest", "recent", "most recent"))
        return {"invoice_ref": invoice_ref, "use_latest": use_latest, "message": message}

    def get_response(self, user_message: str, db: Session = None) -> str:
        msg = user_message.lower().strip()
        if not msg:
            return "Please type your query. I'm here to help!"

        pricing_keywords = [
            "price", "pricing", "cost", "rate", "rates", "pricelist", "price list",
            "laptop", "laptops", "desktop", "desktops", "pc", "computer",
            "router", "switch", "networking", "cctv", "camera", "camera",
            "printer", "monitor", "accessories", "ssd", "hard drive",
            "products", "product", "item", "items", "what is the price",
            "how much", "pkr"
        ]
        is_pricing_query = any(kw in msg for kw in pricing_keywords)

        is_category_query = False
        category_map = {
            "laptop": "laptop", "desktop": "desktop", "pc": "desktop",
            "network": "networking", "router": "networking", "switch": "networking",
            "cctv": "cctv", "camera": "cctv", "surveillance": "cctv",
            "accessor": "accessories", "printer": "accessories", "monitor": "accessories",
            "storage": "accessories", "software": "software", "service": "services",
            "it support": "services", "maintenance": "services",
        }
        matched_category = None
        for kw, cat in category_map.items():
            if kw in msg:
                is_category_query = True
                matched_category = cat
                break

        if is_pricing_query and db is not None:
            search = None
            category_filter = matched_category
            price_response = self._query_prices(db, search_term=search, category=category_filter)
            if price_response:
                header = (
                    "\U0001f4b0 **Live Prices from our Database**\n"
                    f"_(Prices effective as of today)_\n"
                )
                if matched_category:
                    header = f"\U0001f4b0 **Live {matched_category.title()} Prices**\n(_Prices effective as of today_)\n"
                return header + price_response + (
                    "\n\n\U0001f4cc Prices are updated daily. Contact us for bulk discounts.\n"
                    "\U0001f4de +92-316-7788990 | \U0001f4e7 sales@datapointtechnology.com"
                )
            if matched_category:
                return (
                    f"\U0001f50d No prices found for '{matched_category}'.\n\n"
                    "Please check back later or contact us:\n"
                    f"\U0001f4de {self.context['phone']}\n"
                    f"\U0001f4e7 {self.context['sales_email']}"
                )

        best_match = None
        max_score = 0
        for category, data in self.knowledge_base.items():
            score = sum(1 for kw in data["keywords"] if kw in msg)
            if score > max_score:
                max_score = score
                best_match = data["response"]

        if best_match and max_score > 0:
            return best_match

        return (
            "Thank you for your query! I'm not sure I have a specific answer for that.\n\n"
            "You can ask me about:\n"
            "\u2022 Laptops, Desktops, Networking, CCTV, Accessories\n"
            "\u2022 Software Development, IT Support, Web Development\n"
            "\u2022 Invoices, Payments, Estimates, Delivery, Warranty\n\n"
            f"Or contact us directly:\n"
            f"\U0001f4de {self.context['phone']}\n"
            f"\U0001f4e7 {self.context['email']}"
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
                    "I'd be happy to prepare a quotation for you! \U0001f4cb\n\n"
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
                    "Great! Let's get your order started. \U0001f6d2\n\n"
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
                    "For billing inquiries, I can help! \U0001f4b3\n\n"
                    "Please provide:\n"
                    "\u2022 Your Invoice # (e.g. INV-202501-00001)\n"
                    "\u2022 Or your company/client name\n\n"
                    "Our accounts team will verify and update you on payment status."
                ),
                "action": "check_billing"
            },
            "greeting": {
                "type": "greeting",
                "message": (
                    "Assalam-o-Alaikum! \U0001f44b Welcome to DATAPOINT Technologies.\n\n"
                    "I'm your assistant. I can help you with:\n"
                    "\u2022 Product pricing & availability\n"
                    "\u2022 Quotations & estimates\n"
                    "\u2022 Order placement\n"
                    "\u2022 Billing & invoice queries\n"
                    "\u2022 Technical support\n\n"
                    "What can I help you with today?"
                ),
                "action": "none"
            },
            "product_inquiry": {
                "type": "product_inquiry",
                "message": (
                    "We carry a wide range of IT products! \U0001f5a5\ufe0f\n\n"
                    "\u2022 Laptops (Dell, HP, Lenovo)\n"
                    "\u2022 Desktop PCs & Workstations\n"
                    "\u2022 Networking (MikroTik, Cisco, TP-Link, Ubiquiti)\n"
                    "\u2022 CCTV Systems (Hikvision, Dahua)\n"
                    "\u2022 Printers, UPS, Accessories\n\n"
                    "For exact pricing with latest updates, please visit our **Daily Prices** page or ask me for specific product prices!"
                ),
                "action": "show_products"
            },
            "support": {
                "type": "support_request",
                "message": (
                    "I'm sorry to hear you're having an issue. \U0001f6e0\ufe0f\n\n"
                    "For technical support:\n"
                    "\U0001f4de Call us: +92-316-7788990\n"
                    "\U0001f4e7 Email: info@datapointtechnology.com\n\n"
                    "Please describe your issue and we'll assign a technician.\n"
                    "On-site support available in Hyderabad & surrounding areas."
                ),
                "action": "log_support"
            },
            "general": {
                "type": "general",
                "message": (
                    "I'm here to help! You can ask me about:\n"
                    "\u2022 Products & pricing\n"
                    "\u2022 Quotations & orders\n"
                    "\u2022 Invoices & payments\n"
                    "\u2022 Technical support\n\n"
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