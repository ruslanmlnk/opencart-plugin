<?php
namespace Opencart\Admin\Controller\Extension\PromSync;

class Menu extends \Opencart\System\Engine\Controller {
    public function index(&$route, &$data, &$code, &$output): void {
        if (!$this->user->hasPermission('access', 'extension/prom_sync/module/prom_sync')) {
            return;
        }

        if (empty($this->session->data['user_token'])) {
            return;
        }

        if (empty($data['menus']) || !is_array($data['menus'])) {
            return;
        }

        foreach ($data['menus'] as $menu) {
            if (isset($menu['id']) && $menu['id'] === 'menu-prom-sync') {
                return;
            }
        }

        $this->load->language('extension/prom_sync/module/prom_sync');

        $data['menus'][] = [
            'id'       => 'menu-prom-sync',
            'icon'     => 'fa-solid fa-plug-circle-bolt',
            'name'     => $this->language->get('heading_title'),
            'href'     => $this->url->link('extension/prom_sync/module/prom_sync', 'user_token=' . $this->session->data['user_token']),
            'children' => []
        ];
    }
}
