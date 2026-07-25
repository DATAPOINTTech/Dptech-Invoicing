from pydantic_settings import BaseSettings, SettingsConfigDict
from typing import Optional

class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env")

    DATABASE_URL: str = "sqlite:///./dptech.db"
    DB_SSL_REQUIRED: bool = False
    SECRET_KEY: str
    ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 1440

    COMPANY_NAME: str = "DATAPOINT Technologies"
    COMPANY_ADDRESS: str = "G 32 Shayas Residence Jamshoro Road, Hyderabad Sindh"
    COMPANY_PHONE: str = "+92-316-7788990"
    COMPANY_EMAIL: str = "sales@datapointtechnology.com"
    COMPANY_WEBSITE: str = "http://datapointtechnology.com"
    COMPANY_LOGO_URL: str = "http://datapointtechnology.com"
    COMPANY_NTN: str = "7178396-5"
    COMPANY_STRN: str = "S7178396-5"

    SALES_TAX_RATE: float = 17.0

    PDF_OUTPUT_DIR: str = "./pdf_output"

    EMAIL_HOST: Optional[str] = None
    EMAIL_PORT: Optional[int] = None
    EMAIL_USER: Optional[str] = None
    EMAIL_PASS: Optional[str] = None

    WHATSAPP_API_KEY: Optional[str] = None
    WHATSAPP_PHONE_NUMBER_ID: Optional[str] = None

    OPENAI_API_KEY: Optional[str] = None

settings = Settings()
