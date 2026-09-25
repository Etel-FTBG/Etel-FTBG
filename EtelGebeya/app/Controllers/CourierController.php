<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\SchemaGuard;
use App\Core\View;

/**
 * CourierController -- courier-facing delivery panel.
 * Routes registered in public/index.php under /courier/*.
 *
 * Couriers come from Etel Group's shared Delivery & Ride driver network
 * (PRODUCT.md) rather than a public signup form on this site, so there is
 * no "/become-courier" page to mirror VendorController's /sell. Instead,
 * requireCourier() auto-provisions a couriers row on first visit for any
 * account that already carries the 'courier' role, the same pattern
 * VendorController::requireVendor() already uses for sellers.
 */
final class CourierController
{
    private Auth $auth;
    private Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
    }

    private function render(string $view, array $data = []): void
    {
        View::render($view, array_merge(['auth' => $this->auth], $data));
    }

    private function requireCsrf(string $redirectTo): void
    {
        if (!$this->auth->csrfCheck($_POST['_token'] ?? null)) {
            $this->auth->flash('error', 'Your session expired -- please try again.');
            redirect($redirectTo);
        }
    }

    private function requireCourier(): array
    {
        if (!$this->auth->check()) {
            $_SESSION['_intended'] = '/courier';
            redirect('/login');
        }
        if (!$this->auth->isCourier()) {
            redirect('/');
        }

        // couriers.user_id/status aren't queried anywhere else in this
        // codebase (the table was only ever read for its id/name/phone in
        // VendorController's assignment dropdown) -- self-heal both before
        // this is the first thing to ever write to them.
        SchemaGuard::ensureColumns($this->db, 'couriers', [
            'user_id' => 'user_id INT UNSIGNED DEFAULT NULL',
            'status'  => "status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active'",
        ]);

        $courier = $this->db->fetch("SELECT * FROM couriers WHERE user_id = ? LIMIT 1", [$this->auth->id()]);
        if (!$courier) {
            $user = $this->auth->user();
            $this->db->insert('couriers', [
                'user_id'    => $this->auth->id(),
                'name'       => $user['full_name'] ?? 'Courier',
                'phone'      => $user['phone'] ?? null,
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $courier = $this->db->fetch("SELECT * FROM couriers WHERE user_id = ? LIMIT 1", [$this->auth->id()]);
        }
        return $courier;
    }

    /* -- GET /courier -- */
    public function dashboard(): void
    {
        $courier = $this->requireCourier();
        $cid = (int)$courier['id'];

        $assigned  = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM order_items WHERE courier_id = ? AND status = 'shipped'", [$cid]
        ) ?: 0);
        $delivered = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM order_items WHERE courier_id = ? AND status = 'delivered'", [$cid]
        ) ?: 0);
        $today = (int)($this->db->fetchColumn(
            "SELECT COUNT(*) FROM order_items WHERE courier_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURDATE()", [$cid]
        ) ?: 0);

        $recent = [];
        try {
            $recent = $this->db->fetchAll(
                "SELECT oi.id AS item_id, oi.product_name, oi.quantity, oi.status AS item_status, oi.delivered_at,
                        o.order_ref AS code, o.delivery_city AS city, o.delivery_name AS customer_name
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                  WHERE oi.courier_id = ?
                  ORDER BY oi.id DESC LIMIT 8",
                [$cid]
            );
        } catch (\Throwable $_) {}

        $this->render('courier/dashboard', [
            'pageTitle' => 'Courier Dashboard',
            'courier'   => $courier,
            'assigned'  => $assigned,
            'delivered' => $delivered,
            'today'     => $today,
            'recent'    => $recent,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* -- GET /courier/deliveries -- */
    public function deliveries(): void
    {
        $courier = $this->requireCourier();
        $cid = (int)$courier['id'];

        $items = [];
        try {
            $items = $this->db->fetchAll(
                "SELECT oi.id AS item_id, oi.product_name, oi.quantity, oi.status AS item_status,
                        oi.tracking_number, oi.delivered_at,
                        o.order_ref AS code, o.delivery_name AS customer_name, o.delivery_phone AS customer_phone,
                        o.delivery_city AS city, o.delivery_address AS address, o.created_at
                   FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                  WHERE oi.courier_id = ? AND oi.status IN ('shipped','delivered')
                  ORDER BY (oi.status = 'delivered'), o.created_at DESC
                  LIMIT 100",
                [$cid]
            );
        } catch (\Throwable $_) {}

        $this->render('courier/deliveries', [
            'pageTitle' => 'My Deliveries',
            'courier'   => $courier,
            'items'     => $items,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* -- GET /courier/deliveries/{code} -- */
    public function delivery(string $code): void
    {
        $courier = $this->requireCourier();
        $cid = (int)$courier['id'];

        $order = $this->db->fetch("SELECT * FROM orders WHERE order_ref = ? LIMIT 1", [$code]);
        if (!$order) {
            $this->auth->flash('error', 'Delivery not found.');
            redirect('/courier/deliveries');
        }

        $items = $this->db->fetchAll(
            "SELECT * FROM order_items WHERE order_id = ? AND courier_id = ?",
            [$order['id'], $cid]
        );
        if (!$items) {
            $this->auth->flash('error', 'This delivery is not assigned to you.');
            redirect('/courier/deliveries');
        }

        $this->render('courier/delivery', [
            'pageTitle' => 'Delivery ' . $code,
            'courier'   => $courier,
            'order'     => $order,
            'items'     => $items,
            'flash'     => $this->auth->takeFlash(),
        ]);
    }

    /* -- POST /courier/deliveries/{code}/status -- */
    public function updateStatus(string $code): void
    {
        $courier = $this->requireCourier();
        $this->requireCsrf('/courier/deliveries/' . $code);
        $cid = (int)$courier['id'];

        $itemId = (int)($_POST['item_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        // Couriers only ever move a delivery from "shipped" (handed off by
        // the seller) to "delivered" -- every earlier stage belongs to the
        // seller (VendorController::updateOrderItemStatus), so this is a
        // deliberately narrower version of that same forward-only guard.
        if ($status !== 'delivered') {
            $this->auth->flash('error', 'Invalid status update.');
            redirect('/courier/deliveries/' . $code);
        }

        $item = $this->db->fetch(
            "SELECT oi.id, oi.order_id, oi.status
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
              WHERE oi.id = ? AND oi.courier_id = ? AND o.order_ref = ?",
            [$itemId, $cid, $code]
        );
        if (!$item) {
            $this->auth->flash('error', 'Delivery item not found.');
            redirect('/courier/deliveries');
        }
        if ($item['status'] !== 'shipped') {
            $this->auth->flash('error', 'This delivery is not ready to be marked delivered.');
            redirect('/courier/deliveries/' . $code);
        }

        new \App\Core\EscrowService(); // ensures delivered_at/escrow columns exist, same as VendorController
        $this->db->update('order_items', ['status' => 'delivered', 'delivered_at' => date('Y-m-d H:i:s')], 'id = ?', [$itemId]);
        \App\Core\OrderFulfillment::bubbleOrderStatus($this->db, (int)$item['order_id']);

        $this->auth->flash('success', 'Delivery marked as completed.');
        redirect('/courier/deliveries/' . $code);
    }
}
