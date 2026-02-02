<?php
class ModelExtensionModulePromSync extends Model
{
    private $attribute_cache = array();
    private $logger;
    public function install()
    {
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

    public function uninstall()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('prom_sync_order_history');
        $this->model_setting_event->deleteEventByCode('prom_sync_menu');
        $this->model_setting_event->deleteEventByCode('prom_sync_product_list');

        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_product`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_group`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_prom_order`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "prom_sync_oc_order`");
    }

    public function importProducts(array $options = array())
    {
        $settings = $this->getSettings();
        $api = $this->getApi($settings);

        $limit = isset($options['limit']) ? (int) $options['limit'] : (int) $settings['limit'];
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
        $default_category_id = (int) $settings['default_category_id'];
        $last_modified_from = !empty($options['last_modified_from']) ? $options['last_modified_from'] : null;

        do {
            $query = array('limit' => $limit);
            if ($last_id !== null) {
                $query['last_id'] = $last_id;
            }
            if (!empty($options['group_id'])) {
                $query['group_id'] = (int) $options['group_id'];
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

                $prom_id = (int) $prom_product['id'];
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

        // Update the order sync timestamp so that Cron doesn't process old orders from before this import
        $this->updateLastOrderSync();

        return $summary;
    }

    private function updateLastOrderSync($value = null)
    {
        if ($value === null) {
            $value = date('c');
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'module_prom_sync' AND `key` = 'module_prom_sync_last_order_sync'");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'module_prom_sync', `key` = 'module_prom_sync_last_order_sync', `value` = '" . $this->db->escape($value) . "', `serialized` = '0'");
    }

    public function importProductsBatch(array $options = array())
    {
        $settings = $this->getSettings();
        $api = $this->getApi($settings);

        $limit = isset($options['limit']) ? (int) $options['limit'] : (int) $settings['limit'];
        if ($limit <= 0) {
            $limit = 50;
        }

        $query = array('limit' => $limit);
        if (isset($options['last_id']) && $options['last_id'] !== '' && $options['last_id'] !== null) {
            $query['last_id'] = (int) $options['last_id'];
        }
        if (!empty($options['group_id'])) {
            $query['group_id'] = (int) $options['group_id'];
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
        $default_category_id = (int) $settings['default_category_id'];

        foreach ($products as $prom_product) {
            if (!isset($prom_product['id'])) {
                $errors++;
                continue;
            }

            $prom_id = (int) $prom_product['id'];
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

    public function syncProductByOcId($oc_product_id)
    {
        if (!$this->config->get('module_prom_sync_status')) {
            return array('success' => false, 'error' => 'PromSync: module disabled');
        }

        $mapping = $this->getProductMappingByOcId((int) $oc_product_id);
        if (!$mapping || empty($mapping['prom_product_id'])) {
            return array('success' => false, 'error' => 'PromSync: product not mapped to Prom');
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

        $api = $this->getApi($this->getSettings());
        $response = $api->post('/products/edit', $items);
        if (!$response['success']) {
            $this->log->write('PromSync: failed to sync product ' . (int) $oc_product_id . '. ' . $response['error']);
            return array('success' => false, 'error' => $response['error'] ?: 'API error');
        }

        return array('success' => true);
    }

    private function importProduct(array $prom_product, array $settings, array $languages, $default_category_id)
    {
        $prom_id = (int) $prom_product['id'];
        $mapping = $this->getProductMappingByPromId($prom_id);

        $prom_external_id = isset($prom_product['external_id']) ? (string) $prom_product['external_id'] : null;

        if (!$mapping && $prom_external_id) {
            $mapping = $this->getProductMappingByExternalId($prom_external_id);
        }

        $update_existing = !empty($settings['update_existing']);

        if ($mapping) {
            $this->updateExistingProduct((int) $mapping['oc_product_id'], $prom_product, $settings, $languages, $default_category_id);
            $this->touchProductMapping($prom_id, $prom_external_id, (int) $mapping['oc_product_id']);
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
        $this->logMessage(sprintf('PromSync: imported product prom_id=%d oc_id=%d images=%d categories=%d', $prom_id, (int) $oc_product_id, $image_count, $category_count));

        return 'imported';
    }

    private function extractTotalFromResponse(array $data, array $products)
    {
        $candidates = array(
            'total',
            'total_count',
            'total_products',
            'total_items'
        );

        foreach ($candidates as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        if (isset($data['pagination']) && is_array($data['pagination'])) {
            foreach ($candidates as $key) {
                if (isset($data['pagination'][$key]) && is_numeric($data['pagination'][$key])) {
                    return (int) $data['pagination'][$key];
                }
            }
        }

        if (isset($data['count']) && is_numeric($data['count'])) {
            $page_count = count($products);
            if ($page_count && (int) $data['count'] !== $page_count) {
                return (int) $data['count'];
            }
        }

        return 0;
    }

    private function updateExistingProduct($oc_product_id, array $prom_product, array $settings, array $languages, $default_category_id)
    {
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
            $fields[] = "price = '" . (float) $prom_product['price'] . "'";
            $changes[] = 'price';
        }

        if (!empty($settings['map_quantity'])) {
            $quantity = $this->mapQuantity($prom_product);
            $fields[] = "quantity = '" . (int) $quantity . "'";
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
            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $fields) . " WHERE product_id = '" . (int) $oc_product_id . "'");
        }

        if (!empty($settings['map_name']) || !empty($settings['map_description'])) {
            foreach ($languages as $language) {
                $language_id = (int) $language['language_id'];
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
                    $this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET " . implode(', ', $updates) . " WHERE product_id = '" . (int) $oc_product_id . "' AND language_id = '" . (int) $language_id . "'");
                }
            }
            if (!empty($settings['map_name'])) {
                $changes[] = 'name';
            }
            if (!empty($settings['map_description'])) {
                $changes[] = 'description';
            }
        }

        if (!empty($settings['map_groups']) || (int) $default_category_id > 0) {
            $category_ids = $this->getCategoryIdsForPromProduct($prom_product, $settings, $default_category_id, $languages);
            if (!empty($category_ids)) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . (int) $oc_product_id . "'");
                foreach ($category_ids as $category_id) {
                    $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . (int) $oc_product_id . "', category_id = '" . (int) $category_id . "'");
                }
                $changes[] = 'categories';
            }
        }

        if (!empty($settings['map_images'])) {
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
                        $changes[] = 'images';
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

        // Import all attributes
        $product_attribute = $this->buildProductAttributes($prom_product, $languages);
        $this->db->query("DELETE FROM " . DB_PREFIX . "product_attribute WHERE product_id = '" . (int) $oc_product_id . "'");
        foreach ($product_attribute as $attr) {
            foreach ($attr['product_attribute_description'] as $lang_id => $desc) {
                $this->db->query("INSERT INTO " . DB_PREFIX . "product_attribute SET product_id = '" . (int) $oc_product_id . "', attribute_id = '" . (int) $attr['attribute_id'] . "', language_id = '" . (int) $lang_id . "', text = '" . $this->db->escape($desc['text']) . "'");
            }
        }
        $changes[] = 'attributes';

        if ($this->ensureProductSeoUrl($oc_product_id, $prom_product, $languages)) {
            $changes[] = 'seo';
        }

        if (!empty($changes)) {
            $prom_id = !empty($prom_product['id']) ? (int) $prom_product['id'] : 0;
            $this->logMessage(sprintf('PromSync: updated product prom_id=%d oc_id=%d changes=%s', $prom_id, (int) $oc_product_id, implode(', ', array_unique($changes))));
        }
    }

    private function buildNewProductData(array $prom_product, array $settings, array $languages, $default_category_id)
    {
        $quantity = $this->mapQuantity($prom_product);
        $price = isset($prom_product['price']) ? (float) $prom_product['price'] : 0.0;
        $sku = !empty($prom_product['sku']) ? (string) $prom_product['sku'] : '';
        $model = $sku ? $sku : 'PROM-' . (int) $prom_product['id'];

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
            $main_image_set = false;
            foreach ($image_urls as $url) {
                $local = $this->downloadImage($url);
                if ($local) {
                    if (!$main_image_set) {
                        $image = $local;
                        $main_image_set = true;
                    } else {
                        $product_images[] = array('image' => $local, 'sort_order' => count($product_images));
                    }
                }
            }
        }

        $product_attribute = $this->buildProductAttributes($prom_product, $languages);

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
            'stock_status_id' => (int) $this->config->get('config_stock_status_id'),
            'date_available' => date('Y-m-d'),
            'manufacturer_id' => 0,
            'shipping' => 1,
            'price' => $price,
            'points' => 0,
            'tax_class_id' => 0,
            'status' => $quantity > 0 ? 1 : 0,
            'weight' => 0,
            'weight_class_id' => (int) $this->config->get('config_weight_class_id'),
            'length' => 0,
            'width' => 0,
            'height' => 0,
            'length_class_id' => (int) $this->config->get('config_length_class_id'),
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

    private function getLocalizedTexts(array $prom_product, $language_code)
    {
        $base_name = !empty($prom_product['name']) ? (string) $prom_product['name'] : 'Prom product';
        $base_description = !empty($prom_product['description']) ? (string) $prom_product['description'] : '';

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
                    if (strtolower(substr((string) $key, 0, 2)) === $lang_key && $value !== '') {
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
                    if (strtolower(substr((string) $key, 0, 2)) === $lang_key && $value !== '') {
                        $base_description = $value;
                        break;
                    }
                }
            }
        }

        return array('name' => $base_name, 'description' => $base_description);
    }

    private function buildProductSeoUrl(array $prom_product, array $languages)
    {
        $seo = array();
        $store_id = 0;
        $used_keywords = array();

        foreach ($languages as $language) {
            $lang_id = (int) $language['language_id'];
            $texts = $this->getLocalizedTexts($prom_product, $language['code']);
            $keyword = $this->slugify($texts['name']);

            if ($keyword === '') {
                $keyword = 'prom-' . (int) $prom_product['id'];
            }

            // If this keyword was already generated for another language in this product, add prefix
            if (in_array($keyword, $used_keywords)) {
                $prefix = strtolower(substr($language['code'], 0, 2));
                $keyword = $prefix . '-' . $keyword;
            }

            $used_keywords[] = $keyword;

            if (!isset($seo[$store_id])) {
                $seo[$store_id] = array();
            }
            $seo[$store_id][$lang_id] = $keyword;
        }

        return $seo;
    }

    private function ensureProductSeoUrl($product_id, array $prom_product, array $languages)
    {
        $query = $this->db->query("SELECT seo_url_id FROM `" . DB_PREFIX . "seo_url` WHERE query = 'product_id=" . (int) $product_id . "' LIMIT 1");
        if (!empty($query->row)) {
            return false;
        }

        $seo = $this->buildProductSeoUrl($prom_product, $languages);
        foreach ($seo as $store_id => $values) {
            foreach ($values as $language_id => $keyword) {
                if ($keyword === '') {
                    continue;
                }
                $this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id = '" . (int) $store_id . "', language_id = '" . (int) $language_id . "', query = 'product_id=" . (int) $product_id . "', keyword = '" . $this->db->escape($keyword) . "'");
            }
        }

        return true;
    }

    private function slugify($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = strip_tags($value);
        $value = $this->transliterate($value);

        if (function_exists('utf8_strtolower')) {
            $value = utf8_strtolower($value);
        } else {
            $value = strtolower($value);
        }

        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value);

        return $value;
    }

    private function transliterate($string)
    {
        $roman = array(
            'А' => 'A',
            'Б' => 'B',
            'В' => 'V',
            'Г' => 'G',
            'Ґ' => 'G',
            'Д' => 'D',
            'Е' => 'E',
            'Є' => 'Ye',
            'Ё' => 'Yo',
            'Ж' => 'Zh',
            'З' => 'Z',
            'И' => 'I',
            'І' => 'I',
            'Ї' => 'Yi',
            'Й' => 'Y',
            'К' => 'K',
            'Л' => 'L',
            'М' => 'M',
            'Н' => 'N',
            'О' => 'O',
            'П' => 'P',
            'Р' => 'R',
            'С' => 'S',
            'Т' => 'T',
            'У' => 'U',
            'Ф' => 'F',
            'Х' => 'Kh',
            'Ц' => 'Ts',
            'Ч' => 'Ch',
            'Ш' => 'Sh',
            'Щ' => 'Shch',
            'Ъ' => '',
            'Ы' => 'Y',
            'Ь' => '',
            'Э' => 'E',
            'Ю' => 'Yu',
            'Я' => 'Ya',
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'ґ' => 'g',
            'д' => 'd',
            'е' => 'e',
            'є' => 'ye',
            'ё' => 'yo',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'і' => 'i',
            'ї' => 'yi',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'kh',
            'ц' => 'ts',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'shch',
            'ъ' => '',
            'ы' => 'y',
            'ь' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya'
        );
        return strtr($string, $roman);
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
            if ($url !== '' && preg_match('/^https?:\\/\\//i', $url)) {
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
            // Prioritize 'url' key if it exists in the object
            if (!empty($value['url'])) {
                $urls[] = $value['url'];
                return $urls;
            }

            // Fallback: check for other common keys just in case, but 'url' is the standard
            // We can check 'file' or 'original' if 'url' is missing?
            // The user insisted on 'url' being the clear key, so let's stick to the list logic for fallback.

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

    private function buildProductAttributes(array $prom_product, array $languages)
    {
        $product_attributes = array();

        $raw_attrs = array();
        if (!empty($prom_product['characteristics']) && is_array($prom_product['characteristics'])) {
            $raw_attrs = $prom_product['characteristics'];
        } elseif (!empty($prom_product['attributes']) && is_array($prom_product['attributes'])) {
            $raw_attrs = $prom_product['attributes'];
        } elseif (!empty($prom_product['properties']) && is_array($prom_product['properties'])) {
            $raw_attrs = $prom_product['properties'];
        }

        if (empty($raw_attrs)) {
            return $product_attributes;
        }

        $group_id = $this->ensureAttributeGroup($languages);
        if (!$group_id) {
            return $product_attributes;
        }

        foreach ($raw_attrs as $attr) {
            $name = '';
            if (!empty($attr['name'])) {
                $name = trim($attr['name']);
            } elseif (!empty($attr['title'])) {
                $name = trim($attr['title']);
            } elseif (!empty($attr['key'])) {
                $name = trim($attr['key']);
            }

            if (!$name) {
                continue;
            }

            $value = '';
            if (isset($attr['value']) && is_scalar($attr['value'])) {
                $value = trim((string) $attr['value']);
            } elseif (!empty($attr['text']) && is_scalar($attr['text'])) {
                $value = trim((string) $attr['text']);
            }

            if ($value === '') {
                continue;
            }

            $attribute_id = $this->ensureAttributeByGroup($name, $group_id, $languages);
            if (!$attribute_id) {
                continue;
            }

            $descriptions = array();
            foreach ($languages as $language) {
                $val_lang = $value;
                if (!empty($attr['value_multilang']) && is_array($attr['value_multilang'])) {
                    $code = strtolower(substr($language['code'], 0, 2));
                    if ($code === 'ua')
                        $code = 'uk';
                    if (isset($attr['value_multilang'][$code])) {
                        $val_lang = trim((string) $attr['value_multilang'][$code]);
                    }
                }
                $descriptions[$language['language_id']] = array('text' => $val_lang);
            }

            $product_attributes[] = array(
                'attribute_id' => $attribute_id,
                'product_attribute_description' => $descriptions
            );
        }

        return $product_attributes;
    }

    private function ensureAttributeGroup(array $languages)
    {
        $name = 'Характеристики Prom.ua';
        $query = $this->db->query("SELECT attribute_group_id FROM " . DB_PREFIX . "attribute_group_description WHERE name = '" . $this->db->escape($name) . "' LIMIT 1");

        if (!empty($query->row)) {
            return (int) $query->row['attribute_group_id'];
        }

        $this->load->model('catalog/attribute_group');
        $group_description = array();
        foreach ($languages as $language) {
            $group_description[$language['language_id']] = array('name' => $name);
        }

        return $this->model_catalog_attribute_group->addAttributeGroup(array(
            'sort_order' => 0,
            'attribute_group_description' => $group_description
        ));
    }

    private function ensureAttributeByGroup($name, $group_id, array $languages)
    {
        $query = $this->db->query("SELECT attribute_id FROM " . DB_PREFIX . "attribute_description WHERE name = '" . $this->db->escape($name) . "' LIMIT 1");
        if (!empty($query->row)) {
            return (int) $query->row['attribute_id'];
        }

        $this->load->model('catalog/attribute');
        $attribute_description = array();
        foreach ($languages as $language) {
            $attribute_description[$language['language_id']] = array('name' => $name);
        }

        return $this->model_catalog_attribute->addAttribute(array(
            'attribute_group_id' => $group_id,
            'sort_order' => 0,
            'attribute_description' => $attribute_description
        ));
    }

    private function downloadImage($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $data = $this->fetchUrl($url, $status, $error);
        if ($data === false || $data === '') {
            $this->logMessage(sprintf('PromSync: image download failed for %s (error=%s, status=%d)', $url, $error, (int) $status));
            return '';
        }

        // Auto-convert WebP to JPG for OpenCart compatibility
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

        // Validate image data
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($data);
            if ($info === false) {
                // Try one more check: maybe it is SVG, which getimagesizefromstring fails on sometimes?
                // But for standard Prom sync we expect JPG/PNG usually.
                // Or simply log response preview if it is text
                $preview = substr($data, 0, 100);
                $this->logMessage(sprintf('PromSync: invalid image data for %s. Preview: %s', $url, htmlspecialchars($preview)));
                return '';
            }
        }

        $ext = $this->guessImageExtension($url, $data);
        $filename = 'catalog/prom_sync/' . md5($url) . '.' . $ext;
        // DIR_IMAGE usually includes trailing slash
        $full = rtrim(DIR_IMAGE, '/') . '/' . $filename;

        if (!is_file($full)) {
            $dir = dirname($full);
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                $this->logMessage('PromSync: failed to create image dir ' . $dir);
                return '';
            }
            if (!is_writable($dir)) {
                $this->logMessage('PromSync: image dir not writable ' . $dir);
                return '';
            }

            if (@file_put_contents($full, $data) === false) {
                $this->logMessage('PromSync: failed to write image file ' . $full);
                return '';
            }

            $this->logMessage(sprintf('PromSync: saved new image %s to %s', $url, $filename));
        }

        if (is_file($full)) {
            // Force cache creation (resize) to prevent empty thumbnails in admin
            $this->load->model('tool/image');

            // Use absolute path for cache directory creation
            $path_info = pathinfo($filename);
            // Construct absolute path to cache directory: DIR_IMAGE + cache/ + catalog/prom_sync
            // Note: DIR_IMAGE usually ends with /, $filename is catalog/prom_sync/file.jpg
            $relative_dir = 'cache/' . $path_info['dirname']; // cache/catalog/prom_sync
            $full_cache_dir = rtrim(DIR_IMAGE, '/') . '/' . $relative_dir;

            if (!is_dir($full_cache_dir)) {
                // Try to create with full permissions
                if (!@mkdir($full_cache_dir, 0777, true)) {
                    $this->logMessage('PromSync: FAILED to create cache dir: ' . $full_cache_dir);
                } else {
                    // Sometimes permissions need to be set explicitly after creation
                    @chmod($full_cache_dir, 0777);
                }
            }

            // Sizes to pre-generate based on standard OpenCart defaults:
            // 40x40 (Admin List), 100x100 (Admin Thumb), 228x228 (Catalog Thumb), 500x500 (Popup), 74x74 (Additional)
            $sizes = array(
                array(40, 40),
                array(100, 100),
                array(200, 200),
                array(228, 228),
                array(500, 500),
                array(74, 74)
            );

            foreach ($sizes as $size) {
                $w = $size[0];
                $h = $size[1];

                $resized = $this->model_tool_image->resize($filename, $w, $h);

                // Log the resized URL to see if it is generated correctly
                $this->logMessage(sprintf('PromSync: image resized %dx%d %s -> %s', $w, $h, $filename, $resized));

                // Check if physical cache file was created
                $path_info = pathinfo($filename);
                $dirname = str_replace('\\', '/', $path_info['dirname']);
                $cache_filename = 'cache/' . $dirname . '/' . $path_info['filename'] . '-' . $w . 'x' . $h . '.' . $path_info['extension'];
                $full_cache_file = rtrim(DIR_IMAGE, '/') . '/' . $cache_filename;

                if (!is_file($full_cache_file)) {
                    $this->logMessage(sprintf('PromSync: Cache file not found for %dx%d via standard resize. Attempting manual resize fallback...', $w, $h));

                    // Manual Fallback using GD
                    if (function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled') && function_exists('imagejpeg')) {
                        $src = @imagecreatefromstring($data);
                        if ($src) {
                            $width = imagesx($src);
                            $height = imagesy($src);

                            $dst = imagecreatetruecolor($w, $h);
                            if ($dst) {
                                // Preserve transparency for PNG/WebP if applicable, though we likely have JPG here
                                imagealphablending($dst, false);
                                imagesavealpha($dst, true);
                                $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                                imagefilledrectangle($dst, 0, 0, $w, $h, $transparent);

                                imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $width, $height);

                                if ($path_info['extension'] == 'png') {
                                    imagepng($dst, $full_cache_file);
                                } else {
                                    imagejpeg($dst, $full_cache_file, 90);
                                }

                                imagedestroy($dst);
                                $this->logMessage(sprintf('PromSync: Manual resize fallback SUCCESS for %dx%d. Saved to %s', $w, $h, $full_cache_file));
                            }
                            imagedestroy($src);
                        }
                    }
                }
            }

            return $filename;
        }

        return '';
    }

    private function fetchUrl($url, &$status = 0, &$error = '')
    {
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

    private function curlGet($url, $verify_ssl, &$status = 0, &$error = '')
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_ssl ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify_ssl ? 2 : 0);

        $data = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $status >= 400) {
            return false;
        }

        return $data;
    }

    private function guessImageExtension($url, $data = null)
    {
        // Try content detection first because Prom might send WebP with .jpg extension
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

        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext) {
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            return $ext;
        }

        return 'jpg';
    }

    private function getCategoryIdsForPromProduct(array $prom_product, array $settings, $default_category_id, array $languages)
    {
        $category_ids = array();

        if (!empty($settings['map_groups']) || !empty($settings['single_category'])) {
            $group = null;
            if (!empty($prom_product['group'])) {
                if (is_array($prom_product['group'])) {
                    $group = $prom_product['group'];
                } else {
                    $group = array('id' => (int) $prom_product['group']);
                }
            } elseif (!empty($prom_product['group_id'])) {
                $group = array('id' => (int) $prom_product['group_id']);
            } elseif (!empty($prom_product['category_id'])) {
                $group = array('id' => (int) $prom_product['category_id']);
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

    private function ensureCategoryForGroup(array $group, array $settings, array $languages)
    {
        $prom_group_id = (int) $group['id'];
        $query = $this->db->query("SELECT oc_category_id FROM `" . DB_PREFIX . "prom_sync_group` WHERE prom_group_id = '" . (int) $prom_group_id . "' LIMIT 1");

        if (!empty($query->row)) {
            return (int) $query->row['oc_category_id'];
        }

        if (empty($settings['create_categories'])) {
            return 0;
        }

        $parent_id = 0;
        if (!empty($settings['single_category'])) {
            $parent_id = $this->getOrCreateRootGoodsCategory($languages);
        }

        if (!empty($group['parent_group_id'])) {
            $prom_parent_id = (int) $group['parent_group_id'];
            $parent_query = $this->db->query("SELECT oc_category_id FROM `" . DB_PREFIX . "prom_sync_group` WHERE prom_group_id = '" . (int) $prom_parent_id . "' LIMIT 1");

            if (!empty($parent_query->row)) {
                $parent_id = (int) $parent_query->row['oc_category_id'];
            } else {
                // Parent group not mapped yet. Try to find its info in $prom_product if available, 
                // but usually we only have the ID here.
                // For a proper recursive build, we'd need more info. 
                // However, Prom typically sends products with group info.
                // If we don't have parent name, we'll create a placeholder or just use ТОВАРИ.
                $parent_group = array('id' => $prom_parent_id);
                $parent_id = $this->ensureCategoryForGroup($parent_group, $settings, $languages);
            }
        }

        $this->load->model('catalog/category');

        $name = !empty($group['name']) ? (string) $group['name'] : ('Prom Group ' . $prom_group_id);
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
        $this->db->query("INSERT INTO `" . DB_PREFIX . "prom_sync_group` SET prom_group_id = '" . (int) $prom_group_id . "', oc_category_id = '" . (int) $oc_category_id . "'");

        return $oc_category_id;
    }

    private function getOrCreateRootGoodsCategory(array $languages)
    {
        $name = 'ТОВАРИ';

        // Search by name
        $query = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category_description` WHERE name = '" . $this->db->escape($name) . "' LIMIT 1");
        if (!empty($query->row)) {
            return (int) $query->row['category_id'];
        }

        $this->load->model('catalog/category');

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
            'parent_id' => 0,
            'top' => 1,
            'column' => 1,
            'sort_order' => 0,
            'status' => 1,
            'category_description' => $category_description,
            'category_store' => array(0),
            'category_layout' => array(),
            'keyword' => ''
        );

        return $this->model_catalog_category->addCategory($category_data);
    }

    private function getProductMappingByPromId($prom_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_product_id = '" . (int) $prom_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductMappingByOcId($product_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE oc_product_id = '" . (int) $product_id . "' LIMIT 1");
        return !empty($query->row) ? $query->row : null;
    }

    private function getProductQuantity($product_id)
    {
        $query = $this->db->query("SELECT quantity FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int) $product_id . "' LIMIT 1");
        if (!empty($query->row)) {
            return (int) $query->row['quantity'];
        }
        return 0;
    }

    private function getProductMappingByExternalId($external_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "prom_sync_product` WHERE prom_external_id = '" . $this->db->escape($external_id) . "' LIMIT 1");
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

    private function touchProductMapping($prom_id, $external_id, $oc_product_id)
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "prom_sync_product` SET
            prom_external_id = '" . $this->db->escape((string) $external_id) . "',
            date_modified = NOW()
            WHERE prom_product_id = '" . (int) $prom_id . "' AND oc_product_id = '" . (int) $oc_product_id . "'");
    }

    private function getLogger()
    {
        if (!$this->logger) {
            $this->logger = new Log('prom_sync.log');
        }
        return $this->logger;
    }

    private function logMessage($message)
    {
        $logger = $this->getLogger();
        $logger->write($message);
    }

    private function encodeJson($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = print_r($data, true);
        }
        return $json;
    }

    private function getSettings()
    {
        return array(
            'token' => $this->config->get('module_prom_sync_token'),
            'domain' => $this->config->get('module_prom_sync_domain') ?: 'prom.ua',
            'language' => $this->config->get('module_prom_sync_language'),
            'default_category_id' => (int) $this->config->get('module_prom_sync_default_category_id'),
            'map_groups' => $this->getBoolSetting('module_prom_sync_map_groups', true),
            'create_categories' => $this->getBoolSetting('module_prom_sync_create_categories', true),
            'update_existing' => (bool) $this->config->get('module_prom_sync_update_existing'),
            'map_name' => (bool) $this->config->get('module_prom_sync_map_name'),
            'map_description' => (bool) $this->config->get('module_prom_sync_map_description'),
            'map_price' => (bool) $this->config->get('module_prom_sync_map_price'),
            'map_quantity' => (bool) $this->config->get('module_prom_sync_map_quantity'),
            'map_sku' => (bool) $this->config->get('module_prom_sync_map_sku'),
            'map_images' => $this->getBoolSetting('module_prom_sync_map_images', true),
            'single_category' => $this->getBoolSetting('module_prom_sync_single_category', false),
            'limit' => (int) $this->config->get('module_prom_sync_limit')
        );
    }

    private function getBoolSetting($key, $default = false)
    {
        $value = $this->config->get($key);
        if ($value === null || $value === '') {
            return (bool) $default;
        }
        return (bool) $value;
    }

    private function getApi(array $settings)
    {
        require_once(DIR_SYSTEM . 'library/prom_sync/PromApi.php');
        return new PromSyncApi($settings['token'], $settings['domain'], $settings['language']);
    }

    private function getLanguages()
    {
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
