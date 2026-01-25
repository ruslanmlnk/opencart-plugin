<?php
namespace Opencart\Catalog\Controller\Extension\PromSync\Module;

class PromSync extends \Opencart\System\Engine\Controller {
    public function index(): string {
        return '';
    }

    public function cron(): void {
        $key = isset($this->request->get['key']) ? $this->request->get['key'] : '';
        $expected = $this->config->get('module_prom_sync_cron_key');

        if (!$expected || $key !== $expected) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }

        if (!$this->config->get('module_prom_sync_status')) {
            $this->response->setOutput('PromSync: disabled');
            return;
        }

        $this->load->model('extension/prom_sync/module/prom_sync');
        $result = $this->model_extension_prom_sync_module_prom_sync->runCron();
        $this->response->setOutput($result);
    }
}
