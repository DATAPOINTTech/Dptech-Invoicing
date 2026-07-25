import sqlite3, os
db_path = 'dptech.db'
conn = sqlite3.connect(db_path)
cur = conn.cursor()
cur.execute("SELECT name FROM sqlite_master WHERE type='table'")
tables = cur.fetchall()
print('Tables:', tables)

if ('invoices',) in tables:
    cur.execute('SELECT id, invoice_no, client_id, total_amount, status FROM invoices')
    invoices = cur.fetchall()
    print('Invoices:', invoices)
    cur.execute('SELECT COUNT(*) FROM invoices')
    print('Count:', cur.fetchone()[0])
else:
    print('No invoices table')

if ('clients',) in tables:
    cur.execute('SELECT id, name FROM clients')
    clients = cur.fetchall()
    print('Clients:', clients)
else:
    print('No clients table')
conn.close()
