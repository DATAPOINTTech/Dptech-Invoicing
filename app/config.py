from pydantic_settings import BaseSettings, SettingsConfigDict
from typing import Optional

class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    DATABASE_URL: str = "sqlite:///./dptech.db"
    DB_SSL_REQUIRED: bool = False
    SECRET_KEY: str
    ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 1440

    COMPANY_NAME: str = "DATAPOINT Technologies"
    COMPANY_ADDRESS: str = "G 32 Shayas Residence Jamshoro Road, Hyderabad Sindh"
    COMPANY_PHONE: str = "+92-316-7788990"
    COMPANY_MOBILE: str = "+923167788990"
    COMPANY_EMAIL: str = "sales@datapointtechnology.com"
    COMPANY_WEBSITE: str = "http://datapointtechnology.com"
    COMPANY_LOGO_URL: str = "http://datapointtechnology.com"
    COMPANY_NTN: str = "7178396-5"
    COMPANY_STRN: str = "S7178396-5"

    SALES_TAX_RATE: float = 17.0

    PDF_OUTPUT_DIR: str = "./pdf_output"

    # Comma-separated list of allowed CORS origins (e.g. https://app.example.com).
    # Leave empty to disable cross-origin access. Set to "*" for any origin (no credentials).
    CORS_ORIGINS: str = ""

    # Admin bootstrap — used to create the first admin account on an empty database.
    # In production ALWAYS set these via environment/secrets; never rely on a default.
    ADMIN_USERNAME: Optional[str] = None
    ADMIN_EMAIL: Optional[str] = None
    ADMIN_PASSWORD: Optional[str] = None

    EMAIL_HOST: Optional[str] = None
    EMAIL_PORT: Optional[int] = None
    EMAIL_USER: Optional[str] = None
    EMAIL_PASS: Optional[str] = None

    WHATSAPP_API_KEY: Optional[str] = None
    WHATSAPP_PHONE_NUMBER_ID: Optional[str] = None

    OPENAI_API_KEY: Optional[str] = None

settings = Settings()
