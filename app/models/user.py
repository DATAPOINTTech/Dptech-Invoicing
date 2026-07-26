from sqlalchemy import Column, Integer, String, Boolean, DateTime, Enum as SAEnum, JSON
from sqlalchemy.sql import func
from app.database import Base
import enum

class UserRole(str, enum.Enum):
    ADMIN = "admin"
    MANAGER = "manager"
    STAFF = "staff"

DEFAULT_PERMISSIONS = {
    "invoices":   {"view": True,  "create": False, "edit": False, "delete": False},
    "estimates":  {"view": True,  "create": False, "edit": False, "delete": False},
    "purchases":  {"view": True,  "create": False, "edit": False, "delete": False},
    "expenses":   {"view": True,  "create": False, "edit": False, "delete": False},
    "clients":    {"view": True,  "create": False, "edit": False, "delete": False},
    "suppliers":  {"view": True,  "create": False, "edit": False, "delete": False},
    "products":   {"view": True,  "create": False, "edit": False, "delete": False},
    "inventory":  {"view": True,  "create": False, "edit": False, "delete": False},
    "projects":   {"view": True,  "create": False, "edit": False, "delete": False},
    "reports":    {"view": False, "create": False, "edit": False, "delete": False},
    "users":      {"view": False, "create": False, "edit": False, "delete": False},
}

ADMIN_PERMISSIONS = {
    module: {"view": True, "create": True, "edit": True, "delete": True}
    for module in DEFAULT_PERMISSIONS
}

MANAGER_PERMISSIONS = {
    module: {"view": True, "create": True, "edit": True, "delete": False}
    for module in DEFAULT_PERMISSIONS
}
MANAGER_PERMISSIONS["users"] = {"view": False, "create": False, "edit": False, "delete": False}

class User(Base):
    __tablename__ = "users"

    id = Column(Integer, primary_key=True, index=True)
    username = Column(String(50), unique=True, index=True, nullable=False)
    email = Column(String(100), unique=True, index=True, nullable=False)
    hashed_password = Column(String(200), nullable=False)
    full_name = Column(String(100), nullable=False)
    phone = Column(String(20))
    role = Column(SAEnum(UserRole), default=UserRole.STAFF)
    is_active = Column(Boolean, default=True)
    permissions = Column(JSON, nullable=True)
    created_at = Column(DateTime(timezone=True), server_default=func.now())
    updated_at = Column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    def get_permissions(self) -> dict:
        if self.role == UserRole.ADMIN:
            return ADMIN_PERMISSIONS
        if self.permissions:
            return self.permissions
        if self.role == UserRole.MANAGER:
            return MANAGER_PERMISSIONS
        return DEFAULT_PERMISSIONS
