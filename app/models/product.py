from sqlalchemy import Column, Integer, String, Float, Boolean, DateTime, Text, ForeignKey, Enum as SAEnum
from sqlalchemy.sql import func
from sqlalchemy.orm import relationship
from app.database import Base
import enum

class ProductCategory(str, enum.Enum):
    HARDWARE = "hardware"
    SOFTWARE = "software"
    SERVICE = "service"
    NETWORKING = "networking"
    CONSUMABLE = "consumable"
    OTHER = "other"

class Product(Base):
    __tablename__ = "products"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(200), nullable=False)
    description = Column(Text)
    category = Column(SAEnum(ProductCategory), default=ProductCategory.OTHER)
    sku = Column(String(50), unique=True, index=True)
    unit_price = Column(Float, default=0.0)
    cost_price = Column(Float, default=0.0)
    unit = Column(String(20), default="pcs")
    tax_rate = Column(Float, default=17.0)
    tax_inclusive = Column(Boolean, default=True)
    hs_code = Column(String(20))
    is_active = Column(Boolean, default=True)
    min_stock_level = Column(Float, default=0)
    created_at = Column(DateTime(timezone=True), server_default=func.now())
    updated_at = Column(DateTime(timezone=True), onupdate=func.now())

    inventory = relationship("Inventory", back_populates="product", uselist=False)
