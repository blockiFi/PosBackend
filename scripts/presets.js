/**
 * Data Volume Presets
 * 
 * Predefined configurations for different use cases
 */

const PRESETS = {
  // Quick testing - minimal data
  small: {
    categories: 5,
    products: 20,
    customersPerBusiness: 25,
    salesPerBusiness: 100,
    shiftsPerDay: 2,
    daysOfSales: 30,
    itemsPerSale: { min: 1, max: 5 },
    batchesPerProduct: { min: 1, max: 2 },
    refundRequests: 5,
    quickSales: 3,
    stockTransfers: 5,
  },
  
  // Default - balanced dataset
  medium: {
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
  
  // Large dataset for load testing
  large: {
    categories: 20,
    products: 100,
    customersPerBusiness: 200,
    salesPerBusiness: 10000,
    shiftsPerDay: 4,
    daysOfSales: 180,
    itemsPerSale: { min: 1, max: 10 },
    batchesPerProduct: { min: 3, max: 8 },
    refundRequests: 100,
    quickSales: 50,
    stockTransfers: 150,
  },
  
  // Stress test - very large dataset
  xlarge: {
    categories: 30,
    products: 500,
    customersPerBusiness: 1000,
    salesPerBusiness: 50000,
    shiftsPerDay: 4,
    daysOfSales: 365,
    itemsPerSale: { min: 1, max: 15 },
    batchesPerProduct: { min: 5, max: 10 },
    refundRequests: 500,
    quickSales: 250,
    stockTransfers: 500,
  },
};

/**
 * Get preset by name or from environment
 */
function getPreset(name = null) {
  const presetName = name || process.env.DATA_PRESET || 'medium';
  
  if (!PRESETS[presetName]) {
    console.warn(`⚠️  Preset '${presetName}' not found. Using 'medium' preset.`);
    return PRESETS.medium;
  }
  
  return PRESETS[presetName];
}

/**
 * Apply preset to config
 */
function applyPreset(config, presetName) {
  const preset = getPreset(presetName);
  
  return {
    ...config,
    volumes: {
      ...config.volumes,
      ...preset,
    },
  };
}

module.exports = {
  PRESETS,
  getPreset,
  applyPreset,
};
