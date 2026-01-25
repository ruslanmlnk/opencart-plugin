<?php
class ControllerExtensionModulePromSync extends Controller {
    private $error = array();

    public function index() {
        $this->load->language('extension/module/prom_sync');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('extension/module/prom_sync');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            if (!isset($this->request->post['module_prom_sync_last_order_sync'])) {
                $this->request->post['module_prom_sync_last_order_sync'] = $this->config->get('module_prom_sync_last_order_sync');
            }
            $this->model_setting_setting->editSetting('module_prom_sync', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
        }

        $data['heading_title'] = $this->language->get('heading_title');

        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_yes'] = $this->language->get('text_yes');
        $data['text_no'] = $this->language->get('text_no');
        $data['text_home'] = $this->language->get('text_home');

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
        $data['entry_limit'] = $this->language->get('entry_limit');

        $data['help_domain'] = $this->language->get('help_domain');
        $data['help_token'] = $this->language->get('help_token');
        $data['help_language'] = $this->language->get('help_language');
        $data['help_default_category'] = $this->language->get('help_default_category');
        $data['help_cron_key'] = $this->language->get('help_cron_key');
        $data['help_limit'] = $this->language->get('help_limit');

        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['button_import'] = $this->language->get('button_import');

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (!empty($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        if (isset($this->error['token'])) {
            $data['error_token'] = $this->error['token'];
        } else {
            $data['error_token'] = '';
        }

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/prom_sync', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/module/prom_sync', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);
        $data['action_import'] = $this->url->link('extension/module/prom_sync/import', 'user_token=' . $this->session->data['user_token'], true);

        $data['module_prom_sync_status'] = $this->getConfigValue('module_prom_sync_status');
        $data['module_prom_sync_token'] = $this->getConfigValue('module_prom_sync_token');
        $data['module_prom_sync_domain'] = $this->getConfigValue('module_prom_sync_domain', 'prom.ua');
        $data['module_prom_sync_language'] = $this->getConfigValue('module_prom_sync_language', 'uk');
        $data['module_prom_sync_default_category_id'] = $this->getConfigValue('module_prom_sync_default_category_id');
        $data['module_prom_sync_map_groups'] = $this->getConfigValue('module_prom_sync_map_groups');
        $data['module_prom_sync_create_categories'] = $this->getConfigValue('module_prom_sync_create_categories');
        $data['module_prom_sync_update_existing'] = $this->getConfigValue('module_prom_sync_update_existing');
        $data['module_prom_sync_map_name'] = $this->getConfigValue('module_prom_sync_map_name', 1);
        $data['module_prom_sync_map_description'] = $this->getConfigValue('module_prom_sync_map_description', 1);
        $data['module_prom_sync_map_price'] = $this->getConfigValue('module_prom_sync_map_price', 1);
        $data['module_prom_sync_map_quantity'] = $this->getConfigValue('module_prom_sync_map_quantity', 1);
        $data['module_prom_sync_map_sku'] = $this->getConfigValue('module_prom_sync_map_sku', 1);
        $data['module_prom_sync_map_images'] = $this->getConfigValue('module_prom_sync_map_images');
        $data['module_prom_sync_push_stock'] = $this->getConfigValue('module_prom_sync_push_stock', 1);
        $data['module_prom_sync_pull_orders'] = $this->getConfigValue('module_prom_sync_pull_orders', 1);
        $data['module_prom_sync_match_by_sku'] = $this->getConfigValue('module_prom_sync_match_by_sku', 1);
        $data['module_prom_sync_limit'] = $this->getConfigValue('module_prom_sync_limit', 50);

        $cron_key = $this->getConfigValue('module_prom_sync_cron_key');
        if (!$cron_key) {
            $cron_key = bin2hex(random_bytes(16));
        }
        $data['module_prom_sync_cron_key'] = $cron_key;

        if (!empty(HTTP_CATALOG)) {
            $data['cron_url'] = HTTP_CATALOG . 'index.php?route=extension/module/prom_sync/cron&key=' . $cron_key;
        } else {
            $data['cron_url'] = 'index.php?route=extension/module/prom_sync/cron&key=' . $cron_key;
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/prom_sync', $data));
    }

    public function import() {
        $this->load->language('extension/module/prom_sync');
        $this->load->model('extension/module/prom_sync');

        if (!$this->user->hasPermission('modify', 'extension/module/prom_sync')) {
            $this->session->data['error_warning'] = $this->language->get('error_permission');
            $this->response->redirect($this->url->link('extension/module/prom_sync', 'user_token=' . $this->session->data['user_token'], true));
        }

        $summary = $this->model_extension_module_prom_sync->importProducts();
        $message = sprintf('Import finished. Imported: %d, Updated: %d, Skipped: %d, Errors: %d',
            $summary['imported'], $summary['updated'], $summary['skipped'], $summary['errors']
        );

        $this->session->data['success'] = $message;
        $this->response->redirect($this->url->link('extension/module/prom_sync', 'user_token=' . $this->session->data['user_token'], true));
    }

    public function install() {
        $this->load->model('extension/module/prom_sync');
        $this->model_extension_module_prom_sync->install();
    }

    public function uninstall() {
        $this->load->model('extension/module/prom_sync');
        $this->model_extension_module_prom_sync->uninstall();
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/prom_sync')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (empty($this->request->post['module_prom_sync_token'])) {
            $this->error['token'] = $this->language->get('error_token');
        }

        return !$this->error;
    }

    private function getConfigValue($key, $default = '') {
        if (isset($this->request->post[$key])) {
            return $this->request->post[$key];
        }
        $value = $this->config->get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}
