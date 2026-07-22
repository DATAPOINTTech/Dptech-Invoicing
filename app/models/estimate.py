from sqlalchemy import Column, Integer, String, Float, Boolean, DateTime, Text, ForeignKey, Date, Enum as SAEnum
from sqlalchemy.sql import func
from sqlalchemy.orm import relationship
from app.database import Base
import enum

class EstimateStatus(str, enum.Enum):
    DRAFT = "draft"
    SENT = "sent"
    APPROVED = "approved"
    REJECTED = "rejected"
    CONVERTED = "converted"
    EXPIRED = "expired"

class Estimate(Base):
    __tablename__ = "estimates"

    id = Column(Integer, primary_key=True, index=True)
    estimate_no = Column(String(50), unique=True, index=True, nullable=False)
    client_id = Column(Integer, ForeignKey("clients.id"), nullable=False)
    project_id = Column(Integer, ForeignKey("projects.id"))
    title = Column(String(200))
    estimate_date = Column(Date, nullable=False)
    valid_until = Column(Date)
    status = Column(SAEnum(EstimateStatus), default=EstimateStatus.DRAFT)
    subtotal = Column(Float, default=0.0)
    discount_percent = Column(Float, default=0.0)
    discount_amount = Column(Float, default=0.0)
    tax_rate = Column(Float, default=17.0)
    tax_amount = Column(Float, default=0.0)
    total_amount = Column(Float, default=0.0)
    terms_conditions = Column(Text)
    notes = Column(Text)
    created_by = Column(Integer, ForeignKey("users.id"))
    created_at = Column(DateTime(timezone=True), server_default=func.now())
    updated_at = Column(DateTime(timezone=True), onupdate=func.now())

    client = relationship("Client")
    items = relationship("EstimateItem", back_populates="estimate", cascade="all, delete-orphan")

class EstimateItem(Base):
    __tablename__ = "estimate_items"

    id = Column(Integer, primary_key=True, index=True)
    estimate_id = Column(Integer, ForeignKey("estimates.id"), nullable=False)
    product_id = Column(Integer, ForeignKey("products.id"))
    description = Column(String(500), nullable=False)
    quantity = Column(Float, nullable=False)
    unit = Column(String(20), default="pcs")
    unit_price = Column(Float, nullable=False)
    tax_rate = Column(Float, default=17.0)
    tax_amount = Column(Float, default=0.0)
    total_price = Column(Float, nullable=False)

    estimate = relationship("Estimate", back_populates="items")
