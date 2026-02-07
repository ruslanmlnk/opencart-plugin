<?php
class ControllerExtensionModulePromSync extends Controller
{
    public function index($setting = array())
    {
        return '';
    }

    public function cron()
    {
        $key = isset($this->request->get['key']) ? $this->request->get['key'] : '';
        $expected = $this->config->get('module_prom_sync_cron_key');

        if (!$expected || $key !== $expected) {
            $this->load->model('extension/module/prom_sync');
            $this->model_extension_module_prom_sync->logMessage('PromSync cron: ACCESS DENIED (invalid key)');
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }

        if (!$this->config->get('module_prom_sync_status')) {
            $this->load->model('extension/module/prom_sync');
            $this->model_extension_module_prom_sync->logMessage('PromSync cron: module disabled');
            $this->response->setOutput('PromSync: disabled');
            return;
        }

        $this->load->model('extension/module/prom_sync');
        $this->model_extension_module_prom_sync->logMessage('PromSync cron: STARTING task from ' . $this->request->server['REMOTE_ADDR']);
        $result = $this->model_extension_module_prom_sync->runCron();
        $this->model_extension_module_prom_sync->logMessage('PromSync cron: FINISHED. Result: ' . $result);
        $this->response->setOutput($result);
    }

    public function cron_inventory()
    {
        $key = isset($this->request->get['key']) ? $this->request->get['key'] : '';
        $expected = $this->config->get('module_prom_sync_cron_key');

        if (!$expected || $key !== $expected) {
            $this->load->model('extension/module/prom_sync');
            $this->model_extension_module_prom_sync->logMessage('PromSync inventory cron: ACCESS DENIED (invalid key)');
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }

        if (!$this->config->get('module_prom_sync_status')) {
            $this->load->model('extension/module/prom_sync');
            $this->model_extension_module_prom_sync->logMessage('PromSync inventory cron: module disabled');
            $this->response->setOutput('PromSync: disabled');
            return;
        }

        $this->load->model('extension/module/prom_sync');
        $this->model_extension_module_prom_sync->logMessage('PromSync inventory cron: STARTING task from ' . $this->request->server['REMOTE_ADDR']);
        $force = !empty($this->request->get['force']);
        $result = $this->model_extension_module_prom_sync->syncInventoryFromPromProducts($force);
        $this->model_extension_module_prom_sync->logMessage('PromSync inventory cron: FINISHED. Result: ' . $result);
        $this->response->setOutput($result);
    }

    public function webhook()
    {
        $key = isset($this->request->get['key']) ? $this->request->get['key'] : '';
        $expected = $this->config->get('module_prom_sync_cron_key');

        if (!$expected || $key !== $expected) {
            $this->load->model('extension/module/prom_sync');
            $this->model_extension_module_prom_sync->logMessage('PromSync webhook: ACCESS DENIED (invalid key)');
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }

        if (!$this->config->get('module_prom_sync_status')) {
            return;
        }

        $this->load->model('extension/module/prom_sync');

        $input = file_get_contents('php://input');
        if (!$input) {
            $this->model_extension_module_prom_sync->logMessage('PromSync webhook: empty input');
            return;
        }

        $data = json_decode($input, true);
        if (!$data) {
            $this->model_extension_module_prom_sync->logMessage('PromSync webhook: invalid JSON: ' . $input);
            return;
        }

        // Prom sends order data in 'order' key for some webhooks, or direct order object for others
        $order = null;
        if (!empty($data['order'])) {
            $order = $data['order'];
        } elseif (!empty($data['id']) && (!empty($data['products']) || !empty($data['status']))) {
            $order = $data;
        }

        if ($order) {
            $this->model_extension_module_prom_sync->logMessage('PromSync webhook: processing order ' . $order['id']);
            $result = $this->model_extension_module_prom_sync->processPromOrder($order);
            $this->model_extension_module_prom_sync->logMessage('PromSync webhook: result: ' . $result);
            $this->response->setOutput('OK: ' . $result);
        } else {
            $this->model_extension_module_prom_sync->logMessage('PromSync webhook: no order data found in: ' . $input);
            $this->response->setOutput('No order data');
        }
    }
}
