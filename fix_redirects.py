import os
import re

target_dir = r"c:\xamppsoft\htdocs\hello\hello\disaster_relief_app\resources\views"

for fname in os.listdir(target_dir):
    if not fname.endswith(".blade.php"):
        continue
        
    target_path = os.path.join(target_dir, fname)
    with open(target_path, "r", encoding="utf-8") as f:
        content = f.read()
        
    # Replace all \bredirect( with php_redirect(
    new_content = re.sub(r'\bredirect\(', 'php_redirect(', content)
    
    if new_content != content:
        with open(target_path, "w", encoding="utf-8") as f:
            f.write(new_content)
        print(f"Updated {fname}")

print("Done")
