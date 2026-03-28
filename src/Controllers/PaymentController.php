<?php
// src/Controllers/PaymentController.php
namespace App\Controllers;

use Database;
use Auth;
use App\Helpers\Sanitizer;
use App\Repositories\PaymentRepository;

class PaymentController
{
    private PaymentRepository $payments;

    public function __construct()
    {
        $this->payments = new PaymentRepository();
    }

    /**
     * GET /payments
     * Liste tous les règlements avec noms résolus.
     * Query params optionnels : ?user_id=&method=&received_by=
     */
    public function index(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $filters = [
            'user_id'     => $_GET['user_id']     ?? null,
            'method'      => $_GET['method']      ?? null,
            'received_by' => $_GET['received_by'] ?? null,
        ];

        $payments = $this->payments->findAll(array_filter($filters));

        http_response_code(200);
        echo json_encode(['data' => $payments]);
    }

    /**
     * GET /payments/summary
     * Agrégations pour le tableau de bord.
     */
    public function summary(): void
    {
        Auth::requireRole(['admin', 'coach']);

        http_response_code(200);
        echo json_encode([
            'total'       => $this->payments->totalAmount(),
            'month_total' => $this->payments->monthAmount(),
            'count'       => $this->payments->count(),
            'by_method'   => $this->payments->byMethod(),
            'by_coach'    => $this->payments->byCoach(),
            'by_month'    => $this->payments->byMonth(),
        ]);
    }

    /**
     * POST /payments
     * Enregistre un nouveau règlement.
     */
    public function create(): void
    {
        Auth::requireRole(['admin', 'coach']);

        $data = json_decode(file_get_contents('php://input'), true);

        $required = ['user_id', 'amount', 'payment_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(422);
                echo json_encode(['error' => "Le champ '$field' est requis"]);
                return;
            }
        }

        // Validation et sanitization des entrées
        $userId      = Sanitizer::text($data['user_id'] ?? null, 36);
        $amount      = Sanitizer::positiveDecimal($data['amount'] ?? null);
        $paymentDate = Sanitizer::date($data['payment_date'] ?? null);
        $renewalDate = Sanitizer::date($data['renewal_date'] ?? null);
        $method      = Sanitizer::enum($data['payment_method'] ?? null, ['cash', 'cheque', 'virement', 'cb', 'autre']);
        $receivedBy  = Sanitizer::text($data['received_by'] ?? null, 36);
        $comment     = Sanitizer::text($data['comment'] ?? '', 500);

        if ($amount === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Montant invalide (doit être positif)']);
            return;
        }
        if ($paymentDate === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Date de règlement invalide (format YYYY-MM-DD)']);
            return;
        }

        $this->payments->create(
            $userId, $amount, $paymentDate,
            $method, $renewalDate,
            $receivedBy ?: null, $comment ?: null
        );

        http_response_code(201);
        echo json_encode(['message' => 'Règlement enregistré']);
    }

    /**
     * DELETE /payments/{id}
     * Supprime un règlement (Admin uniquement).
     */
    public function delete(string $id): void
    {
        Auth::requireRole(['admin']);
        $this->payments->delete($id);

        http_response_code(200);
        echo json_encode(['message' => 'Règlement supprimé']);
    }
}
