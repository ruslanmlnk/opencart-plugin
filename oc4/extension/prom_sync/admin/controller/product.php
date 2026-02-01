<?php
namespace Opencart\Admin\Controller\Extension\PromSync;

class Product extends \Opencart\System\Engine\Controller {
    public function list(&$route, &$data, &$code, &$output): void {
        if (!$this->user->hasPermission('access', 'extension/prom_sync/module/prom_sync')) {
            return;
        }

        if (empty($this->session->data['user_token'])) {
            return;
        }

        if (empty($data['products']) || !is_array($data['products'])) {
            return;
        }

        $this->load->language('extension/prom_sync/module/prom_sync');
        $text = $this->language->get('text_sync');

        foreach ($data['products'] as &$product) {
            if (empty($product['product_id'])) {
                continue;
            }

            $href = $this->url->link(
                'extension/prom_sync/product.sync',
                'user_token=' . $this->session->data['user_token'] . '&product_id=' . (int)$product['product_id']
            );

            $action = array(
                'text' => $text,
                'href' => $href,
                'icon' => 'fa-solid fa-arrows-rotate'
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

    public function sync(): void {
        $this->load->language('extension/prom_sync/module/prom_sync');

        if (!$this->user->hasPermission('modify', 'extension/prom_sync/module/prom_sync')) {
            $this->session->data['error_warning'] = $this->language->get('error_permission');
            $this->response->redirect($this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token']));
            return;
        }

        $product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
        if (!$product_id) {
            $this->session->data['error_warning'] = $this->language->get('error_sync_product');
            $this->response->redirect($this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token']));
            return;
        }

        $this->load->model('extension/prom_sync/module/prom_sync');
        $result = $this->model_extension_prom_sync_module_prom_sync->syncProductByOcId($product_id);

        if (!empty($result['success'])) {
            $this->session->data['success'] = sprintf($this->language->get('text_sync_success'), $product_id);
        } else {
            $message = !empty($result['error']) ? $result['error'] : $this->language->get('text_sync_error');
            $this->session->data['error_warning'] = $message;
        }

        $this->response->redirect($this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token']));
    }
}
