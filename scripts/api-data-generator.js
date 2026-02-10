#!/usr/bin/env node

/**
 * POS API Data Generator
 * 
 * Generates realistic test data by calling API endpoints
 * Usage: node api-data-generator.js
 */

const axios = require('axios');
const { faker } = require('@faker-js/faker');

// ============================================================================
// CONFIGURATION
// ============================================================================

const CONFIG = {
  // API Configuration
  baseURL: process.env.API_BASE_URL || 'http://localhost:8000/api',
  timeout: 30000,
  
  // Authentication
  authEmail: process.env.AUTH_EMAIL || 'admin@acmeretail.com',
  authPassword: process.env.AUTH_PASSWORD || 'password',
  
  // Data Volume Configuration
  volumes: {
    categories: 15,
    products: 50,
    customersPerBusiness: 50,
    salesPerBusiness: 1000,
    shiftsPerDay: 3,
    daysOfSales: 60,
    itemsPerSale: { min: 1, max: 8 },
    batchesPerProduct: { min: 2, max: 5 },
    refundRequests: 20,
    quickSales: 15,
    stockTransfers: 30,
  },
  
  // Behavior
  delayBetweenRequests: 100, // ms
  delayBetweenBatches: 500, // ms
  maxRetries: 3,
  retryDelay: 2000, // ms
  
  // Restart Safety
  skipExisting: true, // Skip if data already exists
};

// ============================================================================
// GLOBAL STATE
// ============================================================================

let authToken = null;
let currentUser = null;
let createdData = {
  businesses: [],
  branches: [],
  categories: [],
  products: [],
  customers: [],
  paymentMethods: [],
  users: [],
  shifts: [],
  sales: [],
  productBatches: [],
};

// ============================================================================
// HTTP CLIENT
// ============================================================================

const api = axios.create({
  baseURL: CONFIG.baseURL,
  timeout: CONFIG.timeout,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Request interceptor for auth token
api.interceptors.request.use((config) => {
  if (authToken) {
    config.headers.Authorization = `Bearer ${authToken}`;
  }
  return config;
});

// Response interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      console.error('❌ Authentication failed. Token may have expired.');
      throw new Error('AUTHENTICATION_FAILED');
    }
    return Promise.reject(error);
  }
);

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Delay execution
 */
function delay(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Retry wrapper for API calls
 */
async function retryRequest(fn, context, retries = CONFIG.maxRetries) {
  for (let i = 0; i < retries; i++) {
    try {
      return await fn();
    } catch (error) {
      const isLastAttempt = i === retries - 1;
      
      if (error.message === 'AUTHENTICATION_FAILED') {
        throw error;
      }
      
      if (isLastAttempt) {
        console.error(`❌ ${context} failed after ${retries} attempts`);
        throw error;
      }
      
      console.warn(`⚠️  ${context} failed, retrying (${i + 1}/${retries})...`);
      await delay(CONFIG.retryDelay * (i + 1));
    }
  }
}

/**
 * Generate random SKU
 */
function generateSKU(prefix = 'PRD') {
  return `${prefix}${faker.string.alphanumeric(8).toUpperCase()}`;
}

/**
 * Generate random barcode (EAN-13)
 */
function generateBarcode() {
  return faker.string.numeric(13);
}

/**
 * Get random element from array
 */
function randomElement(array) {
  return array[Math.floor(Math.random() * array.length)];
}

/**
 * Get random integer between min and max (inclusive)
 */
function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

/**
 * Get random float between min and max
 */
function randomFloat(min, max, decimals = 2) {
  return parseFloat((Math.random() * (max - min) + min).toFixed(decimals));
}

/**
 * Generate random date in the past
 */
function randomPastDate(days) {
  const date = new Date();
  date.setDate(date.getDate() - randomInt(0, days));
  return date;
}

/**
 * Format date for API
 */
function formatDate(date) {
  return date.toISOString().split('T')[0];
}

/**
 * Format datetime for API
 */
function formatDateTime(date) {
  return date.toISOString();
}

/**
 * Progress logger
 */
function logProgress(message, data = null) {
  const timestamp = new Date().toISOString();
  console.log(`[${timestamp}] ${message}`);
  if (data) {
    console.log(JSON.stringify(data, null, 2));
  }
}

// ============================================================================
// AUTHENTICATION
// ============================================================================

/**
 * Login and get auth token
 */
async function authenticate() {
  try {
    logProgress('🔐 Authenticating...');
    
    const response = await retryRequest(
      () => api.post('/login', {
        email: CONFIG.authEmail,
        password: CONFIG.authPassword,
      }),
      'Authentication'
    );
    
    authToken = response.data.token || response.data.access_token;
    currentUser = response.data.user;
    
    logProgress('✅ Authentication successful', {
      user: currentUser?.name,
      email: currentUser?.email,
    });
    
    return { token: authToken, user: currentUser };
  } catch (error) {
    console.error('❌ Authentication failed:', error.response?.data || error.message);
    throw error;
  }
}

// ============================================================================
// DATA FETCHING
// ============================================================================

/**
 * Fetch existing data to avoid duplicates
 */
async function fetchExistingData() {
  if (!CONFIG.skipExisting) return;
  
  logProgress('📥 Fetching existing data...');
  
  try {
    // Fetch businesses (if endpoint exists)
    try {
      const businessesRes = await api.get('/businesses');
      createdData.businesses = businessesRes.data.data || businessesRes.data || [];
    } catch (e) {
      // Endpoint might not exist or require specific permissions
    }
    
    // Fetch branches
    try {
      const branchesRes = await api.get('/branches');
      createdData.branches = branchesRes.data.data || branchesRes.data || [];
    } catch (e) {}
    
    // Fetch categories
    try {
      const categoriesRes = await api.get('/categories');
      createdData.categories = categoriesRes.data.data || categoriesRes.data || [];
    } catch (e) {}
    
    // Fetch products
    try {
      const productsRes = await api.get('/products');
      createdData.products = productsRes.data.data || productsRes.data || [];
    } catch (e) {}
    
    // Fetch payment methods
    try {
      const paymentMethodsRes = await api.get('/payment-methods');
      createdData.paymentMethods = paymentMethodsRes.data.data || paymentMethodsRes.data || [];
    } catch (e) {}
    
    logProgress('✅ Existing data fetched', {
      businesses: createdData.businesses.length,
      branches: createdData.branches.length,
      categories: createdData.categories.length,
      products: createdData.products.length,
      paymentMethods: createdData.paymentMethods.length,
    });
  } catch (error) {
    console.warn('⚠️  Could not fetch some existing data:', error.message);
  }
}

// ============================================================================
// CATEGORY CREATION
// ============================================================================

const CATEGORY_DATA = [
  { name: 'Electronics', parent: null, children: ['Mobile Phones', 'Laptops', 'Accessories'] },
  { name: 'Groceries', parent: null, children: ['Dairy', 'Bakery', 'Beverages', 'Snacks'] },
  { name: 'Household', parent: null, children: ['Cleaning', 'Kitchen', 'Bathroom'] },
  { name: 'Personal Care', parent: null, children: ['Skincare', 'Haircare', 'Hygiene'] },
  { name: 'Office Supplies', parent: null, children: ['Stationery', 'Paper Products'] },
];

async function createCategories() {
  logProgress('📂 Creating categories...');
  
  for (const categoryGroup of CATEGORY_DATA) {
    // Create parent category
    const parentCat = await createCategory({
      name: categoryGroup.name,
      description: `${categoryGroup.name} products`,
      is_active: true,
    });
    
    if (parentCat) {
      // Create child categories
      for (const childName of categoryGroup.children) {
        await createCategory({
          name: childName,
          parent_id: parentCat.id,
          description: `${childName} under ${categoryGroup.name}`,
          is_active: true,
        });
        
        await delay(CONFIG.delayBetweenRequests);
      }
    }
  }
  
  logProgress(`✅ Created ${createdData.categories.length} categories`);
}

async function createCategory(data) {
  try {
    const response = await retryRequest(
      () => api.post('/categories', data),
      `Creating category: ${data.name}`
    );
    
    const category = response.data.data || response.data;
    createdData.categories.push(category);
    logProgress(`  ✓ Category created: ${category.name} (ID: ${category.id})`);
    
    return category;
  } catch (error) {
    // Check if already exists
    if (error.response?.status === 422 || error.response?.status === 409) {
      console.warn(`  ⚠️  Category '${data.name}' may already exist`);
      return null;
    }
    console.error(`  ❌ Failed to create category '${data.name}':`, error.response?.data || error.message);
    return null;
  }
}

// ============================================================================
// PRODUCT CREATION
// ============================================================================

const PRODUCT_TEMPLATES = [
  // Electronics
  { name: 'iPhone 15 Pro', category: 'Mobile Phones', cost: 750, price: 999, taxable: true },
  { name: 'Samsung Galaxy S24', category: 'Mobile Phones', cost: 650, price: 899, taxable: true },
  { name: 'MacBook Pro 14"', category: 'Laptops', cost: 1500, price: 1999, taxable: true },
  { name: 'Dell XPS 13', category: 'Laptops', cost: 900, price: 1299, taxable: true },
  { name: 'AirPods Pro', category: 'Accessories', cost: 150, price: 249, taxable: true },
  { name: 'USB-C Cable', category: 'Accessories', cost: 5, price: 19.99, taxable: true },
  
  // Groceries
  { name: 'Whole Milk 1L', category: 'Dairy', cost: 1.5, price: 2.99, taxable: false, perishable: true },
  { name: 'Greek Yogurt', category: 'Dairy', cost: 2, price: 3.99, taxable: false, perishable: true },
  { name: 'White Bread', category: 'Bakery', cost: 1, price: 2.49, taxable: false, perishable: true },
  { name: 'Croissants (6pk)', category: 'Bakery', cost: 3, price: 5.99, taxable: false, perishable: true },
  { name: 'Coca Cola 2L', category: 'Beverages', cost: 1.2, price: 2.99, taxable: false },
  { name: 'Orange Juice 1L', category: 'Beverages', cost: 2.5, price: 4.99, taxable: false, perishable: true },
  { name: 'Potato Chips', category: 'Snacks', cost: 1, price: 2.49, taxable: false },
  
  // Household
  { name: 'All-Purpose Cleaner', category: 'Cleaning', cost: 2, price: 4.99, taxable: false },
  { name: 'Dish Soap', category: 'Kitchen', cost: 1.5, price: 3.49, taxable: false },
  { name: 'Toilet Paper (12pk)', category: 'Bathroom', cost: 8, price: 14.99, taxable: false },
  
  // Personal Care
  { name: 'Face Moisturizer', category: 'Skincare', cost: 8, price: 15.99, taxable: false },
  { name: 'Shampoo 500ml', category: 'Haircare', cost: 4, price: 8.99, taxable: false },
  { name: 'Toothpaste', category: 'Hygiene', cost: 2, price: 4.49, taxable: false },
  
  // Office
  { name: 'A4 Paper (500 sheets)', category: 'Paper Products', cost: 4, price: 7.99, taxable: false },
  { name: 'Ballpoint Pens (10pk)', category: 'Stationery', cost: 3, price: 5.99, taxable: false },
];

async function createProducts() {
  logProgress('📦 Creating products...');
  
  const targetCount = CONFIG.volumes.products;
  let created = 0;
  
  // Create products from templates
  for (let i = 0; i < targetCount && i < PRODUCT_TEMPLATES.length; i++) {
    const template = PRODUCT_TEMPLATES[i];
    const category = createdData.categories.find(c => c.name === template.category);
    
    if (!category) {
      console.warn(`  ⚠️  Category '${template.category}' not found for product '${template.name}'`);
      continue;
    }
    
    await createProduct({
      category_id: category.id,
      name: template.name,
      sku: generateSKU(),
      barcode: generateBarcode(),
      description: faker.commerce.productDescription(),
      base_cost_price: template.cost,
      base_selling_price: template.price,
      is_taxable: template.taxable ?? false,
      default_tax_rate: template.taxable ? 10 : 0,
      unit_of_measure: 'piece',
      stock_tracking: 'simple',
      low_stock_threshold: randomInt(10, 50),
      is_active: true,
      _perishable: template.perishable || false, // Track for batch creation
    });
    
    created++;
    await delay(CONFIG.delayBetweenRequests);
  }
  
  // Generate additional random products if needed
  while (created < targetCount) {
    const template = randomElement(PRODUCT_TEMPLATES);
    const category = createdData.categories.find(c => c.name === template.category);
    
    if (category) {
      await createProduct({
        category_id: category.id,
        name: `${template.name} ${faker.commerce.productAdjective()}`,
        sku: generateSKU(),
        barcode: generateBarcode(),
        description: faker.commerce.productDescription(),
        base_cost_price: randomFloat(template.cost * 0.8, template.cost * 1.2),
        base_selling_price: randomFloat(template.price * 0.8, template.price * 1.2),
        is_taxable: template.taxable ?? false,
        default_tax_rate: template.taxable ? 10 : 0,
        unit_of_measure: 'piece',
        stock_tracking: 'simple',
        low_stock_threshold: randomInt(10, 50),
        is_active: true,
        _perishable: template.perishable || false,
      });
      
      created++;
      await delay(CONFIG.delayBetweenRequests);
    }
  }
  
  logProgress(`✅ Created ${createdData.products.length} products`);
}

async function createProduct(data) {
  try {
    const response = await retryRequest(
      () => api.post('/products', data),
      `Creating product: ${data.name}`
    );
    
    const product = response.data.data || response.data;
    product._perishable = data._perishable; // Keep for batch creation
    createdData.products.push(product);
    logProgress(`  ✓ Product created: ${product.name} (ID: ${product.id})`);
    
    return product;
  } catch (error) {
    console.error(`  ❌ Failed to create product '${data.name}':`, error.response?.data || error.message);
    return null;
  }
}

// ============================================================================
// CUSTOMER CREATION
// ============================================================================

async function createCustomers() {
  logProgress('👥 Creating customers...');
  
  const targetCount = CONFIG.volumes.customersPerBusiness;
  
  for (let i = 0; i < targetCount; i++) {
    const hasEmail = Math.random() > 0.3;
    const hasCreditLimit = Math.random() > 0.7;
    
    await createCustomer({
      name: faker.person.fullName(),
      email: hasEmail ? faker.internet.email() : null,
      phone: faker.phone.number(),
      address: faker.location.streetAddress(),
      type: randomElement(['walk-in', 'regular', 'vip']),
      credit_limit: hasCreditLimit ? randomFloat(1000, 50000) : 0,
      loyalty_points: randomInt(0, 500),
      is_active: true,
    });
    
    await delay(CONFIG.delayBetweenRequests);
  }
  
  logProgress(`✅ Created ${createdData.customers.length} customers`);
}

async function createCustomer(data) {
  try {
    const response = await retryRequest(
      () => api.post('/customers', data),
      `Creating customer: ${data.name}`
    );
    
    const customer = response.data.data || response.data;
    createdData.customers.push(customer);
    
    return customer;
  } catch (error) {
    console.error(`  ❌ Failed to create customer:`, error.response?.data || error.message);
    return null;
  }
}

// ============================================================================
// PAYMENT METHOD CREATION
// ============================================================================

async function ensurePaymentMethods() {
  if (createdData.paymentMethods.length > 0) {
    logProgress('✅ Payment methods already exist');
    return;
  }
  
  logProgress('💳 Creating payment methods...');
  
  const methods = [
    { name: 'Cash', type: 'cash', is_active: true, sort_order: 1 },
    { name: 'Credit/Debit Card', type: 'card', is_active: true, sort_order: 2 },
    { name: 'Mobile Money', type: 'mobile_money', is_active: true, sort_order: 3 },
  ];
  
  for (const method of methods) {
    try {
      const response = await retryRequest(
        () => api.post('/payment-methods', method),
        `Creating payment method: ${method.name}`
      );
      
      const paymentMethod = response.data.data || response.data;
      createdData.paymentMethods.push(paymentMethod);
      logProgress(`  ✓ Payment method created: ${paymentMethod.name}`);
    } catch (error) {
      // May already exist
      console.warn(`  ⚠️  Payment method '${method.name}' may already exist`);
    }
    
    await delay(CONFIG.delayBetweenRequests);
  }
}

// ============================================================================
// SHIFT CREATION
// ============================================================================

async function createShift(branch, date, shiftNumber) {
  const startHour = 8 + (shiftNumber * 6); // 8AM, 2PM, 8PM
  const startTime = new Date(date);
  startTime.setHours(startHour, 0, 0, 0);
  
  const endTime = new Date(startTime);
  endTime.setHours(startHour + 6, 0, 0, 0);
  
  const isPast = startTime < new Date();
  
  try {
    const response = await retryRequest(
      () => api.post('/shifts', {
        branch_id: branch.id,
        start_time: formatDateTime(startTime),
        end_time: isPast ? formatDateTime(endTime) : null,
        opening_balance: 500.00,
        status: isPast ? 'closed' : 'open',
      }),
      `Creating shift for ${formatDate(date)}`
    );
    
    const shift = response.data.data || response.data;
    createdData.shifts.push(shift);
    
    return shift;
  } catch (error) {
    console.error(`  ❌ Failed to create shift:`, error.response?.data || error.message);
    return null;
  }
}

// ============================================================================
// SALES CREATION
// ============================================================================

async function createSales() {
  if (createdData.branches.length === 0) {
    console.error('❌ No branches found. Cannot create sales.');
    return;
  }
  
  if (createdData.products.length === 0) {
    console.error('❌ No products found. Cannot create sales.');
    return;
  }
  
  if (createdData.paymentMethods.length === 0) {
    console.error('❌ No payment methods found. Cannot create sales.');
    return;
  }
  
  logProgress('💰 Creating sales...');
  
  const targetSales = CONFIG.volumes.salesPerBusiness;
  const daysOfSales = CONFIG.volumes.daysOfSales;
  const salesPerDay = Math.ceil(targetSales / daysOfSales);
  
  let totalCreated = 0;
  
  for (let dayOffset = daysOfSales; dayOffset >= 0 && totalCreated < targetSales; dayOffset--) {
    const date = new Date();
    date.setDate(date.getDate() - dayOffset);
    
    const dailySalesCount = Math.min(
      randomInt(salesPerDay - 5, salesPerDay + 5),
      targetSales - totalCreated
    );
    
    if (dailySalesCount <= 0) continue;
    
    logProgress(`📅 Creating ${dailySalesCount} sales for ${formatDate(date)}...`);
    
    // Create shifts for each branch
    const shifts = [];
    for (const branch of createdData.branches) {
      const shiftCount = randomInt(1, CONFIG.volumes.shiftsPerDay);
      for (let i = 0; i < shiftCount; i++) {
        const shift = await createShift(branch, date, i);
        if (shift) shifts.push(shift);
        await delay(CONFIG.delayBetweenRequests);
      }
    }
    
    if (shifts.length === 0) {
      console.warn(`  ⚠️  No shifts created for ${formatDate(date)}`);
      continue;
    }
    
    // Create sales for this day
    for (let i = 0; i < dailySalesCount; i++) {
      const shift = randomElement(shifts);
      await createSale(shift, date);
      totalCreated++;
      
      await delay(CONFIG.delayBetweenRequests);
      
      // Progress update every 50 sales
      if (totalCreated % 50 === 0) {
        logProgress(`  Progress: ${totalCreated}/${targetSales} sales created`);
      }
    }
    
    await delay(CONFIG.delayBetweenBatches);
  }
  
  logProgress(`✅ Created ${totalCreated} sales`);
}

async function createSale(shift, date) {
  const hasCustomer = Math.random() > 0.4;
  const customer = hasCustomer && createdData.customers.length > 0 
    ? randomElement(createdData.customers) 
    : null;
  
  // Generate sale items
  const itemCount = randomInt(CONFIG.volumes.itemsPerSale.min, CONFIG.volumes.itemsPerSale.max);
  const items = [];
  let subtotal = 0;
  
  for (let i = 0; i < itemCount; i++) {
    const product = randomElement(createdData.products);
    const quantity = randomInt(1, 5);
    const price = product.base_selling_price;
    const hasDiscount = Math.random() > 0.8;
    const discountPercent = hasDiscount ? randomInt(5, 20) : 0;
    const discountAmount = hasDiscount ? (price * quantity * discountPercent / 100) : 0;
    const itemTotal = (price * quantity) - discountAmount;
    
    items.push({
      product_id: product.id,
      quantity: quantity,
      unit_price: price,
      discount_amount: discountAmount,
      tax_rate: product.default_tax_rate || 0,
      subtotal: itemTotal,
    });
    
    subtotal += itemTotal;
  }
  
  // Calculate totals
  const taxAmount = items.reduce((sum, item) => 
    sum + (item.subtotal * (item.tax_rate / 100)), 0
  );
  const totalAmount = subtotal + taxAmount;
  
  // Determine payment split (90% single payment, 10% split payment)
  const isSplitPayment = Math.random() > 0.9;
  const payments = [];
  
  if (isSplitPayment && createdData.paymentMethods.length >= 2) {
    const method1 = randomElement(createdData.paymentMethods);
    const method2 = createdData.paymentMethods.find(m => m.id !== method1.id);
    const split = randomFloat(0.3, 0.7);
    
    payments.push({
      payment_method_id: method1.id,
      amount: totalAmount * split,
      status: 'completed',
    });
    
    payments.push({
      payment_method_id: method2.id,
      amount: totalAmount * (1 - split),
      status: 'completed',
    });
  } else {
    const paymentMethod = randomElement(createdData.paymentMethods);
    payments.push({
      payment_method_id: paymentMethod.id,
      amount: totalAmount,
      status: 'completed',
    });
  }
  
  // Create sale via API
  try {
    const saleTime = new Date(date);
    saleTime.setHours(
      randomInt(8, 20),
      randomInt(0, 59),
      randomInt(0, 59)
    );
    
    const response = await retryRequest(
      () => api.post('/sales', {
        customer_id: customer?.id || null,
        shift_id: shift.id,
        sale_date: formatDateTime(saleTime),
        items: items,
        payments: payments,
        status: 'completed',
        payment_status: 'paid',
        sale_type: randomElement(['pos', 'online', 'wholesale']),
      }),
      'Creating sale'
    );
    
    const sale = response.data.data || response.data;
    createdData.sales.push(sale);
    
    return sale;
  } catch (error) {
    console.error('  ❌ Failed to create sale:', error.response?.data || error.message);
    return null;
  }
}

// ============================================================================
// WORKFLOW CREATION (Refunds, Quick Sales, Transfers)
// ============================================================================

async function createRefundRequests() {
  if (createdData.sales.length === 0) {
    console.warn('⚠️  No sales found. Skipping refund requests.');
    return;
  }
  
  logProgress('🔄 Creating refund requests...');
  
  const targetCount = Math.min(CONFIG.volumes.refundRequests, createdData.sales.length);
  
  for (let i = 0; i < targetCount; i++) {
    const sale = randomElement(createdData.sales);
    const status = randomElement(['pending', 'approved', 'rejected']);
    
    try {
      await retryRequest(
        () => api.post('/refunds', {
          sale_id: sale.id,
          reason: faker.lorem.sentence(),
          amount: sale.total_amount * randomFloat(0.5, 1.0),
          status: status,
        }),
        'Creating refund request'
      );
    } catch (error) {
      console.error('  ❌ Failed to create refund:', error.response?.data || error.message);
    }
    
    await delay(CONFIG.delayBetweenRequests);
  }
  
  logProgress(`✅ Created ${targetCount} refund requests`);
}

async function createQuickSales() {
  if (createdData.products.length === 0) {
    console.warn('⚠️  No products found. Skipping quick sales.');
    return;
  }
  
  logProgress('⚡ Creating quick sales...');
  
  const targetCount = CONFIG.volumes.quickSales;
  
  for (let i = 0; i < targetCount; i++) {
    const product = randomElement(createdData.products);
    const status = randomElement(['pending', 'approved', 'rejected']);
    
    try {
      await retryRequest(
        () => api.post('/quick-sales', {
          product_id: product.id,
          quantity: randomInt(10, 50),
          discount_percentage: randomInt(20, 50),
          reason: 'Near expiry - quick sale needed',
          status: status,
        }),
        'Creating quick sale'
      );
    } catch (error) {
      console.error('  ❌ Failed to create quick sale:', error.response?.data || error.message);
    }
    
    await delay(CONFIG.delayBetweenRequests);
  }
  
  logProgress(`✅ Created ${targetCount} quick sales`);
}

async function createStockTransfers() {
  if (createdData.branches.length < 2) {
    console.warn('⚠️  Need at least 2 branches for transfers. Skipping.');
    return;
  }
  
  if (createdData.products.length === 0) {
    console.warn('⚠️  No products found. Skipping stock transfers.');
    return;
  }
  
  logProgress('📦 Creating stock transfers...');
  
  const targetCount = CONFIG.volumes.stockTransfers;
  
  for (let i = 0; i < targetCount; i++) {
    const fromBranch = randomElement(createdData.branches);
    const toBranch = createdData.branches.find(b => b.id !== fromBranch.id);
    const product = randomElement(createdData.products);
    const status = randomElement(['pending', 'approved', 'completed']);
    
    try {
      await retryRequest(
        () => api.post('/stock-transfers', {
          from_branch_id: fromBranch.id,
          to_branch_id: toBranch.id,
          product_id: product.id,
          quantity: randomInt(10, 100),
          status: status,
          notes: faker.lorem.sentence(),
        }),
        'Creating stock transfer'
      );
    } catch (error) {
      console.error('  ❌ Failed to create transfer:', error.response?.data || error.message);
    }
    
    await delay(CONFIG.delayBetweenRequests);
  }
  
  logProgress(`✅ Created ${targetCount} stock transfers`);
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

async function main() {
  console.log('═══════════════════════════════════════════════════════');
  console.log('   POS API DATA GENERATOR');
  console.log('═══════════════════════════════════════════════════════');
  console.log('');
  
  try {
    // Step 1: Authenticate
    await authenticate();
    console.log('');
    
    // Step 2: Fetch existing data
    await fetchExistingData();
    console.log('');
    
    // Step 3: Create categories
    if (createdData.categories.length < CONFIG.volumes.categories) {
      await createCategories();
      console.log('');
    } else {
      logProgress(`✅ Skipping categories (${createdData.categories.length} already exist)`);
      console.log('');
    }
    
    // Step 4: Create products
    if (createdData.products.length < CONFIG.volumes.products) {
      await createProducts();
      console.log('');
    } else {
      logProgress(`✅ Skipping products (${createdData.products.length} already exist)`);
      console.log('');
    }
    
    // Step 5: Ensure payment methods
    await ensurePaymentMethods();
    console.log('');
    
    // Step 6: Create customers
    await createCustomers();
    console.log('');
    
    // Step 7: Create sales (includes shifts)
    await createSales();
    console.log('');
    
    // Step 8: Create workflows
    await createRefundRequests();
    console.log('');
    
    await createQuickSales();
    console.log('');
    
    await createStockTransfers();
    console.log('');
    
    // Summary
    console.log('═══════════════════════════════════════════════════════');
    console.log('   DATA GENERATION COMPLETE ✅');
    console.log('═══════════════════════════════════════════════════════');
    console.log('');
    console.log('Summary:');
    console.log(`  Categories:      ${createdData.categories.length}`);
    console.log(`  Products:        ${createdData.products.length}`);
    console.log(`  Customers:       ${createdData.customers.length}`);
    console.log(`  Payment Methods: ${createdData.paymentMethods.length}`);
    console.log(`  Shifts:          ${createdData.shifts.length}`);
    console.log(`  Sales:           ${createdData.sales.length}`);
    console.log('');
    
  } catch (error) {
    console.error('');
    console.error('═══════════════════════════════════════════════════════');
    console.error('   ERROR OCCURRED ❌');
    console.error('═══════════════════════════════════════════════════════');
    console.error('');
    console.error(error);
    process.exit(1);
  }
}

// Run the script
if (require.main === module) {
  main();
}

module.exports = {
  authenticate,
  createCategories,
  createProducts,
  createCustomers,
  createSales,
  CONFIG,
};
