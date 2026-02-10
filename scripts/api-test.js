#!/usr/bin/env node

/**
 * API Connection Test
 * 
 * Quick test to verify API connectivity and authentication
 * Usage: node api-test.js
 */

const axios = require('axios');

const API_BASE_URL = process.env.API_BASE_URL || 'http://localhost:8000/api';
const AUTH_EMAIL = process.env.AUTH_EMAIL || 'admin@acmeretail.com';
const AUTH_PASSWORD = process.env.AUTH_PASSWORD || 'password';

console.log('═══════════════════════════════════════════════════════');
console.log('   POS API CONNECTION TEST');
console.log('═══════════════════════════════════════════════════════');
console.log('');
console.log(`API URL: ${API_BASE_URL}`);
console.log(`Email:   ${AUTH_EMAIL}`);
console.log('');

async function testConnection() {
  try {
    // Test 1: API Reachability
    console.log('1️⃣  Testing API reachability...');
    try {
      await axios.get(`${API_BASE_URL.replace('/api', '')}/`, { timeout: 5000 });
      console.log('   ✅ API server is reachable');
    } catch (error) {
      console.log('   ⚠️  API server connection failed');
      console.log(`   Error: ${error.message}`);
      if (error.code === 'ECONNREFUSED') {
        console.log('   💡 Make sure the Laravel server is running: php artisan serve');
      }
      throw error;
    }
    
    console.log('');
    
    // Test 2: Authentication
    console.log('2️⃣  Testing authentication...');
    try {
      const response = await axios.post(`${API_BASE_URL}/login`, {
        email: AUTH_EMAIL,
        password: AUTH_PASSWORD,
      });
      
      const token = response.data.token || response.data.access_token;
      const user = response.data.user;
      
      console.log('   ✅ Authentication successful');
      console.log(`   User: ${user?.name}`);
      console.log(`   Token: ${token?.substring(0, 20)}...`);
      
      console.log('');
      
      // Test 3: Authenticated Request
      console.log('3️⃣  Testing authenticated request...');
      try {
        const categoriesResponse = await axios.get(`${API_BASE_URL}/categories`, {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json',
          },
        });
        
        const categories = categoriesResponse.data.data || categoriesResponse.data;
        console.log('   ✅ Authenticated request successful');
        console.log(`   Found ${Array.isArray(categories) ? categories.length : 0} categories`);
      } catch (error) {
        console.log('   ⚠️  Authenticated request failed');
        console.log(`   Status: ${error.response?.status}`);
        console.log(`   Error: ${error.response?.data?.message || error.message}`);
      }
      
    } catch (error) {
      console.log('   ❌ Authentication failed');
      console.log(`   Status: ${error.response?.status}`);
      console.log(`   Error: ${error.response?.data?.message || error.message}`);
      
      if (error.response?.status === 404) {
        console.log('   💡 Check that /api/login endpoint exists');
      } else if (error.response?.status === 422) {
        console.log('   💡 Check that credentials are correct');
      }
      throw error;
    }
    
    console.log('');
    console.log('═══════════════════════════════════════════════════════');
    console.log('   ALL TESTS PASSED ✅');
    console.log('═══════════════════════════════════════════════════════');
    console.log('');
    console.log('You can now run the data generator:');
    console.log('  npm run generate');
    console.log('');
    
  } catch (error) {
    console.log('');
    console.log('═══════════════════════════════════════════════════════');
    console.log('   TESTS FAILED ❌');
    console.log('═══════════════════════════════════════════════════════');
    console.log('');
    console.log('Please fix the issues above before running the generator.');
    console.log('');
    process.exit(1);
  }
}

testConnection();
