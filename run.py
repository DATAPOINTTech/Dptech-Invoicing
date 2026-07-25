import os
import uvicorn

if __name__ == "__main__":
    # Fix URL scheme — AWS sometimes returns postgres:// which SQLAlchemy rejects
    db_url = os.environ.get("DATABASE_URL", "")
    if db_url.startswith("postgres://"):
        os.environ["DATABASE_URL"] = db_url.replace("postgres://", "postgresql://", 1)

    port = int(os.getenv("PORT", 8000))
    uvicorn.run("app.main:app", host="0.0.0.0", port=port, reload=False)
