import os
import zipfile
import sys

def create_whitelabel_zip():
    base_dir = os.path.abspath(os.path.dirname(__file__))
    output_zip_path = os.path.join(base_dir, "whitelabel-invoicing-suite.zip")

    # If zip already exists, remove it
    if os.path.exists(output_zip_path):
        os.remove(output_zip_path)

    excluded_dirs = {
        ".git",
        ".vscode",
        "Cpanel-RSAkey",
        "cpanel_backup",
        "node_modules",
        "auth_info",
        "__pycache__",
        "tests",
        "output",
        "pdf_output",
        "dptech-invoicing-cpanel",
    }

    # Exclude databases, personal backups, secrets
    excluded_extensions = {
        ".log",
        ".pyc",
        ".tmp",
        ".db",
        ".sqlite",
        ".sqlite3",
        ".bak",
    }

    excluded_files = {
        "whitelabel-invoicing-suite.zip",
        "dptech-invoicing-cpanel.zip",
        "package_whitelabel.py",
        "package_cpanel.py",
        ".env",
        ".env.production",
        ".app_secret",
        ".bridge_secret",
        "reset_password.txt",
        "reset_database_to_zero.sql",
        "export_cpanel_backup.php",
    }

    # Runtime data folders: ship folder + protective .htaccess but no private runtime data
    runtime_dirs = ("php/storage/", "php/public/static/uploads/")
    runtime_keep = {".htaccess", ".gitkeep"}

    print(f"Packaging standalone White-Label Invoicing Suite from {base_dir}...")
    file_count = 0
    total_uncompressed_bytes = 0

    with zipfile.ZipFile(output_zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zipf:
        for root, dirs, files in os.walk(base_dir):
            # Prune excluded directories in-place
            dirs[:] = [d for d in dirs if d not in excluded_dirs]

            for file in files:
                if file in excluded_files:
                    continue

                _, ext = os.path.splitext(file)
                if ext.lower() in excluded_extensions:
                    continue

                full_path = os.path.join(root, file)
                rel_path = os.path.relpath(full_path, base_dir).replace("\\", "/")

                # Double check path segments for any excluded folder
                path_segments = set(rel_path.split("/"))
                if path_segments.intersection(excluded_dirs):
                    continue

                if rel_path.startswith(runtime_dirs) and file not in runtime_keep:
                    continue

                zipf.write(full_path, arcname=rel_path)
                file_count += 1
                total_uncompressed_bytes += os.path.getsize(full_path)

    zip_size = os.path.getsize(output_zip_path)
    print(f"Archive successfully created: {output_zip_path}")
    print(f"Total files packaged: {file_count}")
    print(f"Uncompressed size: {total_uncompressed_bytes / (1024*1024):.2f} MB")
    print(f"Compressed ZIP size: {zip_size / (1024*1024):.2f} MB ({zip_size} bytes)")

if __name__ == "__main__":
    create_whitelabel_zip()
