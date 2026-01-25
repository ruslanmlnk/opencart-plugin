# Prom.ua Sync for OpenCart

OpenCart 4.1.x module to import products from Prom.ua (Public API) and keep stock in sync between OpenCart and Prom.

## Features
- Import all products from Prom to OpenCart with field mapping toggles.
- Optional auto-create OpenCart categories from Prom groups.
- Push stock changes to Prom when OpenCart orders reach processing/complete status.
- Pull Prom orders to reduce OpenCart stock (cron).

## Installation (OpenCart 4.1.x)
### Option A: Extension Installer (.ocmod.zip)
1. Build or download `prom_sync.ocmod.zip` (archive contains `install.json` and `admin/`, `catalog/`, `system/`).
   - Build locally: `powershell -ExecutionPolicy Bypass -File .\\build-ocmod.ps1`
2. In Admin: Extensions > Installer, upload `prom_sync.ocmod.zip`.
3. In Admin: Extensions > Extensions > Modules > Prom.ua Sync > Install.
4. Open the module and configure:
   - API token
   - Domain (default `prom.ua`)
   - Field mapping options
5. Save.

### Option B: Manual (OC4)
1. Create a folder `extension/prom_sync/` in your OpenCart root.
2. Copy contents of `oc4/` into `extension/prom_sync/`.
3. In Admin: Extensions > Extensions > Modules > Prom.ua Sync > Install.
4. Open the module and configure the options above.
5. Save.

## Manual import
In the module settings page, click **Import products now**.

## Cron (Prom orders -> OpenCart stock)
Use the provided Cron URL from the settings page:

```
https://yourstore.example/index.php?route=extension/prom_sync/module/prom_sync.cron&key=YOUR_SECRET
```

Schedule it every 5-10 minutes depending on order volume.

## Notes / limitations
- Existing products are not modified unless **Update existing products** is enabled.
- Stock is reduced from Prom orders. Cancellations are not restored automatically.
- The module only updates Prom stock for products imported from Prom (mapping table required).

## Data mapping (default)
- Prom `name` -> OpenCart product name
- Prom `description` -> OpenCart product description
- Prom `price` -> OpenCart price
- Prom `quantity_in_stock` / `in_stock` -> OpenCart quantity
- Prom `sku` -> OpenCart SKU
- Prom `main_image` / `images` -> OpenCart images (optional)

## Database tables
- `oc_prom_sync_product`
- `oc_prom_sync_group`
- `oc_prom_sync_prom_order`
- `oc_prom_sync_oc_order`

(Replace `oc_` with your DB prefix.)
