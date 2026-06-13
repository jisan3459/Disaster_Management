import re

filepath = r'c:\xamppsoft\htdocs\hello\hello\disaster_relief_app\resources\views\donor_dashboard.blade.php'

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

# Update HTML for donate page inside donor dashboard
old_donate_form = """<div class="form-group">
                                    <label>Donation Type</label>
                                    <select name="donation_type" class="form-control" required>
                                        <option value="money">Financial Contribution</option>
                                        <option value="supplies">Relief Supplies</option>
                                        <option value="other">Other Resources</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Amount (USD)</label>
                                    <input type="number" name="amount" class="form-control" min="1" step="0.01" required>
                                </div>
                                <div class="form-group">
                                    <label>Payment Method</label>
                                    <select name="payment_method" class="form-control" required>
                                        <option value="">Select a method...</option>
                                        <option value="credit_card">Credit Card</option>
                                        <option value="paypal">PayPal</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Message of Support (Optional)</label>
                                    <textarea name="message" class="form-control" rows="3"></textarea>
                                </div>"""

new_donate_form = """<div class="form-group">
                                    <label>Donation Type</label>
                                    <select name="donation_type" id="donationType" class="form-control" required>
                                        <option value="money">Financial Contribution</option>
                                        <option value="supplies">Relief Supplies</option>
                                        <option value="other">Other Resources</option>
                                    </select>
                                </div>
                                <div class="form-group" id="amountGroup">
                                    <label>Amount (USD)</label>
                                    <input type="number" name="amount" id="amountInput" class="form-control" min="1" step="0.01" required>
                                </div>
                                <div class="form-group" id="paymentMethodGroup">
                                    <label>Payment Method</label>
                                    <select name="payment_method" id="paymentMethodInput" class="form-control" required>
                                        <option value="">Select a method...</option>
                                        <option value="credit_card">Credit Card</option>
                                        <option value="paypal">PayPal</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                    </select>
                                </div>
                                <div class="form-group" id="itemsDescriptionGroup" style="display: none;">
                                    <label>Items Description</label>
                                    <textarea name="items_description" id="itemsDescriptionInput" class="form-control" rows="2" placeholder="Describe the items you are donating (e.g., 50 blankets, 100 canned goods)"></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Message of Support (Optional)</label>
                                    <textarea name="message" class="form-control" rows="3"></textarea>
                                </div>
                                <script>
                                    document.getElementById('donationType').addEventListener('change', function() {
                                        const type = this.value;
                                        const amtGrp = document.getElementById('amountGroup');
                                        const payGrp = document.getElementById('paymentMethodGroup');
                                        const itemGrp = document.getElementById('itemsDescriptionGroup');
                                        const amtInp = document.getElementById('amountInput');
                                        const payInp = document.getElementById('paymentMethodInput');
                                        const itemInp = document.getElementById('itemsDescriptionInput');

                                        if (type === 'money') {
                                            amtGrp.style.display = 'block';
                                            payGrp.style.display = 'block';
                                            itemGrp.style.display = 'none';
                                            amtInp.required = true;
                                            payInp.required = true;
                                            itemInp.required = false;
                                        } else {
                                            amtGrp.style.display = 'none';
                                            payGrp.style.display = 'none';
                                            itemGrp.style.display = 'block';
                                            amtInp.required = false;
                                            payInp.required = false;
                                            itemInp.required = true;
                                            amtInp.value = 0;
                                        }
                                    });
                                </script>"""

content = content.replace(old_donate_form, new_donate_form)

# Add is_read property lookup in history table if it exists
with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)
print("Updated donor dashboard phase 3")
