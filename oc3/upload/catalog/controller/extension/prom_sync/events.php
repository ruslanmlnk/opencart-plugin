<?php
class ControllerExtensionPromSyncEvents extends Controller {
    public function onAddOrderHistory(&$route, &$args, &$output) {
        $order_id = isset($args[0]) ? (int)$args[0] : 0;
        $order_status_id = isset($args[1]) ? (int)$args[1] : 0;

        if (!$order_id) {
            return;
        }

        $this->load->model('extension/module/prom_sync');
        $this->model_extension_module_prom_sync->pushStockForOrder($order_id, $order_status_id);
    }
}
