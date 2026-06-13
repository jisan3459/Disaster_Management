import os

filepath = r'c:\xamppsoft\htdocs\hello\hello\disaster_relief_app\resources\views\donate.blade.php'

with open(filepath, 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Replace lines 63-69 (1-indexed) = 62-68 (0-indexed)
# Remove campaign auto-update, keep just success assignment
new_lines = lines[:62]  # lines 1-62
new_lines.append('            if ($conn->query($insert_donation)) {\n')
new_lines.append('                $donation_success = true;\n')
msg = '                $success_message = "Thank you for your donation of \\$$amount! Your contribution is now pending verification and will make a real difference once approved.";\n'
new_lines.append(msg)
new_lines.extend(lines[69:])  # line 70 onwards

with open(filepath, 'w', encoding='utf-8', newline='') as f:
    f.writelines(new_lines)

print(f"Done. Old lines: {len(lines)}, New lines: {len(new_lines)}")
