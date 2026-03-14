// ─────────────────────────────────────────────────────────────────────────────
// Global state
// ─────────────────────────────────────────────────────────────────────────────
let currentUser = null;
let currentBill = [];
let products    = [];
let customers   = [];
let settings    = {
    storeName:      'B Traders',
    storeAddress:   '',
    storePhone:     '',
    storeEmail:     '',
    taxRate:        10,
    taxName:        'VAT',
    currencySymbol: 'Rs.',
    theme:          'light'
};

// ─────────────────────────────────────────────────────────────────────────────
// Thin API wrapper  —  all calls go through here
// ─────────────────────────────────────────────────────────────────────────────
async function api(action, method = 'GET', body = null, extra = {}) {
    const url = new URL('api.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(extra).forEach(([k, v]) => url.searchParams.set(k, v));

    const opts = { method };
    if (body) {
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body    = JSON.stringify(body);
    }

    try {
        const res  = await fetch(url, opts);
        const data = await res.json();
        return data;
    } catch (err) {
        console.error('API error:', err);
        return { success: false, message: 'Network error' };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    // Check for an existing server session (page refresh)
    const data = await api('session');
    if (data.success) {
        currentUser = data.user;
        document.getElementById('currentUser').textContent = formatName(currentUser.full_name);
        await loadSettings();
        showDashboard();
    } else {
        showLoginScreen();
    }
});

function formatName(name) {
    return name ? name.split(' ')[0] : 'User';
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────
async function login() {
    const username     = document.getElementById('username').value.trim();
    const password     = document.getElementById('password').value;
    const errorEl      = document.getElementById('loginError');
    const toggleIcon   = document.getElementById('togglePassword');

    errorEl.textContent = '';

    if (!username || !password) {
        errorEl.textContent = 'Please enter username and password';
        return;
    }

    showLoading();
    const data = await api('login', 'POST', { username, password });
    hideLoading();

    if (data.success) {
        currentUser = data.user;
        document.getElementById('currentUser').textContent = formatName(currentUser.full_name);
        // Reset password field visibility
        document.getElementById('password').type = 'password';
        toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
        await loadSettings();
        showDashboard();
        showNotification('Welcome, ' + currentUser.full_name + '!', 'success');
    } else {
        errorEl.textContent = data.message || 'Invalid credentials';
        showNotification('Login failed', 'error');
        document.getElementById('password').type = 'password';
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
    }
}

async function logout() {
    await api('logout', 'POST');
    currentUser = null;
    clearBill();
    products   = [];
    customers  = [];
    showLoginScreen();
    showNotification('Logged out successfully', 'success');
}

function showLoginScreen() {
    document.getElementById('loginScreen').style.display = 'flex';
    document.getElementById('dashboard').style.display   = 'none';
}

async function showDashboard() {
    document.getElementById('loginScreen').style.display = 'none';
    document.getElementById('dashboard').style.display   = 'block';
    await loadProducts();
    showSection('billing');
}

// ─────────────────────────────────────────────────────────────────────────────
// Navigation
// ─────────────────────────────────────────────────────────────────────────────
function showSection(name) {
    ['billing','reports','products','customers','settings'].forEach(s => {
        document.getElementById(s + 'Section').style.display = 'none';
    });
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));

    document.getElementById(name + 'Section').style.display = 'block';
    const navEl = document.querySelector(`[data-section="${name}"]`);
    if (navEl) navEl.classList.add('active');

    const titles = {
        billing:   'Billing System',
        reports:   'Sales Reports',
        products:  'Product Management',
        customers: 'Customer Management',
        settings:  'System Settings'
    };
    document.getElementById('pageTitle').textContent = titles[name] || name;

    if (name === 'reports')   loadReports();
    if (name === 'products')  loadProductsTable();
    if (name === 'customers') loadCustomersTable();
    if (name === 'settings')  populateSettingsForm();
}

// ─────────────────────────────────────────────────────────────────────────────
// Billing
// ─────────────────────────────────────────────────────────────────────────────
function toggleAddMode(mode) {
    document.getElementById('quickAddMode').style.display  = mode === 'quick'  ? 'block' : 'none';
    document.getElementById('manualAddMode').style.display = mode === 'manual' ? 'block' : 'none';
    document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}

function updateProductPrice() {
    const productSelect  = document.getElementById('productSelect');
    const unitPriceInput = document.getElementById('unitPrice');
    const selected       = products.find(p => p.id == productSelect.value);

    if (selected) {
        unitPriceInput.value = parseFloat(selected.price).toFixed(2);
        calculateSubtotal();
    } else {
        unitPriceInput.value = '';
    }
}

function calculateSubtotal() {
    const qty      = parseFloat(document.getElementById('quantity').value)   || 0;
    const price    = parseFloat(document.getElementById('unitPrice').value)  || 0;
    const discount = parseFloat(document.getElementById('discount').value)   || 0;
    const sub      = qty * price * (1 - discount / 100);
    document.getElementById('subtotalPreview').value = settings.currencySymbol + ' ' + sub.toFixed(2);
}

function calculateManualSubtotal() {
    const qty      = parseFloat(document.getElementById('manualQuantity').value)  || 0;
    const price    = parseFloat(document.getElementById('manualUnitPrice').value) || 0;
    const discount = parseFloat(document.getElementById('manualDiscount').value)  || 0;
    const sub      = qty * price * (1 - discount / 100);
    document.getElementById('manualSubtotalPreview').value = settings.currencySymbol + ' ' + sub.toFixed(2);
}

function addItem() {
    const productSelect = document.getElementById('productSelect');
    const qty           = parseFloat(document.getElementById('quantity').value);
    const unitPrice     = parseFloat(document.getElementById('unitPrice').value);
    const discount      = parseFloat(document.getElementById('discount').value) || 0;

    if (!productSelect.value || !qty || !unitPrice) {
        showNotification('Please fill all required fields', 'error');
        return;
    }

    const selected  = products.find(p => p.id == productSelect.value);
    const subtotal  = qty * unitPrice * (1 - discount / 100);

    currentBill.push({
        id:          Date.now(),
        productId:   selected.id,
        productName: selected.name,
        quantity:    qty,
        unitPrice:   unitPrice,
        discount:    discount,
        subtotal:    subtotal
    });

    updateBillTable();
    clearAddForm();
    showNotification('Item added', 'success');
}

function addManualItem() {
    const name      = document.getElementById('manualProductName').value.trim();
    const qty       = parseFloat(document.getElementById('manualQuantity').value);
    const unitPrice = parseFloat(document.getElementById('manualUnitPrice').value);
    const discount  = parseFloat(document.getElementById('manualDiscount').value) || 0;

    if (!name || !qty || !unitPrice) {
        showNotification('Please fill all required fields', 'error');
        return;
    }

    const subtotal = qty * unitPrice * (1 - discount / 100);

    currentBill.push({
        id:          Date.now(),
        productId:   null,
        productName: name,
        quantity:    qty,
        unitPrice:   unitPrice,
        discount:    discount,
        subtotal:    subtotal
    });

    updateBillTable();
    clearManualForm();
    showNotification('Item added', 'success');
}

function removeItem(itemId) {
    currentBill = currentBill.filter(i => i.id !== itemId);
    updateBillTable();
}

function updateBillTable() {
    const tbody = document.getElementById('billTableBody');
    if (!currentBill.length) {
        tbody.innerHTML = '<tr class="empty-row"><td colspan="7">No items added yet</td></tr>';
    } else {
        tbody.innerHTML = currentBill.map((item, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td>${item.productName}</td>
                <td>${item.quantity}</td>
                <td>${settings.currencySymbol} ${item.unitPrice.toFixed(2)}</td>
                <td>${item.discount}%</td>
                <td>${settings.currencySymbol} ${item.subtotal.toFixed(2)}</td>
                <td><button class="remove-btn" onclick="removeItem(${item.id})">
                    <i class="fas fa-trash"></i></button></td>
            </tr>`).join('');
    }
    updateBillSummary();
}

function updateBillSummary() {
    const subtotal     = currentBill.reduce((s, i) => s + i.subtotal, 0);
    const totalDisc    = currentBill.reduce((s, i) => s + (i.quantity * i.unitPrice * i.discount / 100), 0);
    const shipping     = parseFloat(document.getElementById('shippingCost').value) || 0;
    const taxAmount    = subtotal * settings.taxRate / 100;
    const finalTotal   = subtotal + taxAmount + shipping;

    document.getElementById('subtotal').textContent     = settings.currencySymbol + ' ' + subtotal.toFixed(2);
    document.getElementById('totalDiscount').textContent = settings.currencySymbol + ' ' + totalDisc.toFixed(2);
    document.getElementById('tax').textContent          = settings.currencySymbol + ' ' + taxAmount.toFixed(2);
    document.getElementById('finalTotal').textContent   = settings.currencySymbol + ' ' + finalTotal.toFixed(2);
}

function clearAddForm() {
    ['productSelect','quantity','unitPrice','discount','subtotalPreview'].forEach(id => {
        document.getElementById(id).value = '';
    });
}

function clearManualForm() {
    ['manualProductName','manualQuantity','manualUnitPrice','manualDiscount','manualSubtotalPreview'].forEach(id => {
        document.getElementById(id).value = '';
    });
}

function clearBill() {
    currentBill = [];
    updateBillTable();
    ['customerName','customerPhone','customerEmail','customerAddress','shippingCost'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.querySelector('input[name="paymentMethod"][value="cash"]').checked = true;
    showNotification('Bill cleared', 'success');
}

function printBill() {
    if (!currentBill.length) { showNotification('No items to print', 'error'); return; }
    document.getElementById('printTemplate').innerHTML = generatePrintContent();
    window.print();
}

function downloadBill() {
    if (!currentBill.length) { showNotification('No items to download', 'error'); return; }

    const { jsPDF } = window.jspdf;
    const doc       = new jsPDF();
    const sym       = settings.currencySymbol;

    doc.setFontSize(18); doc.text(settings.storeName, 20, 20);
    doc.setFontSize(10);
    doc.text(settings.storeAddress, 20, 28);
    doc.text('Tel: ' + settings.storePhone + '  |  ' + settings.storeEmail, 20, 34);

    const billNum  = 'BILL-' + Date.now();
    const customer = document.getElementById('customerName').value || 'Walk-in Customer';
    doc.text('Bill #: ' + billNum,                    20, 46);
    doc.text('Date: ' + new Date().toLocaleDateString(), 20, 52);
    doc.text('Customer: ' + customer,                 20, 58);
    doc.text('Cashier: ' + (currentUser?.full_name || ''), 20, 64);

    let y = 76;
    doc.setFont(undefined, 'bold');
    doc.text('#  Product            Qty  Price      Total',  20, y); y += 6;
    doc.setFont(undefined, 'normal');
    doc.line(20, y, 190, y); y += 4;

    currentBill.forEach((item, i) => {
        const line = `${i+1}. ${item.productName.substring(0,18).padEnd(20)} ${String(item.quantity).padEnd(5)} ${(sym+item.unitPrice.toFixed(2)).padEnd(11)} ${sym+item.subtotal.toFixed(2)}`;
        doc.text(line, 20, y); y += 7;
        if (y > 270) { doc.addPage(); y = 20; }
    });

    const subtotal   = currentBill.reduce((s, i) => s + i.subtotal, 0);
    const tax        = subtotal * settings.taxRate / 100;
    const shipping   = parseFloat(document.getElementById('shippingCost').value) || 0;
    const total      = subtotal + tax + shipping;

    y += 4; doc.line(20, y, 190, y); y += 8;
    doc.text('Subtotal: ' + sym + subtotal.toFixed(2), 130, y); y += 7;
    doc.text('Tax (' + settings.taxRate + '%): ' + sym + tax.toFixed(2), 130, y); y += 7;
    doc.text('Shipping: ' + sym + shipping.toFixed(2), 130, y); y += 7;
    doc.setFont(undefined, 'bold');
    doc.text('TOTAL: ' + sym + total.toFixed(2), 130, y);

    doc.save('bill_' + billNum + '.pdf');
    showNotification('PDF downloaded', 'success');
}

async function saveBill() {
    if (!currentBill.length) { showNotification('No items to save', 'error'); return; }

    const customerName  = document.getElementById('customerName').value.trim();
    const customerPhone = document.getElementById('customerPhone').value.trim();
    const customerEmail = document.getElementById('customerEmail').value.trim();
    const customerAddr  = document.getElementById('customerAddress').value.trim();

    const subtotal  = currentBill.reduce((s, i) => s + i.subtotal, 0);
    const taxAmt    = subtotal * settings.taxRate / 100;
    const shipping  = parseFloat(document.getElementById('shippingCost').value) || 0;
    const total     = subtotal + taxAmt + shipping;
    const totalDisc = currentBill.reduce((s, i) => s + (i.quantity * i.unitPrice * i.discount / 100), 0);

    // ── Auto-create customer if name + phone provided ──────────────────
    let customerId = null;
    if (customerName && customerName !== 'Walk-in Customer' && customerPhone) {
        const existing = await api('customers', 'GET', null, { search: customerPhone });
        const found = existing.success && existing.customers.find(
            c => c.phone === customerPhone
        );
        if (found) {
            customerId = found.id;
        } else {
            const newCust = await api('customers', 'POST', {
                name:    customerName,
                phone:   customerPhone,
                email:   customerEmail,
                address: customerAddr
            });
            if (newCust.success) customerId = newCust.customer_id;
        }
    }

    const payload = {
        customer_id:      customerId,
        customer_name:    customerName || 'Walk-in Customer',
        customer_phone:   customerPhone,
        customer_email:   customerEmail,
        customer_address: customerAddr,
        items: currentBill.map(item => ({
            product_id:          item.productId || null,
            product_name:        item.productName,
            quantity:            item.quantity,
            unit_price:          item.unitPrice,
            discount_percentage: item.discount,
            discount_amount:     item.quantity * item.unitPrice * item.discount / 100,
            subtotal:            item.subtotal
        })),
        subtotal:        subtotal,
        discount_amount: totalDisc,
        tax_amount:      taxAmt,
        shipping_cost:   shipping,
        total_amount:    total,
        payment_method:  document.querySelector('input[name="paymentMethod"]:checked').value,
        payment_status:  'paid'
    };

    showLoading();
    const data = await api('bills', 'POST', payload);
    hideLoading();

    if (data.success) {
        clearBill();
        showNotification('Bill #' + data.bill_number + ' saved!', 'success');
    } else {
        showNotification(data.message || 'Failed to save bill', 'error');
    }
}

function generatePrintContent() {
    const customer   = document.getElementById('customerName').value || 'Walk-in Customer';
    const subtotal   = currentBill.reduce((s, i) => s + i.subtotal, 0);
    const taxAmount  = subtotal * settings.taxRate / 100;
    const shipping   = parseFloat(document.getElementById('shippingCost').value) || 0;
    const total      = subtotal + taxAmount + shipping;
    const sym        = settings.currencySymbol;

    const rows = currentBill.map((item, idx) => `
        <tr>
            <td>${idx + 1}</td>
            <td>${item.productName}</td>
            <td>${item.quantity}</td>
            <td>${sym} ${item.unitPrice.toFixed(2)}</td>
            <td>${sym} ${item.subtotal.toFixed(2)}</td>
        </tr>`).join('');

    return `
        <div class="print-header">
            <h2>${settings.storeName}</h2>
            <p>${settings.storeAddress}</p>
            <p>Tel: ${settings.storePhone}  |  ${settings.storeEmail}</p>
        </div>
        <div class="print-bill-details">
            <p><strong>Date:</strong> ${new Date().toLocaleString()}</p>
            <p><strong>Customer:</strong> ${customer}</p>
            <p><strong>Cashier:</strong> ${currentUser?.full_name || ''}</p>
        </div>
        <div class="print-items">
            <table>
                <thead><tr><th>S.No</th><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div class="print-summary">
            <p><strong>Subtotal:</strong> ${sym} ${subtotal.toFixed(2)}</p>
            <p><strong>${settings.taxName} (${settings.taxRate}%):</strong> ${sym} ${taxAmount.toFixed(2)}</p>
            <p><strong>Shipping:</strong> ${sym} ${shipping.toFixed(2)}</p>
            <p><strong>Total:</strong> ${sym} ${total.toFixed(2)}</p>
        </div>
        <div class="print-footer"><p>Thank you for your business!</p></div>`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Products
// ─────────────────────────────────────────────────────────────────────────────
async function loadProducts() {
    const data = await api('products');
    if (data.success) {
        products = data.products;
        populateProductSelect();
    }
}

function populateProductSelect() {
    const sel = document.getElementById('productSelect');
    sel.innerHTML = '<option value="">Select Product</option>';
    products.forEach(p => {
        const opt = document.createElement('option');
        opt.value       = p.id;
        opt.textContent = `${p.name} — ${settings.currencySymbol} ${parseFloat(p.price).toFixed(2)}`;
        sel.appendChild(opt);
    });
}

async function loadProductsTable() {
    const data = await api('products');
    if (!data.success) return;
    products = data.products;

    const tbody = document.getElementById('productsTableBody');
    if (!products.length) {
        tbody.innerHTML = '<tr><td colspan="6">No products found</td></tr>';
        return;
    }
    tbody.innerHTML = products.map(p => `
        <tr>
            <td>${p.id}</td>
            <td>${p.name}</td>
            <td>${settings.currencySymbol} ${parseFloat(p.price).toFixed(2)}</td>
            <td>${p.stock}</td>
            <td>${p.category}</td>
            <td>
                <button class="btn" onclick="editProduct(${p.id})">Edit</button>
                <button class="remove-btn" onclick="deleteProduct(${p.id})">Delete</button>
            </td>
        </tr>`).join('');
}

function showAddProductForm() {
    document.getElementById('addProductForm').style.display = 'block';
    clearProductForm();
}

function hideAddProductForm() {
    document.getElementById('addProductForm').style.display = 'none';
    clearProductForm();
}

function clearProductForm() {
    ['newProductName','newProductPrice','newProductStock'].forEach(id => {
        document.getElementById(id).value = '';
    });
    // FIX: reset to 'Others' — the first valid category in the HTML
    document.getElementById('newProductCategory').value = 'Others';
    // Clear hidden edit id
    const form = document.getElementById('addProductForm');
    delete form.dataset.editId;
    const heading = form.querySelector('h3');
    if (heading) heading.textContent = 'Add New Product';
    const btn = form.querySelector('.save-product-btn');
    if (btn) btn.textContent = '💾 Save Product';
}

async function saveProduct() {
    const name     = document.getElementById('newProductName').value.trim();
    const price    = parseFloat(document.getElementById('newProductPrice').value);
    const stock    = parseInt(document.getElementById('newProductStock').value);
    const category = document.getElementById('newProductCategory').value;

    if (!name || isNaN(price) || price <= 0 || isNaN(stock)) {
        showNotification('Please fill all required fields with valid values', 'error');
        return;
    }

    const payload = { name, price, stock, category };
    const form    = document.getElementById('addProductForm');
    const editId  = form.dataset.editId;

    showLoading();
    let data;
    if (editId) {
        data = await api('products', 'PUT', { ...payload, id: parseInt(editId) });
    } else {
        data = await api('products', 'POST', payload);
    }
    hideLoading();

    if (data.success) {
        showNotification(editId ? 'Product updated' : 'Product added', 'success');
        hideAddProductForm();
        await loadProductsTable();
        await loadProducts(); // refresh select dropdown
    } else {
        showNotification(data.message || 'Failed to save product', 'error');
    }
}

// FIX: function was missing — referenced in loadProductsTable HTML
async function editProduct(id) {
    const product = products.find(p => p.id == id);
    if (!product) return;

    document.getElementById('newProductName').value     = product.name;
    document.getElementById('newProductPrice').value    = parseFloat(product.price).toFixed(2);
    document.getElementById('newProductStock').value    = product.stock;
    document.getElementById('newProductCategory').value = product.category;

    const form    = document.getElementById('addProductForm');
    form.dataset.editId = id;
    form.style.display  = 'block';

    const heading = form.querySelector('h3');
    if (heading) heading.textContent = 'Edit Product';
    const btn = form.querySelector('.save-product-btn');
    if (btn) btn.textContent = '💾 Update Product';

    form.scrollIntoView({ behavior: 'smooth' });
}

async function deleteProduct(id) {
    if (!confirm('Delete this product?')) return;

    showLoading();
    const data = await api('products', 'DELETE', null, { id });
    hideLoading();

    if (data.success) {
        showNotification('Product deleted', 'success');
        await loadProductsTable();
        await loadProducts();
    } else {
        showNotification(data.message || 'Delete failed', 'error');
    }
}

async function searchProducts() {
    const q    = document.getElementById('productSearch').value.trim();
    const data = q ? await api('products', 'GET', null, { search: q }) : await api('products');

    if (!data.success) return;

    const tbody = document.getElementById('productsTableBody');
    const list  = data.products;
    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6">No products found</td></tr>';
        return;
    }
    tbody.innerHTML = list.map(p => `
        <tr>
            <td>${p.id}</td>
            <td>${p.name}</td>
            <td>${settings.currencySymbol} ${parseFloat(p.price).toFixed(2)}</td>
            <td>${p.stock}</td>
            <td>${p.category}</td>
            <td>
                <button class="btn" onclick="editProduct(${p.id})">Edit</button>
                <button class="remove-btn" onclick="deleteProduct(${p.id})">Delete</button>
            </td>
        </tr>`).join('');
}

// ─────────────────────────────────────────────────────────────────────────────
// Customers
// ─────────────────────────────────────────────────────────────────────────────
async function loadCustomersTable() {
    const data = await api('customers');
    if (!data.success) return;
    customers = data.customers;

    const tbody = document.getElementById('customersTableBody');
    if (!customers.length) {
        tbody.innerHTML = '<tr><td colspan="7">No customers found</td></tr>';
        return;
    }
    tbody.innerHTML = customers.map(c => `
        <tr>
            <td>${c.id}</td>
            <td>${c.name}</td>
            <td>${c.phone || '—'}</td>
            <td>${c.email || '—'}</td>
            <td>${c.total_orders}</td>
            <td>${settings.currencySymbol} ${parseFloat(c.total_spent).toFixed(2)}</td>
            <td>
                <button class="btn" onclick="viewCustomer(${c.id})">View</button>
                <button class="remove-btn" onclick="deleteCustomer(${c.id})">Delete</button>
            </td>
        </tr>`).join('');
}

// FIX: function was missing — referenced in loadCustomersTable HTML
async function viewCustomer(id) {
    const data = await api('customers', 'GET', null, { id });
    if (!data.success) { showNotification('Customer not found', 'error'); return; }

    const c = data.customer;
    const orders = (c.orders || []).map(o =>
        `  • ${o.bill_number} — ${settings.currencySymbol} ${parseFloat(o.total_amount).toFixed(2)}`
    ).join('\n') || '  (none)';

    alert(`Customer: ${c.name}\nPhone: ${c.phone || '—'}\nEmail: ${c.email || '—'}\nTotal Orders: ${c.total_orders}\nTotal Spent: ${settings.currencySymbol} ${parseFloat(c.total_spent).toFixed(2)}\n\nRecent Orders:\n${orders}`);
}

async function deleteCustomer(id) {
    if (!confirm('Delete this customer?')) return;

    showLoading();
    const data = await api('customers', 'DELETE', null, { id });
    hideLoading();

    if (data.success) {
        showNotification('Customer deleted', 'success');
        await loadCustomersTable();
    } else {
        showNotification(data.message || 'Delete failed', 'error');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Reports / Dashboard
// ─────────────────────────────────────────────────────────────────────────────
async function loadReports() {
    showLoading();
    const data = await api('dashboard');
    hideLoading();
    if (!data.success) return;

    const d   = data.data;
    const sym = settings.currencySymbol;

    document.getElementById('todaySales').textContent    = sym + ' ' + parseFloat(d.today_sales.total_sales  || 0).toFixed(2);
    document.getElementById('todayBills').textContent    = d.today_sales.bill_count   || 0;
    document.getElementById('totalOrders').textContent   = d.monthly_sales.bill_count || 0;
    document.getElementById('monthlyRevenue').textContent = sym + ' ' + parseFloat(d.monthly_sales.total_sales || 0).toFixed(2);
    document.getElementById('totalCustomers').textContent = d.total_customers || 0;

    // Recent bills table
    const tbody = document.getElementById('recentBillsTable');
    const bills = d.recent_bills || [];
    if (!bills.length) {
        tbody.innerHTML = '<tr><td colspan="6">No recent bills</td></tr>';
        return;
    }
    tbody.innerHTML = bills.map(b => `
        <tr>
            <td>${b.bill_number}</td>
            <td>${new Date(b.created_at).toLocaleDateString()}</td>
            <td>${b.customer_name}</td>
            <td>${sym} ${parseFloat(b.total_amount).toFixed(2)}</td>
            <td>${b.payment_method}</td>
            <td><button class="btn" onclick="viewBill(${b.id})">View</button>
                <button class="remove-btn" onclick="deleteBill(${b.id})">Delete</button></td>
        </tr>`).join('');
}

async function viewBill(id) {
    const data = await api('bills', 'GET', null, { id });
    if (!data.success) { showNotification('Bill not found', 'error'); return; }
    const b = data.bill;
    alert(`Bill: ${b.bill_number}\nCustomer: ${b.customer_name}\nTotal: ${settings.currencySymbol} ${parseFloat(b.total_amount).toFixed(2)}\nPayment: ${b.payment_method}\nDate: ${new Date(b.created_at).toLocaleString()}`);
}

async function deleteBill(id) {
    if (!confirm('Delete this bill? Stock will be restored.')) return;

    showLoading();
    const data = await api('bills', 'DELETE', null, { id });
    hideLoading();

    if (data.success) {
        showNotification('Bill deleted', 'success');
        loadReports();
    } else {
        showNotification(data.message || 'Delete failed', 'error');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Settings
// ─────────────────────────────────────────────────────────────────────────────
async function loadSettings() {
    const data = await api('settings');
    if (!data.success) return;

    const s = data.settings;
    settings.storeName      = s.store_name      || settings.storeName;
    settings.storeAddress   = s.store_address   || '';
    settings.storePhone     = s.store_phone     || '';
    settings.storeEmail     = s.store_email     || '';
    settings.taxRate        = parseFloat(s.tax_rate)  || 10;
    settings.taxName        = s.tax_name        || 'VAT';
    settings.currencySymbol = s.currency_symbol || 'Rs.';
    settings.theme          = s.theme           || 'light';

    populateSettingsForm();
    updateBillSummary();
}

function populateSettingsForm() {
    const ids = {
        storeName:      'storeName',
        storeAddress:   'storeAddress',
        storePhone:     'storePhone',
        storeEmail:     'storeEmail',
        taxRate:        'taxRate',
        taxName:        'taxName',
        currencySymbol: 'currencySymbol',
        theme:          'theme'
    };
    Object.entries(ids).forEach(([key, id]) => {
        const el = document.getElementById(id);
        if (el) el.value = settings[key];
    });
}

async function saveSettings() {
    const payload = {
        store_name:      document.getElementById('storeName').value,
        store_address:   document.getElementById('storeAddress').value,
        store_phone:     document.getElementById('storePhone').value,
        store_email:     document.getElementById('storeEmail').value,
        tax_rate:        document.getElementById('taxRate').value,
        tax_name:        document.getElementById('taxName').value,
        currency_symbol: document.getElementById('currencySymbol').value,
        theme:           document.getElementById('theme').value
    };

    showLoading();
    const data = await api('settings', 'POST', payload);
    hideLoading();

    if (data.success) {
        await loadSettings();
        populateProductSelect();
        showNotification('Settings saved', 'success');
    } else {
        showNotification(data.message || 'Failed to save settings', 'error');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// UI Utilities
// ─────────────────────────────────────────────────────────────────────────────
function showNotification(message, type = 'success') {
    const el = document.getElementById('notification');
    el.className = 'notification ' + type;
    document.getElementById('notificationText').textContent = message;
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 3500);
}

function hideNotification() {
    document.getElementById('notification').style.display = 'none';
}

function showLoading() {
    document.getElementById('loading').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loading').style.display = 'none';
}

// ─────────────────────────────────────────────────────────────────────────────
// Event listeners
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && document.getElementById('loginScreen').style.display !== 'none') {
        login();
    }
});

document.getElementById('togglePassword').addEventListener('click', function () {
    const input = document.getElementById('password');
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    this.classList.toggle('fa-eye',       !isHidden);
    this.classList.toggle('fa-eye-slash',  isHidden);
});


function showAddCustomerForm() {
    document.getElementById('addCustomerForm').style.display = 'block';
}

function hideAddCustomerForm() {
    document.getElementById('addCustomerForm').style.display = 'none';
    ['newCustomerName','newCustomerPhone','newCustomerEmail','newCustomerAddress']
        .forEach(id => document.getElementById(id).value = '');
}

async function saveNewCustomer() {
    const name    = document.getElementById('newCustomerName').value.trim();
    const phone   = document.getElementById('newCustomerPhone').value.trim();
    const email   = document.getElementById('newCustomerEmail').value.trim();
    const address = document.getElementById('newCustomerAddress').value.trim();

    if (!name || !phone) {
        showNotification('Name and phone are required', 'error');
        return;
    }

    showLoading();
    const data = await api('customers', 'POST', { name, phone, email, address });
    hideLoading();

    if (data.success) {
        showNotification('Customer added successfully!', 'success');
        hideAddCustomerForm();
        loadCustomersTable();
    } else {
        showNotification(data.message || 'Failed to add customer', 'error');
    }
}