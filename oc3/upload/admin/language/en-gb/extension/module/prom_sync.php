<?php
// Heading
$_['heading_title'] = 'Prom.ua Sync';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Success: You have modified Prom.ua Sync settings.';
$_['text_edit'] = 'Edit Prom.ua Sync';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_yes'] = 'Yes';
$_['text_no'] = 'No';
$_['text_home'] = 'Home';
$_['text_import_started'] = 'Import started. Check the log for details.';
$_['text_tab_general'] = 'General';
$_['text_tab_import'] = 'Import';
$_['text_tab_sync'] = 'Sync';
$_['text_tab_logs'] = 'Logs';
$_['text_section_connection'] = 'Connection';
$_['text_section_import'] = 'Import Settings';
$_['text_section_mapping'] = 'Mapping & Categories';
$_['text_section_sync'] = 'Stock Sync';
$_['text_section_actions'] = 'Actions';
$_['text_section_cron'] = 'Cron';
$_['text_section_logs'] = 'Logs';
$_['text_import_note'] = 'Run a manual import to fetch products from Prom into OpenCart.';
$_['text_cron_note'] = 'Keep this URL secret. Use it in your server scheduler.';
$_['text_import_progress'] = 'Import progress';
$_['text_import_complete'] = 'Import complete.';
$_['text_import_error'] = 'Import failed. Check the error log.';
$_['text_logs_note'] = 'Latest entries from prom_sync.log.';
$_['text_sync'] = 'Sync with Prom';
$_['text_sync_success'] = 'PromSync: product %d synced.';
$_['text_sync_error'] = 'PromSync: sync failed.';

// Entry
$_['entry_status'] = 'Status';
$_['entry_token'] = 'API token';
$_['entry_domain'] = 'Prom domain';
$_['entry_language'] = 'API language';
$_['entry_default_category'] = 'Default category ID';
$_['entry_map_groups'] = 'Map Prom groups to categories';
$_['entry_create_categories'] = 'Auto-create categories';
$_['entry_update_existing'] = 'Update existing products';
$_['entry_map_name'] = 'Map name';
$_['entry_map_description'] = 'Map description';
$_['entry_map_price'] = 'Map price';
$_['entry_map_quantity'] = 'Map quantity';
$_['entry_map_sku'] = 'Map SKU';
$_['entry_map_images'] = 'Map images';
$_['entry_push_stock'] = 'Push stock to Prom on OpenCart order';
$_['entry_pull_orders'] = 'Pull orders from Prom to update stock';
$_['entry_match_by_sku'] = 'Match products by SKU if no mapping';
$_['entry_cron_key'] = 'Cron key';
$_['entry_cron_url'] = 'Cron URL';
$_['entry_limit'] = 'API page size (limit)';
$_['entry_country_ru'] = 'Manufacturer country (RU)';
$_['entry_country_uk'] = 'Manufacturer country (UA)';
$_['entry_single_category'] = 'Nested under root ТОВАРИ category';
$_['entry_keep_zero_qty_enabled'] = 'Keep enabled if quantity is 0';

// Help
$_['help_domain'] = 'Default: prom.ua (use satu.kz, deal.by, vendigo.ro if needed).';
$_['help_token'] = 'Prom Public API bearer token.';
$_['help_language'] = 'X-LANGUAGE header for API responses (uk, ru, en).';
$_['help_default_category'] = 'If no group mapping, new products will use this category ID.';
$_['help_single_category'] = 'If enabled, all imported Prom groups will be created as subcategories of a single root category named \'ТОВАРИ\' (created automatically).';
$_['help_cron_key'] = 'Secret key for the cron URL.';
$_['help_limit'] = 'Max number of items per API request.';
$_['help_country'] = 'Used when Prom does not provide country of origin.';

// Button
$_['button_import'] = 'Import products now';
$_['button_copy'] = 'Copy';

// Error
$_['error_permission'] = 'Warning: You do not have permission to modify Prom.ua Sync.';
$_['error_token'] = 'API token is required.';
$_['error_sync_product'] = 'PromSync: missing product ID.';
