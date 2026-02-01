<?php
namespace Opencart\Admin\Model\Extension\PromSync\Module;

class PromSync extends \Opencart\System\Engine\Model {
    public function install(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "prom_sync_product` (
            `prom_product_id` BIGINT NOT NULL,
            `prom_external_id` VARCHAR(64) NULL,
            `oc_product_id` INT NOT NULL,
            `oc_sku` VARCHAR(64) NULL,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`prom_product_id`),
            UNIQUE KEY `oc_product_id` (`oc_product_id`),
            KEY `prom_external_id` (`prom_external_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "prom_sync_group` (
            `prom_group_id` BIGINT NOT NULL,
            `oc_category_id` INT NOT NULL,
            PRIMARY KEY (`prom_group_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "prom_sync_prom_order` (
            `prom_order_id` BIGINT NOT NULL,
            `processed_at` DATETIME NOT NULL,
            PRIMARY KEY (`prom_order_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "prom_sync_oc_order` (
            `oc_order_id` INT NOT NULL,
            `processed_at` DATETIME NOT NULL,
            PRIMARY KEY (`oc_order_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        $this->load->model('setting/event');
        $this->model_setting_event->addEvent(array(
            'code' => 'prom_sync_order_history',
            'description' => 'PromSync: push stock on order history',
            'trigger' => 'catalog/model/checkout/order.addOrderHistory/after',
            'action' => 'extension/prom_sync/events.onAddOrderHistory',
            'status' => 1,
            'sort_order' => 1
        ));
        $this->model_setting_event->addEvent(array(
            'code' => 'prom_sync_menu',
            'description' => 'PromSync: add admin menu item',
            'trigger' => 'view/common/column_left/before',
            'action' => 'extension/prom_sync/menu',
            'status' => 1,
            'sort_order' => 2
        ));
        $this->model_setting_event->addEvent(array(
            'code' => 'prom_sync_product_list',
            'description' => 'PromSync: add product sync action',
            'trigger' => 'view/catalog/product_list/before',
            'action' => 'extension/prom_sync/product.list',
            'status' => 1,
            'sort_order' => 3
        ));
    }

    public function uninstall(): void {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('prom_sync_order_history');
        $this->model_setting_event->deleteEventByCode('prom_sync_menu');
        $this->model_setting_event->deleteEventByCode('prom_sync_product_list');

        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_product`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_group`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_prom_order`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_oc_order`");
    }

    public function importProducts(array $options = array()) {
        $settings = $this->getSettings();
        $api = $this->getApi($settings);

        $limit = isset($options['limit']) ? (int)$options['limit'] : (int)$settings['limit'];
        if ($limit <= 0) {
            $limit = 50;
        }

        $last_id = null;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $languages = $this->getLanguages();
        $default_category_id = (int)$settings['default_category_id'];
        $last_modified_from = !empty($options['last_modified_from']) ? $options['last_modified_from'] : null;

        do {
            $query = array('limit' => $limit);
            if ($last_id !== null) {
                $query['last_id'] = $last_id;
            }
            if (!empty($options['group_id'])) {
                $query['group_id'] = (int)$options['group_id'];
            }
            if ($last_modified_from) {
                $query['last_modified_from'] = $last_modified_from;
            }

            $response = $api->get('/products/list', $query);
            if (!$response['success']) {
                $this->log->write('PromSync: API error on products/list: ' . $response['error']);
                $errors++;
                break;
            }

            $data = $response['data'];
            if (empty($data['products']) || !is_array($data['products'])) {
                break;
            }

            $min_id = null;
            foreach ($data['products'] as $prom_product) {
                if (!isset($prom_product['id'])) {
                    $errors++;
                    continue;
                }

                $prom_id = (int)$prom_product['id'];
                if ($min_id === null || $prom_id < $min_id) {
                    $min_id = $prom_id;
                }

                $result = $this->importProduct($prom_product, $settings, $languages, $default_category_id);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $errors++;
                }
            }

            if ($min_id === null) {
                break;
            }

            $last_id = $min_id - 1;
        } while ($last_id > 0 && count($data['products']) == $limit);

        $summary = array(
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors
        );

        return $summary;
    }

    public function importProductsBatch(array $options = array()) {
        $settings = $this->getSettings();
        $api = $this->getApi($settings);

        $limit = isset($options['limit']) ? (int)$options['limit'] : (int)$settings['limit'];
        if ($limit <= 0) {
            $limit = 50;
        }

        $query = array('limit' => $limit);
        if (isset($options['last_id']) && $options['last_id'] !== '' && $options['last_id'] !== null) {
            $query['last_id'] = (int)$options['last_id'];
        }
        if (!empty($options['group_id'])) {
            $query['group_id'] = (int)$options['group_id'];
        }
        if (!empty($options['last_modified_from'])) {
            $query['last_modified_from'] = $options['last_modified_from'];
        }

        $response = $api->get('/products/list', $query);
        if (!$response['success']) {
            $this->log->write('PromSync: API error on products/list: ' . $response['error']);
            return array(
                'success' => false,
                'error' => $response['error'] ?: 'API error'
            );
        }

        $data = is_array($response['data']) ? $response['data'] : array();
        $products = (!empty($data['products']) && is_array($data['products'])) ? $data['products'] : array();
        $total = $this->extractTotalFromResponse($data, $products);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $min_id = null;

        $languages = $this->getLanguages();
        $default_category_id = (int)$settings['default_category_id'];

        foreach ($products as $prom_product) {
            if (!isset($prom_product['id'])) {
                $errors++;
                continue;
            }

            $prom_id = (int)$prom_product['id'];
            if ($min_id === null || $prom_id < $min_id) {
                $min_id = $prom_id;
            }

            $result = $this->importProduct($prom_product, $settings, $languages, $default_category_id);
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'updated') {
                $updated++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        }

        $next_last_id = null;
        $done = true;

        if ($min_id !== null) {
            $next_last_id = $min_id - 1;
        }

        if ($next_last_id !== null && $next_last_id > 0 && count($products) == $limit) {
            $done = false;
        }

        return array(
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'batch_processed' => $imported + $updated + $skipped + $errors,
            'next_last_id' => $next_last_id,
            'done' => $done,
            'total' => $total
        );
    }

    public function syncProductByOcId($oc_product_id) {
        if (!$this->config->get('module_prom_sync_status')) {
            return array('success' => false, 'error' => 'PromSync: module disabled');
        }

        $mapping = $this->getProductMappingByOcId((int)$oc_product_id);
        if (!$mapping || empty($mapping['prom_product_id'])) {
            return array('success' => false, 'error' => 'PromSync: product not mapped to Prom');
        }

        $quantity = $this->getProductQuantity((int)$oc_product_id);
        $items = array(
            array(
                'id' => (int)$mapping['prom_product_id'],
                'quantity_in_stock' => (int)$quantity,
                'in_stock' => $quantity > 0,
                'presence' => $quantity > 0 ? 'available' : 'not_available'
            )
        );

        $api = $this->getApi($this->getSettings());
        $response = $api->post('/products/edit', $items);
        if (!$response['success']) {
            $this->log->write('PromSync: failed to sync product ' . (int)$oc_product_id . '. ' . $response['error']);
            return array('success' => false, 'error' => $response['error'] ?: 'API error');
        }

        return array('success' => true);
    }

    private function importProduct(array $prom_product, array $settings, array $languages, $default_category_id) {
        $prom_id = (int)$prom_product['id'];
        $mapping = $this->getProductMappingByPromId($prom_id);

        $prom_external_id = isset($prom_product['external_id']) ? (string)$prom_product['external_id'] : null;

        if (!$mapping && $prom_external_id) {
            $mapping = $this->getProductMappingByExternalId($prom_external_id);
        }

        $update_existing = !empty($settings['update_existing']);

        if ($mapping && !$update_existing) {
            return 'skipped';
        }

        if ($mapping) {
            $this->updateExistingProduct((int)$mapping['oc_product_id'], $prom_product, $settings, $languages);
            $this->touchProductMapping($prom_id, $prom_external_id, (int)$mapping['oc_product_id']);
            return 'updated';
        }

        $this->load->model('catalog/product');

        $product_data = $this->buildNewProductData($prom_product, $settings, $languages, $default_category_id);
        $oc_product_id = $this->model_catalog_product->addProduct($product_data);

        $this->setProductMapping($prom_id, $prom_external_id, $oc_product_id, $product_data['sku']);

        return 'imported';
    }

    private function extractTotalFromResponse(array $data, array $products) {
        $candidates = array(
            'total',
            'total_count',
            'total_products',
            'total_items'
        );

        foreach ($candidates as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int)$data[$key];
            }
        }

        if (isset($data['pagination']) && is_array($data['pagination'])) {
            foreach ($candidates as $key) {
                if (isset($data['pagination'][$key]) && is_numeric($data['pagination'][$key])) {
                    return (int)$data['pagination'][$key];
                }
            }
        }

        if (isset($data['count']) && is_numeric($data['count'])) {
            $page_count = count($products);
            if ($page_count && (int)$data['count'] !== $page_count) {
                return (int)$data['count'];
            }
        }

        return 0;
    }

    private function updateExistingProduct($oc_product_id, array $prom_product, array $settings, array $languages) {
        $fields = array();

        if (!empty($settings['map_price']) && isset($prom_product['price'])) {
            $fields[] = "price = '" . (float)$prom_product['price'] . "'";
        }

        if (!empty($settings['map_quantity'])) {
            $quantity = $this->mapQuantity($prom_product);
            $fields[] = "quantity = '" . (int)$quantity . "'";
            $fields[] = "status = '" . ($quantity > 0 ? 1 : 0) . "'";
        }

        if (!empty($settings['map_sku']) && !empty($prom_product['sku'])) {
            $fields[] = "sku = '" . $this->db->escape($prom_product['sku']) . "'";
        }

        if (!empty($fields)) {
            $fields[] = "date_modified = NOW()";
            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $fields) . " WHERE product_id = '" . (int)$oc_product_id . "'");
        }

        if (!empty($settings['map_name']) || !empty($settings['map_description'])) {
            foreach ($languages as $language) {
                $language_id = (int)$language['language_id'];
                $texts = $this->getLocalizedTexts($prom_product, $language['code']);

                $name = !empty($settings['map_name']) ? $texts['name'] : null;
                $description = !empty($settings['map_description']) ? $texts['description'] : null;

                $updates = array();
                if ($name !== null) {
                    $updates[] = "name = '" . $this->db->escape($name) . "'";
                    $updates[] = "meta_title = '" . $this->db->escape($name) . "'";
                }
                if ($description !== null) {
                    $updates[] = "description = '" . $this->db->escape($description) . "'";
                }

                if (!empty($updates)) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET " . implode(', ', $updates) . " WHERE product_id = '" . (int)$oc_product_id . "' AND language_id = '" . (int)$language_id . "'");
                }
            }
        }
    }

    private function buildNewProductData(array $prom_product, array $settings, array $languages, $default_category_id) {
        $quantity = $this->mapQuantity($prom_product);
        $price = isset($prom_product['price']) ? (float)$prom_product['price'] : 0.0;
        $sku = !empty($prom_product['sku']) ? (string)$prom_product['sku'] : '';
        $model = $sku ? $sku : 'PROM-' . (int)$prom_product['id'];

        $product_description = array();
        foreach ($languages as $language) {
            $texts = $this->getLocalizedTexts($prom_product, $language['code']);
            $product_description[$language['language_id']] = array(
                'name' => $texts['name'],
                'description' => $texts['description'],
                'tag' => '',
                'meta_title' => $texts['name'],
                'meta_description' => '',
                'meta_keyword' => ''
            );
        }

        $category_ids = $this->getCategoryIdsForPromProduct($prom_product, $settings, $default_category_id, $languages);

        $image = '';
        $product_images = array();
        if (!empty($settings['map_images'])) {
            $image_urls = $this->collectImageUrls($prom_product);
            if (!empty($image_urls)) {
                $image = $this->downloadImage($image_urls[0]);
                $sort = 0;
                foreach (array_slice($image_urls, 1) as $url) {
                    $local = $this->downloadImage($url);
                    if ($local) {
                        $product_images[] = array('image' => $local, 'sort_order' => $sort++);
                    }
                }
            }
        }

        return array(
            'master_id' => 0,
            'model' => $model,
            'sku' => $sku,
            'upc' => '',
            'ean' => '',
            'jan' => '',
            'isbn' => '',
            'mpn' => '',
            'location' => '',
            'quantity' => $quantity,
            'minimum' => 1,
            'subtract' => 1,
            'stock_status_id' => (int)$this->config->get('config_stock_status_id'),
            'date_available' => date('Y-m-d'),
            'manufacturer_id' => 0,
            'shipping' => 1,
            'price' => $price,
            'points' => 0,
            'tax_class_id' => 0,
            'status' => $quantity > 0 ? 1 : 0,
            'weight' => 0,
            'weight_class_id' => (int)$this->config->get('config_weight_class_id'),
            'length' => 0,
            'width' => 0,
            'height' => 0,
            'length_class_id' => (int)$this->config->get('config_length_class_id'),
            'sort_order' => 0,
            'product_description' => $product_description,
            'product_category' => $category_ids,
            'product_store' => array(0),
            'product_layout' => array(),
            'image' => $image,
            'product_image' => $product_images,
            'product_attribute' => array(),
            'product_option' => array(),
            'product_discount' => array(),
            'product_special' => array(),
            'product_reward' => array(),
            'product_related' => array(),
            'product_download' => array(),
            'product_filter' => array()
        );
    }

    private function mapQuantity(array $prom_product) {
        if (isset($prom_product['quantity_in_stock'])) {
            return (int)$prom_product['quantity_in_stock'];
        }

        if (isset($prom_product['in_stock'])) {
            return $prom_product['in_stock'] ? 1 : 0;
        }

        if (isset($prom_product['presence'])) {
            return $prom_product['presence'] === 'available' ? 1 : 0;
        }

        return 0;
    }

    private function getLocalizedTexts(array $prom_product, $language_code) {
        $base_name = !empty($prom_product['name']) ? (string)$prom_product['name'] : 'Prom product';
        $base_description = !empty($prom_product['description']) ? (string)$prom_product['description'] : '';

        $lang_key = strtolower(substr($language_code, 0, 2));
        if (!empty($prom_product['name_multilang'][$lang_key])) {
            $base_name = $prom_product['name_multilang'][$lang_key];
        }
        if (!empty($prom_product['description_multilang'][$lang_key])) {
            $base_description = $prom_product['description_multilang'][$lang_key];
        }

        return array('name' => $base_name, 'description' => $base_description);
    }

    private function collectImageUrls(array $prom_product) {
        $urls = array();
        if (!empty($prom_product['main_image'])) {
            $urls[] = $prom_product['main_image'];
        }
        if (!empty($prom_product['images']) && is_array($prom_product['images'])) {
            foreach ($prom_product['images'] as $image) {
                if (!empty($image['url'])) {
                    $urls[] = $image['url'];
                }
            }
        }
        return array_values(array_unique($urls));
    }

    private function downloadImage($url) {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (!$ext) {
            $ext = 'jpg';
        }

        $filename = 'catalog/prom_sync/' . md5($url) . '.' . $ext;
        $full = rtrim(DIR_IMAGE, '/') . '/' . $filename;

        if (!is_file($full)) {
            $dir = dirname($full);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $data = curl_exec($ch);
            curl_close($ch);

            if ($data !== false && $data !== '') {
                file_put_contents($full, $data);
            }
        }

        return is_file($full) ? $filename : '';
    }

    private function getCategoryIdsForPromProduct(array $prom_product, array $settings, $default_category_id, array $languages) {
        $category_ids = array();

        if (!empty($settings['map_groups']) && !empty($prom_product['group']) && is_array($prom_product['group'])) {
            $group = $prom_product['group'];
            if (!empty($group['id'])) {
                $category_id = $this->ensureCategoryForGroup($group, $settings, $languages);
                if ($category_id) {
                    $category_ids[] = $category_id;
                }
            }
        }

        if (empty($category_ids) && $default_category_id > 0) {
            $category_ids[] = $default_category_id;
        }

        return $category_ids;
    }

    private function ensureCategoryForGroup(array $group, array $settings, array $languages) {
        $prom_group_id = (int)$group['id'];
        $query = $this->db->query("SELECT oc_category_id FROM `" . DB_PREFIX . "prom_sync_group` WHERE prom_group_id = '" . (int)$prom_group_id . "' LIMIT 1");
        if (!empty($query->row)) {
            return (int)$query->row['oc_category_id'];
        }

        if (empty($settings['create_categories'])) {
            return 0;
        }

        $parent_id = 0;
        if (!empty($group['parent_group_id'])) {
            $parent_query = $this->db->query("SELECT oc_category_id FROM `" . DB_PREFIX . "prom_sync_group` WHERE prom_group_id = '" . (int)$group['parent_group_id'] . "' LIMIT 1");
            if (!empty($parent_query->row)) {
                $parent_id = (int)$parent_query->row['oc_category_id'];
            }
        }

        $this->load->model('catalog/category');

        $name = !empty($group['name']) ? (string)$group['name'] : ('Prom Group ' . $prom_group_id);
        $category_description = array();
        foreach ($languages as $language) {
            $category_description[$language['language_id']] = array(
                'name' => $name,
                'description' => '',
                'meta_title' => $name,
                'meta_description' => '',
                'meta_keyword' => ''
            );
        }

        $category_data = array(
            'parent_id' => $parent_id,
            'top' => 0,
            'column' => 1,
            'sort_order' => 0,
            'status' => 1,
            'category_description' => $category_description,
            'category_store' => array(0),
            'category_layout' => array(),
            'keyword' => ''
        );

        $oc_category_id = $this->model_catalog_category->addCategory($category_data);
        $this->db->query("INSERT INTO `" . DB_PREFIX . "prom_sync_group` SET prom_group_id = '" . (int)$prom_group_id . "', oc_category_id = '" . (int)$oc_category_id . "'");

        return $oc_category_id;
    }

    private function getProductMappingByPromId($prom_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_product_id = '" . (int)$prom_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductMappingByOcId($product_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE oc_product_id = '" . (int)$product_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductQuantity($product_id) {
        $query = $this->db->query("SELECT quantity FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int)$product_id . "' LIMIT 1");
        if (!empty($query->row)) {
            return (int)$query->row['quantity'];
        }
        return 0;
    }

    private function getProductMappingByExternalId($external_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_external_id = '" . $this->db->escape($external_id) . "' LIMIT 1");
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

    private function touchProductMapping($prom_id, $external_id, $oc_product_id) {
        $this->db->query("UPDATE `" . DB_PREFIX . "prom_sync_product` SET
            prom_external_id = '" . $this->db->escape((string)$external_id) . "',
            date_modified = NOW()
            WHERE prom_product_id = '" . (int)$prom_id . "' AND oc_product_id = '" . (int)$oc_product_id . "'");
    }

    private function getSettings() {
        return array(
            'token' => $this->config->get('module_prom_sync_token'),
            'domain' => $this->config->get('module_prom_sync_domain') ?: 'prom.ua',
            'language' => $this->config->get('module_prom_sync_language'),
            'default_category_id' => (int)$this->config->get('module_prom_sync_default_category_id'),
            'map_groups' => (bool)$this->config->get('module_prom_sync_map_groups'),
            'create_categories' => (bool)$this->config->get('module_prom_sync_create_categories'),
            'update_existing' => (bool)$this->config->get('module_prom_sync_update_existing'),
            'map_name' => (bool)$this->config->get('module_prom_sync_map_name'),
            'map_description' => (bool)$this->config->get('module_prom_sync_map_description'),
            'map_price' => (bool)$this->config->get('module_prom_sync_map_price'),
            'map_quantity' => (bool)$this->config->get('module_prom_sync_map_quantity'),
            'map_sku' => (bool)$this->config->get('module_prom_sync_map_sku'),
            'map_images' => (bool)$this->config->get('module_prom_sync_map_images'),
            'limit' => (int)$this->config->get('module_prom_sync_limit')
        );
    }

    private function getApi(array $settings) {
        require_once(DIR_EXTENSION . 'prom_sync/system/library/prom_sync/PromApi.php');
        return new \PromSyncApi($settings['token'], $settings['domain'], $settings['language']);
    }

    private function getLanguages() {
        $this->load->model('localisation/language');
        $languages = $this->model_localisation_language->getLanguages();
        $result = array();
        foreach ($languages as $language) {
            $result[] = array(
                'language_id' => $language['language_id'],
                'code' => $language['code']
            );
        }
        return $result;
    }
}
