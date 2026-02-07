<?php
class ControllerExtensionPromSyncProduct extends Controller {
    public function list(&$route, &$data, &$output) {
        if (!$this->user->hasPermission('access', 'extension/module/prom_sync')) {
            return;
        }

        if (empty($this->session->data['user_token']) && empty($this->session->data['token'])) {
            return;
        }

        if (empty($data['products']) || !is_array($data['products'])) {
            return;
        }

        $this->load->language('extension/module/prom_sync');
        $text = $this->language->get('text_sync');

        foreach ($data['products'] as &$product) {
            if (empty($product['product_id'])) {
                continue;
            }

            $href = $this->url->link(
                'extension/prom_sync/product/sync',
                $this->buildToken() . '&product_id=' . (int)$product['product_id'],
                true
            );

            $action = array(
                'text' => $text,
                'href' => $href,
                'icon' => 'fa-refresh'
            );

            $exists = false;
            if (isset($product['action']) && is_array($product['action'])) {
                foreach ($product['action'] as $existing) {
                    if (isset($existing['href']) && $existing['href'] === $href) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $product['action'][] = $action;
                }
            } else {
                $product['action'] = array($action);
            }
        }
    }

    public function sync() {
        $this->load->language('extension/module/prom_sync');

        if (!$this->user->hasPermission('modify', 'extension/module/prom_sync')) {
            $this->session->data['error_warning'] = $this->language->get('error_permission');
            $this->response->redirect($this->url->link('catalog/product', $this->buildToken(), true));
            return;
        }

        $product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
        if (!$product_id) {
            $this->session->data['error_warning'] = $this->language->get('error_sync_product');
            $this->response->redirect($this->url->link('catalog/product', $this->buildToken(), true));
            return;
        }

        $this->load->model('extension/module/prom_sync');
        $result = $this->model_extension_module_prom_sync->syncProductByOcId($product_id);

        if (!empty($result['success'])) {
            $this->session->data['success'] = sprintf($this->language->get('text_sync_success'), $product_id);
        } else {
            $message = !empty($result['error']) ? $result['error'] : $this->language->get('text_sync_error');
            $this->session->data['error_warning'] = $message;
        }

        $this->response->redirect($this->url->link('catalog/product', $this->buildToken(), true));
    }

    private function buildToken() {
        if (!empty($this->session->data['user_token'])) {
            return 'user_token=' . $this->session->data['user_token'];
        }
        return 'token=' . $this->session->data['token'];
    }
}
