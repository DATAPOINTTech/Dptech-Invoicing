# DATAPOINT Invoicing System — Product Overview

## Purpose
A full-stack business management web application for small-to-medium businesses. Handles the complete billing and operations lifecycle: invoicing, estimates, inventory, expenses, purchases, and client/supplier management — with an integrated AI support agent.

## Key Features

### Core Business Modules
- **Invoicing** — Create, manage, and PDF-export sales invoices with line items, tax calculations (GST/Sales Tax), and status tracking
- **Estimates** — Generate client estimates/quotations that can be converted to invoices
- **Inventory** — Track product stock levels, manage products, and monitor inventory movements
- **Expenses** — Record and categorize business expenses
- **Purchase Invoices** — Track supplier purchases and inbound inventory
- **Projects** — Associate work with client projects

### Client & Supplier Management
- Full client directory with contact details
- Supplier management linked to purchase invoices
- Per-client invoice and estimate history

### Financial & Reporting
- Dashboard with business KPIs
- Tax-aware calculations (configurable Sales Tax Rate, NTN/STRN fields for Pakistani tax compliance)
- PDF generation for invoices and estimates (ReportLab)
- Reports view

### AI Support Agent
- Integrated chat interface powered by OpenAI
- Business-context-aware assistant for user support

### Communication
- Optional email sending via SMTP
- Optional WhatsApp messaging via Meta WhatsApp Business API

### Authentication & Multi-user
- JWT-based authentication with bcrypt password hashing
- User registration and management
- 24-hour token expiry (configurable)

## Target Users
- Small-to-medium businesses needing a self-hosted or cloud-deployed invoicing system
- Pakistani businesses (NTN/STRN tax fields, configurable sales tax rate)
- Teams requiring multi-user access with secure login

## Value Proposition
- Self-hostable on AWS EC2, Railway, Render, or Heroku
- SQLite for development, PostgreSQL/Aurora for production — zero code changes
- All business documents (invoices, estimates) exportable as PDFs
- Single deployable Python application — no microservices complexity
