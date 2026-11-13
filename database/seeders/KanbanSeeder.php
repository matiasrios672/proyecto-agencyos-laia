<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\KanbanCard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Tableros de demostración: calendario de contenido de redes de cada cliente sembrado. */
class KanbanSeeder extends Seeder
{
    public function run(): void
    {
        // [título, descripción, columna, días hasta el vencimiento (null = sin fecha), prioridad]
        $byClient = [
            'La Espiga' => [
                ['Carrusel de recetas de pan casero', 'Cinco recetas con foto de proceso. Cierre con llamado a pedir por WhatsApp.', 'pendientes', 3, 'media'],
                ['Reel: así sale el pan del horno', 'Plano cenital, sonido ambiente. Subir antes de las 8 h.', 'pendientes', 1, 'alta'],
                ['Historia: promo de facturas del finde', null, 'listo', 2, 'media'],
                ['Post: nuevo horario de atención', null, 'publicado', -2, 'baja'],
            ],
            'Benítez Contadores' => [
                ['Post: vencimientos impositivos del mes', 'Calendario simple con fechas de monotributo y ganancias.', 'pendientes', -1, 'alta'],
                ['Infografía: ¿monotributo o responsable inscripto?', null, 'listo', 5, 'media'],
                ['Artículo para el blog: cierre de balance', 'Mínimo 800 palabras, con llamado a consulta.', 'pendientes', 9, 'baja'],
            ],
            'Sonríe Odontología' => [
                ['Reel: mitos del blanqueamiento dental', 'Con la Dra. Paula. Guion aprobado por el cliente.', 'pendientes', 2, 'alta'],
                ['Carrusel: cuidados después de una extracción', null, 'listo', 4, 'media'],
                ['Campaña de turnos de ortodoncia', 'Piezas para feed y stories + texto del anuncio.', 'pendientes', 7, 'media'],
                ['Post: equipo de la clínica', null, 'publicado', -5, 'baja'],
            ],
            'Altos del Valle' => [
                ['Historia: vendimia en vivo', 'Cobertura el sábado, 3 stories con ubicación.', 'pendientes', 4, 'alta'],
                ['Reel: maridaje del Malbec reserva', null, 'listo', 3, 'media'],
                ['Post: reconocimiento en guía de vinos', null, 'publicado', -3, 'media'],
            ],
            'Fuerza Norte' => [
                ['Reel: rutina de piernas de 20 minutos', null, 'pendientes', 2, 'media'],
                ['Reto de 30 días: piezas semanales', 'Cuatro posts + una story de seguimiento por semana.', 'pendientes', 10, 'alta'],
                ['Post: horarios de clases de febrero', null, 'listo', 6, 'baja'],
            ],
            'Costa Atlántica' => [
                ['Carrusel: departamentos disponibles en Playa Grande', 'Fotos del fotógrafo + precios actualizados.', 'pendientes', 1, 'alta'],
                ['Reel: recorrido por el dúplex de Punta Mogotes', null, 'listo', 3, 'media'],
                ['Post: guía de zonas para vivir todo el año', null, 'pendientes', null, 'baja'],
            ],
            'Don Raúl' => [
                ['Post: promo de service de verano', 'Sin responsable todavía: el cliente no tiene equipo asignado.', 'pendientes', 5, 'media'],
                ['Antes y después de una restauración', null, 'pendientes', null, 'baja'],
            ],
            'El Aleph' => [
                ['Carrusel: los 5 libros más leídos del mes', null, 'pendientes', 2, 'media'],
                ['Reel: novedades de la semana', 'Mostrar tapas y leer una frase de cada una.', 'listo', 1, 'alta'],
                ['Historia: encuesta "¿qué leemos en el club?"', null, 'publicado', -1, 'baja'],
            ],
        ];

        foreach (Client::with('users')->orderBy('id')->get() as $client) {
            $set = $byClient[$client->trade_name] ?? null;
            if ($set === null) {
                continue;
            }

            $board = $client->ensureBoard();
            $columns = $board->columns()->get()->keyBy('key');
            $team = $client->users->where('is_active', true)->values();
            $position = [];

            foreach ($set as $i => [$title, $description, $column, $days, $priority]) {
                $col = $columns[$column];
                $position[$col->id] = $position[$col->id] ?? 0;

                KanbanCard::create([
                    'board_id' => $board->id,
                    'column_id' => $col->id,
                    'title' => $title,
                    'description' => $description,
                    // Reparte las tarjetas entre quienes integran el equipo del cliente (sin equipo → sin responsable).
                    'assigned_user_id' => $team->isEmpty() ? null : $team[$i % $team->count()]->id,
                    'due_date' => $days === null ? null : today()->addDays($days),
                    'priority' => $priority,
                    'position' => $position[$col->id]++,
                    'created_by' => $team->first()?->id,
                    'completed_at' => $column === 'publicado' ? now()->addDays($days ?? 0) : null,
                ]);
            }
        }

        // Sembrar no debe llenar las campanitas con avisos de asignación.
        DB::table('notifications')->where('type', 'App\\Notifications\\CardAssigned')->delete();
    }
}
