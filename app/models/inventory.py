from sqlalchemy import Column, Integer, String, Float, DateTime, ForeignKey, Text, Enum as SAEnum
from sqlalchemy.sql import func
from sqlalchemy.orm import relationship
from app.database import Base
import enum

class MovementType(str, enum.Enum):
    PURCHASE_IN = "purchase_in"
    SALE_OUT = "sale_out"
    ADJUSTMENT = "adjustment"
    RETURN_IN = "return_in"
    RETURN_OUT = "return_out"
    TRANSFER = "transfer"

class Inventory(Base):
    __tablename__ = "inventory"

    id = Column(Integer, primary_key=True, index=True)
    product_id = Column(Integer, ForeignKey("products.id"), unique=True, nullable=False)
    quantity = Column(Float, default=0.0)
    warehouse = Column(String(100), default="Main")
    location = Column(String(100))
    last_updated = Column(DateTime(timezone=True), onupdate=func.now())

    product = relationship("Product", back_populates="inventory")

class StockMovement(Base):
    __tablename__ = "stock_movements"

    id = Column(Integer, primary_key=True, index=True)
    product_id = Column(Integer, ForeignKey("products.id"), nullable=False)
    quantity = Column(Float, nullable=False)
    movement_type = Column(SAEnum(MovementType), nullable=False)
    reference_type = Column(String(50))
    reference_id = Column(Integer)
    notes = Column(Text)
    created_by = Column(Integer, ForeignKey("users.id"))
    created_at = Column(DateTime(timezone=True), server_default=func.now())

    product = relationship("Product")
