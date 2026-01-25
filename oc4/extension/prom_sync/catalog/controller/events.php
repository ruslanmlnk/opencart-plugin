<?php
namespace Opencart\Catalog\Controller\Extension\PromSync;

class Events extends \Opencart\System\Engine\Controller {
    public function onAddOrderHistory(&$route, &$args, &$output): void {
        $order_id = isset($args[0]) ? (int)$args[0] : 0;
        $order_status_id = isset($args[1]) ? (int)$args[1] : 0;

        if (!$order_id) {
            return;
        }

        $this->load->model('extension/prom_sync/module/prom_sync');
        $this->model_extension_prom_sync_module_prom_sync->pushStockForOrder($order_id, $order_status_id);
    }
}
