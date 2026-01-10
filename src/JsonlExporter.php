<?php

namespace LuangDev\Serap;

use LuangDev\Serap\Facades\Serap;

final class JsonlExporter
{
    public function export(): void
    {
        $tx = Serap::getTransaction();
        if (empty($tx)) {
            return;
        }

        // Gate: hanya export jika sampled=true
        if (($tx['sampled'] ?? true) !== true) {
            return;
        }

        info('serap', [
            'metadata' => Serap::getMetadata(),
            'transaction' => $tx,
            'spans' => Serap::getSpans(),
        ]);
    }

    /**
     * Export error always (ignores sampled gate).
     * Dipakai dari exception handler.
     */
    public function exportErrorAlways(): void
    {
        $tx = Serap::getTransaction();
        if (empty($tx)) {
            return;
        }

        info('serap', [
            'metadata' => Serap::getMetadata(),
            'transaction' => $tx,
            'spans' => Serap::getSpans(),
        ]);
    }
}
