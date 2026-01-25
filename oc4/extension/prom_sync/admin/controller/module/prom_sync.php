<?php
namespace Opencart\Admin\Controller\Extension\PromSync\Module;

class PromSync extends \Opencart\System\Engine\Controller {
    private array $error = array();

    public function index(): void {
        $this->load->language('extension/prom_sync/module/prom_sync');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('extension/prom_sync/module/prom_sync');
        $this->ensureMenuEvent();

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
        $data['text_section_connection'] = $this->language->get('text_section_connection');
        $data['text_section_import'] = $this->language->get('text_section_import');
        $data['text_section_mapping'] = $this->language->get('text_section_mapping');
        $data['text_section_sync'] = $this->language->get('text_section_sync');
        $data['text_section_actions'] = $this->language->get('text_section_actions');
        $data['text_section_cron'] = $this->language->get('text_section_cron');
        $data['text_import_note'] = $this->language->get('text_import_note');
        $data['text_cron_note'] = $this->language->get('text_cron_note');

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

        $data['help_domain'] = $this->language->get('help_domain');
        $data['help_token'] = $this->language->get('help_token');
        $data['help_language'] = $this->language->get('help_language');
        $data['help_default_category'] = $this->language->get('help_default_category');
        $data['help_cron_key'] = $this->language->get('help_cron_key');
        $data['help_limit'] = $this->language->get('help_limit');

        $data['button_save'] = $this->language->get('button_save');
        $data['button_back'] = $this->language->get('button_back');
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
            $data['error_warning'] = '';
        }
        $data['error_token'] = '';

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/prom_sync/module/prom_sync', 'user_token=' . $this->session->data['user_token'])
        );

        $data['save'] = $this->url->link('extension/prom_sync/module/prom_sync.save', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');
        $data['action_import'] = $this->url->link('extension/prom_sync/module/prom_sync.import', 'user_token=' . $this->session->data['user_token']);

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

        $catalog = '';
        if (defined('HTTPS_CATALOG')) {
            $catalog = HTTPS_CATALOG;
        } elseif (defined('HTTP_CATALOG')) {
            $catalog = HTTP_CATALOG;
        }

        if ($catalog) {
            $data['cron_url'] = $catalog . 'index.php?route=extension/prom_sync/module/prom_sync.cron&key=' . $cron_key;
        } else {
            $data['cron_url'] = 'index.php?route=extension/prom_sync/module/prom_sync.cron&key=' . $cron_key;
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/prom_sync/module/prom_sync', $data));
    }

    public function save(): void {
        $this->load->language('extension/prom_sync/module/prom_sync');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/prom_sync/module/prom_sync')) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        if (empty($this->request->post['module_prom_sync_token'])) {
            $json['error']['token'] = $this->language->get('error_token');
        }

        if (!$json) {
            if (!isset($this->request->post['module_prom_sync_last_order_sync'])) {
                $this->request->post['module_prom_sync_last_order_sync'] = $this->config->get('module_prom_sync_last_order_sync');
            }

            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('module_prom_sync', $this->request->post);

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function import(): void {
        $this->load->language('extension/prom_sync/module/prom_sync');
        $this->load->model('extension/prom_sync/module/prom_sync');

        if (!$this->user->hasPermission('modify', 'extension/prom_sync/module/prom_sync')) {
            $this->session->data['error_warning'] = $this->language->get('error_permission');
            $this->response->redirect($this->url->link('extension/prom_sync/module/prom_sync', 'user_token=' . $this->session->data['user_token']));
        }

        $summary = $this->model_extension_prom_sync_module_prom_sync->importProducts();
        $message = sprintf('Import finished. Imported: %d, Updated: %d, Skipped: %d, Errors: %d',
            $summary['imported'], $summary['updated'], $summary['skipped'], $summary['errors']
        );

        $this->session->data['success'] = $message;
        $this->response->redirect($this->url->link('extension/prom_sync/module/prom_sync', 'user_token=' . $this->session->data['user_token']));
    }

    public function install(): void {
        $this->load->model('extension/prom_sync/module/prom_sync');
        $this->model_extension_prom_sync_module_prom_sync->install();
    }

    public function uninstall(): void {
        $this->load->model('extension/prom_sync/module/prom_sync');
        $this->model_extension_prom_sync_module_prom_sync->uninstall();
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

    private function ensureMenuEvent(): void {
        $this->load->model('setting/event');

        $event = $this->model_setting_event->getEventByCode('prom_sync_menu');
        if ($event) {
            return;
        }

        $this->model_setting_event->addEvent(array(
            'code' => 'prom_sync_menu',
            'description' => 'PromSync: add admin menu item',
            'trigger' => 'view/common/column_left/before',
            'action' => 'extension/prom_sync/menu',
            'status' => 1,
            'sort_order' => 2
        ));
    }
}
