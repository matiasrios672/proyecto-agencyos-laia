<?php

use App\Enums\ContentStatus;
use App\Models\Client;
use App\Models\ContentGrid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-11-13 10:00:00');

    $this->duena = userWithRole('dueno');
    $this->admin = userWithRole('sysadmin');
    $this->camila = userWithRole('empleado');
    $this->tomas = userWithRole('empleado');

    $this->espiga = Client::factory()->create(['business_name' => 'La Espiga S.R.L.', 'trade_name' => 'La Espiga', 'whatsapp' => '5491122334455']);
    $this->aleph = Client::factory()->create(['business_name' => 'El Aleph', 'trade_name' => null, 'whatsapp' => null]);
    $this->espiga->users()->attach($this->camila);
    $this->aleph->users()->attach($this->tomas);
});

afterEach(fn () => Carbon::setTestNow());

function uploadGrid($test, Client $client, array $overrides = [], $as = null)
{
    return ($as ? $test->actingAs($as) : $test->actingAs($test->duena))
        ->post("/clientes/{$client->id}/contenido", array_replace(['month' => '2026-11', 'file' => pdfUpload()], $overrides));
}

function makeGrid(Client $client, string $month = '2026-11', array $attrs = []): ContentGrid
{
    $path = "content-grids/{$client->id}/".str()->random(20).'.pdf';
    Storage::disk('local')->put($path, validPdf('Grilla '.$month));

    return ContentGrid::create(array_replace([
        'client_id' => $client->id, 'month' => $month.'-01', 'title' => "Grilla {$month}", 'file_path' => $path,
        'original_name' => 'canva.pdf', 'size' => 1200, 'status' => 'borrador',
    ], $attrs));
}

describe('subida', function () {
    it('guarda el PDF en el disco privado con nombre aleatorio y registra la grilla', function () {
        uploadGrid($this, $this->espiga, ['title' => 'Noviembre — campaña verano', 'notes' => 'Revisar fechas'])->assertRedirect();

        $grid = ContentGrid::firstOrFail();
        expect($grid->client_id)->toBe($this->espiga->id)
            ->and($grid->month->toDateString())->toBe('2026-11-01')
            ->and($grid->title)->toBe('Noviembre — campaña verano')
            ->and($grid->notes)->toBe('Revisar fechas')
            ->and($grid->status)->toBe(ContentStatus::Borrador)
            ->and($grid->uploaded_by)->toBe($this->duena->id)
            ->and($grid->original_name)->toBe('grilla.pdf')
            ->and($grid->size)->toBeGreaterThan(100)
            // El nombre original NO toca el disco: se guarda con un nombre aleatorio .pdf dentro de la carpeta del cliente.
            ->and($grid->file_path)->toStartWith("content-grids/{$this->espiga->id}/")->toMatch('/\/[A-Za-z0-9]{40}\.pdf$/')->not->toContain('grilla');
        Storage::disk('local')->assertExists($grid->file_path);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'content', 'description' => 'Grilla de contenido subida']);
    });

    it('nunca queda dentro de public', function () {
        Storage::fake('public');
        uploadGrid($this, $this->espiga);

        Storage::disk('public')->assertDirectoryEmpty('/');
        expect(ContentGrid::first()->file_path)->not->toContain('public');
    });

    it('propone un título por defecto con el mes', function () {
        uploadGrid($this, $this->espiga, ['month' => '2026-12']);

        expect(ContentGrid::first()->title)->toBe('Grilla de contenido — Diciembre 2026');
    });

    it('rechaza archivos que no son PDF aunque se llamen .pdf', function (string $name, string $content) {
        uploadGrid($this, $this->espiga, ['file' => pdfUpload($name, $content)])->assertSessionHasErrors('file');

        expect(ContentGrid::count())->toBe(0);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'texto renombrado' => ['grilla.pdf', 'esto es un texto cualquiera'],
        'script PHP renombrado' => ['grilla.pdf', '<?php system($_GET["c"]); ?>'],
        'imagen PNG renombrada' => ['grilla.pdf', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64)],
        'HTML renombrado' => ['grilla.pdf', '<html><script>alert(1)</script></html>'],
        'vacío' => ['grilla.pdf', ''],
    ]);

    it('rechaza una extensión que no es PDF', function () {
        uploadGrid($this, $this->espiga, ['file' => pdfUpload('grilla.exe')])->assertSessionHasErrors('file');
        uploadGrid($this, $this->espiga, ['file' => pdfUpload('grilla.png')])->assertSessionHasErrors('file');

        expect(ContentGrid::count())->toBe(0);
    });

    it('rechaza archivos de más de 25 MB', function () {
        $big = pdfUpload('enorme.pdf', validPdf().str_repeat('0', 26 * 1024 * 1024));

        uploadGrid($this, $this->espiga, ['file' => $big])->assertSessionHasErrors('file');

        expect(ContentGrid::count())->toBe(0);
    });

    it('acepta un PDF de casi 25 MB', function () {
        $ok = pdfUpload('grande.pdf', validPdf().str_repeat('0', 24 * 1024 * 1024));

        uploadGrid($this, $this->espiga, ['file' => $ok])->assertSessionHasNoErrors();

        expect(ContentGrid::count())->toBe(1);
    });

    it('valida el mes', function (mixed $month) {
        uploadGrid($this, $this->espiga, ['month' => $month])->assertSessionHasErrors('month');
    })->with(['noviembre', '2026-13', '2026-00', '26-11', '', '2031-01', '1999-05']);

    it('exige archivo y limita título y notas', function () {
        $this->actingAs($this->duena)->post("/clientes/{$this->espiga->id}/contenido", ['month' => '2026-11'])->assertSessionHasErrors('file');
        uploadGrid($this, $this->espiga, ['title' => str_repeat('a', 161)])->assertSessionHasErrors('title');
        uploadGrid($this, $this->espiga, ['notes' => str_repeat('a', 2001)])->assertSessionHasErrors('notes');
    });

    it('un nombre original malicioso solo se guarda para mostrar, saneado', function () {
        uploadGrid($this, $this->espiga, ['file' => pdfUpload("../../etc/pass\nwd.pdf")]);

        $grid = ContentGrid::firstOrFail();
        expect($grid->original_name)->not->toContain('/')->not->toContain("\n")
            ->and($grid->file_path)->not->toContain('..')->not->toContain('passwd');
    });
});

describe('un solo PDF por cliente y mes (reemplazo)', function () {
    it('si ya hay una de ese mes pide confirmar y no toca nada', function () {
        $existing = makeGrid($this->espiga);
        $before = Storage::disk('local')->get($existing->file_path);

        uploadGrid($this, $this->espiga, ['file' => pdfUpload('nueva.pdf', validPdf('Otra'))])->assertSessionHasErrors('replace');

        expect(ContentGrid::count())->toBe(1)->and(Storage::disk('local')->get($existing->fresh()->file_path))->toBe($before);
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);   // el archivo nuevo tampoco quedó huérfano
    });

    it('con confirmación reemplaza el archivo, borra el anterior y vuelve a borrador', function () {
        $existing = makeGrid($this->espiga, attrs: ['status' => 'aprobado', 'approved_at' => now(), 'shared_at' => now()]);
        $oldPath = $existing->file_path;

        uploadGrid($this, $this->espiga, ['file' => pdfUpload('v2.pdf', validPdf('Version dos')), 'replace' => true, 'title' => 'v2'])->assertSessionHasNoErrors();

        $fresh = $existing->fresh();
        expect(ContentGrid::count())->toBe(1)
            ->and($fresh->file_path)->not->toBe($oldPath)
            ->and($fresh->title)->toBe('v2')
            ->and($fresh->original_name)->toBe('v2.pdf')
            ->and($fresh->status)->toBe(ContentStatus::Borrador)
            ->and($fresh->approved_at)->toBeNull()
            ->and($fresh->shared_at)->toBeNull()
            ->and(Storage::disk('local')->get($fresh->file_path))->toContain('Version dos');
        Storage::disk('local')->assertMissing($oldPath);
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'content', 'description' => 'Grilla de contenido reemplazada']);
    });

    it('un reemplazo con un archivo inválido no destruye la grilla existente', function () {
        $existing = makeGrid($this->espiga);

        uploadGrid($this, $this->espiga, ['file' => pdfUpload('malo.pdf', 'no soy un pdf'), 'replace' => true])->assertSessionHasErrors('file');

        Storage::disk('local')->assertExists($existing->file_path);
        expect($existing->fresh()->original_name)->toBe('canva.pdf');
    });

    it('meses distintos y clientes distintos no se pisan', function () {
        uploadGrid($this, $this->espiga, ['month' => '2026-11']);
        uploadGrid($this, $this->espiga, ['month' => '2026-12']);
        uploadGrid($this, $this->aleph, ['month' => '2026-11']);

        expect(ContentGrid::count())->toBe(3);
    });
});

describe('permisos', function () {
    it('el empleado sube, ve y gestiona las de SUS clientes', function () {
        uploadGrid($this, $this->espiga, [], $this->camila)->assertSessionHasNoErrors();
        $grid = ContentGrid::firstOrFail();

        $this->actingAs($this->camila)->get("/clientes/{$this->espiga->id}/contenido")->assertOk();
        $this->actingAs($this->camila)->get("/contenido/{$grid->id}/archivo")->assertOk();
        $this->actingAs($this->camila)->patch("/contenido/{$grid->id}/estado", ['status' => 'revision_interna'])->assertRedirect();
    });

    it('el empleado NO ve, descarga, cambia ni borra grillas de clientes ajenos (403)', function () {
        $ajena = makeGrid($this->aleph);

        $this->actingAs($this->camila)->get("/clientes/{$this->aleph->id}/contenido")->assertForbidden();
        $this->actingAs($this->camila)->get("/contenido/{$ajena->id}/archivo")->assertForbidden();
        $this->actingAs($this->camila)->get("/contenido/{$ajena->id}/archivo?download=1")->assertForbidden();
        $this->actingAs($this->camila)->patch("/contenido/{$ajena->id}/estado", ['status' => 'aprobado'])->assertForbidden();
        $this->actingAs($this->camila)->post("/contenido/{$ajena->id}/compartir", ['channel' => 'whatsapp'])->assertForbidden();
        $this->actingAs($this->camila)->delete("/contenido/{$ajena->id}")->assertForbidden();
        uploadGrid($this, $this->aleph, [], $this->camila)->assertForbidden();

        expect($ajena->fresh()->status)->toBe(ContentStatus::Borrador)->and(ContentGrid::count())->toBe(1);
        Storage::disk('local')->assertExists($ajena->file_path);
    });

    it('sin content.view no entra a nada; con view pero sin manage solo mira', function () {
        $role = Role::findByName('empleado');
        $grid = makeGrid($this->espiga);

        $role->revokePermissionTo('content.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->camila->fresh())->get("/clientes/{$this->espiga->id}/contenido")->assertOk()
            ->assertInertia(fn ($p) => $p->where('can.manage', false)->where('grids.0.share_url', null)->where('grids.0.whatsapp_url', null));
        $this->actingAs($this->camila->fresh())->get("/contenido/{$grid->id}/archivo")->assertOk();
        $this->actingAs($this->camila->fresh())->patch("/contenido/{$grid->id}/estado", ['status' => 'aprobado'])->assertForbidden();
        $this->actingAs($this->camila->fresh())->post("/contenido/{$grid->id}/compartir", ['channel' => 'link'])->assertForbidden();
        $this->actingAs($this->camila->fresh())->delete("/contenido/{$grid->id}")->assertForbidden();
        uploadGrid($this, $this->espiga, ['month' => '2026-12'], $this->camila->fresh())->assertForbidden();

        $role->revokePermissionTo('content.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->camila->fresh())->get('/contenido')->assertForbidden();
        $this->actingAs($this->camila->fresh())->get("/clientes/{$this->espiga->id}/contenido")->assertForbidden();
        $this->actingAs($this->camila->fresh())->get("/contenido/{$grid->id}/archivo")->assertForbidden();
    });

    it('Dirección y sysadmin ven y gestionan todo', function (string $who) {
        $a = makeGrid($this->espiga);
        $b = makeGrid($this->aleph);

        foreach ([$a, $b] as $grid) {
            $this->actingAs($this->$who)->get("/contenido/{$grid->id}/archivo")->assertOk();
        }
        $this->actingAs($this->$who)->get("/clientes/{$this->aleph->id}/contenido")->assertOk();
        $this->actingAs($this->$who)->patch("/contenido/{$b->id}/estado", ['status' => 'revision_interna'])->assertRedirect();
    })->with(['duena', 'admin']);

    it('exige sesión', function () {
        $grid = makeGrid($this->espiga);

        $this->get('/contenido')->assertRedirect('/login');
        $this->get("/clientes/{$this->espiga->id}/contenido")->assertRedirect('/login');
        $this->get("/contenido/{$grid->id}/archivo")->assertRedirect('/login');
        $this->post("/clientes/{$this->espiga->id}/contenido", [])->assertRedirect('/login');
    });

    it('un cliente eliminado deja de ser visible aunque la grilla exista', function () {
        $grid = makeGrid($this->espiga);
        $this->espiga->delete();

        $this->actingAs($this->camila)->get("/contenido/{$grid->id}/archivo")->assertForbidden();
        $this->actingAs($this->duena)->get('/contenido')->assertInertia(fn ($p) => $p->has('grids.data', 0));
    });
});

describe('estados', function () {
    it('cambia el estado y deja auditoría', function () {
        $grid = makeGrid($this->espiga);

        $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", ['status' => 'revision_interna'])->assertRedirect();

        expect($grid->fresh()->status)->toBe(ContentStatus::RevisionInterna);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'content', 'description' => 'Estado de la grilla modificado', 'causer_id' => $this->duena->id]);
        $log = \Spatie\Activitylog\Models\Activity::where('log_name', 'content')->latest('id')->first();
        expect($log->properties['from'])->toBe('borrador')->and($log->properties['to'])->toBe('revision_interna');
    });

    it('aprobado fija la fecha de aprobación; en curso la conserva; volver atrás la borra', function () {
        $grid = makeGrid($this->espiga);

        $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", ['status' => 'aprobado']);
        expect($grid->fresh()->approved_at?->toDateTimeString())->toBe('2026-11-13 10:00:00');

        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", ['status' => 'en_curso']);
        expect($grid->fresh()->status)->toBe(ContentStatus::EnCurso)->and($grid->fresh()->approved_at?->toDateTimeString())->toBe('2026-11-13 10:00:00');

        $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", ['status' => 'revision_interna']);
        expect($grid->fresh()->approved_at)->toBeNull();
    });

    it('cada uno de los cinco estados es válido y uno inventado no', function () {
        $grid = makeGrid($this->espiga);

        foreach (ContentStatus::cases() as $status) {
            $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", ['status' => $status->value])->assertSessionHasNoErrors();
            expect($grid->fresh()->status)->toBe($status);
        }

        $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", ['status' => 'publicado'])->assertSessionHasErrors('status');
        $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", [])->assertSessionHasErrors('status');
    });

    it('repetir el mismo estado no ensucia la auditoría', function () {
        $grid = makeGrid($this->espiga);

        $this->actingAs($this->duena)->patch("/contenido/{$grid->id}/estado", ['status' => 'borrador'])->assertRedirect();

        $this->assertDatabaseMissing('activity_log', ['description' => 'Estado de la grilla modificado']);
    });
});

describe('visor y descarga (ruta autenticada)', function () {
    it('sirve el PDF inline con los headers de seguridad', function () {
        $grid = makeGrid($this->espiga);

        $response = $this->actingAs($this->duena)->get("/contenido/{$grid->id}/archivo")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')->toContain('Grilla-la-espiga-2026-11.pdf');
        expect($response->streamedContent())->toStartWith('%PDF-');
    });

    it('con ?download=1 se descarga como adjunto', function () {
        $grid = makeGrid($this->espiga);

        $response = $this->actingAs($this->duena)->get("/contenido/{$grid->id}/archivo?download=1")->assertOk();

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
    });

    it('el nombre de descarga sale del cliente y el mes, no del archivo subido', function () {
        $grid = makeGrid($this->espiga, attrs: ['original_name' => "x\"; filename=evil.exe\r\nSet-Cookie: a=b"]);

        $response = $this->actingAs($this->duena)->get("/contenido/{$grid->id}/archivo")->assertOk();

        expect($response->headers->get('Content-Disposition'))->not->toContain('evil')->not->toContain('Set-Cookie');
    });

    it('si el archivo falta en el disco responde 404 y no un error 500', function () {
        $grid = makeGrid($this->espiga);
        Storage::disk('local')->delete($grid->file_path);

        $this->actingAs($this->duena)->get("/contenido/{$grid->id}/archivo")->assertNotFound();
    });

    it('una grilla inexistente es 404', function () {
        $this->actingAs($this->duena)->get('/contenido/9999/archivo')->assertNotFound();
    });
});

describe('compartir por WhatsApp', function () {
    it('la página trae el enlace de WhatsApp con el PDF firmado y un mensaje en español', function () {
        makeGrid($this->espiga);

        $this->actingAs($this->duena)->get("/clientes/{$this->espiga->id}/contenido")->assertInertia(function ($page) {
            $page->where('grids.0.share_url', fn ($u) => str_contains($u, '/p/contenido/') && str_contains($u, 'signature='))
                ->where('grids.0.whatsapp_url', function ($u) {
                    $text = urldecode(explode('text=', $u)[1]);

                    return str_starts_with($u, 'https://wa.me/5491122334455?text=')
                        && str_contains($text, 'Noviembre 2026')
                        && str_contains($text, 'La Espiga')
                        && str_contains($text, '/p/contenido/');
                });
        });
    });

    it('sin WhatsApp cargado no hay enlace de WhatsApp pero sí enlace para copiar', function () {
        makeGrid($this->aleph);

        $this->actingAs($this->duena)->get("/clientes/{$this->aleph->id}/contenido")->assertInertia(fn ($p) => $p
            ->where('grids.0.whatsapp_url', null)->where('grids.0.share_url', fn ($u) => str_contains($u, 'signature=')));
    });

    it('el enlace firmado abre el PDF sin sesión', function () {
        $grid = makeGrid($this->espiga);
        $url = URL::temporarySignedRoute('content.public_file', now()->addDays(30), ['grid' => $grid->id]);

        $response = $this->get($url)->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');
        expect($response->streamedContent())->toStartWith('%PDF-');
    });

    it('un enlace sin firma, adulterado, de otra grilla o vencido da 403', function () {
        $a = makeGrid($this->espiga);
        $b = makeGrid($this->aleph);

        $this->get("/p/contenido/{$a->id}/pdf")->assertForbidden();

        $signed = URL::temporarySignedRoute('content.public_file', now()->addDay(), ['grid' => $a->id]);
        $this->get(str_replace("/{$a->id}/", "/{$b->id}/", $signed))->assertForbidden();
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=00', $signed))->assertForbidden();

        $expired = URL::temporarySignedRoute('content.public_file', now()->addDays(30), ['grid' => $a->id]);
        Carbon::setTestNow('2026-12-20 10:00:00');   // pasaron más de 30 días
        $this->get($expired)->assertForbidden();
    });

    it('el enlace público de una grilla borrada deja de funcionar', function () {
        $grid = makeGrid($this->espiga);
        $url = URL::temporarySignedRoute('content.public_file', now()->addDays(30), ['grid' => $grid->id]);

        $this->actingAs($this->duena)->delete("/contenido/{$grid->id}");

        auth()->logout();
        $this->get($url)->assertNotFound();
    });

    it('compartir por WhatsApp pasa borrador y revisión interna a "enviado al cliente" y lo audita', function (string $from) {
        $grid = makeGrid($this->espiga, attrs: ['status' => $from]);

        $this->actingAs($this->duena)->post("/contenido/{$grid->id}/compartir", ['channel' => 'whatsapp'])->assertRedirect();

        $fresh = $grid->fresh();
        expect($fresh->status)->toBe(ContentStatus::EnviadoCliente)->and($fresh->shared_at?->toDateTimeString())->toBe('2026-11-13 10:00:00');
        $this->assertDatabaseHas('activity_log', ['log_name' => 'content', 'description' => 'Grilla compartida por WhatsApp']);
    })->with(['borrador', 'revision_interna']);

    it('compartir no retrocede una grilla ya aprobada o en curso', function (string $status) {
        $grid = makeGrid($this->espiga, attrs: ['status' => $status, 'approved_at' => now()]);

        $this->actingAs($this->duena)->post("/contenido/{$grid->id}/compartir", ['channel' => 'whatsapp'])->assertRedirect();

        expect($grid->fresh()->status->value)->toBe($status)->and($grid->fresh()->approved_at)->not->toBeNull()->and($grid->fresh()->shared_at)->not->toBeNull();
    })->with(['aprobado', 'en_curso', 'enviado_cliente']);

    it('copiar el enlace registra el compartido pero no cambia el estado', function () {
        $grid = makeGrid($this->espiga);

        $this->actingAs($this->duena)->post("/contenido/{$grid->id}/compartir", ['channel' => 'link'])->assertRedirect();

        expect($grid->fresh()->status)->toBe(ContentStatus::Borrador)->and($grid->fresh()->shared_at)->not->toBeNull();
        $this->assertDatabaseHas('activity_log', ['description' => 'Enlace de la grilla copiado']);
    });

    it('rechaza canales desconocidos', function () {
        $grid = makeGrid($this->espiga);

        $this->actingAs($this->duena)->post("/contenido/{$grid->id}/compartir", ['channel' => 'paloma'])->assertSessionHasErrors('channel');
    });
});

describe('eliminar', function () {
    it('borra la grilla y su archivo, y lo audita', function () {
        $grid = makeGrid($this->espiga);
        $path = $grid->file_path;

        $this->actingAs($this->duena)->delete("/contenido/{$grid->id}")->assertRedirect();

        expect(ContentGrid::count())->toBe(0);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'content', 'description' => 'Grilla de contenido eliminada']);
    });

    it('al eliminar un cliente de forma definitiva se van sus grillas (cascada)', function () {
        makeGrid($this->espiga);

        $this->espiga->forceDelete();

        expect(ContentGrid::count())->toBe(0);
    });
});

describe('páginas', function () {
    it('el listado del cliente trae las grillas por mes (la más reciente primero) y avisa si falta la del mes en curso', function () {
        makeGrid($this->espiga, '2026-09');
        makeGrid($this->espiga, '2026-10');

        $this->actingAs($this->duena)->get("/clientes/{$this->espiga->id}/contenido")->assertInertia(fn ($p) => $p
            ->component('Content/Client', false)   // el verificador de archivos de Inertia mira "Pages" (con mayúscula)
            ->where('client.name', 'La Espiga')
            ->where('hasCurrent', false)
            ->where('currentMonth.value', '2026-11')
            ->where('currentMonth.label', 'Noviembre 2026')
            ->where('grids.0.month', '2026-10')->where('grids.1.month', '2026-09')
            ->where('grids.0.month_label', 'Octubre 2026')
            ->where('grids.0.can.manage', true));

        makeGrid($this->espiga, '2026-11');
        $this->actingAs($this->duena)->get("/clientes/{$this->espiga->id}/contenido")->assertInertia(fn ($p) => $p->where('hasCurrent', true)->where('grids.0.month', '2026-11'));
    });

    it('el panorama global solo incluye clientes visibles y marca los que no tienen la grilla del mes', function () {
        makeGrid($this->espiga, '2026-11');
        makeGrid($this->aleph, '2026-10');

        // Camila solo ve La Espiga: no aparece El Aleph ni en las grillas ni en los pendientes.
        $this->actingAs($this->camila)->get('/contenido')->assertOk()->assertInertia(fn ($p) => $p
            ->has('grids.data', 1)->where('grids.data.0.client.name', 'La Espiga')
            ->where('missing', [])->has('clients', 1));

        // Dirección ve ambos; a El Aleph le falta noviembre.
        $this->actingAs($this->duena)->get('/contenido')->assertInertia(fn ($p) => $p
            ->has('grids.data', 2)
            ->has('missing', 1)->where('missing.0.name', 'El Aleph'));
    });

    it('filtra por cliente, estado y mes, y valida los filtros', function () {
        makeGrid($this->espiga, '2026-11', ['status' => 'aprobado']);
        makeGrid($this->espiga, '2026-10');
        makeGrid($this->aleph, '2026-11');

        $this->actingAs($this->duena)->get("/contenido?client={$this->aleph->id}")->assertInertia(fn ($p) => $p->has('grids.data', 1)->where('grids.data.0.client.name', 'El Aleph'));
        $this->actingAs($this->duena)->get('/contenido?status=aprobado')->assertInertia(fn ($p) => $p->has('grids.data', 1)->where('grids.data.0.status', 'aprobado'));
        $this->actingAs($this->duena)->get('/contenido?month=2026-10')->assertInertia(fn ($p) => $p->has('grids.data', 1)->where('grids.data.0.month', '2026-10'));
        $this->actingAs($this->duena)->get('/contenido?month=2026-11&status=borrador')->assertInertia(fn ($p) => $p->has('grids.data', 1));

        $this->actingAs($this->duena)->get('/contenido?status=inventado')->assertSessionHasErrors('status');
        $this->actingAs($this->duena)->get('/contenido?month=noviembre')->assertSessionHasErrors('month');
    });

    it('el mes en curso aparece primero', function () {
        makeGrid($this->espiga, '2026-09');
        makeGrid($this->espiga, '2026-11');
        makeGrid($this->espiga, '2026-10');

        $this->actingAs($this->duena)->get('/contenido')->assertInertia(fn ($p) => $p
            ->where('grids.data.0.month', '2026-11')->where('grids.data.1.month', '2026-10')->where('grids.data.2.month', '2026-09'));
    });

    it('un cliente inactivo no figura entre los pendientes del mes', function () {
        $this->aleph->update(['is_active' => false]);

        $this->actingAs($this->duena)->get('/contenido')->assertInertia(fn ($p) => $p->where('missing', fn ($m) => collect($m)->pluck('name')->doesntContain('El Aleph')));
    });

    it('pagina el panorama global', function () {
        foreach (range(1, 12) as $i) {
            makeGrid(Client::factory()->create(['trade_name' => null]), '2026-11');
        }

        $this->actingAs($this->duena)->get('/contenido')->assertInertia(fn ($p) => $p->has('grids.data', 12)->where('grids.total', 12));
    });
});
