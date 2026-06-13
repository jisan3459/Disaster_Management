import re

filepath = r'c:\xamppsoft\htdocs\hello\hello\disaster_relief_app\resources\views\donor_dashboard.blade.php'

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

# Add cm-dropdown style
style_insert_point = ".badge.completed { background: #dcfce7; color: #166534; }"
style_insert = ".badge.completed { background: #dcfce7; color: #166534; }\n        .cm-dropdown.show { display: block !important; }"
content = content.replace(style_insert_point, style_insert)

# Update Chat UI
old_chat = """<?php elseif ($page === 'chat'): ?>
                    <div class="panel" style="padding: 4rem; text-align: center; border-style: dashed; background: transparent; opacity: 0.7;">
                        <i data-lucide="message-square" style="width: 48px; height: 48px; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <h3>Support Chat</h3>
                        <p style="color: var(--text-muted); max-width: 400px; margin: 0 auto 1.5rem;">Connect with our support team or camp managers to ask questions about your donations or learn how you can help further.</p>
                        <form method="POST" style="max-width: 500px; margin: 0 auto; display: flex; gap: 10px;">
                            @csrf
                            <input type="text" name="message" class="form-control" placeholder="Type your message..." required style="flex: 1;">
                            <button type="submit" class="btn btn-primary">Send</button>
                        </form>
                    </div>"""

new_chat = """<?php elseif ($page === 'chat'): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 style="font-size: 1.5rem; font-weight: 700;">Support Chat</h2>
                        <div style="display: flex; gap: 10px;">
                            <span class="badge" style="background: #eff6ff; color: #1e40af;">Admin Contact</span>
                        </div>
                    </div>
                    <div class="panel" style="max-width: 800px;">
                        <div style="height: 400px; background: #f8fafc; border-radius: 18px; padding: 1.5rem; overflow-y: auto; display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;" id="chatContainer">
                            <?php 
                            $admin_query = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                            $admin_id = ($admin_query && $admin_query->num_rows > 0) ? $admin_query->fetch_assoc()['id'] : 1;
                            
                            $conn->query("UPDATE messages SET is_read = 1 WHERE sender_id = $admin_id AND receiver_id = $user_id AND is_read = 0");

                            $messages = $conn->query("SELECT * FROM messages WHERE (sender_id = $user_id AND receiver_id = $admin_id) OR (sender_id = $admin_id AND receiver_id = $user_id) ORDER BY created_at ASC");
                            if ($messages && $messages->num_rows > 0):
                                while ($msg = $messages->fetch_assoc()):
                                    $is_me = ($msg['sender_id'] == $user_id);
                            ?>
                                <div style="background: <?php echo $is_me ? '#2563eb' : 'white'; ?>; color: <?php echo $is_me ? 'white' : '#111827'; ?>; padding: 0.8rem 1.2rem; border-radius: 14px; max-width: 80%; <?php echo $is_me ? 'align-self: flex-end; border-bottom-right-radius: 4px;' : 'align-self: flex-start; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-bottom-left-radius: 4px;'; ?>">
                                    <div style="font-size: 0.95rem; line-height: 1.4;"><?php echo htmlspecialchars($msg['message_text']); ?></div>
                                    <div style="font-size: 0.7rem; color: <?php echo $is_me ? '#93c5fd' : '#9ca3af'; ?>; margin-top: 0.4rem; text-align: right;">
                                        <?php echo date('M d, g:i a', strtotime($msg['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endwhile; else: ?>
                                <div style="text-align: center; color: #6b7280; font-size: 0.9rem; margin-top: auto; margin-bottom: auto;">
                                    Start a conversation with our support team.
                                </div>
                            <?php endif; ?>
                        </div>
                        <form method="POST">
                            @csrf
                            <div style="display: flex; gap: 1rem;">
                                <input type="text" name="message" placeholder="Type a message..." style="flex: 1; border: 1px solid #d1d5db; border-radius: 14px; padding: 0.95rem 1rem;" required autocomplete="off" autofocus>
                                <button type="submit" class="btn btn-primary" style="background: #2563eb;">Send</button>
                            </div>
                        </form>
                        <script>
                            const chatContainer = document.getElementById('chatContainer');
                            if (chatContainer) chatContainer.scrollTop = chatContainer.scrollHeight;
                        </script>
                    </div>"""
content = content.replace(old_chat, new_chat)

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)
print("Updated donor dashboard phase 2")
