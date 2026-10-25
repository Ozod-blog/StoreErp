<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    private const SHOW_ERRORS = 100;

    public function __construct(private ImportService $imports)
    {
    }

    public function template(Request $request, string $type)
    {
        $def = $this->definition($request, $type);
        $path = $this->imports->template($type);

        return response()->download($path, 'import-'.$type.'-shablon.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Hech narsa yozmaydi: faqat fayl to'g'riligini ko'rsatadi. */
    public function preview(Request $request, string $type): JsonResponse
    {
        [$result] = $this->analyze($request, $type);

        return response()->json($this->payload($type, $result));
    }

    public function commit(Request $request, string $type): JsonResponse
    {
        [$result, $ctx] = $this->analyze($request, $type);

        if ($result['errors'] !== []) {
            AuditLogger::log('imports.commit', null, null, ['type' => $type, 'errors' => count($result['errors'])], 'failed');

            return response()->json($this->payload($type, $result) + ['message' => 'Faylda xatolar bor. Hech narsa import qilinmadi.'], 422);
        }
        if ($result['items'] === []) {
            return response()->json($this->payload($type, $result) + ['message' => 'Import qilinadigan yangi qator yo‘q.'], 422);
        }

        try {
            $done = $this->imports->commit($type, $result['items'], $ctx, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (QueryException) {
            return response()->json(['message' => 'Import paytida ma’lumotlar o‘zgardi (masalan, takroriy kod). Qayta urinib ko‘ring.'], 422);
        }

        AuditLogger::log('imports.commit', null, null, ['type' => $type, 'file' => $ctx['filename'], 'created' => $done['created'], 'skipped' => $result['skipped'], 'document' => $done['document']['number'] ?? null]);

        return response()->json($this->payload($type, $result) + $done + ['message' => 'Import yakunlandi.']);
    }

    // ---------------------------------------------------------------------

    private function definition(Request $request, string $type): array
    {
        $def = $this->imports->types()[$type] ?? null;
        abort_unless($def, 404, 'Import turi topilmadi.');
        foreach ($def['permissions'] as $perm) {
            if (! $request->user()->can($perm)) {
                throw new AuthorizationException();
            }
        }

        return $def;
    }

    /** @return array{0: array, 1: array} */
    private function analyze(Request $request, string $type): array
    {
        $this->definition($request, $type);
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv'],
            'warehouse_id' => [$type === 'opening_stock' ? 'required' : 'nullable', 'integer', 'exists:warehouses,id'],
            'doc_date' => ['nullable', 'date'],
        ]);
        $file = $request->file('file');
        $ctx = [
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'doc_date' => $request->input('doc_date'),
            'filename' => $file->getClientOriginalName(),
        ];

        $parsed = $this->imports->parse($type, $file);
        if ($parsed['errors'] !== []) {
            return [['items' => [], 'errors' => $parsed['errors'], 'warnings' => [], 'skipped' => 0, 'total_rows' => 0, 'new_categories' => []], $ctx];
        }

        return [$this->imports->validate($type, $parsed['rows'], $ctx), $ctx];
    }

    private function payload(string $type, array $r): array
    {
        return [
            'type' => $type,
            'total_rows' => $r['total_rows'],
            'valid_rows' => count($r['items']),
            'skipped_rows' => $r['skipped'],
            'errors_total' => count($r['errors']),
            'errors' => array_slice($r['errors'], 0, self::SHOW_ERRORS),
            'warnings' => $r['warnings'],
            'new_categories' => $r['new_categories'],
            'sample' => array_map(fn ($i) => array_diff_key($i, ['unit_id' => 1, 'product_id' => 1]), array_slice($r['items'], 0, 5)),
        ];
    }
}
