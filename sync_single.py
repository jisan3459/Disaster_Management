import os, re

target_dir = r"c:\xamppsoft\htdocs\hello\hello\disaster_relief_app\resources\views"
source_dir = r"c:\xamppsoft\htdocs\hello\hello"

def convert(content):
    # 1. Config include
    content = content.replace("include 'config.php';", "include base_path('../config.php');")
    
    # Remove existing @csrf to prevent duplicates
    content = content.replace("@csrf", "")
    
    # 2. Add @csrf
    def add_csrf(m):
        return m.group(0) + "\n                    @csrf"
    content = re.sub(r'(?i)<form\s+[^>]*method=["\']POST["\'][^>]*>', add_csrf, content)
    
    # 3. URL attributes
    def repl_url(m):
        attr = m.group(1)
        quote = m.group(2)
        base = m.group(3)
        if base == "index":
            return f"{attr}={quote}{{{{ url('/') }}}}"
        return f"{attr}={quote}{{{{ url('{base}') }}}}"
    content = re.sub(r'(?i)(href|action|src)=([\'"])([a-zA-Z0-9_-]+)\.php', repl_url, content)
    
    # 4. redirect
    def repl_redirect(m):
        base = m.group(1)
        if base == "index":
            return f"php_redirect(url('/'))"
        return f"php_redirect(url('{base}'))"
    content = re.sub(r'(?i)\bredirect\([\'"]([a-zA-Z0-9_-]+)\.php[\'"]\)', repl_redirect, content)

    # 5. location.href
    def repl_location(m):
        quote = m.group(1)
        base = m.group(2)
        if base == "index":
            return f"location.href={quote}{{{{ url('/') }}}}"
        return f"location.href={quote}{{{{ url('{base}') }}}}"
    content = re.sub(r'(?i)location\.href=([\'"])([a-zA-Z0-9_-]+)\.php', repl_location, content)
    
    # 6. fetch
    def repl_fetch(m):
        quote = m.group(1)
        base = m.group(2)
        if base == "index":
            return f"fetch({quote}{{{{ url('/') }}}}"
        return f"fetch({quote}{{{{ url('{base}') }}}}"
    content = re.sub(r'(?i)fetch\(([\'"])([a-zA-Z0-9_-]+)\.php', repl_fetch, content)
    
    return content

src_file = os.path.join(source_dir, "camp_manager_dashboard.php")
dest_file = os.path.join(target_dir, "camp_manager_dashboard.blade.php")

with open(src_file, 'r', encoding='utf-8') as f:
    original = f.read()

new_content = convert(original)

with open(dest_file, 'w', encoding='utf-8') as f:
    f.write(new_content)

print("Synced camp_manager_dashboard.php to camp_manager_dashboard.blade.php")
