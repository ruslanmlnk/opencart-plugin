<?php
class ModelExtensionModulePromSync extends Model {
    public function pushStockForOrder($order_id, $order_status_id = 0) {
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
            return;
        }

        $items = array();
        foreach ($order_products as $order_product) {
            $product_id = (int)$order_product['product_id'];
            $mapping = $this->getProductMappingByOcId($product_id);
            if (!$mapping) {
                continue;
            }

            $quantity = $this->getProductQuantity($product_id);
            $items[] = array(
                'id' => (int)$mapping['prom_product_id'],
                'quantity_in_stock' => (int)$quantity,
                'in_stock' => $quantity > 0,
                'presence' => $quantity > 0 ? 'available' : 'not_available'
            );
        }

        if (empty($items)) {
            return;
        }

        $api = $this->getApi();
        $response = $api->post('/products/edit', $items);
        if (!$response['success']) {
            $this->log->write('PromSync: failed to push stock. ' . $response['error']);
            return;
        }

        $this->markOcOrderSynced($order_id);
    }

    public function runCron() {
        if (!$this->config->get('module_prom_sync_pull_orders')) {
            return 'PromSync: pull orders disabled';
        }

        $summary = $this->syncStockFromPromOrders();
        return sprintf('PromSync: orders=%d updated=%d skipped=%d errors=%d',
            $summary['orders'], $summary['updated'], $summary['skipped'], $summary['errors']
        );
    }

    public function syncStockFromPromOrders() {
        $api = $this->getApi();
        $limit = (int)$this->config->get('module_prom_sync_limit');
        if ($limit <= 0) {
            $limit = 50;
        }

        $last_modified_from = $this->config->get('module_prom_sync_last_order_sync');

        $orders_processed = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $last_id = null;

        do {
            $query = array('limit' => $limit);
            if ($last_id !== null) {
                $query['last_id'] = $last_id;
            }
            if ($last_modified_from) {
                $query['last_modified_from'] = $last_modified_from;
            }

            $response = $api->get('/orders/list', $query);
            if (!$response['success']) {
                $this->log->write('PromSync: API error on orders/list: ' . $response['error']);
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

                $order_id = (int)$order['id'];
                if ($min_id === null || $order_id < $min_id) {
                    $min_id = $order_id;
                }

                if ($this->isPromOrderProcessed($order_id)) {
                    $skipped++;
                    continue;
                }

                $status = isset($order['status']) ? strtolower($order['status']) : '';
                if ($status === 'canceled' || $status === 'cancelled') {
                    $this->markPromOrderProcessed($order_id);
                    $skipped++;
                    continue;
                }

                if (empty($order['products']) || !is_array($order['products'])) {
                    $this->markPromOrderProcessed($order_id);
                    $skipped++;
                    continue;
                }

                foreach ($order['products'] as $product) {
                    $oc_product_id = $this->resolveOcProductId($product);
                    if (!$oc_product_id) {
                        continue;
                    }
                    $qty = isset($product['quantity']) ? (float)$product['quantity'] : 0;
                    if ($qty <= 0) {
                        continue;
                    }
                    $this->applyStockDelta($oc_product_id, $qty);
                    $updated++;
                }

                $this->markPromOrderProcessed($order_id);
                $orders_processed++;
            }

            if ($min_id === null) {
                break;
            }

            $last_id = $min_id - 1;
        } while ($last_id > 0 && count($data['orders']) == $limit);

        $this->updateLastOrderSync();

        return array(
            'orders' => $orders_processed,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors
        );
    }

    private function applyStockDelta($product_id, $qty) {
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = GREATEST(quantity - " . (float)$qty . ", 0), date_modified = NOW() WHERE product_id = '" . (int)$product_id . "'");
    }

    private function resolveOcProductId(array $prom_product) {
        if (!empty($prom_product['id'])) {
            $mapping = $this->getProductMappingByPromId((int)$prom_product['id']);
            if ($mapping) {
                return (int)$mapping['oc_product_id'];
            }
        }

        if (!empty($prom_product['external_id'])) {
            $mapping = $this->getProductMappingByExternalId($prom_product['external_id']);
            if ($mapping) {
                return (int)$mapping['oc_product_id'];
            }
        }

        if (!empty($this->config->get('module_prom_sync_match_by_sku')) && !empty($prom_product['sku'])) {
            $query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE sku = '" . $this->db->escape($prom_product['sku']) . "' LIMIT 1");
            if (!empty($query->row)) {
                $product_id = (int)$query->row['product_id'];
                if (!empty($prom_product['id'])) {
                    $this->setProductMapping((int)$prom_product['id'], $prom_product['external_id'], $product_id, $prom_product['sku']);
                }
                return $product_id;
            }
        }

        return 0;
    }

    private function getProductQuantity($product_id) {
        $query = $this->db->query("SELECT quantity FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int)$product_id . "'");
        if (!empty($query->row)) {
            return (int)$query->row['quantity'];
        }
        return 0;
    }

    private function isPromOrderProcessed($order_id) {
        $query = $this->db->query("SELECT prom_order_id FROM `" . DB_PREFIX . "prom_sync_prom_order` WHERE prom_order_id = '" . (int)$order_id . "' LIMIT 1");
        return !empty($query->row);
    }

    private function markPromOrderProcessed($order_id) {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "prom_sync_prom_order` SET prom_order_id = '" . (int)$order_id . "', processed_at = NOW()");
    }

    private function isOcOrderSynced($order_id) {
        $query = $this->db->query("SELECT oc_order_id FROM `" . DB_PREFIX . "prom_sync_oc_order` WHERE oc_order_id = '" . (int)$order_id . "' LIMIT 1");
        return !empty($query->row);
    }

    private function markOcOrderSynced($order_id) {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "prom_sync_oc_order` SET oc_order_id = '" . (int)$order_id . "', processed_at = NOW()");
    }

    private function updateLastOrderSync() {
        $value = date('c');
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'module_prom_sync', `key` = 'module_prom_sync_last_order_sync', `value` = '" . $this->db->escape($value) . "', `serialized` = '0'");
    }

    private function getProductMappingByPromId($prom_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_product_id = '" . (int)$prom_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductMappingByExternalId($external_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_external_id = '" . $this->db->escape($external_id) . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductMappingByOcId($product_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE oc_product_id = '" . (int)$product_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function setProductMapping($prom_id, $external_id, $oc_product_id, $sku) {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "prom_sync_product` SET
            prom_product_id = '" . (int)$prom_id . "',
            prom_external_id = '" . $this->db->escape((string)$external_id) . "',
            oc_product_id = '" . (int)$oc_product_id . "',
            oc_sku = '" . $this->db->escape((string)$sku) . "',
            date_added = NOW(),
            date_modified = NOW()");
    }

    private function getApi() {
        $token = $this->config->get('module_prom_sync_token');
        $domain = $this->config->get('module_prom_sync_domain') ?: 'prom.ua';
        $language = $this->config->get('module_prom_sync_language');

        require_once(DIR_SYSTEM . 'library/prom_sync/PromApi.php');
        return new PromSyncApi($token, $domain, $language);
    }
}
