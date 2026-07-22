from sqlalchemy import Column, Integer, String, Float, Boolean, DateTime, Text, ForeignKey, Date, Enum as SAEnum
from sqlalchemy.sql import func
from sqlalchemy.orm import relationship
from app.database import Base
import enum

class InvoiceStatus(str, enum.Enum):
    DRAFT = "draft"
    SENT = "sent"
    PAID = "paid"
    PARTIALLY_PAID = "partially_paid"
    OVERDUE = "overdue"
    CANCELLED = "cancelled"

class Invoice(Base):
    __tablename__ = "invoices"

    id = Column(Integer, primary_key=True, index=True)
    invoice_no = Column(String(50), unique=True, index=True, nullable=False)
    client_id = Column(Integer, ForeignKey("clients.id"), nullable=False)
    estimate_id = Column(Integer, ForeignKey("estimates.id"))
    project_id = Column(Integer, ForeignKey("projects.id"))
    invoice_date = Column(Date, nullable=False)
    due_date = Column(Date)
    status = Column(SAEnum(InvoiceStatus), default=InvoiceStatus.DRAFT)
    subtotal = Column(Float, default=0.0)
    discount_percent = Column(Float, default=0.0)
    discount_amount = Column(Float, default=0.0)

    tax_rate = Column(Float, default=17.0)
    tax_amount = Column(Float, default=0.0)
    withholding_tax_rate = Column(Float, default=0.0)
    withholding_tax_amount = Column(Float, default=0.0)
    fed_rate = Column(Float, default=0.0)
    fed_amount = Column(Float, default=0.0)
    total_amount = Column(Float, default=0.0)

    amount_paid = Column(Float, default=0.0)
    balance_due = Column(Float, default=0.0)
    payment_terms = Column(String(200))
    notes = Column(Text)
    terms_conditions = Column(Text)
    created_by = Column(Integer, ForeignKey("users.id"))
    created_at = Column(DateTime(timezone=True), server_default=func.now())
    updated_at = Column(DateTime(timezone=True), onupdate=func.now())

    client = relationship("Client")
    estimate = relationship("Estimate")
    items = relationship("InvoiceItem", back_populates="invoice", cascade="all, delete-orphan")

class InvoiceItem(Base):
    __tablename__ = "invoice_items"

    id = Column(Integer, primary_key=True, index=True)
    invoice_id = Column(Integer, ForeignKey("invoices.id"), nullable=False)
    product_id = Column(Integer, ForeignKey("products.id"))
    description = Column(String(500), nullable=False)
    quantity = Column(Float, nullable=False)
    unit = Column(String(20), default="pcs")
    unit_price = Column(Float, nullable=False)
    tax_rate = Column(Float, default=17.0)
    tax_amount = Column(Float, default=0.0)
    total_price = Column(Float, nullable=False)

    invoice = relationship("Invoice", back_populates="items")
