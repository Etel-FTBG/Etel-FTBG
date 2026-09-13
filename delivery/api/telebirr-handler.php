<?php
/**
 * ETEL Delivery  Telebirr Payment Gateway
 * Handles USSD-initiated ETB payments for Hawassa
 */

require_once __DIR__ . '/config-etb.php';

class TelebirrGateway {

    private $merchantId;
    private $apiKey;
    private $secretKey;
    private $baseUrl;

    public function __construct() {
        $this->merchantId = TELEBIRR_MERCHANT_ID;
        $this->apiKey     = TELEBIRR_API_KEY;
        $this->secretKey  = TELEBIRR_SECRET_KEY;
        $this->baseUrl    = TELEBIRR_BASE_URL;
    }

    /**
     * Initiate USSD payment  customer dials *150# to confirm
     */
    public function initiateUSSDPayment($pdo, $user_id, $order_id, $phone, $amount) {
        if (!$this->validateEthiopianPhone($phone)) {
            return array(
                'success' => false,
                'message' => 'Invalid Ethiopian phone. Use format: +251911234567 or 0911234567'
            );
        }

        $merchantTxId = 'ETEL' . time() . rand(1000, 9999);
        $formattedPhone = $this->formatPhone($phone);

        $sql = 'INSERT INTO telebirr_payments
                (user_id, order_id, amount, currency, phone, merchant_transaction_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)';
        try {
            $s = $pdo->prepare($sql);
            $s->execute(array($user_id, $order_id, $amount, 'ETB', $formattedPhone, $merchantTxId, 'pending'));
            $paymentId = $pdo->lastInsertId();
        } catch (Exception $e) {
            error_log('[ETEL][telebirr] payment insert failed: ' . $e->getMessage());
            return array('success' => false, 'message' => 'Could not start payment, please try again');
        }

        $callbackUrl = 'https://' . (defined('CFG_APP_HOST') ? CFG_APP_HOST : 'ethelmarket.com')
            . '/delivery/api/telebirr-callback.php';

        $payload = array(
            'request_type'            => 'PAY_WITH_USSD',
            'merchant_id'             => $this->merchantId,
            'merchant_transaction_id' => $merchantTxId,
            'amount'                  => (int) round($amount * 100),
            'currency'                => 'ETB',
            'phone'                   => $formattedPhone,
            'description'             => 'ETEL Order #' . $order_id,
            'customer_id'             => $user_id,
            'merchant_callback_url'   => $callbackUrl,
        );
        $payload['signature'] = $this->sign($payload);

        $response = $this->request('POST', '/transaction/initiate', $payload);

        if ($response['success']) {
            $pdo->prepare('UPDATE telebirr_payments
                          SET telebirr_transaction_id=?, response_data=?, status=?
                          WHERE id=?')
                ->execute(array(
                    isset($response['data']['transaction_id']) ? $response['data']['transaction_id'] : '',
                    json_encode($response['data']),
                    'processing',
                    $paymentId
                ));

            return array(
                'success'        => true,
                'payment_id'     => $paymentId,
                'transaction_id' => isset($response['data']['transaction_id']) ? $response['data']['transaction_id'] : '',
                'message'        => 'Payment initiated! Dial *150# on ' . $formattedPhone . ' to confirm.',
                'amount'         => $amount,
                'phone'          => $formattedPhone,
            );
        }

        $pdo->prepare('UPDATE telebirr_payments SET status=?, error_message=? WHERE id=?')
            ->execute(array('failed', $response['message'], $paymentId));

        return array('success' => false, 'message' => 'Telebirr error: ' . $response['message']);
    }

    /**
     * Check payment status (used by polling)
     */
    public function checkPaymentStatus($pdo, $paymentId) {
        $s = $pdo->prepare('SELECT * FROM telebirr_payments WHERE id=?');
        $s->execute(array($paymentId));
        $payment = $s->fetch();

        if (!$payment) {
            return array('success' => false, 'message' => 'Payment not found');
        }

        if ($payment['status'] === 'completed') {
            return array('success' => true, 'status' => 'completed',
                         'amount' => $payment['amount'],
                         'transaction_id' => $payment['telebirr_transaction_id']);
        }

        $payload = array(
            'request_type'            => 'CHECK_STATUS',
            'merchant_id'             => $this->merchantId,
            'merchant_transaction_id' => $payment['merchant_transaction_id'],
            'transaction_id'          => $payment['telebirr_transaction_id'],
        );
        $payload['signature'] = $this->sign($payload);
        $response = $this->request('POST', '/transaction/status', $payload);

        if ($response['success'] && (isset($response['data']['status']) ? $response['data']['status'] : '') === 'COMPLETED') {
            $pdo->prepare('UPDATE telebirr_payments SET status=?, completed_at=NOW() WHERE id=?')
                ->execute(array('completed', $paymentId));

            if ($payment['order_id']) {
                $pdo->prepare("UPDATE orders SET status='paid', payment_method='telebirr' WHERE id=?")
                    ->execute(array($payment['order_id']));
            }

            return array('success' => true, 'status' => 'completed', 'message' => 'Payment received!');
        }

        return array(
            'success' => false,
            'status'  => isset($response['data']['status']) ? $response['data']['status'] : 'processing',
            'message' => 'Awaiting confirmation. Please dial *150# on your phone.',
        );
    }

    /** Format phone to 251XXXXXXXXX */
    public function formatPhone($phone) {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '251' . substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '251' . $digits;
        }
        return $digits;
    }

    /** Accept 0911234567, +251911234567, 251911234567, 911234567 */
    public function validateEthiopianPhone($phone) {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) === 10) return $digits[0] === '0';
        if (strlen($digits) === 12) return substr($digits, 0, 3) === '251';
        if (strlen($digits) === 9)  return true;
        return false;
    }

    private function sign($data) {
        return hash_hmac('sha256', json_encode($data), $this->secretKey);
    }

    private function request($method, $endpoint, $payload) {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ),
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return array('success' => false, 'message' => 'Connection error: ' . $error, 'data' => array());
        }

        $data = json_decode($response, true) ?: array();
        $ok   = ($httpCode === 200) && in_array(isset($data['status']) ? $data['status'] : '', array('SUCCESS', 'PENDING'));

        return array('success' => $ok, 'data' => $data,
                     'message' => isset($data['message']) ? $data['message'] : 'Error',
                     'http_code' => $httpCode);
    }
}
