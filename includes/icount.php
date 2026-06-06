<?php
require_once __DIR__ . '/../config.php';

class ICountClient {

    private string $apiKey;
    private string $baseUrl = 'https://api.icount.co.il/api/v3.php';

    public function __construct() {
        $this->apiKey = ICOUNT_API_KEY;
    }

    private function request(string $endpoint, array $body = []): array {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("iCount cURL error: $err");

        $data = json_decode($response, true);
        if (!$data) throw new RuntimeException("iCount returned invalid JSON: $response");

        return $data;
    }

    /**
     * שליפת עסקאות לפי טווח תאריכים
     */
    public function getTransactions(string $fromDate, string $toDate, string $doctype = 'receipt'): array {
        $data = $this->request('doc/search', [
            'start_date'  => $fromDate,
            'end_date'    => $toDate,
            'doctype'     => $doctype,
            'detail_level' => 10,
            'max_results' => 1000,
            'sort_field'  => 'dateissued',
            'sort_order'  => 'DESC',
        ]);

        if (empty($data['results_list'])) return [];
        return $data['results_list'];
    }

    /**
     * סנכרון כל הקורסים — שולף עסקאות ומתאים לפי payment_page_id
     */
    public function syncAllCourses(\PDO $db): array {
        $courses = $db->query("SELECT * FROM courses WHERE status='active'")->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($courses)) return ['inserted' => 0, 'skipped' => 0, 'errors' => ['אין קורסים פעילים']];

        // שלוף את כל העסקאות מ-30 יום אחרונים
        $fromDate = date('Y-m-d', strtotime('-30 days'));
        $toDate   = date('Y-m-d');

        $inserted = 0;
        $skipped  = 0;
        $errors   = [];

        // נסה doctypes שונים
        $doctypes = ['receipt', 'invrec', 'invoice'];

        foreach ($doctypes as $doctype) {
            try {
                $transactions = $this->getTransactions($fromDate, $toDate, $doctype);

                foreach ($transactions as $tx) {
                    $txId       = (string)($tx['docnum'] ?? '');
                    $txDoctype  = $tx['doctype'] ?? $doctype;
                    $uniqueId   = $txDoctype . '_' . $txId;
                    $amount     = (float)($tx['totalwithvat'] ?? $tx['paid'] ?? 0);
                    $clientName = $tx['client_name'] ?? '';
                    $clientEmail = $tx['email'] ?? '';
                    $txDate     = $tx['dateissued'] ?? date('Y-m-d');

                    if (!$txId || $amount <= 0) continue;

                    // התאם לקורס לפי payment_page_id — כרגע נכניס לפי הקורס הראשון של הלקוח
                    // בעתיד ניתן להוסיף שדה custom לקישור
                    foreach ($courses as $course) {
                        $stmt = $db->prepare("
                            INSERT IGNORE INTO purchases
                                (course_id, icount_transaction_id, buyer_name, buyer_email, amount, purchase_date)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$course['id'], $uniqueId, $clientName, $clientEmail, $amount, $txDate]);

                        if ($stmt->rowCount() > 0) {
                            $inserted++;
                        } else {
                            $skipped++;
                        }
                        break; // רק לקורס אחד
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "doctype $doctype: " . $e->getMessage();
            }
        }

        return compact('inserted', 'skipped', 'errors');
    }
}
