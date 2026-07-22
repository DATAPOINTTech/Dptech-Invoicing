from pydantic_settings import BaseSettings
from typing import Optional

class Settings(BaseSettings):
    DATABASE_URL: str = "sqlite:///./dptech.db"
    SECRET_KEY: str = "dptech-secret-key"
    ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 1440

    COMPANY_NAME: str = "DATAPOINT Technologies"
    COMPANY_ADDRESS: str = "G 32 Shayas Residence Jamshoro Road, Hyderabad Sindh"
    COMPANY_PHONE: str = "+92-XXX-XXXXXXX"
    COMPANY_EMAIL: str = "info@datapointtechnology.com"
    COMPANY_WEBSITE: str = "http://datapointtechnology.com"
    COMPANY_LOGO_URL: str = "http://datapointtechnology.com"
    COMPANY_NTN: str = "XXXXXXXXXXXXX"
    COMPANY_STRN: str = "XXXXXXXXXXXXX"

    SALES_TAX_RATE: float = 17.0

    PDF_OUTPUT_DIR: str = "./pdf_output"

    EMAIL_HOST: Optional[str] = None
    EMAIL_PORT: Optional[int] = None
    EMAIL_USER: Optional[str] = None
    EMAIL_PASS: Optional[str] = None

    WHATSAPP_API_KEY: Optional[str] = None
    WHATSAPP_PHONE_NUMBER_ID: Optional[str] = None

    OPENAI_API_KEY: Optional[str] = None

    class Config:
        env_file = ".env"

settings = Settings()
