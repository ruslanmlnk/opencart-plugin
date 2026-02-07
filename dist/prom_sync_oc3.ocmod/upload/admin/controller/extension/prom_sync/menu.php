<?php
class ControllerExtensionPromSyncMenu extends Controller {
    public function index(&$route, &$data, &$output) {
        if (!$this->user->hasPermission('access', 'extension/module/prom_sync')) {
            return;
        }

        if (empty($this->session->data['user_token']) && empty($this->session->data['token'])) {
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

        $this->load->language('extension/module/prom_sync');

        $data['menus'][] = array(
            'id'       => 'menu-prom-sync',
            'icon'     => 'fa-plug',
            'name'     => $this->language->get('heading_title'),
            'href'     => $this->url->link('extension/module/prom_sync', $this->buildToken(), true),
            'children' => array()
        );
    }

    private function buildToken() {
        if (!empty($this->session->data['user_token'])) {
            return 'user_token=' . $this->session->data['user_token'];
        }
        return 'token=' . $this->session->data['token'];
    }
}
