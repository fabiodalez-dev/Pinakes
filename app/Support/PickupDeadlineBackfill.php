<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\SettingsRepository;
use mysqli;

/**
 * Issue #366 follow-up (0.7.65): backfill pickup_deadline on legacy
 * ready-pickup rows.
 *
 * The pickup-expiry sweep only expires `da_ritirare` rows whose
 * pickup_deadline IS NOT NULL, and the 0.7.63 repair deliberately preserves a
 * ready pickup whose copy is genuinely free. A legacy row promoted by the
 * pre-0.7.62 flow with a NULL deadline therefore stayed "ready for pickup"
 * forever: nothing expired it and nothing repaired it (the state reported on
 * #366 after the 0.7.63 Docker upgrade). Current code always sets a deadline
 * on promotion, so NULL-deadline da_ritirare rows can only be legacy data.
 *
 * The backfill mirrors the approval flow: today + loans.pickup_expiry_days
 * (default 3), capped at the loan's own data_scadenza so a pickup can never be
 * confirmed after its loan window. A row whose window already closed receives
 * a past deadline and is culled by the very next maintenance sweep, which
 * releases the copy through the normal expiry path (notifications and
 * reassignment included) instead of this backfill guessing at lifecycle side
 * effects.
 *
 * This is PHP wired into Updater::runMigrations() AFTER reapplyTriggers(), not
 * a migration SQL file, ON PURPOSE: SQL migrations execute while the STARTING
 * version's BEFORE UPDATE trigger is still installed, and the pre-0.7.63
 * trigger re-fires its same-copy overlap gate on ANY update of a copy-bound
 * row — it would abort the whole upgrade on a preserved pickup sharing its
 * copy with an overlapping scheduled loan. The corrected trigger (re-applied
 * just before this runs) exempts updates that leave the commitment unchanged,
 * and this UPDATE touches only pickup_deadline.
 *
 * Idempotent (repaired rows have a NON-NULL deadline) and never throws — a
 * failure is logged and retried on the next upgrade run.
 */
final class PickupDeadlineBackfill
{
    public static function run(mysqli $db): bool
    {
        try {
            $settings = new SettingsRepository($db);
            $pickupDays = max(1, (int) ($settings->get('loans', 'pickup_expiry_days', '3') ?? 3));

            // COALESCE fallback equals the uncapped deadline so rows with a
            // NULL data_scadenza (legacy data) simply get today + N days.
            $stmt = $db->prepare("
                UPDATE prestiti
                SET pickup_deadline = LEAST(
                    DATE_ADD(CURDATE(), INTERVAL ? DAY),
                    COALESCE(data_scadenza, DATE_ADD(CURDATE(), INTERVAL ? DAY))
                )
                WHERE stato = 'da_ritirare'
                  AND attivo = 1
                  AND pickup_deadline IS NULL
            ");
            if ($stmt === false) {
                SecureLogger::warning('PickupDeadlineBackfill: prepare failed: ' . $db->error);
                return false;
            }
            $stmt->bind_param('ii', $pickupDays, $pickupDays);
            if (!$stmt->execute()) {
                // With mysqli in exception mode a failure throws (caught below);
                // this guard also holds if reporting is ever disabled, so the
                // Updater sees the failure instead of a silent partial backfill.
                $stmt->close();
                return false;
            }
            $repaired = $stmt->affected_rows;
            $stmt->close();

            if ($repaired > 0) {
                SecureLogger::info('PickupDeadlineBackfill: assigned a pickup deadline to legacy ready-pickup rows', [
                    'rows' => $repaired,
                    'pickup_days' => $pickupDays,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            SecureLogger::warning('PickupDeadlineBackfill failed: ' . $e->getMessage());
            return false;
        }
    }
}
