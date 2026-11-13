<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Models\Client;
use App\Models\ContentGrid;
use App\Models\User;
use App\Services\PdfStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Grillas de contenido de demostración. Los PDF se generan acá con dompdf (válidos y con la marca de Laia):
 * no hay binarios en el repositorio. Cada cliente queda con un historial distinto; algunos NO tienen la grilla
 * del mes en curso, para que el panorama de "pendientes" muestre algo.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'direccion@laia.test')->first();
        $statuses = [ContentStatus::EnCurso, ContentStatus::Aprobado, ContentStatus::EnviadoCliente, ContentStatus::RevisionInterna, ContentStatus::Borrador];

        foreach (Client::where('is_active', true)->orderBy('id')->get() as $i => $client) {
            // Los clientes en posiciones 2 y 5 quedan sin la grilla del mes en curso (pendientes).
            $months = [2, 1, 0];
            if (in_array($i, [2, 5], true)) {
                $months = [3, 2, 1];
            }

            foreach ($months as $n => $back) {
                $month = now()->startOfMonth()->subMonthsNoOverflow($back);
                $status = $back === 0 ? $statuses[($i + 2) % count($statuses)] : $statuses[$n % 2 === 0 ? 0 : 1];
                $path = "content-grids/{$client->id}/".Str::random(40).'.pdf';
                $pdf = $this->render($client, $month);
                Storage::disk(PdfStorage::DISK)->put($path, $pdf);

                ContentGrid::create([
                    'client_id' => $client->id,
                    'month' => $month,
                    'title' => 'Grilla de contenido — '.ucfirst($month->copy()->locale('es')->translatedFormat('F Y')),
                    'file_path' => $path,
                    'original_name' => 'Grilla '.Str::slug($client->displayName()).' '.$month->format('m-Y').' (Canva).pdf',
                    'size' => strlen($pdf),
                    'status' => $status,
                    'notes' => $back === 0 && $status === ContentStatus::RevisionInterna ? 'Falta confirmar las fechas de los sorteos.' : null,
                    'uploaded_by' => $owner?->id,
                    'approved_at' => in_array($status, [ContentStatus::Aprobado, ContentStatus::EnCurso], true) ? $month->copy()->addDays(2) : null,
                    'shared_at' => in_array($status, [ContentStatus::EnviadoCliente, ContentStatus::Aprobado, ContentStatus::EnCurso], true) ? $month->copy()->addDay() : null,
                ]);
            }
        }
    }

    /** PDF de muestra: calendario del mes con las publicaciones del plan (contenido ficticio). */
    private function render(Client $client, \Illuminate\Support\Carbon $month): string
    {
        $label = ucfirst($month->copy()->locale('es')->translatedFormat('F Y'));
        $themes = ['Reel: detrás de escena', 'Carrusel: tips', 'Historia: encuesta', 'Post: producto estrella', 'Reel: testimonio', 'Post: promoción del mes'];
        $rows = '';
        foreach (range(0, 5) as $k) {
            $day = $month->copy()->addDays(2 + $k * 4);
            $rows .= '<tr><td>'.$day->format('d/m').'</td><td>'.($k % 2 === 0 ? 'Instagram' : 'Facebook + Instagram').'</td><td>'.$themes[$k].'</td></tr>';
        }

        $html = <<<HTML
        <html><head><meta charset="utf-8"><style>
          body { font-family: Helvetica, sans-serif; color: #121b47; margin: 0; }
          .band { background: #162660; color: #fff; padding: 28px 36px; }
          .band small { color: #a3aed6; letter-spacing: 2px; text-transform: uppercase; font-size: 9px; }
          h1 { margin: 6px 0 0; font-size: 24px; }
          .line { height: 5px; background: #f5a773; }
          .body { padding: 28px 36px; }
          table { width: 100%; border-collapse: collapse; margin-top: 14px; }
          th { text-align: left; background: #eef0f9; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; padding: 8px; color: #5b6489; }
          td { padding: 10px 8px; border-bottom: 1px solid #e0e4f1; font-size: 12px; }
        </style></head><body>
          <div class="band"><small>Laia · Estudio Creativo Digital</small><h1>Grilla de contenido — {$label}</h1><div>{$client->displayName()}</div></div>
          <div class="line"></div>
          <div class="body"><p>Plan de publicaciones del mes (documento de demostración).</p>
            <table><tr><th>Fecha</th><th>Canal</th><th>Pieza</th></tr>{$rows}</table></div>
        </body></html>
        HTML;

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }
}
