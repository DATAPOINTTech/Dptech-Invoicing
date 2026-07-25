from sqlalchemy import create_engine, text
from sqlalchemy.orm import declarative_base, sessionmaker
from sqlalchemy.pool import NullPool, QueuePool
from .config import settings
import logging

logger = logging.getLogger(__name__)

def _build_engine():
    url = settings.DATABASE_URL
    is_sqlite = "sqlite" in url
    is_postgres = "postgresql" in url or "postgres" in url

    if is_sqlite:
        return create_engine(url, connect_args={"check_same_thread": False})

    if is_postgres:
        connect_args = {}
        if settings.DB_SSL_REQUIRED:
            connect_args["sslmode"] = "require"
        return create_engine(
            url,
            poolclass=QueuePool,
            pool_size=5,
            max_overflow=10,
            pool_pre_ping=True,       # detect stale connections
            pool_recycle=1800,        # recycle every 30 min (avoids RDS timeout)
            connect_args=connect_args,
        )

    return create_engine(url)

engine = _build_engine()
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
Base = declarative_base()

def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

def init_db():
    Base.metadata.create_all(bind=engine)
    # one-time migration guard for legacy SQLite installs
    if "sqlite" in settings.DATABASE_URL:
        try:
            with engine.connect() as conn:
                conn.execute(text(
                    "ALTER TABLE purchase_invoices ADD COLUMN supplier_id INTEGER REFERENCES suppliers(id)"
                ))
                conn.commit()
        except Exception:
            pass
    logger.info("Database initialised: %s", settings.DATABASE_URL.split("@")[-1])
