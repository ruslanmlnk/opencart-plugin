<?php
class ModelExtensionModulePromSync extends Model
{
    private $logger;
    public function pushStockForOrder($order_id, $order_status_id = 0)
    {
        if (!$this->config->get('module_prom_sync_status')) {
            return;
        }
        if (!$this->config->get('module_prom_sync_push_stock')) {
            return;
        }

        $processing = $this->config->get('config_processing_status');
        $complete = $this->config->get('config_complete_status');
        if (!is_array($processing)) {
            $processing = array();
        }
        if (!is_array($complete)) {
            $complete = array();
        }

        if ($order_status_id && !in_array($order_status_id, array_merge($processing, $complete))) {
            return;
        }

        if ($this->isOcOrderSynced($order_id)) {
            return;
        }

        $this->load->model('checkout/order');
        $order_products = $this->model_checkout_order->getOrderProducts($order_id);
        if (!$order_products) {
            $this->logMessage('PromSync: push stock skipped, no products in order ' . $order_id);
            return;
        }

        $items = array();
        foreach ($order_products as $order_product) {
            $product_id = (int) $order_product['product_id'];
            $prom_product_id = 0;

            // 1. Try mapping table
            $mapping = $this->getProductMappingByOcId($product_id);
            if ($mapping) {
                $prom_product_id = (int) $mapping['prom_product_id'];
            }

            // 2. Try extract from model (PROM-ID)
            if (!$prom_product_id) {
                $query = $this->db->query("SELECT model FROM `" . DB_PREFIX . "product` WHERE product_id = '" . $product_id . "' LIMIT 1");
                if (!empty($query->row['model']) && preg_match('/^PROM-(\d+)$/', $query->row['model'], $matches)) {
                    $prom_product_id = (int) $matches[1];
                }
            }

            if (!$prom_product_id) {
                $this->logMessage('PromSync: product ' . $product_id . ' in order ' . $order_id . ' not linked to Prom (no mapping or PROM-ID in model)');
                continue;
            }

            $quantity = $this->getProductQuantity($product_id);
            $items[] = array(
                'id' => $prom_product_id,
                'quantity_in_stock' => (int) $quantity,
                'in_stock' => $quantity > 0,
                'presence' => $quantity > 0 ? 'available' : 'not_available'
            );
            $this->logMessage(sprintf('PromSync: order %d, adding product %d (Prom ID: %d) to stock push, new qty: %d', $order_id, $product_id, $prom_product_id, $quantity));
        }

        if (empty($items)) {
            return;
        }

        $api = $this->getApi();
        $response = $api->post('/products/edit', $items);
        if (!$response['success']) {
            $this->logMessage('PromSync: failed to push stock for OC order ' . $order_id . '. Error: ' . (isset($response['error']) ? $response['error'] : 'Unknown error'));
            return;
        }

        $this->logMessage('PromSync: successfully pushed stock for order ' . $order_id);
        $this->markOcOrderSynced($order_id);
    }

    public function runCron()
    {
        if (!$this->config->get('module_prom_sync_pull_orders')) {
            return 'PromSync: pull orders disabled';
        }

        $summary = $this->syncStockFromPromOrders();
        $message = sprintf(
            'PromSync: orders=%d updated=%d skipped=%d errors=%d',
            $summary['orders'],
            $summary['updated'],
            $summary['skipped'],
            $summary['errors']
        );
        $this->logMessage('PromSync cron: ' . $message);
        return $message;
    }

    public function syncInventoryFromPromProducts($force = false)
    {
        $api = $this->getApi();
        $limit = 100;

        $last_sync = $force ? null : $this->config->get('module_prom_sync_last_inventory_sync');

        if ($last_sync && !$force) {
            try {
                $sync_date = new DateTime($last_sync);
                $sync_date->modify('-1 day');
                $last_sync = $sync_date->format('Y-m-d\TH:i:s');
            } catch (Exception $e) {
                $this->logMessage('PromSync Inventory Cron: Date error: ' . $e->getMessage());
            }
        }

        $this->logMessage('PromSync Inventory Cron: START. ' . ($force ? 'FORCE FULL SYNC.' : 'Checking changes from: ' . ($last_sync ?: 'beginning')));

        $updated = 0;
        $skipped = 0;
        $processed = 0;
        $last_id = null;

        do {
            $options = array('limit' => $limit);
            if ($last_sync) {
                $options['last_modified_from'] = $last_sync;
            }
            if ($last_id) {
                $options['last_id'] = $last_id;
            }

            $response = $api->get('/products/list', $options);
            if (!$response['success']) {
                $this->logMessage('PromSync Inventory Cron: API error: ' . $response['error']);
                break;
            }

            $data = $response['data'];
            if (empty($data['products']) || !is_array($data['products'])) {
                break;
            }

            $min_id = null;
            foreach ($data['products'] as $prom_product) {
                $processed++;
                $prom_id = (int) $prom_product['id'];
                if ($min_id === null || $prom_id < $min_id) {
                    $min_id = $prom_id;
                }

                $oc_product_id = $this->resolveOcProductId($prom_product);
                if ($oc_product_id) {
                    $qty = $this->mapQuantity($prom_product);
                    $status = ($qty > 0 || (bool) $this->config->get('module_prom_sync_keep_zero_qty_enabled')) ? 1 : 0;

                    $this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = '" . (int) $qty . "', status = '" . (int) $status . "', date_modified = NOW() WHERE product_id = '" . (int) $oc_product_id . "'");

                    if ($this->config->get('module_prom_sync_map_price') && isset($prom_product['price'])) {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET price = '" . (float) $prom_product['price'] . "' WHERE product_id = '" . (int) $oc_product_id . "'");
                    }

                    if ($this->config->get('module_prom_sync_map_sku') && !empty($prom_product['sku'])) {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET sku = '" . $this->db->escape($prom_product['sku']) . "' WHERE product_id = '" . (int) $oc_product_id . "'");
                    }

                    if ($this->config->get('module_prom_sync_map_images')) {
                        $image_urls = $this->collectImageUrls($prom_product);
                        $main_image_set = false;
                        $downloaded_count = 0;

                        foreach ($image_urls as $url) {
                            $local = $this->downloadImage($url);
                            if ($local) {
                                if (!$main_image_set) {
                                    $this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($local) . "' WHERE product_id = '" . (int) $oc_product_id . "'");
                                    $this->db->query("DELETE FROM `" . DB_PREFIX . "product_image` WHERE product_id = '" . (int) $oc_product_id . "'");
                                    $main_image_set = true;
                                } else {
                                    $sort = $downloaded_count - 1;
                                    $this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET product_id = '" . (int) $oc_product_id . "', image = '" . $this->db->escape($local) . "', sort_order = '" . (int) $sort . "'");
                                }
                                $downloaded_count++;
                            }
                        }

                        if ($downloaded_count > 0) {
                            $this->logMessage(sprintf('PromSync: processed %d images for product oc_id=%d', $downloaded_count, $oc_product_id));
                        }
                    }

                    if ($this->config->get('module_prom_sync_map_name') || $this->config->get('module_prom_sync_map_description')) {
                        $langs = $this->getCatalogLanguages();
                        foreach ($langs as $lang) {
                            $texts = $this->getLocalizedTexts($prom_product, $lang['code']);
                            $fields = array();
                            if ($this->config->get('module_prom_sync_map_name') && !empty($texts['name'])) {
                                $fields[] = "name = '" . $this->db->escape($texts['name']) . "'";
                            }
                            if ($this->config->get('module_prom_sync_map_description') && !empty($texts['description'])) {
                                $fields[] = "description = '" . $this->db->escape($texts['description']) . "'";
                            }

                            if (!empty($fields)) {
                                $this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET " . implode(', ', $fields) . " WHERE product_id = '" . (int) $oc_product_id . "' AND language_id = '" . (int) $lang['language_id'] . "'");
                            }
                        }
                    }

                    $updated++;
                } else {
                    $skipped++;
                }
            }

            if ($min_id === null) {
                break;
            }

            $last_id = $min_id - 1;

            // Safety limit to avoid timeout
            if ($processed >= 1000) {
                $this->logMessage('PromSync Inventory Cron: reached batch safety limit (1000 products).');
                break;
            }

        } while ($last_id > 0 && count($data['products']) == $limit);

        $this->updateLastInventorySync();
        return sprintf('Processed: %d, Updated: %d, Skipped: %d', $processed, $updated, $skipped);
    }

    public function syncStockFromPromOrders()
    {
        $api = $this->getApi();
        $limit = (int) $this->config->get('module_prom_sync_limit');
        if ($limit <= 0) {
            $limit = 50;
        }

        // Window of 2 days (yesterday and today) for reliability, as per user script
        $date_from = date('Y-m-d', strtotime('-1 day'));
        $date_to = date('Y-m-d', strtotime('+1 day'));

        $orders_processed = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $last_id = null;

        do {
            $query = array(
                'limit' => $limit,
                'date_from' => $date_from,
                'date_to' => $date_to
            );

            if ($last_id !== null) {
                $query['last_id'] = $last_id;
            }

            $response = $api->get('/orders/list', $query);
            if (!$response['success']) {
                $this->logMessage('PromSync: API error on orders/list: ' . $response['error']);
                $errors++;
                break;
            }

            $data = $response['data'];
            if (empty($data['orders']) || !is_array($data['orders'])) {
                break;
            }

            $min_id = null;
            foreach ($data['orders'] as $order) {
                if (empty($order['id'])) {
                    $errors++;
                    continue;
                }

                $order_id = (int) $order['id'];
                if ($min_id === null || $order_id < $min_id) {
                    $min_id = $order_id;
                }

                $process_result = $this->processPromOrder($order);
                if ($process_result === 'updated') {
                    $updated++;
                    $orders_processed++;
                } elseif ($process_result === 'skipped') {
                    $skipped++;
                } else {
                    $errors++;
                }
            }

            if ($min_id === null) {
                break;
            }

            $last_id = $min_id - 1;

            if ($orders_processed >= 200) {
                $this->logMessage('PromSync cron: reached batch safety limit (200 orders).');
                break;
            }

        } while ($last_id > 0 && count($data['orders']) == $limit);

        return array(
            'orders' => $orders_processed,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors
        );
    }

    public function processPromOrder(array $order)
    {
        $order_id = (int) $order['id'];
        if (!$order_id) {
            return 'error';
        }

        $force = !empty($this->request->get['force']);

        if (!$force && $this->isPromOrderProcessed($order_id)) {
            // No need to log every time for already processed orders unless you want to see them
            return 'skipped';
        }

        $status = isset($order['status']) ? strtolower($order['status']) : '';
        if ($status === 'canceled' || $status === 'cancelled') {
            $this->markPromOrderProcessed($order_id);
            $this->logMessage(sprintf('PromSync: order %d skipped (status: %s)', $order_id, $status));
            return 'skipped';
        }

        if (empty($order['products']) || !is_array($order['products'])) {
            $this->markPromOrderProcessed($order_id);
            $this->logMessage(sprintf('PromSync: order %d skipped (no products)', $order_id));
            return 'skipped';
        }

        $any_updated = false;
        foreach ($order['products'] as $product) {
            $oc_product_id = $this->resolveOcProductId($product);
            if (!$oc_product_id) {
                $sku = !empty($product['sku']) ? $product['sku'] : (!empty($product['name']) ? $product['name'] : 'ID: ' . $product['id']);
                $this->logMessage(sprintf('PromSync: order %d, product "%s" skipped (not found in OpenCart)', $order_id, $sku));
                continue;
            }
            $qty = isset($product['quantity']) ? (float) $product['quantity'] : 0;
            if ($qty <= 0) {
                continue;
            }
            $this->applyStockDelta($oc_product_id, $qty);
            $any_updated = true;
        }

        if (!$any_updated) {
            $this->logMessage(sprintf('PromSync: order %d skipped (no products were updated/mapped)', $order_id));
        }

        $this->markPromOrderProcessed($order_id);

        return $any_updated ? 'updated' : 'skipped';
    }

    private function applyStockDelta($product_id, $qty)
    {
        $current = $this->getProductQuantity($product_id);

        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = GREATEST(quantity - " . (float) $qty . ", 0), date_modified = NOW() WHERE product_id = '" . (int) $product_id . "'");

        $this->logMessage(sprintf('PromSync: product_id=%d stock %s -> delta -%s', (int) $product_id, $current, (float) $qty));

        // Push updated stock back to Prom to ensure sync
        $this->syncProductByOcId($product_id);
    }

    public function syncProductByOcId($oc_product_id)
    {
        $mapping = $this->getProductMappingByOcId((int) $oc_product_id);
        if (!$mapping || empty($mapping['prom_product_id'])) {
            return false;
        }

        $quantity = $this->getProductQuantity((int) $oc_product_id);
        $items = array(
            array(
                'id' => (int) $mapping['prom_product_id'],
                'quantity_in_stock' => (int) $quantity,
                'in_stock' => $quantity > 0,
                'presence' => $quantity > 0 ? 'available' : 'not_available'
            )
        );

        $api = $this->getApi();
        $response = $api->post('/products/edit', $items);
        return $response['success'];
    }

    private function resolveOcProductId(array $prom_product)
    {
        $prom_id = !empty($prom_product['id']) ? (int) $prom_product['id'] : 0;

        if ($prom_id) {
            $mapping = $this->getProductMappingByPromId($prom_id);
            if ($mapping) {
                return (int) $mapping['oc_product_id'];
            }
        }

        if (!empty($prom_product['external_id'])) {
            $mapping = $this->getProductMappingByExternalId($prom_product['external_id']);
            if ($mapping) {
                return (int) $mapping['oc_product_id'];
            }
        }

        // Try matching by Model (PROM-ID) or raw Model
        if ($prom_id) {
            $model_prom = 'PROM-' . $prom_id;
            $query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE model = '" . $this->db->escape($model_prom) . "' LIMIT 1");
            if (!empty($query->row)) {
                $product_id = (int) $query->row['product_id'];
                $this->setProductMapping($prom_id, isset($prom_product['external_id']) ? $prom_product['external_id'] : '', $product_id, isset($prom_product['sku']) ? $prom_product['sku'] : '');
                return $product_id;
            }
        }

        // Final fallback: try search by model directly (case when SKU was put into Model)
        if (!empty($prom_product['sku'])) {
            $model_raw = $this->db->escape($prom_product['sku']);
            $query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE model = '" . $model_raw . "' LIMIT 1");
            if (!empty($query->row)) {
                $product_id = (int) $query->row['product_id'];
                if ($prom_id) {
                    $this->setProductMapping($prom_id, isset($prom_product['external_id']) ? $prom_product['external_id'] : '', $product_id, $prom_product['sku']);
                }
                return $product_id;
            }
        }

        // Extremely final fallback: try search by exact NAME
        if (!empty($prom_product['name'])) {
            $name = $this->db->escape($prom_product['name']);
            $query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product_description` WHERE name = '" . $name . "' LIMIT 1");
            if (!empty($query->row)) {
                $product_id = (int) $query->row['product_id'];
                $this->logMessage(sprintf('PromSync: linked product %d by name match "%s"', $product_id, $prom_product['name']));
                if ($prom_id) {
                    $this->setProductMapping($prom_id, isset($prom_product['external_id']) ? $prom_product['external_id'] : '', $product_id, isset($prom_product['sku']) ? $prom_product['sku'] : '');
                }
                return $product_id;
            }
        }

        if (!empty($this->config->get('module_prom_sync_match_by_sku')) && !empty($prom_product['sku'])) {
            $sku = $this->db->escape($prom_product['sku']);
            $query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE sku = '" . $sku . "' LIMIT 1");
            if (!empty($query->row)) {
                $product_id = (int) $query->row['product_id'];
                if ($prom_id) {
                    $this->setProductMapping($prom_id, isset($prom_product['external_id']) ? $prom_product['external_id'] : '', $product_id, $prom_product['sku']);
                }
                return $product_id;
            }
        }

        return 0;
    }

    private function getProductQuantity($product_id)
    {
        $query = $this->db->query("SELECT quantity FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int) $product_id . "'");
        if (!empty($query->row)) {
            return (int) $query->row['quantity'];
        }
        return 0;
    }

    private function getLogger()
    {
        if (!$this->logger) {
            $this->logger = new Log('prom_sync.log');
        }
        return $this->logger;
    }

    public function logMessage($message)
    {
        $logger = $this->getLogger();
        $logger->write($message);
    }

    private function isPromOrderProcessed($order_id)
    {
        $query = $this->db->query("SELECT prom_order_id FROM `" . DB_PREFIX . "prom_sync_prom_order` WHERE prom_order_id = '" . (int) $order_id . "' LIMIT 1");
        return !empty($query->row);
    }

    public function markPromOrderProcessed($order_id)
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "prom_sync_prom_order` SET prom_order_id = '" . (int) $order_id . "', processed_at = NOW()");
    }

    private function isOcOrderSynced($order_id)
    {
        $query = $this->db->query("SELECT oc_order_id FROM `" . DB_PREFIX . "prom_sync_oc_order` WHERE oc_order_id = '" . (int) $order_id . "' LIMIT 1");
        return !empty($query->row);
    }

    public function markOcOrderSynced($order_id)
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "prom_sync_oc_order` SET oc_order_id = '" . (int) $order_id . "', processed_at = NOW()");
    }

    public function updateLastOrderSync($value = null)
    {
        if ($value === null) {
            $value = gmdate('Y-m-d\TH:i:s') . 'Z';
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'module_prom_sync' AND `key` = 'module_prom_sync_last_order_sync'");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'module_prom_sync', `key` = 'module_prom_sync_last_order_sync', `value` = '" . $this->db->escape($value) . "', `serialized` = '0'");
    }

    public function updateLastInventorySync($value = null)
    {
        if ($value === null) {
            $value = gmdate('Y-m-d\TH:i:s') . 'Z';
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'module_prom_sync' AND `key` = 'module_prom_sync_last_inventory_sync'");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'module_prom_sync', `key` = 'module_prom_sync_last_inventory_sync', `value` = '" . $this->db->escape($value) . "', `serialized` = '0'");
    }

    private function getProductMappingByPromId($prom_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_product_id = '" . (int) $prom_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductMappingByExternalId($external_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_external_id = '" . $this->db->escape($external_id) . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductMappingByOcId($product_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE oc_product_id = '" . (int) $product_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function setProductMapping($prom_id, $external_id, $oc_product_id, $sku)
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "prom_sync_product` SET
            prom_product_id = '" . (int) $prom_id . "',
            prom_external_id = '" . $this->db->escape((string) $external_id) . "',
            oc_product_id = '" . (int) $oc_product_id . "',
            oc_sku = '" . $this->db->escape((string) $sku) . "',
            date_added = NOW(),
            date_modified = NOW()");
    }

    private function mapQuantity(array $prom_product)
    {
        if (isset($prom_product['quantity_in_stock'])) {
            return (int) $prom_product['quantity_in_stock'];
        }

        if (isset($prom_product['in_stock'])) {
            return $prom_product['in_stock'] ? 1 : 0;
        }

        if (isset($prom_product['presence'])) {
            return $prom_product['presence'] === 'available' ? 1 : 0;
        }

        return 0;
    }

    private function getCatalogLanguages()
    {
        $this->load->model('localisation/language');
        return $this->model_localisation_language->getLanguages();
    }

    private function getLocalizedTexts(array $prom_product, $language_code)
    {
        $lang_key = strtolower(substr((string) $language_code, 0, 2));
        $keys = array($lang_key, $language_code);
        if ($lang_key === 'uk')
            $keys[] = 'ua';
        if ($lang_key === 'ru')
            $keys[] = 'ru';

        $base_name = !empty($prom_product['name']) ? $prom_product['name'] : '';
        if (!empty($prom_product['name_multilang']) && is_array($prom_product['name_multilang'])) {
            foreach ($keys as $key) {
                if (!empty($prom_product['name_multilang'][$key])) {
                    $base_name = $prom_product['name_multilang'][$key];
                    break;
                }
            }

            if ($base_name === $prom_product['name']) {
                foreach ($prom_product['name_multilang'] as $key => $value) {
                    if (strtolower(substr((string) $key, 0, 2)) === $lang_key && $value !== '') {
                        $base_name = $value;
                        break;
                    }
                }
            }
        }

        $base_description = !empty($prom_product['description']) ? $prom_product['description'] : '';
        if (!empty($prom_product['description_multilang']) && is_array($prom_product['description_multilang'])) {
            foreach ($keys as $key) {
                if (!empty($prom_product['description_multilang'][$key])) {
                    $base_description = $prom_product['description_multilang'][$key];
                    break;
                }
            }

            if ($base_description === $prom_product['description']) {
                foreach ($prom_product['description_multilang'] as $key => $value) {
                    if (strtolower(substr((string) $key, 0, 2)) === $lang_key && $value !== '') {
                        $base_description = $value;
                        break;
                    }
                }
            }
        }

        return array('name' => $base_name, 'description' => $base_description);
    }

    private function collectImageUrls(array $prom_product)
    {
        $urls = array();

        $urls = array_merge($urls, $this->extractImageUrls(isset($prom_product['main_image']) ? $prom_product['main_image'] : null));
        $urls = array_merge($urls, $this->extractImageUrls(isset($prom_product['image']) ? $prom_product['image'] : null));
        $urls = array_merge($urls, $this->extractImageUrls(isset($prom_product['picture']) ? $prom_product['picture'] : null));
        $urls = array_merge($urls, $this->extractImageUrls(isset($prom_product['pictures']) ? $prom_product['pictures'] : null));

        if (!empty($prom_product['images']) && is_array($prom_product['images'])) {
            foreach ($prom_product['images'] as $image) {
                $urls = array_merge($urls, $this->extractImageUrls($image));
            }
        }

        $filtered = array();
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url !== '' && preg_match('/^https?:\/\//i', $url)) {
                $filtered[] = $url;
            }
        }

        return array_values(array_unique($filtered));
    }

    private function extractImageUrls($value)
    {
        $urls = array();

        if (is_string($value)) {
            $urls[] = $value;
            return $urls;
        }

        if (is_array($value)) {
            if (!empty($value['url'])) {
                $urls[] = $value['url'];
                return $urls;
            }

            foreach ($value as $item) {
                if (is_string($item)) {
                    $urls[] = $item;
                } elseif (is_array($item)) {
                    $urls = array_merge($urls, $this->extractImageUrls($item));
                }
            }
        }

        return $urls;
    }

    private function downloadImage($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        // Apply quality transformation for Prom.ua images (Default to original)
        $quality = $this->config->get('module_prom_sync_image_quality') ?: 'original';
        if (strpos($url, 'images.prom.ua') !== false) {
            if ($quality === 'original') {
                $url = preg_replace('/_w\d+_h\d+_/', '_', $url);
            } elseif (in_array($quality, array('640', '1280'))) {
                $url = preg_replace('/_w\d+_h\d+_/', '_w' . (int) $quality . '_h' . (int) $quality . '_', $url);
            }
        }

        $this->logMessage('PromSync: downloading image from ' . $url);

        $data = $this->fetchUrl($url, $status, $error);
        if ($data === false || $data === '') {
            $this->logMessage(sprintf('PromSync: image download failed for %s (error=%s, status=%d)', $url, $error, (int) $status));
            return '';
        }

        if (function_exists('getimagesizefromstring') && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $info = @getimagesizefromstring($data);
            if ($info && isset($info[2]) && defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP) {
                $im = @imagecreatefromstring($data);
                if ($im) {
                    ob_start();
                    imagejpeg($im, null, 90);
                    $new_data = ob_get_clean();
                    imagedestroy($im);
                    if ($new_data) {
                        $data = $new_data;
                        $this->logMessage('PromSync: converted WebP to JPG for ' . $url);
                    }
                }
            }
        }

        $ext = $this->guessImageExtension($url, $data);
        $filename = 'catalog/prom_sync/' . md5($url) . '.' . $ext;
        $full = rtrim(DIR_IMAGE, '/') . '/' . $filename;

        if (!is_file($full)) {
            $dir = dirname($full);
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                $this->logMessage('PromSync: failed to create image dir ' . $dir);
                return '';
            }
            if (@file_put_contents($full, $data) === false) {
                $this->logMessage('PromSync: failed to write image file ' . $full);
                return '';
            }
        }

        return $filename;
    }

    private function fetchUrl($url, &$status = 0, &$error = '')
    {
        $status = 0;
        $error = '';
        if (!function_exists('curl_init')) {
            $data = @file_get_contents($url);
            return $data;
        }
        $data = $this->curlGet($url, true, $status, $error);
        if ($data === false) {
            $data = $this->curlGet($url, false, $status, $error);
        }
        return $data;
    }

    private function curlGet($url, $verify_ssl, &$status = 0, &$error = '')
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_ssl ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify_ssl ? 2 : 0);
        $data = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data === false || $status >= 400)
            return false;
        return $data;
    }

    private function guessImageExtension($url, $data = null)
    {
        if ($data !== null && function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($data);
            if ($info && isset($info[2])) {
                switch ($info[2]) {
                    case IMAGETYPE_JPEG:
                        return 'jpg';
                    case IMAGETYPE_PNG:
                        return 'png';
                    case IMAGETYPE_WEBP:
                        return 'webp';
                }
            }
        }
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        return $ext ?: 'jpg';
    }

    private function getApi()
    {
        $token = $this->config->get('module_prom_sync_token');
        $domain = $this->config->get('module_prom_sync_domain') ?: 'prom.ua';
        $language = $this->config->get('module_prom_sync_language');

        require_once(DIR_SYSTEM . 'library/prom_sync/PromApi.php');
        return new PromSyncApi($token, $domain, $language);
    }
}
