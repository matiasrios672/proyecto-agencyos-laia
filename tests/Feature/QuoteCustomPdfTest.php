<?php

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Services\QuotePdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');
    $this->duena = userWithRole('dueno');
    $this->camila = userWithRole('empleado');
});

/** Crea un presupuesto con PDF propio a través del endpoint real (multipart). */
function quoteWithCustomPdf($test, string $marker = 'CANVA-ORIGINAL', array $overrides = []): Quote
{
    $test->actingAs($test->duena)->post('/presupuestos', quotePayload($overrides + [
        'custom_pdf' => pdfUpload('presupuesto-canva.pdf', validPdf($marker)),
    ]))->assertSessionHasNoErrors();

    return Quote::latest('id')->firstOrFail();
}

/** Mensajes que salieron por el transporte "array" de los tests. */
function sentMessages()
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
}

describe('alta con PDF propio', function () {
    it('guarda el PDF en el disco privado con nombre aleatorio y conserva ítems y totales', function () {
        $quote = quoteWithCustomPdf($this);

        expect($quote->hasCustomPdf())->toBeTrue()
            ->and($quote->custom_pdf_name)->toBe('presupuesto-canva.pdf')
            ->and($quote->custom_pdf_path)->toStartWith("quotes/{$quote->id}/")->toMatch('/\/[A-Za-z0-9]{40}\.pdf$/')->not->toContain('canva')
            // Los ítems y totales siguen siendo obligatorios y se calculan igual (la conversión a cliente los necesita).
            ->and($quote->items)->toHaveCount(2)->and($quote->total)->toBe(106_200_090);
        Storage::disk('local')->assertExists($quote->custom_pdf_path);
    });

    it('sigue exigiendo los ítems aunque haya PDF propio, y no deja archivos sueltos si falla', function () {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['items' => [], 'custom_pdf' => pdfUpload()]))
            ->assertSessionHasErrors('items');

        expect(Quote::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('es opcional: sin PDF propio se usa el generado', function () {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload())->assertSessionHasNoErrors();

        $quote = Quote::firstOrFail();
        expect($quote->hasCustomPdf())->toBeFalse()->and($quote->custom_pdf_path)->toBeNull();
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('validaciones', function () {
    it('rechaza lo que no es un PDF real', function (string $name, string $content) {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['custom_pdf' => pdfUpload($name, $content)]))
            ->assertSessionHasErrors('custom_pdf');

        expect(Quote::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'texto renombrado a .pdf' => ['presupuesto.pdf', 'no soy un pdf'],
        'PHP renombrado a .pdf' => ['presupuesto.pdf', '<?php echo 1; ?>'],
        'imagen renombrada a .pdf' => ['presupuesto.pdf', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64)],
        'PDF con extensión .exe' => ['presupuesto.exe', "%PDF-1.4\n%%EOF"],
        'vacío' => ['presupuesto.pdf', ''],
    ]);

    it('rechaza un PDF de más de 25 MB', function () {
        $big = pdfUpload('enorme.pdf', validPdf().str_repeat('0', 26 * 1024 * 1024));

        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['custom_pdf' => $big]))->assertSessionHasErrors('custom_pdf');

        expect(Quote::count())->toBe(0);
    });

    it('al editar, un archivo inválido no pisa el PDF propio existente', function () {
        $quote = quoteWithCustomPdf($this);
        $path = $quote->custom_pdf_path;

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload(['custom_pdf' => pdfUpload('malo.pdf', 'no soy un pdf')]))
            ->assertSessionHasErrors('custom_pdf');

        Storage::disk('local')->assertExists($path);
        expect($quote->fresh()->custom_pdf_path)->toBe($path)->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });
});

describe('se usa ESE archivo en descarga, enlace público y email', function () {
    it('la descarga autenticada entrega el PDF propio', function () {
        $quote = quoteWithCustomPdf($this, 'CANVA-ORIGINAL');

        $response = $this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/pdf")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->getContent())->toContain('CANVA-ORIGINAL');
    });

    it('sin PDF propio la descarga sigue siendo el generado por el sistema', function () {
        $quote = makeQuote($this->duena);

        $content = $this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/pdf")->assertOk()->getContent();

        expect($content)->toStartWith('%PDF')->not->toContain('CANVA-ORIGINAL');
    });

    it('el enlace público firmado entrega el PDF propio, y sigue exigiendo firma', function () {
        $quote = quoteWithCustomPdf($this, 'CANVA-PUBLICO');
        $url = URL::temporarySignedRoute('quotes.public_pdf', now()->addDay(), ['quote' => $quote->id]);

        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($this->get($url)->getContent())->toContain('CANVA-PUBLICO');
        $this->get("/p/presupuesto/{$quote->id}/pdf")->assertForbidden();
    });

    it('el email adjunta el PDF propio (no el generado)', function () {
        $quote = quoteWithCustomPdf($this, 'CANVA-ADJUNTO');

        $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/enviar", ['channel' => 'email'])->assertRedirect()->assertSessionHasNoErrors();

        $message = sentMessages()->first()->getOriginalMessage();
        $attachments = $message->getAttachments();
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype())->toBe('application/pdf')
            ->and($attachments[0]->getBody())->toContain('CANVA-ADJUNTO')
            ->and($attachments[0]->getFilename())->toBe("Presupuesto-{$quote->number}.pdf");
    });

    it('sin PDF propio el email adjunta el generado', function () {
        $quote = makeQuote($this->duena);

        $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/enviar", ['channel' => 'email'])->assertRedirect();

        $attachment = sentMessages()->first()->getOriginalMessage()->getAttachments()[0];
        expect($attachment->getBody())->toStartWith('%PDF')->not->toContain('CANVA');
    });

    it('si el archivo propio desapareció del disco, cae al generado en vez de fallar', function () {
        $quote = quoteWithCustomPdf($this, 'CANVA-PERDIDO');
        Storage::disk('local')->delete($quote->custom_pdf_path);

        $pdf = QuotePdf::render($quote->fresh());

        expect($pdf)->toStartWith('%PDF')->not->toContain('CANVA-PERDIDO');
        $this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/pdf")->assertOk();
    });
});

describe('ficha y armador', function () {
    it('la ficha avisa que tiene PDF propio y el armador lo ofrece para quitar o reemplazar', function () {
        $quote = quoteWithCustomPdf($this);
        $plain = makeQuote($this->duena);

        $this->actingAs($this->duena)->get("/presupuestos/{$quote->id}")->assertInertia(fn ($p) => $p->where('quote.custom_pdf', 'presupuesto-canva.pdf'));
        $this->actingAs($this->duena)->get("/presupuestos/{$plain->id}")->assertInertia(fn ($p) => $p->where('quote.custom_pdf', null));
        $this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/edit")->assertInertia(fn ($p) => $p->where('quote.custom_pdf_name', 'presupuesto-canva.pdf'));
    });

    it('no expone la ruta interna del archivo', function () {
        $quote = quoteWithCustomPdf($this);

        $html = $this->actingAs($this->duena)->get("/presupuestos/{$quote->id}")->getContent();

        expect($html)->not->toContain($quote->custom_pdf_path)->not->toContain('custom_pdf_path');
    });
});

describe('reemplazar y quitar', function () {
    it('reemplazar guarda el nuevo y borra el archivo anterior', function () {
        $quote = quoteWithCustomPdf($this, 'VERSION-1');
        $old = $quote->custom_pdf_path;

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload(['custom_pdf' => pdfUpload('v2.pdf', validPdf('VERSION-2'))]))
            ->assertSessionHasNoErrors();

        $fresh = $quote->fresh();
        expect($fresh->custom_pdf_path)->not->toBe($old)->and($fresh->custom_pdf_name)->toBe('v2.pdf')
            ->and(Storage::disk('local')->get($fresh->custom_pdf_path))->toContain('VERSION-2');
        Storage::disk('local')->assertMissing($old);
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('quitar borra el archivo y vuelve al PDF generado', function () {
        $quote = quoteWithCustomPdf($this, 'VERSION-1');
        $path = $quote->custom_pdf_path;

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload(['remove_custom_pdf' => true]))->assertSessionHasNoErrors();

        expect($quote->fresh()->hasCustomPdf())->toBeFalse()->and($quote->fresh()->custom_pdf_name)->toBeNull();
        Storage::disk('local')->assertMissing($path);
        expect($this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/pdf")->getContent())->not->toContain('VERSION-1');
    });

    it('editar sin tocar el PDF lo conserva', function () {
        $quote = quoteWithCustomPdf($this);
        $path = $quote->custom_pdf_path;

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload(['discount_percent' => 0]))->assertSessionHasNoErrors();

        expect($quote->fresh()->custom_pdf_path)->toBe($path)->and($quote->fresh()->total)->toBe(118_000_100);
        Storage::disk('local')->assertExists($path);
    });

    it('si llegan "quitar" y un archivo nuevo a la vez, gana el archivo nuevo', function () {
        $quote = quoteWithCustomPdf($this, 'VERSION-1');

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload([
            'remove_custom_pdf' => true, 'custom_pdf' => pdfUpload('v3.pdf', validPdf('VERSION-3')),
        ]))->assertSessionHasNoErrors();

        expect($quote->fresh()->hasCustomPdf())->toBeTrue()
            ->and(Storage::disk('local')->get($quote->fresh()->custom_pdf_path))->toContain('VERSION-3');
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('agregar un PDF propio a un presupuesto que no lo tenía', function () {
        $quote = makeQuote($this->duena);

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload(['custom_pdf' => pdfUpload('nuevo.pdf', validPdf('RECIEN'))]))
            ->assertSessionHasNoErrors();

        expect($quote->fresh()->hasCustomPdf())->toBeTrue();
        expect($this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/pdf")->getContent())->toContain('RECIEN');
    });
});

describe('permisos y estados', function () {
    it('el empleado no puede subir ni descargar PDF de presupuestos', function () {
        $quote = quoteWithCustomPdf($this);

        $this->actingAs($this->camila)->post('/presupuestos', quotePayload(['custom_pdf' => pdfUpload()]))->assertForbidden();
        $this->actingAs($this->camila)->put("/presupuestos/{$quote->id}", quotePayload(['custom_pdf' => pdfUpload()]))->assertForbidden();
        $this->actingAs($this->camila)->get("/presupuestos/{$quote->id}/pdf")->assertForbidden();
        expect(Quote::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('un presupuesto aprobado o rechazado ya no admite cambiar su PDF', function (QuoteStatus $status) {
        $quote = quoteWithCustomPdf($this);
        $path = $quote->custom_pdf_path;
        $quote->forceFill(['status' => $status])->save();

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload(['custom_pdf' => pdfUpload('otro.pdf', validPdf('OTRO'))]))->assertForbidden();
        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload(['remove_custom_pdf' => true]))->assertForbidden();

        expect($quote->fresh()->custom_pdf_path)->toBe($path);
        Storage::disk('local')->assertExists($path);
    })->with([QuoteStatus::Aprobado, QuoteStatus::Rechazado]);

    it('eliminar un borrador lo da de baja pero conserva el archivo (baja lógica)', function () {
        $quote = quoteWithCustomPdf($this);

        $this->actingAs($this->duena)->delete("/presupuestos/{$quote->id}")->assertRedirect('/presupuestos');

        expect(Quote::find($quote->id))->toBeNull()->and(Quote::withTrashed()->find($quote->id))->not->toBeNull();
        Storage::disk('local')->assertExists($quote->custom_pdf_path);
    });

    it('al aprobar y convertir, el PDF propio sigue disponible en el presupuesto', function () {
        $quote = quoteWithCustomPdf($this, 'CANVA-FINAL', ['prospect_tax_id' => '30-71458963-2']);

        $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/convertir", [])->assertRedirect();

        expect($quote->fresh()->status)->toBe(QuoteStatus::Aprobado);
        expect($this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/pdf")->getContent())->toContain('CANVA-FINAL');
    });
});
