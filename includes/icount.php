<?php
require_once __DIR__ . '/../config.php';

class ICountClient {

    private string $apiKey;
    private string $companyId;
    private string $baseUrl = 'https://api.icount.co.il/api/v3.php';

    public function __construct() {
        $this->apiKey    = ICOUNT_API_KEY;
        $this->companyId = ICOUNT_COMPANY_ID;
    }

    private function request(string $endpoint, array $params = []): array {
        $params['api_key']    = $this->apiKey;
        $params['company_id'] = $this->companyId;

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("iCount cURL error: $err");
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new RuntimeException("iCount returned invalid JSON");
        }
        return $data;
    }

    /**
     * שליפת עסקאות לפי דף תשלום וטווח תאריכים
     */
    public function getTransactions(string $paymentPageId, string $fromDate, string $toDate): array {
        $data = $this->request('doc/getList', [
            'payment_page_id' => $paymentPageId,
            'date_from'       => $fromDate,
            'date_to'         => $toDate,
            'doc_type'        => 320,  // קבלה/חשבונית מס
        ]);

        if (empty($data['status']) || $data['status'] !== 'success') {
            // החזר מערך ריק אם אין תוצאות (לא שגיאה)
            return [];
        }

        return $data['list'] ?? [];
    }

    /**
     * סנכרון כל הקורסים הפעילים ממסד הנתונים
     */
    public function syncAllCourses(\PDO $db): array {
        $courses = $db->query("SELECT * FROM courses WHERE status='active'")->fetchAll(\PDO::FETCH_ASSOC);

        $inserted = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($courses as $course) {
            try {
                // שלוף עסקאות מה-30 ימים האחרונים (או ניתן להרחיב)
                $fromDate = date('Y-m-d', strtotime('-30 days'));
                $toDate   = date('Y-m-d');

                $transactions = $this->getTransactions(
                    $course['icount_payment_page_id'],
                    $fromDate,
                    $toDate
                );

                foreach ($transactions as $tx) {
                    $transactionId  = (string)($tx['doc_id'] ?? $tx['id'] ?? '');
                    $buyerName      = trim(($tx['client_name'] ?? '') ?: ($tx['name'] ?? ''));
                    $buyerEmail     = trim($tx['email'] ?? '');
                    $amount         = (float)($tx['total'] ?? $tx['amount'] ?? 0);
                    $purchaseDate   = $tx['doc_date'] ?? $tx['date'] ?? date('Y-m-d H:i:s');

                    if (!$transactionId || $amount <= 0) continue;

                    $stmt = $db->prepare("
                        INSERT IGNORE INTO purchases
                            (course_id, icount_transaction_id, buyer_name, buyer_email, amount, purchase_date)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $affected = $stmt->execute([$course['id'], $transactionId, $buyerName, $buyerEmail, $amount, $purchaseDate]);

                    if ($stmt->rowCount() > 0) {
                        $inserted++;
                    } else {
                        $skipped++;
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "קורס {$course['name']}: " . $e->getMessage();
            }
        }

        return compact('inserted', 'skipped', 'errors');
    }
}
