from sqlalchemy import Column, Integer, String, Float, Boolean, DateTime, Text, ForeignKey, Date, Enum as SAEnum
from sqlalchemy.sql import func
from sqlalchemy.orm import relationship
from app.database import Base
import enum

class PurchaseStatus(str, enum.Enum):
    DRAFT = "draft"
    RECEIVED = "received"
    CANCELLED = "cancelled"

class PurchaseInvoice(Base):
    __tablename__ = "purchase_invoices"

    id = Column(Integer, primary_key=True, index=True)
    invoice_no = Column(String(50), unique=True, index=True, nullable=False)
    supplier_name = Column(String(200), nullable=False)
    supplier_ntn = Column(String(50))
    supplier_address = Column(Text)
    invoice_date = Column(Date, nullable=False)
    received_date = Column(Date)
    status = Column(SAEnum(PurchaseStatus), default=PurchaseStatus.DRAFT)
    subtotal = Column(Float, default=0.0)
    tax_amount = Column(Float, default=0.0)
    tax_rate = Column(Float, default=0.0)
    total_amount = Column(Float, default=0.0)
    notes = Column(Text)
    created_by = Column(Integer, ForeignKey("users.id"))
    created_at = Column(DateTime(timezone=True), server_default=func.now())
    updated_at = Column(DateTime(timezone=True), onupdate=func.now())

    items = relationship("PurchaseItem", back_populates="purchase", cascade="all, delete-orphan")

class PurchaseItem(Base):
    __tablename__ = "purchase_items"

    id = Column(Integer, primary_key=True, index=True)
    purchase_id = Column(Integer, ForeignKey("purchase_invoices.id"), nullable=False)
    product_id = Column(Integer, ForeignKey("products.id"))
    product_name = Column(String(200), nullable=False)
    description = Column(Text)
    quantity = Column(Float, nullable=False)
    unit = Column(String(20), default="pcs")
    unit_price = Column(Float, nullable=False)
    tax_rate = Column(Float, default=0.0)
    tax_amount = Column(Float, default=0.0)
    total_price = Column(Float, nullable=False)

    purchase = relationship("PurchaseInvoice", back_populates="items")
