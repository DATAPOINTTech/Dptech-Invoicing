from sqlalchemy import Column, Integer, String, Float, Boolean, DateTime, Text, ForeignKey, Date, Enum as SAEnum
from sqlalchemy.sql import func
from sqlalchemy.orm import relationship
from app.database import Base
import enum

class ExpenseCategory(str, enum.Enum):
    UTILITIES = "utilities"
    RENT = "rent"
    SALARY = "salary"
    TRANSPORT = "transport"
    OFFICE_SUPPLIES = "office_supplies"
    MARKETING = "marketing"
    MAINTENANCE = "maintenance"
    TRAVEL = "travel"
    PROFESSIONAL_FEES = "professional_fees"
    TAXES = "taxes"
    OTHER = "other"

class Expense(Base):
    __tablename__ = "expenses"

    id = Column(Integer, primary_key=True, index=True)
    expense_no = Column(String(50), unique=True, index=True)
    category = Column(SAEnum(ExpenseCategory), default=ExpenseCategory.OTHER)
    description = Column(Text, nullable=False)
    amount = Column(Float, nullable=False)
    tax_amount = Column(Float, default=0.0)
    total_amount = Column(Float, nullable=False)
    expense_date = Column(Date, nullable=False)
    payment_method = Column(String(50))
    vendor_name = Column(String(200))
    receipt_ref = Column(String(100))
    project_id = Column(Integer, ForeignKey("projects.id"))
    notes = Column(Text)
    created_by = Column(Integer, ForeignKey("users.id"))
    created_at = Column(DateTime(timezone=True), server_default=func.now())
    updated_at = Column(DateTime(timezone=True), onupdate=func.now())
