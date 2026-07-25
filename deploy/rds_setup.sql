-- Run this once on your RDS/Aurora instance as the master user
-- psql -h <writer-endpoint> -U postgres -d postgres -W

-- Create database
CREATE DATABASE dptech_db;

-- Create app user
CREATE USER dptech WITH PASSWORD '<your-strong-password>';

-- Grant privileges
GRANT ALL PRIVILEGES ON DATABASE dptech_db TO dptech;

-- Connect to the new database, then grant schema access
\c dptech_db
GRANT ALL ON SCHEMA public TO dptech;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO dptech;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO dptech;
