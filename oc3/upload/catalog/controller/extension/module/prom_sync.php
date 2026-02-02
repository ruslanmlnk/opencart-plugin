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
}
