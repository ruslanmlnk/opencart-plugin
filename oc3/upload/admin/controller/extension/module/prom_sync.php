<?php
class ControllerExtensionModulePromSync extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/module/prom_sync');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('extension/module/prom_sync');
        $this->ensureMenuEvent();

        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            if (!isset($this->request->post['module_prom_sync_last_order_sync'])) {
                $this->request->post['module_prom_sync_last_order_sync'] = $this->config->get('module_prom_sync_last_order_sync');
            }

            if (empty($this->request->post['module_prom_sync_cron_key'])) {
                $existing = $this->config->get('module_prom_sync_cron_key');
                $this->request->post['module_prom_sync_cron_key'] = $existing ? $existing : $this->generateCronKey();
            }

            $this->model_setting_setting->editSetting('module_prom_sync', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', $this->buildToken() . '&type=module', true));
        }

        $data['heading_title'] = $this->language->get('heading_title');

        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_yes'] = $this->language->get('text_yes');
        $data['text_no'] = $this->language->get('text_no');
        $data['text_home'] = $this->language->get('text_home');
        $data['text_tab_general'] = $this->language->get('text_tab_general');
        $data['text_tab_import'] = $this->language->get('text_tab_import');
        $data['text_tab_sync'] = $this->language->get('text_tab_sync');
        $data['text_tab_logs'] = $this->language->get('text_tab_logs');
        $data['text_section_connection'] = $this->language->get('text_section_connection');
        $data['text_section_import'] = $this->language->get('text_section_import');
        $data['text_section_mapping'] = $this->language->get('text_section_mapping');
        $data['text_section_sync'] = $this->language->get('text_section_sync');
        $data['text_section_actions'] = $this->language->get('text_section_actions');
        $data['text_section_cron'] = $this->language->get('text_section_cron');
        $data['text_section_logs'] = $this->language->get('text_section_logs');
        $data['text_import_note'] = $this->language->get('text_import_note');
        $data['text_cron_note'] = $this->language->get('text_cron_note');
        $data['text_import_progress'] = $this->language->get('text_import_progress');
        $data['text_import_complete'] = $this->language->get('text_import_complete');
        $data['text_import_error'] = $this->language->get('text_import_error');
        $data['text_logs_note'] = $this->language->get('text_logs_note');

        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_token'] = $this->language->get('entry_token');
        $data['entry_domain'] = $this->language->get('entry_domain');
        $data['entry_language'] = $this->language->get('entry_language');
        $data['entry_default_category'] = $this->language->get('entry_default_category');
        $data['entry_map_groups'] = $this->language->get('entry_map_groups');
        $data['entry_create_categories'] = $this->language->get('entry_create_categories');
        $data['entry_update_existing'] = $this->language->get('entry_update_existing');
        $data['entry_map_name'] = $this->language->get('entry_map_name');
        $data['entry_map_description'] = $this->language->get('entry_map_description');
        $data['entry_map_price'] = $this->language->get('entry_map_price');
        $data['entry_map_quantity'] = $this->language->get('entry_map_quantity');
        $data['entry_map_sku'] = $this->language->get('entry_map_sku');
        $data['entry_map_images'] = $this->language->get('entry_map_images');
        $data['entry_push_stock'] = $this->language->get('entry_push_stock');
        $data['entry_pull_orders'] = $this->language->get('entry_pull_orders');
        $data['entry_match_by_sku'] = $this->language->get('entry_match_by_sku');
        $data['entry_cron_key'] = $this->language->get('entry_cron_key');
        $data['entry_cron_url'] = $this->language->get('entry_cron_url');
        $data['entry_limit'] = $this->language->get('entry_limit');
        $data['entry_country_ru'] = $this->language->get('entry_country_ru');
        $data['entry_country_uk'] = $this->language->get('entry_country_uk');

        $data['help_domain'] = $this->language->get('help_domain');
        $data['help_token'] = $this->language->get('help_token');
        $data['help_language'] = $this->language->get('help_language');
        $data['help_default_category'] = $this->language->get('help_default_category');
        $data['help_cron_key'] = $this->language->get('help_cron_key');
        $data['help_limit'] = $this->language->get('help_limit');
        $data['help_country'] = $this->language->get('help_country');

        $data['button_save'] = $this->language->get('button_save');
        $data['button_back'] = $this->language->get('button_back');
        $data['button_copy'] = $this->language->get('button_copy');
        $data['button_import'] = $this->language->get('button_import');

        if (!empty($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        if (!empty($this->session->data['error_warning'])) {
            $data['error_warning'] = $this->session->data['error_warning'];
            unset($this->session->data['error_warning']);
        } else {
            $data['error_warning'] = !empty($this->error['warning']) ? $this->error['warning'] : '';
        }
        $data['error_token'] = !empty($this->error['token']) ? $this->error['token'] : '';

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', $this->buildToken(), true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', $this->buildToken() . '&type=module', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/prom_sync', $this->buildToken(), true)
        );

        $data['action'] = $this->url->link('extension/module/prom_sync', $this->buildToken(), true);
        $data['back'] = $this->url->link('marketplace/extension', $this->buildToken() . '&type=module', true);
        $data['action_import'] = $this->url->link('extension/module/prom_sync/import', $this->buildToken(), true);
        $data['action_import_batch'] = $this->url->link('extension/module/prom_sync/importBatch', $this->buildToken(), true);

        $data['module_prom_sync_status'] = $this->getConfigValue('module_prom_sync_status');
        $data['module_prom_sync_token'] = $this->getConfigValue('module_prom_sync_token');
        $data['module_prom_sync_domain'] = $this->getConfigValue('module_prom_sync_domain', 'prom.ua');
        $data['module_prom_sync_language'] = $this->getConfigValue('module_prom_sync_language', 'uk');
        $data['module_prom_sync_default_category_id'] = $this->getConfigValue('module_prom_sync_default_category_id');
        $data['module_prom_sync_map_groups'] = $this->getConfigValue('module_prom_sync_map_groups', 1);
        $data['module_prom_sync_create_categories'] = $this->getConfigValue('module_prom_sync_create_categories', 1);
        $data['module_prom_sync_update_existing'] = $this->getConfigValue('module_prom_sync_update_existing');
        $data['module_prom_sync_map_name'] = $this->getConfigValue('module_prom_sync_map_name', 1);
        $data['module_prom_sync_map_description'] = $this->getConfigValue('module_prom_sync_map_description', 1);
        $data['module_prom_sync_map_price'] = $this->getConfigValue('module_prom_sync_map_price', 1);
        $data['module_prom_sync_map_quantity'] = $this->getConfigValue('module_prom_sync_map_quantity', 1);
        $data['module_prom_sync_map_sku'] = $this->getConfigValue('module_prom_sync_map_sku', 1);
        $data['module_prom_sync_map_images'] = $this->getConfigValue('module_prom_sync_map_images', 1);
        $data['module_prom_sync_push_stock'] = $this->getConfigValue('module_prom_sync_push_stock', 1);
        $data['module_prom_sync_pull_orders'] = $this->getConfigValue('module_prom_sync_pull_orders', 1);
        $data['module_prom_sync_match_by_sku'] = $this->getConfigValue('module_prom_sync_match_by_sku', 1);
        $data['module_prom_sync_keep_zero_qty_enabled'] = $this->getConfigValue('module_prom_sync_keep_zero_qty_enabled', 0);
        $data['module_prom_sync_limit'] = $this->getConfigValue('module_prom_sync_limit', 50);
        $data['module_prom_sync_country_ru'] = $this->getConfigValue('module_prom_sync_country_ru', 'Украина');
        $data['module_prom_sync_country_uk'] = $this->getConfigValue('module_prom_sync_country_uk', 'Україна');
        $data['module_prom_sync_single_category'] = $this->getConfigValue('module_prom_sync_single_category', 0);

        $data['log_content'] = $this->getLogTail(DIR_LOGS . 'prom_sync.log', 300);

        $cron_key = $this->getConfigValue('module_prom_sync_cron_key');
        if (!$cron_key) {
            $cron_key = $this->generateCronKey();
        }
        $data['module_prom_sync_cron_key'] = $cron_key;

        $catalog = '';
        if (defined('HTTPS_CATALOG')) {
            $catalog = HTTPS_CATALOG;
        } elseif (defined('HTTP_CATALOG')) {
            $catalog = HTTP_CATALOG;
        }

        if ($catalog) {
            $data['cron_url'] = $catalog . 'index.php?route=extension/module/prom_sync/cron&key=' . $cron_key;
        } else {
            $data['cron_url'] = 'index.php?route=extension/module/prom_sync/cron&key=' . $cron_key;
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/prom_sync', $data));
    }

    private function getLogTail($filename, $lines = 200)
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return '';
        }

        $handle = fopen($filename, 'rb');
        if (!$handle) {
            return '';
        }

        $buffer = '';
        $chunk_size = 8192;
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        $line_count = 0;

        while ($position > 0 && $line_count <= $lines) {
            $read = ($position - $chunk_size) >= 0 ? $chunk_size : $position;
            $position -= $read;
            fseek($handle, $position);
            $data = fread($handle, $read);
            $buffer = $data . $buffer;
            $line_count = substr_count($buffer, "\n");
        }

        fclose($handle);

        $parts = explode("\n", $buffer);
        if (count($parts) > $lines) {
            $parts = array_slice($parts, -$lines);
        }

        return implode("\n", $parts);
    }

    public function import()
    {
        $this->load->language('extension/module/prom_sync');
        $this->load->model('extension/module/prom_sync');

        if (!$this->user->hasPermission('modify', 'extension/module/prom_sync')) {
            $this->session->data['error_warning'] = $this->language->get('error_permission');
            $this->response->redirect($this->url->link('extension/module/prom_sync', $this->buildToken(), true));
        }

        $summary = $this->model_extension_module_prom_sync->importProducts();
        $message = sprintf(
            'Import finished. Imported: %d, Updated: %d, Skipped: %d, Errors: %d',
            $summary['imported'],
            $summary['updated'],
            $summary['skipped'],
            $summary['errors']
        );

        $this->session->data['success'] = $message;
        $this->response->redirect($this->url->link('extension/module/prom_sync', $this->buildToken(), true));
    }

    public function importBatch()
    {
        $this->load->language('extension/module/prom_sync');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/prom_sync')) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json) {
            $this->load->model('extension/module/prom_sync');

            $options = array();
            if (isset($this->request->post['last_id']) && $this->request->post['last_id'] !== '') {
                $options['last_id'] = (int) $this->request->post['last_id'];
            }

            $result = $this->model_extension_module_prom_sync->importProductsBatch($options);

            if (empty($result['success'])) {
                $json['error'] = !empty($result['error']) ? $result['error'] : $this->language->get('text_import_error');
            } else {
                $json = $result;
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install()
    {
        $this->load->model('user/user_group');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/module/prom_sync');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/module/prom_sync');

        $this->load->model('extension/module/prom_sync');
        $this->model_extension_module_prom_sync->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/module/prom_sync');
        $this->model_extension_module_prom_sync->uninstall();
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/prom_sync')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (empty($this->request->post['module_prom_sync_token'])) {
            $this->error['token'] = $this->language->get('error_token');
        }

        return !$this->error;
    }

    private function getConfigValue($key, $default = '')
    {
        if (isset($this->request->post[$key])) {
            return $this->request->post[$key];
        }
        $value = $this->config->get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    private function ensureMenuEvent()
    {
        $this->load->model('setting/event');
        if (!$this->eventExists('prom_sync_menu')) {
            $this->model_setting_event->addEvent('prom_sync_menu', 'view/common/column_left/before', 'extension/prom_sync/menu', 1, 2);
        }

        if (!$this->eventExists('prom_sync_product_list')) {
            $this->model_setting_event->addEvent('prom_sync_product_list', 'view/catalog/product_list/before', 'extension/prom_sync/product/list', 1, 3);
        }
    }

    private function eventExists($code)
    {
        if (method_exists($this->model_setting_event, 'getEventByCode')) {
            return (bool) $this->model_setting_event->getEventByCode($code);
        }

        $query = $this->db->query("SELECT event_id FROM `" . DB_PREFIX . "event` WHERE code = '" . $this->db->escape($code) . "' LIMIT 1");
        return !empty($query->row);
    }

    private function buildToken()
    {
        if (!empty($this->session->data['user_token'])) {
            return 'user_token=' . $this->session->data['user_token'];
        }
        return 'token=' . $this->session->data['token'];
    }

    private function generateCronKey()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(16));
        }
        return md5(uniqid('', true));
    }
}

