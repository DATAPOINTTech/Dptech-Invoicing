from sqlalchemy import Column, Integer, String, Float, Boolean, DateTime, Date, Text
from sqlalchemy.sql import func
from app.database import Base

class PriceList(Base):
    __tablename__ = "price_list"

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(300), nullable=False, index=True)
    description = Column(Text, nullable=True)
    category = Column(String(100), default="general")
    unit = Column(String(20), default="pcs")
    unit_price = Column(Float, nullable=False)
    currency = Column(String(10), default="PKR")
    effective_date = Column(Date, nullable=False)
    source = Column(String(200), nullable=True)   # url / csv / manual / google_sheet
    image_url = Column(String(500), nullable=True)  # product image URL
    is_active = Column(Boolean, default=True)
    created_at = Column(DateTime(timezone=True), server_default=func.now())
    updated_at = Column(DateTime(timezone=True), onupdate=func.now())
