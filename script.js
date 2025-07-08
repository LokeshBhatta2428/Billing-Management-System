// Global variables
let currentUser = null;
let currentBill = [];
let billCounter = 1;
let products = [];
let customers = [];
let bills = [];
let settings = {
    storeName: 'My Store',
    storeAddress: '123 Main Street, City, State',
    storePhone: '+1234567890',
    storeEmail: 'store@example.com',
    taxRate: 10,
    taxName: 'VAT',
    currencySymbol: 'Rs.',
    theme: 'light'
};

// Initialize the application
document.addEventListener('DOMContentLoaded', function() {
    loadData();
    showLoginScreen();
});

// Authentication functions
function login() {
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const errorElement = document.getElementById('loginError');
    
    // Demo credentials
    const credentials = {
        'admin': 'admin123',
        'cashier': 'cashier123',
        'manager': 'manager123'
    };
    
    if (credentials[username] && credentials[username] === password) {
        currentUser = {
            username: username,
            role: username,
            loginTime: new Date()
        };
        
        document.getElementById('currentUser').textContent = 
            username.charAt(0).toUpperCase() + username.slice(1);
        
        showDashboard();
        showNotification('Login successful!', 'success');
    } else {
        errorElement.textContent = 'Invalid username or password';
        showNotification('Invalid credentials', 'error');
    }
}

function logout() {
    currentUser = null;
    clearBill();
    showLoginScreen();
    showNotification('Logged out successfully', 'success');
}

function showLoginScreen() {
    document.getElementById('loginScreen').style.display = 'flex';
    document.getElementById('dashboard').style.display = 'none';
}

function showDashboard() {
    document.getElementById('loginScreen').style.display = 'none';
    document.getElementById('dashboard').style.display = 'block';
    loadProducts();
    loadCustomers();
    updateDashboardStats();
}

// Navigation functions
function showSection(sectionName) {
    // Hide all sections
    const sections = ['billing', 'reports', 'products', 'customers', 'settings'];
    sections.forEach(section => {
        document.getElementById(section + 'Section').style.display = 'none';
    });
    
    // Remove active class from all nav items
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
    });
    
    // Show selected section
    document.getElementById(sectionName + 'Section').style.display = 'block';
    
    // Add active class to selected nav item
    document.querySelector(`[data-section="${sectionName}"]`).classList.add('active');
    
    // Update page title
    const titles = {
        'billing': 'Billing System',
        'reports': 'Sales Reports',
        'products': 'Product Management',
        'customers': 'Customer Management',
        'settings': 'System Settings'
    };
    document.getElementById('pageTitle').textContent = titles[sectionName];
    
    // Load section-specific data
    if (sectionName === 'reports') {
        loadReports();
    } else if (sectionName === 'products') {
        loadProductsTable();
    } else if (sectionName === 'customers') {
        loadCustomersTable();
    }
}

// Billing functions
function toggleAddMode(mode) {
    document.getElementById('quickAddMode').style.display = mode === 'quick' ? 'block' : 'none';
    document.getElementById('manualAddMode').style.display = mode === 'manual' ? 'block' : 'none';
    
    // Update toggle buttons
    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
}

function updateProductPrice() {
    const productSelect = document.getElementById('productSelect');
    const unitPriceInput = document.getElementById('unitPrice');
    const selectedProduct = products.find(p => p.id == productSelect.value);
    
    if (selectedProduct) {
        unitPriceInput.value = selectedProduct.price;
        calculateSubtotal();
    } else {
        unitPriceInput.value = '';
    }
}

function calculateSubtotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitPrice = parseFloat(document.getElementById('unitPrice').value) || 0;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    
    const subtotal = quantity * unitPrice;
    const discountAmount = (subtotal * discount) / 100;
    const finalSubtotal = subtotal - discountAmount;
    
    document.getElementById('subtotalPreview').value = 
        settings.currencySymbol + ' ' + finalSubtotal.toFixed(2);
}

function calculateManualSubtotal() {
    const quantity = parseFloat(document.getElementById('manualQuantity').value) || 0;
    const unitPrice = parseFloat(document.getElementById('manualUnitPrice').value) || 0;
    const discount = parseFloat(document.getElementById('manualDiscount').value) || 0;
    
    const subtotal = quantity * unitPrice;
    const discountAmount = (subtotal * discount) / 100;
    const finalSubtotal = subtotal - discountAmount;
    
    document.getElementById('manualSubtotalPreview').value = 
        settings.currencySymbol + ' ' + finalSubtotal.toFixed(2);
}

function addItem() {
    const productSelect = document.getElementById('productSelect');
    const quantity = parseFloat(document.getElementById('quantity').value);
    const unitPrice = parseFloat(document.getElementById('unitPrice').value);
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    
    if (!productSelect.value || !quantity || !unitPrice) {
        showNotification('Please fill all required fields', 'error');
        return;
    }
    
    const selectedProduct = products.find(p => p.id == productSelect.value);
    const subtotal = quantity * unitPrice;
    const discountAmount = (subtotal * discount) / 100;
    const finalSubtotal = subtotal - discountAmount;
    
    const item = {
        id: Date.now(),
        productId: selectedProduct.id,
        productName: selectedProduct.name,
        quantity: quantity,
        unitPrice: unitPrice,
        discount: discount,
        subtotal: finalSubtotal
    };
    
    currentBill.push(item);
    updateBillTable();
    clearAddForm();
    showNotification('Item added successfully', 'success');
}

function addManualItem() {
    const productName = document.getElementById('manualProductName').value;
    const quantity = parseFloat(document.getElementById('manualQuantity').value);
    const unitPrice = parseFloat(document.getElementById('manualUnitPrice').value);
    const discount = parseFloat(document.getElementById('manualDiscount').value) || 0;
    
    if (!productName || !quantity || !unitPrice) {
        showNotification('Please fill all required fields', 'error');
        return;
    }
    
    const subtotal = quantity * unitPrice;
    const discountAmount = (subtotal * discount) / 100;
    const finalSubtotal = subtotal - discountAmount;
    
    const item = {
        id: Date.now(),
        productId: null,
        productName: productName,
        quantity: quantity,
        unitPrice: unitPrice,
        discount: discount,
        subtotal: finalSubtotal
    };
    
    currentBill.push(item);
    updateBillTable();
    clearManualForm();
    showNotification('Item added successfully', 'success');
}

function removeItem(itemId) {
    currentBill = currentBill.filter(item => item.id !== itemId);
    updateBillTable();
    showNotification('Item removed', 'success');
}

function updateBillTable() {
    const tbody = document.getElementById('billTableBody');
    
    if (currentBill.length === 0) {
        tbody.innerHTML = '<tr class="empty-row"><td colspan="7">No items added yet</td></tr>';
    } else {
        tbody.innerHTML = currentBill.map((item, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${item.productName}</td>
                <td>${item.quantity}</td>
                <td>${settings.currencySymbol} ${item.unitPrice.toFixed(2)}</td>
                <td>${item.discount}%</td>
                <td>${settings.currencySymbol} ${item.subtotal.toFixed(2)}</td>
                <td>
                    <button class="remove-btn" onclick="removeItem(${item.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    }
    
    updateBillSummary();
}

function updateBillSummary() {
    const subtotal = currentBill.reduce((sum, item) => sum + item.subtotal, 0);
    const totalDiscount = currentBill.reduce((sum, item) => {
        const itemSubtotal = item.quantity * item.unitPrice;
        return sum + (itemSubtotal * item.discount / 100);
    }, 0);
    
    const shippingCost = parseFloat(document.getElementById('shippingCost').value) || 0;
    const taxAmount = (subtotal * settings.taxRate) / 100;
    const finalTotal = subtotal + taxAmount + shippingCost;
    
    document.getElementById('subtotal').textContent = 
        settings.currencySymbol + ' ' + subtotal.toFixed(2);
    document.getElementById('totalDiscount').textContent = 
        settings.currencySymbol + ' ' + totalDiscount.toFixed(2);
    document.getElementById('tax').textContent = 
        settings.currencySymbol + ' ' + taxAmount.toFixed(2);
    document.getElementById('finalTotal').textContent = 
        settings.currencySymbol + ' ' + finalTotal.toFixed(2);
}

function clearAddForm() {
    document.getElementById('productSelect').value = '';
    document.getElementById('quantity').value = '';
    document.getElementById('unitPrice').value = '';
    document.getElementById('discount').value = '';
    document.getElementById('subtotalPreview').value = '';
}

function clearManualForm() {
    document.getElementById('manualProductName').value = '';
    document.getElementById('manualQuantity').value = '';
    document.getElementById('manualUnitPrice').value = '';
    document.getElementById('manualDiscount').value = '';
    document.getElementById('manualSubtotalPreview').value = '';
}

function clearBill() {
    currentBill = [];
    updateBillTable();
    
    // Clear customer info
    document.getElementById('customerName').value = '';
    document.getElementById('customerPhone').value = '';
    document.getElementById('customerEmail').value = '';
    document.getElementById('customerAddress').value = '';
    
    // Clear shipping cost
    document.getElementById('shippingCost').value = '';
    
    // Reset payment method
    document.querySelector('input[name="paymentMethod"][value="cash"]').checked = true;
    
    showNotification('Bill cleared', 'success');
}

function printBill() {
    if (currentBill.length === 0) {
        showNotification('No items to print', 'error');
        return;
    }
    
    const printContent = generatePrintContent();
    document.getElementById('printTemplate').innerHTML = printContent;
    
    window.print();
}

function downloadBill() {
    if (currentBill.length === 0) {
        showNotification('No items to download', 'error');
        return;
    }
    
    // Using jsPDF for PDF generation
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    
    // Add store information
    doc.setFontSize(20);
    doc.text(settings.storeName, 20, 20);
    doc.setFontSize(12);
    doc.text(settings.storeAddress, 20, 30);
    doc.text(settings.storePhone, 20, 40);
    doc.text(settings.storeEmail, 20, 50);
    
    // Add bill details
    doc.text(`Bill #: ${billCounter}`, 20, 70);
    doc.text(`Date: ${new Date().toLocaleDateString()}`, 20, 80);
    doc.text(`Customer: ${document.getElementById('customerName').value || 'Walk-in Customer'}`, 20, 90);
    
    // Add items table
    let yPosition = 110;
    doc.text('Items:', 20, yPosition);
    yPosition += 10;
    
    currentBill.forEach((item, index) => {
        doc.text(`${index + 1}. ${item.productName} - Qty: ${item.quantity} - ${settings.currencySymbol} ${item.subtotal.toFixed(2)}`, 20, yPosition);
        yPosition += 10;
    });
    
    // Add totals
    const subtotal = currentBill.reduce((sum, item) => sum + item.subtotal, 0);
    const taxAmount = (subtotal * settings.taxRate) / 100;
    const shippingCost = parseFloat(document.getElementById('shippingCost').value) || 0;
    const finalTotal = subtotal + taxAmount + shippingCost;
    
    yPosition += 10;
    doc.text(`Subtotal: ${settings.currencySymbol} ${subtotal.toFixed(2)}`, 20, yPosition);
    doc.text(`Tax: ${settings.currencySymbol} ${taxAmount.toFixed(2)}`, 20, yPosition + 10);
    doc.text(`Shipping: ${settings.currencySymbol} ${shippingCost.toFixed(2)}`, 20, yPosition + 20);
    doc.text(`Total: ${settings.currencySymbol} ${finalTotal.toFixed(2)}`, 20, yPosition + 30);
    
    doc.save(`bill_${billCounter}.pdf`);
    showNotification('Bill downloaded successfully', 'success');
}

function saveBill() {
    if (currentBill.length === 0) {
        showNotification('No items to save', 'error');
        return;
    }
    
    const customerName = document.getElementById('customerName').value || 'Walk-in Customer';
    const customerPhone = document.getElementById('customerPhone').value;
    const customerEmail = document.getElementById('customerEmail').value;
    const customerAddress = document.getElementById('customerAddress').value;
    
    const subtotal = currentBill.reduce((sum, item) => sum + item.subtotal, 0);
    const taxAmount = (subtotal * settings.taxRate) / 100;
    const shippingCost = parseFloat(document.getElementById('shippingCost').value) || 0;
    const finalTotal = subtotal + taxAmount + shippingCost;
    
    const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked').value;
    
    const bill = {
        id: billCounter,
        date: new Date().toISOString(),
        customer: {
            name: customerName,
            phone: customerPhone,
            email: customerEmail,
            address: customerAddress
        },
        items: [...currentBill],
        subtotal: subtotal,
        tax: taxAmount,
        shipping: shippingCost,
        total: finalTotal,
        paymentMethod: paymentMethod,
        cashier: currentUser.username
    };
    
    bills.push(bill);
    billCounter++;
    
    // Save customer if not exists
    if (customerName !== 'Walk-in Customer' && customerPhone) {
        saveCustomer(customerName, customerPhone, customerEmail, customerAddress);
    }
    
    saveData();
    clearBill();
    showNotification('Bill saved successfully', 'success');
}

function generatePrintContent() {
    const customerName = document.getElementById('customerName').value || 'Walk-in Customer';
    const subtotal = currentBill.reduce((sum, item) => sum + item.subtotal, 0);
    const taxAmount = (subtotal * settings.taxRate) / 100;
    const shippingCost = parseFloat(document.getElementById('shippingCost').value) || 0;
    const finalTotal = subtotal + taxAmount + shippingCost;
    
    const itemsHtml = currentBill.map((item, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${item.productName}</td>
            <td>${item.quantity}</td>
            <td>${settings.currencySymbol} ${item.unitPrice.toFixed(2)}</td>
            <td>${settings.currencySymbol} ${item.subtotal.toFixed(2)}</td>
        </tr>
    `).join('');
    
    return `
        <div class="print-header">
            <h2>${settings.storeName}</h2>
            <p>${settings.storeAddress}</p>
            <p>Phone: ${settings.storePhone} | Email: ${settings.storeEmail}</p>
        </div>
        
        <div class="print-bill-details">
            <p><strong>Bill #:</strong> ${billCounter}</p>
            <p><strong>Date:</strong> ${new Date().toLocaleDateString()}</p>
            <p><strong>Customer:</strong> ${customerName}</p>
            <p><strong>Cashier:</strong> ${currentUser.username}</p>
        </div>
        
        <div class="print-items">
            <table>
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
        </div>
        
        <div class="print-summary">
            <p><strong>Subtotal:</strong> ${settings.currencySymbol} ${subtotal.toFixed(2)}</p>
            <p><strong>Tax (${settings.taxRate}%):</strong> ${settings.currencySymbol} ${taxAmount.toFixed(2)}</p>
            <p><strong>Shipping:</strong> ${settings.currencySymbol} ${shippingCost.toFixed(2)}</p>
            <p><strong>Total:</strong> ${settings.currencySymbol} ${finalTotal.toFixed(2)}</p>
        </div>
        
        <div class="print-footer">
            <p>Thank you for your business!</p>
            <p>Generated on ${new Date().toLocaleString()}</p>
        </div>
    `;
}

// Product management functions
function loadProducts() {
    const productSelect = document.getElementById('productSelect');
    productSelect.innerHTML = '<option value="">Select Product</option>';
    
    products.forEach(product => {
        const option = document.createElement('option');
        option.value = product.id;
        option.textContent = `${product.name} - ${settings.currencySymbol} ${product.price}`;
        productSelect.appendChild(option);
    });
}

function loadProductsTable() {
    const tbody = document.getElementById('productsTableBody');
    
    if (products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6">No products found</td></tr>';
        return;
    }
    
    tbody.innerHTML = products.map(product => `
        <tr>
            <td>${product.id}</td>
            <td>${product.name}</td>
            <td>${settings.currencySymbol} ${product.price}</td>
            <td>${product.stock}</td>
            <td>${product.category}</td>
            <td>
                <button class="btn" onclick="editProduct(${product.id})">Edit</button>
                <button class="remove-btn" onclick="deleteProduct(${product.id})">Delete</button>
            </td>
        </tr>
    `).join('');
}

function showAddProductForm() {
    document.getElementById('addProductForm').style.display = 'block';
}

function hideAddProductForm() {
    document.getElementById('addProductForm').style.display = 'none';
    clearProductForm();
}

function clearProductForm() {
    document.getElementById('newProductName').value = '';
    document.getElementById('newProductPrice').value = '';
    document.getElementById('newProductStock').value = '';
    document.getElementById('newProductCategory').value = 'Electronics';
}

function saveProduct() {
    const name = document.getElementById('newProductName').value;
    const price = parseFloat(document.getElementById('newProductPrice').value);
    const stock = parseInt(document.getElementById('newProductStock').value);
    const category = document.getElementById('newProductCategory').value;
    
    if (!name || !price || isNaN(stock)) {
        showNotification('Please fill all required fields', 'error');
        return;
    }
    
    const product = {
        id: Date.now(),
        name: name,
        price: price,
        stock: stock,
        category: category
    };
    
    products.push(product);
    saveData();
    loadProducts();
    loadProductsTable();
    hideAddProductForm();
    showNotification('Product saved successfully', 'success');
}

function deleteProduct(productId) {
    if (confirm('Are you sure you want to delete this product?')) {
        products = products.filter(product => product.id !== productId);
        saveData();
        loadProducts();
        loadProductsTable();
        showNotification('Product deleted successfully', 'success');
    }
}

function searchProducts() {
    const searchTerm = document.getElementById('productSearch').value.toLowerCase();
    const filteredProducts = products.filter(product => 
        product.name.toLowerCase().includes(searchTerm) ||
        product.category.toLowerCase().includes(searchTerm)
    );
    
    const tbody = document.getElementById('productsTableBody');
    
    if (filteredProducts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6">No products found</td></tr>';
        return;
    }
    
    tbody.innerHTML = filteredProducts.map(product => `
        <tr>
            <td>${product.id}</td>
            <td>${product.name}</td>
            <td>${settings.currencySymbol} ${product.price}</td>
            <td>${product.stock}</td>
            <td>${product.category}</td>
            <td>
                <button class="btn" onclick="editProduct(${product.id})">Edit</button>
                <button class="remove-btn" onclick="deleteProduct(${product.id})">Delete</button>
            </td>
        </tr>
    `).join('');
}

// Customer management functions
function saveCustomer(name, phone, email, address) {
    const existingCustomer = customers.find(c => c.phone === phone);
    
    if (!existingCustomer) {
        const customer = {
            id: Date.now(),
            name: name,
            phone: phone,
            email: email,
            address: address,
            totalOrders: 0,
            totalSpent: 0
        };
        customers.push(customer);
        saveData();
    }
}

function loadCustomers() {
    // This function can be called to refresh customer data
}

function loadCustomersTable() {
    const tbody = document.getElementById('customersTableBody');
    
    if (customers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7">No customers found</td></tr>';
        return;
    }
    
    tbody.innerHTML = customers.map(customer => `
        <tr>
            <td>${customer.id}</td>
            <td>${customer.name}</td>
            <td>${customer.phone}</td>
            <td>${customer.email || 'N/A'}</td>
            <td>${customer.totalOrders}</td>
            <td>${settings.currencySymbol} ${customer.totalSpent.toFixed(2)}</td>
            <td>
                <button class="btn" onclick="viewCustomer(${customer.id})">View</button>
                <button class="remove-btn" onclick="deleteCustomer(${customer.id})">Delete</button>
            </td>
        </tr>
    `).join('');
}

function deleteCustomer(customerId) {
    if (confirm('Are you sure you want to delete this customer?')) {
        customers = customers.filter(customer => customer.id !== customerId);
        saveData();
        loadCustomersTable();
        showNotification('Customer deleted successfully', 'success');
    }
}

// Reports functions
function loadReports() {
    updateDashboardStats();
    loadRecentBills();
}

function updateDashboardStats() {
    const today = new Date().toDateString();
    const todayBills = bills.filter(bill => new Date(bill.date).toDateString() === today);
    const todaySales = todayBills.reduce((sum, bill) => sum + bill.total, 0);
    
    const currentMonth = new Date().getMonth();
    const currentYear = new Date().getFullYear();
    const monthlyBills = bills.filter(bill => {
        const billDate = new Date(bill.date);
        return billDate.getMonth() === currentMonth && billDate.getFullYear() === currentYear;
    });
    const monthlyRevenue = monthlyBills.reduce((sum, bill) => sum + bill.total, 0);
    
    document.getElementById('todaySales').textContent = 
        settings.currencySymbol + ' ' + todaySales.toFixed(2);
    document.getElementById('todayBills').textContent = todayBills.length;
    document.getElementById('totalOrders').textContent = bills.length;
    document.getElementById('monthlyRevenue').textContent = 
        settings.currencySymbol + ' ' + monthlyRevenue.toFixed(2);
    document.getElementById('totalCustomers').textContent = customers.length;
}

function loadRecentBills() {
    const tbody = document.getElementById('recentBillsTable');
    const recentBills = bills.slice(-10).reverse(); // Last 10 bills
    
    if (recentBills.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6">No recent bills</td></tr>';
        return;
    }
    
    tbody.innerHTML = recentBills.map(bill => `
        <tr>
            <td>#${bill.id}</td>
            <td>${new Date(bill.date).toLocaleDateString()}</td>
            <td>${bill.customer.name}</td>
            <td>${settings.currencySymbol} ${bill.total.toFixed(2)}</td>
            <td>${bill.paymentMethod}</td>
            <td>
                <button class="btn" onclick="viewBill(${bill.id})">View</button>
                <button class="remove-btn" onclick="deleteBill(${bill.id})">Delete</button>
            </td>
        </tr>
    `).join('');
}

function viewBill(billId) {
    const bill = bills.find(b => b.id === billId);
    if (bill) {
        // This would typically open a modal or new page to view bill details
        alert(`Bill #${bill.id}\nCustomer: ${bill.customer.name}\nTotal: ${settings.currencySymbol} ${bill.total.toFixed(2)}`);
    }
}

function deleteBill(billId) {
    if (confirm('Are you sure you want to delete this bill?')) {
        bills = bills.filter(bill => bill.id !== billId);
        saveData();
        loadRecentBills();
        updateDashboardStats();
        showNotification('Bill deleted successfully', 'success');
    }
}

// Settings functions
function saveSettings() {
    settings.storeName = document.getElementById('storeName').value;
    settings.storeAddress = document.getElementById('storeAddress').value;
    settings.storePhone = document.getElementById('storePhone').value;
    settings.storeEmail = document.getElementById('storeEmail').value;
    settings.taxRate = parseFloat(document.getElementById('taxRate').value);
    settings.taxName = document.getElementById('taxName').value;
    settings.currencySymbol = document.getElementById('currencySymbol').value;
    settings.theme = document.getElementById('theme').value;
    
    saveData();
    updateBillSummary(); // Refresh bill summary with new settings
    showNotification('Settings saved successfully', 'success');
}

// Utility functions
function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    const notificationText = document.getElementById('notificationText');
    
    notification.className = `notification ${type}`;
    notificationText.textContent = message;
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 3000);
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

// Data management functions
function saveData() {
    const data = {
        products: products,
        customers: customers,
        bills: bills,
        settings: settings,
        billCounter: billCounter
    };
    
    // In a real application, this would save to a database
    // For now, we'll use localStorage
    try {
        localStorage.setItem('billingSystemData', JSON.stringify(data));
    } catch (e) {
        console.error('Error saving data:', e);
    }
}

function loadData() {
    try {
        const savedData = localStorage.getItem('billingSystemData');
        if (savedData) {
            const data = JSON.parse(savedData);
            products = data.products || [];
            customers = data.customers || [];
            bills = data.bills || [];
            settings = { ...settings, ...data.settings };
            billCounter = data.billCounter || 1;
        } else {
            // Load default products
            loadDefaultProducts();
        }
    } catch (e) {
        console.error('Error loading data:', e);
        loadDefaultProducts();
    }
}

function loadDefaultProducts() {
    products = [
        { id: 1, name: 'Laptop', price: 50000, stock: 10, category: 'Electronics' },
        { id: 2, name: 'Mouse', price: 500, stock: 50, category: 'Accessories' },
        { id: 3, name: 'Keyboard', price: 1500, stock: 30, category: 'Accessories' },
        { id: 4, name: 'Monitor', price: 15000, stock: 20, category: 'Electronics' },
        { id: 5, name: 'Headphones', price: 2000, stock: 25, category: 'Accessories' }
    ];
    saveData();
}

// Event listeners
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        if (document.getElementById('loginScreen').style.display !== 'none') {
            login();
        }
    }
});

document.getElementById('togglePassword').addEventListener('click', function(e) {
    const passwordInput = document.getElementById('password');
    const icon = e.target;
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
});

// Also update the login function
function login() {
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const errorElement = document.getElementById('loginError');
    const toggleIcon = document.getElementById('togglePassword');
    
    // Demo credentials
    const credentials = {
        'admin': 'admin123',
        'cashier': 'cashier123',
        'manager': 'manager123'
    };
    
    if (credentials[username] && credentials[username] === password) {
        currentUser = {
            username: username,
            role: username,
            loginTime: new Date()
        };
        
        document.getElementById('currentUser').textContent = 
            username.charAt(0).toUpperCase() + username.slice(1);
        
        showDashboard();
        showNotification('Login successful!', 'success');
    } else {
        errorElement.textContent = 'Invalid username or password';
        showNotification('Invalid credentials', 'error');
        // Reset password visibility on failed login
        document.getElementById('password').type = 'password';
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
    }
}
// Initialize settings form
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        if (document.getElementById('storeName')) {
            document.getElementById('storeName').value = settings.storeName;
            document.getElementById('storeAddress').value = settings.storeAddress;
            document.getElementById('storePhone').value = settings.storePhone;
            document.getElementById('storeEmail').value = settings.storeEmail;
            document.getElementById('taxRate').value = settings.taxRate;
            document.getElementById('taxName').value = settings.taxName;
            document.getElementById('currencySymbol').value = settings.currencySymbol;
            document.getElementById('theme').value = settings.theme;
        }
    }, 100);
});
