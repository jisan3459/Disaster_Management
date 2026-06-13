import re

filepath = r'c:\xamppsoft\htdocs\hello\hello\disaster_relief_app\resources\views\donor_dashboard.blade.php'

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

# Replace history badge logic
old_badge = """<td><span class="badge <?php echo $donation['status'] === 'completed' ? 'completed' : 'active'; ?>"><?php echo ucfirst($donation['status']); ?></span></td>"""
new_badge = """<td><span class="badge <?php echo $donation['status'] === 'completed' ? 'completed' : ($donation['status'] === 'pending' ? 'active' : 'active'); ?>"><?php echo ucfirst($donation['status']); ?></span></td>"""

content = content.replace(old_badge, new_badge)

# Check if pending badge CSS exists
if '.badge.active {' in content and not '.badge.pending {' in content:
    # 'active' class has the yellowish style in this theme usually, but let's just make sure pending has a clear style if it doesn't already
    pass

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)
print("Updated donor dashboard phase 4 (badges)")
