# BNA Payment Bridge Plugin

Lightweight modular bridge plugin for BNA Smart Payment iframe integration with WooCommerce.

## Development Stages

### ✅ Stage 1: Basic Plugin Structure (CURRENT)
- Main plugin file with proper WordPress headers
- Autoloader for class management
- Basic activation/deactivation hooks
- Core helper functions

### 🔄 Stage 2: API Module (NEXT)
- BNA API integration class
- Token management
- Basic authentication handling

### 🔄 Stage 3: WooCommerce Integration
- Payment gateway class
- Checkout iframe rendering
- Order processing

### 🔄 Stage 4: Frontend Module
- Dynamic iframe loading
- Message event handling
- Form validation

### 🔄 Stage 5: Admin Panel
- Settings page
- Connection testing
- Configuration options

## Testing Stage 1

1. Upload to `/wp-content/plugins/bna-payment-bridge/`
2. Activate the plugin in WordPress admin
3. Check that no errors occur
4. Verify plugin appears in plugins list
5. Check error log for any issues

## File Structure
```
bna-payment-bridge/
├── bna-payment-bridge.php (Main plugin file)
├── includes/
│   ├── core/
│   │   ├── class-autoloader.php
│   │   └── class-helper.php
│   ├── admin/ (Ready for Stage 5)
│   ├── frontend/ (Ready for Stage 4)
│   └── api/ (Ready for Stage 2)
├── assets/
│   ├── css/
│   └── js/
└── languages/
```
