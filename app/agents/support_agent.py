import json
from typing import List, Dict, Optional
from datetime import datetime

class SupportAgent:
    def __init__(self):
        self.context = {
            "company": "DATAPOINT Technologies",
            "address": "G 32 Shayas Residence Jamshoro Road, Hyderabad Sindh",
            "phone": "+92-316-7788990",
            "email": "info@datapointtechnology.com",
            "website": "http://datapointtechnology.com",
            "services": [
                "IT Solutions & Services",
                "Software Development",
                "Networking Solutions",
                "Hardware Supply & Installation",
                "CCTV & Security Systems",
                "Web Development"
            ],
            "billing_info": {
                "payment_terms": "Net 30 days",
                "accepted_payment": ["Bank Transfer", "Cheque", "Cash", "JazzCash", "Easypaisa"],
                "tax_rate": "18% GST/Sales Tax as per Pakistan tax law",
                "late_payment_fee": "2% per month on overdue amounts"
            }
        }
        self.knowledge_base = self._load_knowledge_base()

    def _load_knowledge_base(self) -> Dict:
        return {
            "payment_methods": {
                "keywords": ["payment", "pay", "bank", "transfer", "jazzcash", "easypaisa", "cheque"],
                "response": "We accept the following payment methods:\n1. Bank Transfer\n2. Crossed Cheque\n3. Cash\n4. JazzCash\n5. Easypaisa\n\nBank details will be provided on the invoice. Please use your Invoice # as reference."
            },
            "tax_query": {
                "keywords": ["tax", "gst", "sales tax", "ntn", "strn", "withholding"],
                "response": "All invoices include 18% General Sales Tax (GST) as per Pakistan Sales Tax Act 1990. Our NTN and STRN are printed on all tax invoices. Withholding tax (WHT) is applied where applicable as per FBR rules."
            },
            "invoice_query": {
                "keywords": ["invoice", "bill", "receipt", "statement"],
                "response": "Invoices are generated after approval of estimates/quotation. You will receive your invoice via email and WhatsApp. PDF copies are available on request."
            },
            "estimate_query": {
                "keywords": ["estimate", "quotation", "quote", "proposal"],
                "response": "We provide free estimates for all our services. Estimates are valid for 15 days. Once approved, estimates are converted to sales invoices. You can also request a quotation via our voice agent."
            },
            "delivery_query": {
                "keywords": ["delivery", "shipping", "dispatch", "timeline", "time"],
                "response": "Delivery timelines depend on product availability and service type. Hardware items: 2-5 working days. Services: Scheduled as per project timeline. You will be notified once your order is ready."
            },
            "return_query": {
                "keywords": ["return", "refund", "cancel", "replacement", "warranty"],
                "response": "Return/Replacement Policy:\n- Hardware: 7 days from delivery for defective items\n- Software/Services: Non-refundable once delivered\n- Warranty: As per manufacturer terms\nPlease contact us with your Invoice # for return assistance."
            },
            "product_query": {
                "keywords": ["product", "price", "available", "stock", "item"],
                "response": "Please browse our product catalog or contact us with specific product names. We deal in:\n- Computer Hardware & Accessories\n- Networking Equipment\n- Security Systems (CCTV)\n- Software Solutions\n- IT Consumables"
            },
            "contact_query": {
                "keywords": ["contact", "address", "location", "office", "reach", "call"],
                "response": "You can reach us at:\n📍 G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh\n📞 +92-XXX-XXXXXXX\n📧 info@datapointtechnology.com\n🌐 http://datapointtechnology.com\nBusiness Hours: Monday-Saturday, 9:00 AM - 7:00 PM"
            },
            "greeting": {
                "keywords": ["hi", "hello", "hey", "salam", "assalam", "good morning", "good evening"],
                "response": "👋 Assalam-o-Alaikum! Welcome to DATAPOINT Technologies Support. How can I assist you today? You can ask me about:\n• Invoices & Payments\n• Estimates & Quotations\n• Products & Services\n• Delivery & Returns\n• Contact Information"
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
        return ("Thank you for your query. For personalized assistance regarding your specific "
                "billing or product details, please contact our support team at "
                f"{self.context['phone']} or email {self.context['email']}. "
                "You can also visit us at our office.")

    def get_product_info(self, product_name: str) -> Optional[str]:
        return (f"For information about '{product_name}', please check our catalog or contact "
                f"our sales team. We offer competitive pricing and bulk discounts for "
                f"business customers.")

    def get_invoice_status(self, invoice_no: str) -> str:
        return (f"To check status of Invoice #{invoice_no}, please contact our accounts "
                f"department at {self.context['email']} or call {self.context['phone']}. "
                f"We'll be happy to help you with payment status and invoice details.")

support_agent = SupportAgent()

class VoiceQuotationAgent:
    def __init__(self):
        self.products = []

    def process_voice_input(self, audio_text: str) -> Dict:
        text = audio_text.lower()
        intent = self._detect_intent(text)
        response = self._generate_response(intent, text)
        return response

    def _detect_intent(self, text: str) -> str:
        if any(w in text for w in ["quote", "quotation", "estimate", "price", "cost", "rate"]):
            return "quotation"
        elif any(w in text for w in ["buy", "order", "purchase", "want", "need"]):
            return "order"
        elif any(w in text for w in ["invoice", "bill", "payment"]):
            return "billing"
        elif any(w in text for w in ["hello", "hi", "salam", "help"]):
            return "greeting"
        else:
            return "general"

    def _generate_response(self, intent: str, text: str) -> Dict:
        if intent == "quotation":
            return {
                "type": "quotation_request",
                "message": "I'd be happy to help you with a quotation! Please tell me what products or services you need, and I'll prepare an estimate for you.",
                "action": "collect_details"
            }
        elif intent == "order":
            return {
                "type": "order_request",
                "message": "Great! I can help you place an order. Please specify the items you need with quantities.",
                "action": "create_order"
            }
        elif intent == "billing":
            return {
                "type": "billing_inquiry",
                "message": "For billing inquiries, I can check your invoice status. Please provide your invoice number or client name.",
                "action": "check_billing"
            }
        elif intent == "greeting":
            return {
                "type": "greeting",
                "message": "Assalam-o-Alaikum! Welcome to DATAPOINT Technologies. I'm your voice assistant. You can ask me for quotations, place orders, or check billing information.",
                "action": "none"
            }
        else:
            return {
                "type": "general",
                "message": "I understand you need assistance. Please specify if you'd like a quotation, want to place an order, or need billing help.",
                "action": "clarify"
            }

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
