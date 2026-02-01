<?php
class ModelExtensionModulePromSync extends Model {
    private $attribute_cache = array();
    private $logger;
    public function install() {
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

        $this->model_setting_event->deleteEventByCode('prom_sync_order_history');
        $this->model_setting_event->deleteEventByCode('prom_sync_menu');
        $this->model_setting_event->deleteEventByCode('prom_sync_product_list');

        $this->model_setting_event->addEvent('prom_sync_order_history', 'catalog/model/checkout/order/addOrderHistory/after', 'extension/prom_sync/events/onAddOrderHistory', 1, 1);
        $this->model_setting_event->addEvent('prom_sync_menu', 'view/common/column_left/before', 'extension/prom_sync/menu', 1, 2);
        $this->model_setting_event->addEvent('prom_sync_product_list', 'view/catalog/product_list/before', 'extension/prom_sync/product/list', 1, 3);
    }

    public function uninstall() {
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

        $this->logMessage('PromSync: import started');
        $logged_raw = false;

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
                $this->logMessage('PromSync: API error on products/list: ' . $response['error']);
                $errors++;
                break;
            }

            $data = $response['data'];
            if (empty($data['products']) || !is_array($data['products'])) {
                break;
            }

            if (!$logged_raw && !empty($data['products'][0])) {
                $this->logMessage('PromSync: first product raw: ' . $this->encodeJson($data['products'][0]));
                $logged_raw = true;
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

        $this->logMessage(sprintf('PromSync: import summary imported=%d updated=%d skipped=%d errors=%d', $imported, $updated, $skipped, $errors));

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
            $this->logMessage('PromSync: API error on products/list: ' . $response['error']);
            return array(
                'success' => false,
                'error' => $response['error'] ?: 'API error'
            );
        }

        $data = is_array($response['data']) ? $response['data'] : array();
        $products = (!empty($data['products']) && is_array($data['products'])) ? $data['products'] : array();
        $total = $this->extractTotalFromResponse($data, $products);

        if (!empty($products[0])) {
            $this->logMessage('PromSync: first product raw: ' . $this->encodeJson($products[0]));
        }

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

        if ($mapping) {
            $this->updateExistingProduct((int)$mapping['oc_product_id'], $prom_product, $settings, $languages, $default_category_id);
            $this->touchProductMapping($prom_id, $prom_external_id, (int)$mapping['oc_product_id']);
            return 'updated';
        }

        $this->load->model('catalog/product');

        $product_data = $this->buildNewProductData($prom_product, $settings, $languages, $default_category_id);
        $oc_product_id = $this->model_catalog_product->addProduct($product_data);

        $this->setProductMapping($prom_id, $prom_external_id, $oc_product_id, $product_data['sku']);

        $image_count = 0;
        if (!empty($product_data['image'])) {
            $image_count++;
        }
        if (!empty($product_data['product_image']) && is_array($product_data['product_image'])) {
            $image_count += count($product_data['product_image']);
        }
        $category_count = (!empty($product_data['product_category']) && is_array($product_data['product_category'])) ? count($product_data['product_category']) : 0;
        $this->logMessage(sprintf('PromSync: imported product prom_id=%d oc_id=%d images=%d categories=%d', $prom_id, (int)$oc_product_id, $image_count, $category_count));

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

    private function updateExistingProduct($oc_product_id, array $prom_product, array $settings, array $languages, $default_category_id) {
        if (empty($settings['update_existing'])) {
            $settings['map_name'] = false;
            $settings['map_description'] = false;
            $settings['map_price'] = false;
            $settings['map_quantity'] = false;
            $settings['map_sku'] = false;
        }

        $changes = array();

        $fields = array();

        if (!empty($settings['map_price']) && isset($prom_product['price'])) {
            $fields[] = "price = '" . (float)$prom_product['price'] . "'";
            $changes[] = 'price';
        }

        if (!empty($settings['map_quantity'])) {
            $quantity = $this->mapQuantity($prom_product);
            $fields[] = "quantity = '" . (int)$quantity . "'";
            $fields[] = "status = '" . ($quantity > 0 ? 1 : 0) . "'";
            $changes[] = 'quantity';
        }

        if (!empty($settings['map_sku']) && !empty($prom_product['sku'])) {
            $fields[] = "sku = '" . $this->db->escape($prom_product['sku']) . "'";
            $changes[] = 'sku';
        }

        $fields[] = "minimum = '1'";
        $fields[] = "shipping = '1'";

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
            if (!empty($settings['map_name'])) {
                $changes[] = 'name';
            }
            if (!empty($settings['map_description'])) {
                $changes[] = 'description';
            }
        }

        if (!empty($settings['map_groups']) || (int)$default_category_id > 0) {
            $category_ids = $this->getCategoryIdsForPromProduct($prom_product, $settings, $default_category_id, $languages);
            if (!empty($category_ids)) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . (int)$oc_product_id . "'");
                foreach ($category_ids as $category_id) {
                    $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . (int)$oc_product_id . "', category_id = '" . (int)$category_id . "'");
                }
                $changes[] = 'categories';
            }
        }

        if (!empty($settings['map_images'])) {
            $image_urls = $this->collectImageUrls($prom_product);
            if (!empty($image_urls)) {
                $main_image = $this->downloadImage($image_urls[0]);
                if ($main_image) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($main_image) . "' WHERE product_id = '" . (int)$oc_product_id . "'");
                    $this->db->query("DELETE FROM `" . DB_PREFIX . "product_image` WHERE product_id = '" . (int)$oc_product_id . "'");

                    $sort = 0;
                    foreach (array_slice($image_urls, 1) as $url) {
                        $local = $this->downloadImage($url);
                        if ($local) {
                            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET product_id = '" . (int)$oc_product_id . "', image = '" . $this->db->escape($local) . "', sort_order = '" . (int)$sort . "'");
                            $sort++;
                        }
                    }
                    $changes[] = 'images';
                }
            }
        }

        $country_values = $this->getCountryValues($prom_product, $languages);
        if (!empty($country_values)) {
            $this->setCountryAttribute($oc_product_id, $country_values, $languages);
            $changes[] = 'country_attribute';
        }

        if ($this->ensureProductSeoUrl($oc_product_id, $prom_product, $languages)) {
            $changes[] = 'seo';
        }

        if (!empty($changes)) {
            $prom_id = !empty($prom_product['id']) ? (int)$prom_product['id'] : 0;
            $this->logMessage(sprintf('PromSync: updated product prom_id=%d oc_id=%d changes=%s', $prom_id, (int)$oc_product_id, implode(', ', array_unique($changes))));
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

        $product_attribute = array();
        $country_values = $this->getCountryValues($prom_product, $languages);
        if (!empty($country_values)) {
            $product_attribute = $this->buildCountryAttribute($country_values, $languages);
        }

        $product_seo_url = $this->buildProductSeoUrl($prom_product, $languages);

        return array(
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
            'product_seo_url' => $product_seo_url,
            'image' => $image,
            'product_image' => $product_images,
            'product_attribute' => $product_attribute,
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
        $keys = array($lang_key);
        if ($lang_key === 'ua') {
            $keys[] = 'uk';
        } elseif ($lang_key === 'uk') {
            $keys[] = 'ua';
        }

        if (!empty($prom_product['name_multilang']) && is_array($prom_product['name_multilang'])) {
            foreach ($keys as $key) {
                if (!empty($prom_product['name_multilang'][$key])) {
                    $base_name = $prom_product['name_multilang'][$key];
                    break;
                }
            }

            if ($base_name === $prom_product['name']) {
                foreach ($prom_product['name_multilang'] as $key => $value) {
                    if (strtolower(substr((string)$key, 0, 2)) === $lang_key && $value !== '') {
                        $base_name = $value;
                        break;
                    }
                }
            }
        }

        if (!empty($prom_product['description_multilang']) && is_array($prom_product['description_multilang'])) {
            foreach ($keys as $key) {
                if (!empty($prom_product['description_multilang'][$key])) {
                    $base_description = $prom_product['description_multilang'][$key];
                    break;
                }
            }

            if ($base_description === $prom_product['description']) {
                foreach ($prom_product['description_multilang'] as $key => $value) {
                    if (strtolower(substr((string)$key, 0, 2)) === $lang_key && $value !== '') {
                        $base_description = $value;
                        break;
                    }
                }
            }
        }

        return array('name' => $base_name, 'description' => $base_description);
    }

    private function buildProductSeoUrl(array $prom_product, array $languages) {
        $seo = array();
        $store_id = 0;

        foreach ($languages as $language) {
            $texts = $this->getLocalizedTexts($prom_product, $language['code']);
            $keyword = $this->slugify($texts['name']);
            if ($keyword === '') {
                $keyword = 'prom-' . (int)$prom_product['id'];
            }
            if (!isset($seo[$store_id])) {
                $seo[$store_id] = array();
            }
            $seo[$store_id][$language['language_id']] = $keyword;
        }

        return $seo;
    }

    private function ensureProductSeoUrl($product_id, array $prom_product, array $languages) {
        $query = $this->db->query("SELECT seo_url_id FROM `" . DB_PREFIX . "seo_url` WHERE query = 'product_id=" . (int)$product_id . "' LIMIT 1");
        if (!empty($query->row)) {
            return false;
        }

        $seo = $this->buildProductSeoUrl($prom_product, $languages);
        foreach ($seo as $store_id => $values) {
            foreach ($values as $language_id => $keyword) {
                if ($keyword === '') {
                    continue;
                }
                $this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id = '" . (int)$store_id . "', language_id = '" . (int)$language_id . "', query = 'product_id=" . (int)$product_id . "', keyword = '" . $this->db->escape($keyword) . "'");
            }
        }

        return true;
    }

    private function slugify($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = strip_tags($value);

        if (function_exists('utf8_strtolower')) {
            $value = utf8_strtolower($value);
        } else {
            $value = strtolower($value);
        }

        $value = preg_replace('/[^\\p{L}\\p{N}]+/u', '-', $value);
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value);

        return $value;
    }

    private function collectImageUrls(array $prom_product) {
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
            $url = trim((string)$url);
            if ($url !== '' && preg_match('/^https?:\\/\\//i', $url)) {
                $filtered[] = $url;
            }
        }

        return array_values(array_unique($filtered));
    }

    private function extractImageUrls($value) {
        $urls = array();

        if (is_string($value)) {
            $urls[] = $value;
            return $urls;
        }

        if (is_array($value)) {
            $known_keys = array('url', 'href', 'file', 'original', 'big', 'medium', 'small');
            foreach ($known_keys as $key) {
                if (!empty($value[$key])) {
                    $urls[] = $value[$key];
                }
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

    private function getCountryValue(array $prom_product) {
        $direct_keys = array(
            'country',
            'country_of_origin',
            'origin_country',
            'manufacturer_country',
            'production_country',
            'country_origin',
            'country_manufacturer'
        );

        foreach ($direct_keys as $key) {
            if (!empty($prom_product[$key])) {
                return trim((string)$prom_product[$key]);
            }
        }

        if (!empty($prom_product['attributes'])) {
            $value = $this->extractCountryFromAttributes($prom_product['attributes']);
            if ($value !== '') {
                return $value;
            }
        }

        if (!empty($prom_product['properties'])) {
            $value = $this->extractCountryFromAttributes($prom_product['properties']);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function getCountryValues(array $prom_product, array $languages) {
        $values = array();

        $value = $this->getCountryValue($prom_product);
        if ($value !== '') {
            foreach ($languages as $language) {
                $values[$language['language_id']] = $value;
            }
            return $values;
        }

        $ru_default = trim((string)$this->config->get('module_prom_sync_country_ru'));
        $uk_default = trim((string)$this->config->get('module_prom_sync_country_uk'));

        if ($ru_default === '' && $uk_default === '') {
            $domain = strtolower((string)$this->config->get('module_prom_sync_domain'));
            if ($domain === '' || substr($domain, -3) === '.ua') {
                $ru_default = 'Украина';
                $uk_default = 'Україна';
            }
        }
        if ($ru_default === '' && $uk_default === '') {
            $domain = (string)$this->config->get('module_prom_sync_domain');
            if ($domain && stripos($domain, '.ua') !== false) {
                $ru_default = 'Украина';
                $uk_default = 'Україна';
            }
        }
        $fallback = $ru_default !== '' ? $ru_default : $uk_default;

        foreach ($languages as $language) {
            $code = strtolower(substr($language['code'], 0, 2));
            if ($code === 'ru') {
                $value = $ru_default;
            } elseif ($code === 'uk' || $code === 'ua') {
                $value = $uk_default;
            } else {
                $value = $fallback;
            }

            if ($value !== '') {
                $values[$language['language_id']] = $value;
            }
        }

        return $values;
    }

    private function extractCountryFromAttributes($attributes) {
        if (!is_array($attributes)) {
            return '';
        }

        foreach ($attributes as $key => $value) {
            if (!is_int($key) && $this->matchesCountryAttributeName($key)) {
                if (is_scalar($value)) {
                    return trim((string)$value);
                }
                if (is_array($value)) {
                    if (!empty($value['value'])) {
                        return trim((string)$value['value']);
                    }
                    if (!empty($value['text'])) {
                        return trim((string)$value['text']);
                    }
                }
            }
        }

        foreach ($attributes as $item) {
            if (!is_array($item)) {
                continue;
            }

            $names = array();
            foreach (array('name', 'title', 'key') as $key) {
                if (!empty($item[$key])) {
                    $names[] = $item[$key];
                }
            }

            if (!empty($item['name_multilang']) && is_array($item['name_multilang'])) {
                foreach ($item['name_multilang'] as $value) {
                    if ($value !== '') {
                        $names[] = $value;
                    }
                }
            }

            $matched = false;
            foreach ($names as $name) {
                if ($this->matchesCountryAttributeName($name)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            foreach (array('value', 'text') as $key) {
                if (!empty($item[$key]) && is_scalar($item[$key])) {
                    return trim((string)$item[$key]);
                }
            }

            if (!empty($item['value_multilang']) && is_array($item['value_multilang'])) {
                foreach ($item['value_multilang'] as $value) {
                    if ($value !== '') {
                        return trim((string)$value);
                    }
                }
            }
        }

        return '';
    }

    private function matchesCountryAttributeName($name) {
        $normalized = $this->normalizeAttributeName($name);
        if ($normalized === '') {
            return false;
        }

        $candidates = array(
            'страна производитель',
            'страна производителя',
            'страна производства',
            'країна виробник',
            'країна виробника',
            'країна виробництва',
            'country of origin',
            'origin country',
            'manufacturer country',
            'production country',
            'country origin',
            'country'
        );

        foreach ($candidates as $candidate) {
            if ($normalized === $candidate) {
                return true;
            }
        }

        if (strpos($normalized, 'страна') !== false && strpos($normalized, 'производ') !== false) {
            return true;
        }
        if (strpos($normalized, 'країна') !== false && (strpos($normalized, 'вироб') !== false || strpos($normalized, 'виробництв') !== false)) {
            return true;
        }
        if (strpos($normalized, 'country') !== false && strpos($normalized, 'origin') !== false) {
            return true;
        }

        return false;
    }

    private function normalizeAttributeName($name) {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            $name = mb_strtolower($name, 'UTF-8');
        } else {
            $name = strtolower($name);
        }
        $name = str_replace(array('_', '-'), ' ', $name);
        $name = preg_replace('/\\s+/', ' ', $name);
        $name = trim($name);
        return $name;
    }

    private function buildCountryAttribute(array $values, array $languages) {
        $attribute_id = $this->ensureCountryAttribute($languages);
        if (!$attribute_id) {
            return array();
        }

        $default_value = $this->getDefaultAttributeValue($values);

        $descriptions = array();
        foreach ($languages as $language) {
            $language_id = (int)$language['language_id'];
            $text = isset($values[$language_id]) ? $values[$language_id] : $default_value;
            $descriptions[$language_id] = array('text' => $text);
        }

        return array(
            array(
                'attribute_id' => $attribute_id,
                'product_attribute_description' => $descriptions
            )
        );
    }

    private function setCountryAttribute($product_id, array $values, array $languages) {
        $attribute_id = $this->ensureCountryAttribute($languages);
        if (!$attribute_id) {
            return;
        }

        $default_value = $this->getDefaultAttributeValue($values);

        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_attribute` WHERE product_id = '" . (int)$product_id . "' AND attribute_id = '" . (int)$attribute_id . "'");
        foreach ($languages as $language) {
            $language_id = (int)$language['language_id'];
            $text_value = isset($values[$language_id]) ? $values[$language_id] : $default_value;
            $text = $this->db->escape($text_value);
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_attribute` SET product_id = '" . (int)$product_id . "', attribute_id = '" . (int)$attribute_id . "', language_id = '" . (int)$language_id . "', text = '" . $text . "'");
        }
    }

    private function getDefaultAttributeValue(array $values) {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function ensureCountryAttribute(array $languages) {
        if (isset($this->attribute_cache['country'])) {
            return (int)$this->attribute_cache['country'];
        }

        $names = $this->getCountryAttributeNames($languages);
        $unique = array_values(array_unique($names));

        if (!empty($unique)) {
            $escaped = array();
            foreach ($unique as $name) {
                $escaped[] = "'" . $this->db->escape($name) . "'";
            }
            $query = $this->db->query("SELECT attribute_id FROM `" . DB_PREFIX . "attribute_description` WHERE name IN (" . implode(', ', $escaped) . ") LIMIT 1");
            if (!empty($query->row)) {
                $this->attribute_cache['country'] = (int)$query->row['attribute_id'];
                return (int)$query->row['attribute_id'];
            }
        }

        $group_id = $this->ensureAttributeGroup($languages);
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute` SET attribute_group_id = '" . (int)$group_id . "', sort_order = '0'");
        $attribute_id = (int)$this->db->getLastId();

        foreach ($languages as $language) {
            $language_id = (int)$language['language_id'];
            $name = $names[$language_id];
            $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_description` SET attribute_id = '" . (int)$attribute_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($name) . "'");
        }

        $this->attribute_cache['country'] = $attribute_id;
        return $attribute_id;
    }

    private function ensureAttributeGroup(array $languages) {
        if (isset($this->attribute_cache['group'])) {
            return (int)$this->attribute_cache['group'];
        }

        $group_name = 'Prom Sync';
        $query = $this->db->query("SELECT attribute_group_id FROM `" . DB_PREFIX . "attribute_group_description` WHERE name = '" . $this->db->escape($group_name) . "' LIMIT 1");
        if (!empty($query->row)) {
            $this->attribute_cache['group'] = (int)$query->row['attribute_group_id'];
            return (int)$query->row['attribute_group_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET sort_order = '0'");
        $group_id = (int)$this->db->getLastId();

        foreach ($languages as $language) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group_description` SET attribute_group_id = '" . (int)$group_id . "', language_id = '" . (int)$language['language_id'] . "', name = '" . $this->db->escape($group_name) . "'");
        }

        $this->attribute_cache['group'] = $group_id;
        return $group_id;
    }

    private function getCountryAttributeNames(array $languages) {
        $names = array();
        foreach ($languages as $language) {
            $code = strtolower(substr($language['code'], 0, 2));
            if ($code === 'uk' || $code === 'ua') {
                $names[$language['language_id']] = 'Країна виробник';
            } elseif ($code === 'ru') {
                $names[$language['language_id']] = 'Страна производитель';
            } else {
                $names[$language['language_id']] = 'Country of origin';
            }
        }
        return $names;
    }

    private function downloadImage($url) {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $data = $this->fetchUrl($url, $status, $error);
        if ($data === false || $data === '') {
            $this->log->write('PromSync: image download failed for ' . $url . ' (' . $error . ', status=' . (int)$status . ')');
            return '';
        }

        $ext = $this->guessImageExtension($url, $data);
        $filename = 'catalog/prom_sync/' . md5($url) . '.' . $ext;
        $full = rtrim(DIR_IMAGE, '/') . '/' . $filename;

        if (!is_file($full)) {
            $dir = dirname($full);
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                $this->log->write('PromSync: failed to create image dir ' . $dir);
                return '';
            }
            if (!is_writable($dir)) {
                $this->log->write('PromSync: image dir not writable ' . $dir);
                return '';
            }

            if (@file_put_contents($full, $data) === false) {
                $this->log->write('PromSync: failed to write image file ' . $full);
                return '';
            }
        }

        return is_file($full) ? $filename : '';
    }

    private function fetchUrl($url, &$status = 0, &$error = '') {
        $status = 0;
        $error = '';

        if (!function_exists('curl_init')) {
            $data = @file_get_contents($url);
            if ($data === false) {
                $error = 'file_get_contents failed';
                return false;
            }
            return $data;
        }

        $data = $this->curlGet($url, true, $status, $error);
        if ($data === false) {
            $data = $this->curlGet($url, false, $status, $error);
        }

        return $data;
    }

    private function curlGet($url, $verify_ssl, &$status = 0, &$error = '') {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (PromSync Image Fetch)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_ssl ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify_ssl ? 2 : 0);

        $data = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $status >= 400) {
            return false;
        }

        return $data;
    }

    private function guessImageExtension($url, $data = null) {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext) {
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            return $ext;
        }

        if ($data !== null && function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($data);
            if ($info && isset($info[2])) {
                switch ($info[2]) {
                    case IMAGETYPE_JPEG:
                        return 'jpg';
                    case IMAGETYPE_PNG:
                        return 'png';
                    case IMAGETYPE_GIF:
                        return 'gif';
                    case IMAGETYPE_WEBP:
                        return 'webp';
                }
            }
        }

        return 'jpg';
    }

    private function getCategoryIdsForPromProduct(array $prom_product, array $settings, $default_category_id, array $languages) {
        $category_ids = array();

        if (!empty($settings['map_groups'])) {
            $group = null;
            if (!empty($prom_product['group'])) {
                if (is_array($prom_product['group'])) {
                    $group = $prom_product['group'];
                } else {
                    $group = array('id' => (int)$prom_product['group']);
                }
            } elseif (!empty($prom_product['group_id'])) {
                $group = array('id' => (int)$prom_product['group_id']);
            } elseif (!empty($prom_product['category_id'])) {
                $group = array('id' => (int)$prom_product['category_id']);
            }

            if ($group && !empty($group['id'])) {
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

    private function getLogger() {
        if (!$this->logger) {
            $this->logger = new Log('prom_sync.log');
        }
        return $this->logger;
    }

    private function logMessage($message) {
        $logger = $this->getLogger();
        $logger->write($message);
    }

    private function encodeJson($data) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = print_r($data, true);
        }
        return $json;
    }

    private function getSettings() {
        return array(
            'token' => $this->config->get('module_prom_sync_token'),
            'domain' => $this->config->get('module_prom_sync_domain') ?: 'prom.ua',
            'language' => $this->config->get('module_prom_sync_language'),
            'default_category_id' => (int)$this->config->get('module_prom_sync_default_category_id'),
            'map_groups' => $this->getBoolSetting('module_prom_sync_map_groups', true),
            'create_categories' => $this->getBoolSetting('module_prom_sync_create_categories', true),
            'update_existing' => (bool)$this->config->get('module_prom_sync_update_existing'),
            'map_name' => (bool)$this->config->get('module_prom_sync_map_name'),
            'map_description' => (bool)$this->config->get('module_prom_sync_map_description'),
            'map_price' => (bool)$this->config->get('module_prom_sync_map_price'),
            'map_quantity' => (bool)$this->config->get('module_prom_sync_map_quantity'),
            'map_sku' => (bool)$this->config->get('module_prom_sync_map_sku'),
            'map_images' => $this->getBoolSetting('module_prom_sync_map_images', true),
            'limit' => (int)$this->config->get('module_prom_sync_limit')
        );
    }

    private function getBoolSetting($key, $default = false) {
        $value = $this->config->get($key);
        if ($value === null || $value === '') {
            return (bool)$default;
        }
        return (bool)$value;
    }

    private function getApi(array $settings) {
        require_once(DIR_SYSTEM . 'library/prom_sync/PromApi.php');
        return new PromSyncApi($settings['token'], $settings['domain'], $settings['language']);
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
