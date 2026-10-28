<?php
declare(strict_types=1);

namespace App\Support;

use App\Services\FineParameters;
use App\Services\PatronLedger;
use TCPDF;

/**
 * PDF receipt for a payment recorded in the patron ledger (plan §7).
 * Same A4 layout, branding and footer as the loan receipt.
 */
class PaymentReceiptPdf extends LoanPdfGenerator
{
    /**
     * @throws \RuntimeException when the movement is not a payment of $userId
     */
    public function generateForPayment(int $userId, int $movementId): string
    {
        $ledger = new PatronLedger($this->db);
        $movement = $ledger->movement($movementId);
        if ($movement === null || (int) $movement['utente_id'] !== $userId || (string) $movement['tipo'] !== 'pagamento') {
            throw new \RuntimeException('payment_not_found');
        }

        $stmt = $this->db->prepare('SELECT nome, cognome, codice_tessera, email FROM utenti WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $appName = (string) ConfigStore::get('app.name', 'Biblioteca');
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->configurePdf($pdf, $appName);
        $pdf->SetTitle(__('Ricevuta di pagamento'));
        $pdf->AddPage();
        $this->renderHeader($pdf, $appName, $this->resolveLogoPath(), __('Ricevuta di pagamento'));

        $pdf->SetFont('dejavusans', '', 10);
        $this->renderSection($pdf, __('Pagamento'), [
            __('Numero ricevuta:') => sprintf('P-%06d', (int) $movement['id']),
            __('Data:') => format_date((string) $movement['created_at'], true, '/'),
            __('Importo:') => FineParameters::format(-FineParameters::decimalToCents($movement['importo'])),
            __('Metodo di pagamento:') => PatronLedger::methodLabel((string) ($movement['metodo_pagamento'] ?? '')) ?: __('Non specificato'),
            __('Registrato da:') => trim((string) ($movement['operatore'] ?? '')) !== '' ? (string) $movement['operatore'] : __('Non specificato'),
        ]);
        $pdf->Ln(3);

        $userFields = [__('Nome:') => trim(((string) ($user['nome'] ?? '')) . ' ' . ((string) ($user['cognome'] ?? '')))];
        if (!empty($user['codice_tessera'])) {
            $userFields[__('Codice Tessera:')] = (string) $user['codice_tessera'];
        }
        if (!empty($user['email'])) {
            $userFields[__('Email:')] = (string) $user['email'];
        }
        $this->renderSection($pdf, __('Dati Utente'), $userFields);
        $pdf->Ln(3);

        $this->renderSection($pdf, __('Saldo'), [
            __('Saldo residuo:') => FineParameters::format(max(0, $ledger->balanceAfterCents($userId, $movementId))),
        ]);

        $note = trim((string) ($movement['descrizione'] ?? ''));
        if ($note !== '') {
            $pdf->Ln(3);
            $this->renderSection($pdf, __('Note'), ['' => $note]);
        }

        $this->renderFooter($pdf);
        return $pdf->Output('', 'S');
    }
}
